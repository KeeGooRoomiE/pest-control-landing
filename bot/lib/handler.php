<?php
declare(strict_types=1);
defined('BOT_APP') || exit;

/*
 * Диалог заказа (порт bot-python): /start order_<id> → имя → телефон → комментарий → заявка в группу.
 * Один вызов handle_update на одно обновление от Telegram; состояние между сообщениями — в StateStore.
 * $deps: tg (объект с call()), store (StateStore), catalog (callable(): ?array), config (array).
 */

const ORDERS_PER_HOUR = 5;

function kb(array $rows): array
{
    return ['keyboard' => $rows, 'resize_keyboard' => true, 'one_time_keyboard' => true];
}

const NO_KB = ['remove_keyboard' => true];

function reply(array $d, int $chatId, string $text, ?array $markup = null, bool $html = false): ?array
{
    $params = ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true];
    if ($markup !== null) {
        $params['reply_markup'] = $markup;
    }
    if ($html) {
        $params['parse_mode'] = 'HTML';
    }
    return $d['tg']->call('sendMessage', $params);
}

function fallback_hint(array $config): string
{
    return ($config['contact_phone'] ?? '') !== '' ? ' Или позвоните: ' . $config['contact_phone'] . '.' : '';
}

function handle_update(array $update, array $d): void
{
    $m = $update['message'] ?? null;
    if (!is_array($m) || ($m['chat']['type'] ?? '') !== 'private' || !isset($m['from']['id'], $m['chat']['id'])) {
        return;
    }
    $userId = (int) $m['from']['id'];
    $updateId = (int) ($update['update_id'] ?? 0);

    $d['store']->locked($userId, function (array &$state) use ($m, $updateId, $d): void {
        // Telegram может прислать одно обновление дважды (повтор после таймаута) — не оформляем заказ дважды.
        if ($updateId !== 0 && $updateId <= (int) $state['last_update']) {
            return;
        }
        $state['last_update'] = $updateId;
        process_message($m, $state, $d);
    });
}

function process_message(array $m, array &$state, array $d): void
{
    $config = $d['config'];
    $chatId = (int) $m['chat']['id'];
    $text = (string) ($m['text'] ?? '');

    if (preg_match('~^/start(?:@\w+)?(?:\s+(\S+))?~u', $text, $mm) === 1) {
        do_start($m, $state, $d, $mm[1] ?? null);
        return;
    }
    if (preg_match('~^/cancel(?:@\w+)?\s*$~u', $text) === 1) {
        $state['session'] = null;
        reply($d, $chatId, 'Заказ отменён.', NO_KB);
        return;
    }
    $session = $state['session'];
    if (!is_array($session) || str_starts_with($text, '/')) {
        return; // вне диалога бот молчит; прочие команды не обрабатываются
    }

    // Фото, стикеры и прочее без текста игнорируем; контакт принимаем только на шаге телефона.
    if (!isset($m['text']) && !($session['step'] === 'phone' && isset($m['contact']))) {
        return;
    }

    switch ($session['step']) {
        case 'name':
            $name = clean_text($text, 100);
            if ($name === '') {
                reply($d, $chatId, 'Напишите, пожалуйста, имя текстом.');
                return;
            }
            $session['name'] = $name;
            $session['step'] = 'phone';
            $state['session'] = $session;
            reply($d, $chatId, 'Оставьте номер телефона для связи — нажмите кнопку или напишите номер.',
                kb([[['text' => '📱 Отправить мой номер', 'request_contact' => true]]]));
            return;

        case 'phone':
            $raw = isset($m['contact']['phone_number']) ? (string) $m['contact']['phone_number'] : $text;
            $phone = normalize_phone($raw);
            if ($phone === null) {
                reply($d, $chatId, 'Не похоже на номер телефона. Пример: +7 705 123 45 67');
                return;
            }
            $session['phone'] = $phone;
            $session['step'] = 'comment';
            $state['session'] = $session;
            reply($d, $chatId, 'Добавьте комментарий (количество, адрес доставки, вопросы) или нажмите «Пропустить».',
                kb([['Пропустить']]));
            return;

        case 'comment':
            finish_order($m, $state, $d, $session, clean_text($text, 500));
            return;
    }
}

function do_start(array $m, array &$state, array $d, ?string $arg): void
{
    $config = $d['config'];
    $chatId = (int) $m['chat']['id'];
    $state['session'] = null;
    $productId = parse_order_payload($arg);

    if ($productId === null) {
        reply($d, $chatId, "Здравствуйте! Это бот QAZ Pest Control.\nВыберите товар на сайте " . $config['site_url']
            . ' и нажмите «Заказать» — я оформлю заявку.', NO_KB);
        return;
    }
    $catalog = ($d['catalog'])();
    if ($catalog === null) {
        error_log('bot: каталог недоступен');
        reply($d, $chatId, 'Каталог временно недоступен, попробуйте чуть позже.' . fallback_hint($config));
        return;
    }
    $product = null;
    foreach ($catalog as $p) {
        if (is_array($p) && ($p['id'] ?? null) === $productId) {
            $product = $p;
            break;
        }
    }
    if ($product === null) {
        reply($d, $chatId, 'Не нашёл такой товар — возможно, он снят с продажи. Выберите другой на сайте: ' . $config['site_url']);
        return;
    }

    $state['session'] = ['step' => 'name', 'product' => $product, 'ts' => time()];
    $price = format_price((float) ($product['price'] ?? 0), (string) ($product['currency'] ?? 'KZT'));
    $first = (string) ($m['from']['first_name'] ?? '');
    reply($d, $chatId,
        "Оформляем заказ:\n<b>" . esc((string) ($product['name'] ?? '')) . '</b> — ' . esc($price) . "\n\nКак к вам обращаться?",
        $first !== '' ? kb([[['text' => $first]]]) : NO_KB, true);
}

function finish_order(array $m, array &$state, array $d, array $session, string $comment): void
{
    $config = $d['config'];
    $chatId = (int) $m['chat']['id'];
    $comment = mb_strtolower($comment) === 'пропустить' ? '' : $comment;

    if (!isset($session['product'], $session['name'], $session['phone'])) {
        $state['session'] = null;
        reply($d, $chatId, 'Что-то пошло не так, начнём заново: нажмите «Заказать» на сайте.', NO_KB);
        return;
    }
    // Защита группы от флуда: не больше ORDERS_PER_HOUR заказов в час от одного человека.
    $now = time();
    $state['orders'] = array_values(array_filter($state['orders'], fn($t) => is_int($t) && $t > $now - 3600));
    if (count($state['orders']) >= ORDERS_PER_HOUR) {
        $state['session'] = null;
        reply($d, $chatId, 'Слишком много заявок подряд.' . fallback_hint($config) . ' Попробуйте позже.', NO_KB);
        return;
    }

    $ref = strtoupper(bin2hex(random_bytes(3)));
    $text = render_order_message(
        $ref,
        $session['product'],
        $session['name'],
        $session['phone'],
        $comment,
        (int) $m['from']['id'],
        isset($m['from']['username']) ? (string) $m['from']['username'] : null,
    );
    $sent = $d['tg']->call('sendMessage', [
        'chat_id' => $config['orders_chat_id'],
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ]);
    $state['session'] = null;
    if ($sent === null) {
        error_log("bot: не удалось отправить заказ #{$ref} в чат заказов");
        reply($d, $chatId, 'Не удалось передать заявку менеджеру.' . fallback_hint($config) . ' Попробуйте ещё раз позже.', NO_KB);
        return;
    }
    $state['orders'][] = $now;
    reply($d, $chatId, "Спасибо! Заявка #{$ref} принята. Менеджер свяжется с вами в ближайшее время.", NO_KB);
}
