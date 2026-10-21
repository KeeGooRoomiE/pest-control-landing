<?php
// Управление webhook бота. Запускать у себя в терминале (нужен только PHP с curl):
//   php server/bot-webhook.php <путь/к/bot-config.php> set|info|delete
// set    — зарегистрировать https://…/bot/webhook.php в Telegram с секретом из конфига;
// info   — показать текущее состояние (ошибки доставки, очередь);
// delete — вернуть бота в режим getUpdates (нужно, чтобы запустить старого бота на VPS).
// Токен в вывод не печатается.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Только из командной строки.\n");
}
[$self, $path, $cmd] = array_pad($argv, 3, null);
if ($path === null || !in_array($cmd, ['set', 'info', 'delete'], true) || !is_file($path)) {
    fwrite(STDERR, "Использование: php server/bot-webhook.php <bot-config.php> set|info|delete\n");
    exit(2);
}
$config = require $path;
foreach (['bot_token', 'webhook_secret'] as $key) {
    if (!is_string($config[$key] ?? null) || $config[$key] === '') {
        fwrite(STDERR, "В конфиге не заполнено поле {$key}.\n");
        exit(2);
    }
}
if (!preg_match('/^[A-Za-z0-9_-]{1,256}$/', $config['webhook_secret'])) {
    fwrite(STDERR, "webhook_secret: допустимы только A-Z a-z 0-9 _ - (до 256 символов).\n");
    exit(2);
}
$url = (string) ($config['webhook_url'] ?? 'https://qazpestcontrol.com/bot/webhook.php');

function tg(string $token, string $method, array $params = []): array
{
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $res = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($res) ? $res : ['ok' => false, 'description' => 'нет ответа от Telegram'];
}

$res = match ($cmd) {
    'set' => tg($config['bot_token'], 'setWebhook', [
        'url' => $url,
        'secret_token' => $config['webhook_secret'],
        'allowed_updates' => ['message'],
        'max_connections' => 5,
    ]),
    'delete' => tg($config['bot_token'], 'deleteWebhook'),
    'info' => tg($config['bot_token'], 'getWebhookInfo'),
};
if ($cmd === 'info' && isset($res['result']['url'])) {
    echo "url: {$res['result']['url']}\n";
    echo 'в очереди: ' . ($res['result']['pending_update_count'] ?? 0) . "\n";
    echo 'последняя ошибка: ' . ($res['result']['last_error_message'] ?? '—') . "\n";
    exit(0);
}
echo ($res['ok'] ?? false) ? "OK ({$cmd})" . ($cmd === 'set' ? ": {$url}" : '') . "\n" : 'Ошибка: ' . ($res['description'] ?? 'неизвестно') . "\n";
exit(($res['ok'] ?? false) ? 0 : 1);
