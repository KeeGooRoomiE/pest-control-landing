<?php
declare(strict_types=1);

/* Тесты PHP-бота без сети и токенов: php tests/bot/run.php (порт bot-python/test_bot.py). */

define('BOT_APP', true);
foreach (['texts', 'telegram', 'state', 'handler'] as $f) {
    require __DIR__ . '/../../public/bot/lib/' . $f . '.php';
}

$failed = 0;
$total = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $failed, $total;
    $total++;
    if (!$ok) {
        $failed++;
        echo "FAIL  {$name} {$extra}\n";
    }
}
function same(string $name, mixed $got, mixed $want): void
{
    check($name, $got === $want, 'got=' . var_export($got, true) . ' want=' . var_export($want, true));
}

// --- тексты ---------------------------------------------------------------
same('payload ok', parse_order_payload('order_kelt-1l'), 'kelt-1l');
same('payload пустой', parse_order_payload(null), null);
same('payload чужой', parse_order_payload('ref_abc'), null);
same('payload мусор', parse_order_payload('order_a b'), null);
same('payload длинный', parse_order_payload('order_' . str_repeat('a', 65)), null);
same('цена', format_price(26000), '26 000 ₸');
same('цена usd', format_price(5, 'USD'), '5 $');
same('телефон 8', normalize_phone('8 (705) 123-45-67'), '+77051234567');
same('телефон +7', normalize_phone('+7 705 123 45 67'), '+77051234567');
same('телефон 10 цифр', normalize_phone('7051234567'), '+77051234567');
same('телефон плохой', normalize_phone('abc'), null);
same('телефон короткий', normalize_phone('12345'), null);
same('clean_text', clean_text("  a \n  b\t c ", 100), 'a b c');
same('clean_text лимит', clean_text('абвгд', 3), 'абв');

$product = ['id' => 'x<1>', 'name' => 'Яд <b>"крыс"</b>', 'price' => 26000, 'currency' => 'KZT'];
$msg = render_order_message('AB12CD', $product, 'Иван <script>', '+77051234567', 'a & b', 42, 'ivan_x', new DateTimeImmutable('2026-10-21 04:49:00 UTC'));
check('экранирование имени', str_contains($msg, 'Иван &lt;script&gt;'));
check('экранирование товара', str_contains($msg, 'Яд &lt;b&gt;&quot;крыс&quot;&lt;/b&gt;'));
check('нет сырых тегов', !str_contains($msg, '<script>') && !str_contains($msg, '<b>"'));
check('юзернейм и профиль', str_contains($msg, '@ivan_x · <a href="tg://user?id=42">профиль</a>'));
check('время по Алматы', str_contains($msg, '21.10.2026 09:49 (Алматы)'));
check('комментарий', str_contains($msg, '<b>Комментарий:</b> a &amp; b'));
$msg2 = render_order_message('R', $product, 'N', '+7', '', 7, null, new DateTimeImmutable('2026-01-01 00:00:00 UTC'));
check('без юзернейма и комментария', !str_contains($msg2, '@') && !str_contains($msg2, 'Комментарий') && str_contains($msg2, 'tg://user?id=7'));

// --- диалог ----------------------------------------------------------------
class FakeTg
{
    public array $sent = [];
    public bool $failOrders = false;
    public function call(string $method, array $params): ?array
    {
        if ($params['chat_id'] === -100500 && $this->failOrders) {
            return null;
        }
        $this->sent[] = $params;
        return [];
    }
}

function make(?array $catalog, FakeTg $tg, ?string &$dir = null): array
{
    $dir = sys_get_temp_dir() . '/botstate-' . bin2hex(random_bytes(4));
    return [
        'tg' => $tg,
        'store' => new StateStore($dir),
        'catalog' => fn() => $catalog,
        'config' => ['orders_chat_id' => -100500, 'site_url' => 'https://example.test/', 'contact_phone' => '+7 705 000 00 00'],
    ];
}

$uid = 0;
function msg(int &$updateId, string $text, array $extra = [], int $user = 42, string $type = 'private'): array
{
    return ['update_id' => ++$updateId, 'message' => [
        'chat' => ['id' => $user, 'type' => $type],
        'from' => ['id' => $user, 'first_name' => 'Анна', 'username' => 'anna'],
        'text' => $text,
    ] + $extra];
}
$catalog = [['id' => 'kelt-1l', 'name' => 'Кельт 1 л', 'price' => 24000, 'currency' => 'KZT']];

// полный путь
$tg = new FakeTg();
$d = make($catalog, $tg);
$u = 0;
handle_update(msg($u, '/start order_kelt-1l'), $d);
check('start: вопрос имени', str_contains($tg->sent[0]['text'], 'Кельт 1 л') && str_contains($tg->sent[0]['text'], 'Как к вам обращаться'));
same('start: кнопка с именем', $tg->sent[0]['reply_markup']['keyboard'][0][0]['text'], 'Анна');
handle_update(msg($u, 'Анна'), $d);
check('имя: просит телефон', str_contains($tg->sent[1]['text'], 'номер телефона') && isset($tg->sent[1]['reply_markup']['keyboard'][0][0]['request_contact']));
handle_update(msg($u, '8 705 123 45 67'), $d);
check('телефон: просит комментарий', str_contains($tg->sent[2]['text'], 'комментарий'));
handle_update(msg($u, 'Нужно 3 штуки'), $d);
$order = $tg->sent[3];
same('заказ уходит в группу', $order['chat_id'], -100500);
check('заказ: содержимое', str_contains($order['text'], 'Кельт 1 л') && str_contains($order['text'], '+77051234567') && str_contains($order['text'], 'Нужно 3 штуки'));
same('заказ: HTML', $order['parse_mode'], 'HTML');
check('клиенту: подтверждение', str_contains($tg->sent[4]['text'], 'Заявка #') && $tg->sent[4]['chat_id'] === 42);
$n = count($tg->sent);
handle_update(msg($u, 'просто текст после заказа'), $d);
same('после заказа бот молчит', count($tg->sent), $n);

// контакт-кнопка и «Пропустить»
$tg = new FakeTg();
$d = make($catalog, $tg);
$u = 0;
handle_update(msg($u, '/start order_kelt-1l'), $d);
handle_update(msg($u, 'Анна'), $d);
$contact = msg($u, '');
unset($contact['message']['text']);
$contact['message']['contact'] = ['phone_number' => '77051112233'];
handle_update($contact, $d);
handle_update(msg($u, 'Пропустить'), $d);
check('контакт и пропуск: без комментария', !str_contains($tg->sent[3]['text'], 'Комментарий') && str_contains($tg->sent[3]['text'], '+77051112233'));

// плохой телефон
$tg = new FakeTg();
$d = make($catalog, $tg);
$u = 0;
handle_update(msg($u, '/start order_kelt-1l'), $d);
handle_update(msg($u, 'Анна'), $d);
handle_update(msg($u, 'не телефон'), $d);
check('плохой телефон: переспрашивает', str_contains($tg->sent[2]['text'], 'Не похоже на номер'));
handle_update(msg($u, '+7 705 123 45 67'), $d);
check('после исправления идёт дальше', str_contains($tg->sent[3]['text'], 'комментарий'));

// /start без payload и неизвестный товар
$tg = new FakeTg();
$d = make($catalog, $tg);
$u = 0;
handle_update(msg($u, '/start'), $d);
check('start без payload', str_contains($tg->sent[0]['text'], 'https://example.test/'));
handle_update(msg($u, '/start order_nope'), $d);
check('неизвестный товар', str_contains($tg->sent[1]['text'], 'Не нашёл такой товар'));
handle_update(msg($u, 'Анна'), $d);
same('после неизвестного товара бот молчит', count($tg->sent), 2);

// каталог недоступен
$tg = new FakeTg();
$d = make(null, $tg);
$u = 0;
handle_update(msg($u, '/start order_kelt-1l'), $d);
check('каталог недоступен: телефон в подсказке', str_contains($tg->sent[0]['text'], 'временно недоступен') && str_contains($tg->sent[0]['text'], '+7 705 000 00 00'));

// групповые чаты игнорируются
$tg = new FakeTg();
$d = make($catalog, $tg);
$u = 0;
handle_update(msg($u, '/start order_kelt-1l', [], 42, 'group'), $d);
same('групповой чат игнорируется', count($tg->sent), 0);

// сбой отправки в группу
$tg = new FakeTg();
$d = make($catalog, $tg);
$u = 0;
handle_update(msg($u, '/start order_kelt-1l'), $d);
handle_update(msg($u, 'Анна'), $d);
handle_update(msg($u, '+7 705 123 45 67'), $d);
$tg->failOrders = true;
handle_update(msg($u, 'Пропустить'), $d);
$last = end($tg->sent);
check('сбой группы: клиенту сообщают и дают телефон', str_contains($last['text'], 'Не удалось передать') && str_contains($last['text'], '+7 705 000 00 00'));

// дубль обновления не создаёт второй заказ
$tg = new FakeTg();
$d = make($catalog, $tg);
$u = 0;
handle_update(msg($u, '/start order_kelt-1l'), $d);
handle_update(msg($u, 'Анна'), $d);
handle_update(msg($u, '+7 705 123 45 67'), $d);
$final = msg($u, 'Пропустить');
handle_update($final, $d);
handle_update($final, $d);
$orders = array_filter($tg->sent, fn($p) => $p['chat_id'] === -100500);
same('дубль update_id: один заказ', count($orders), 1);

// отмена
$tg = new FakeTg();
$d = make($catalog, $tg);
$u = 0;
handle_update(msg($u, '/start order_kelt-1l'), $d);
handle_update(msg($u, '/cancel'), $d);
check('отмена', str_contains(end($tg->sent)['text'], 'Заказ отменён'));
$n = count($tg->sent);
handle_update(msg($u, 'Анна'), $d);
same('после отмены бот молчит', count($tg->sent), $n);

// лимит заказов в час
$tg = new FakeTg();
$d = make($catalog, $tg);
$u = 0;
for ($i = 0; $i < ORDERS_PER_HOUR + 1; $i++) {
    handle_update(msg($u, '/start order_kelt-1l'), $d);
    handle_update(msg($u, 'Анна'), $d);
    handle_update(msg($u, '+7 705 123 45 67'), $d);
    handle_update(msg($u, 'Пропустить'), $d);
}
same('лимит в час: в группу ушло не больше лимита', count(array_filter($tg->sent, fn($p) => $p['chat_id'] === -100500)), ORDERS_PER_HOUR);
check('лимит в час: клиент предупреждён', str_contains(end($tg->sent)['text'], 'Слишком много заявок'));

// два пользователя не мешают друг другу
$tg = new FakeTg();
$d = make($catalog, $tg);
$u = 0;
handle_update(msg($u, '/start order_kelt-1l', [], 1), $d);
handle_update(msg($u, 'Борис', [], 2), $d); // у второго нет сессии
same('чужая сессия не используется', count($tg->sent), 1);

// старая сессия (>24 ч) не продолжается
$tg = new FakeTg();
$d = make($catalog, $tg);
$u = 0;
handle_update(msg($u, '/start order_kelt-1l'), $d);
$d['store']->locked(42, function (array &$s) { $s['session']['ts'] = time() - StateStore::SESSION_TTL - 10; });
handle_update(msg($u, 'Анна'), $d);
same('просроченная сессия игнорируется', count($tg->sent), 1);

echo $failed === 0 ? "OK: {$total} проверок\n" : "ПРОВАЛЕНО: {$failed} из {$total}\n";
exit($failed === 0 ? 0 : 1);
