<?php
declare(strict_types=1);

/*
 * Webhook Telegram-бота заказов (@qaz_pest_bot): Telegram присылает сюда каждое сообщение.
 * Сайт ведёт на t.me/<бот>?start=order_<id товара>; бот собирает имя, телефон, комментарий
 * и отправляет заявку в группу заказов.
 *
 * Секреты — только в <домашняя папка>/private/bot-config.php (пример: server/bot-config.example.php).
 * Регистрация webhook: php server/bot-webhook.php <конфиг> set  (README → «Бот»).
 */

define('BOT_APP', true);
require __DIR__ . '/lib/texts.php';
require __DIR__ . '/lib/telegram.php';
require __DIR__ . '/lib/state.php';
require __DIR__ . '/lib/handler.php';

/** Конфиг без «<?php» в начале PHP вывел бы как текст (с токеном) — такой файл не исполняем. */
function load_bot_config(): ?array
{
    $path = getenv('BOT_CONFIG') ?: dirname(__DIR__, 2) . '/private/bot-config.php';
    $raw = is_file($path) ? (string) @file_get_contents($path) : '';
    if (!str_starts_with(ltrim($raw, "\xEF\xBB\xBF \t\r\n"), '<?php')) {
        return null;
    }
    ob_start();
    try {
        $config = require $path;
    } finally {
        ob_end_clean();
    }
    foreach (['bot_token', 'orders_chat_id', 'webhook_secret'] as $key) {
        if (!is_array($config) || !is_scalar($config[$key] ?? null) || (string) $config[$key] === '') {
            return null;
        }
    }
    return $config + [
        'site_url' => 'https://qazpestcontrol.com/',
        'contact_phone' => '',
        'products_file' => dirname(__DIR__) . '/api/products.json',
        'state_dir' => dirname(__DIR__, 2) . '/private/bot-state',
        'telegram_api' => 'https://api.telegram.org',
    ];
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
$config = load_bot_config();
if ($config === null) {
    error_log('bot: конфиг не найден или не заполнен');
    http_response_code(503);
    exit;
}
// Только Telegram знает секрет, заданный при setWebhook.
$sent = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (!hash_equals((string) $config['webhook_secret'], $sent)) {
    http_response_code(403);
    exit;
}
$body = file_get_contents('php://input', false, null, 0, 200_001);
$update = is_string($body) && strlen($body) <= 200_000 ? json_decode($body, true) : null;
if (!is_array($update)) {
    http_response_code(400);
    exit;
}

// Подтверждаем получение сразу: иначе при медленном ответе Telegram повторит обновление.
ignore_user_abort(true);
http_response_code(200);
echo 'ok';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    header('Connection: close');
    header('Content-Length: 2');
    @ob_end_flush();
    flush();
}

try {
    $catalog = function () use ($config): ?array {
        $json = @file_get_contents((string) $config['products_file']);
        $data = is_string($json) ? json_decode($json, true) : null;
        return is_array($data) ? $data : null;
    };
    handle_update($update, [
        'tg' => new Telegram((string) $config['bot_token'], (string) $config['telegram_api']),
        'store' => new StateStore((string) $config['state_dir']),
        'catalog' => $catalog,
        'config' => $config,
    ]);
} catch (Throwable $e) {
    error_log('bot: ' . get_class($e) . ': ' . $e->getMessage());
}
