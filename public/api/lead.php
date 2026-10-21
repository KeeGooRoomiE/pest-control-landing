<?php
declare(strict_types=1);

/*
 * Приём заявок с формы сайта → Telegram-группа.
 *
 * Секреты (токен бота, ID чата) лежат ТОЛЬКО в конфиге на сервере вне webroot:
 *   <домашняя папка хостинга>/private/lead-config.php   (пример: server/lead-config.example.php)
 * В репозиторий и в публичный JS они не попадают.
 *
 * Защита: только POST, лимит размера, проверка Origin, honeypot, ограничение частоты
 * по IP и общий дневной лимит, все поля очищаются и обрезаются.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function clean(mixed $value, int $limit): string
{
    $s = is_scalar($value) ? (string) $value : '';
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return mb_substr(trim($s), 0, $limit);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// --- конфиг ---------------------------------------------------------------
$configPath = getenv('LEAD_CONFIG') ?: dirname(__DIR__, 2) . '/private/lead-config.php';
// Конфиг без «<?php» в начале PHP вывел бы как текст (с токеном) — такой файл не исполняем.
$configRaw = is_file($configPath) ? (string) @file_get_contents($configPath) : '';
$config = null;
if (str_starts_with(ltrim($configRaw, "\xEF\xBB\xBF \t\r\n"), '<?php')) {
    ob_start();
    $config = require $configPath;
    ob_end_clean();
}
if (!is_array($config) || empty($config['bot_token']) || empty($config['chat_id'])) {
    error_log('lead.php: конфиг не найден или не заполнен: ' . $configPath);
    respond(503, ['ok' => false, 'error' => 'not_configured']);
}

// --- Origin ----------------------------------------------------------------
$allowedHosts = $config['allowed_hosts'] ?? [];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && $allowedHosts) {
    $originHost = parse_url($origin, PHP_URL_HOST);
    if (!is_string($originHost) || !in_array(strtolower($originHost), array_map('strtolower', $allowedHosts), true)) {
        respond(403, ['ok' => false, 'error' => 'forbidden_origin']);
    }
}

// --- тело запроса ----------------------------------------------------------
$raw = file_get_contents('php://input', false, null, 0, 20481);
if ($raw === false || strlen($raw) > 20480) {
    respond(413, ['ok' => false, 'error' => 'too_large']);
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    respond(400, ['ok' => false, 'error' => 'bad_request']);
}

// honeypot: бот заполнил невидимое поле — делаем вид, что всё хорошо
if (clean($input['website'] ?? '', 200) !== '') {
    respond(200, ['ok' => true]);
}

$name = clean($input['name'] ?? '', 100);
$phone = clean($input['phone'] ?? '', 50);
$objectType = clean($input['objectType'] ?? '', 100);
$comment = clean($input['comment'] ?? '', 1000);

if ($name === '' || strlen(preg_replace('/\D/', '', $phone) ?? '') < 6) {
    respond(422, ['ok' => false, 'error' => 'invalid_fields']);
}

// --- ограничение частоты ---------------------------------------------------
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
if ($forwarded !== '' && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
    $candidate = trim(explode(',', $forwarded)[0]);
    if (filter_var($candidate, FILTER_VALIDATE_IP)) {
        $ip = $candidate; // за прокси nginx реальный адрес приходит в заголовке
    }
}

$stateDir = (string) ($config['state_dir'] ?? dirname(__DIR__, 2) . '/private/ratelimit');
if (!is_dir($stateDir) && !@mkdir($stateDir, 0700, true)) {
    $stateDir = sys_get_temp_dir() . '/qaz-lead-ratelimit';
    @mkdir($stateDir, 0700, true);
}

/** Возвращает true и записывает попытку, если лимит не превышен. */
function allow(string $file, int $maxInWindow, int $windowSec): bool
{
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return true; // не смогли открыть файл состояния — лучше принять заявку, чем потерять
    }
    flock($fp, LOCK_EX);
    $stored = json_decode((string) stream_get_contents($fp), true);
    $now = time();
    $hits = array_values(array_filter(is_array($stored) ? $stored : [], fn($t) => is_int($t) && $t > $now - 86400));
    $recent = count(array_filter($hits, fn($t) => $t > $now - $windowSec));
    $ok = $recent < $maxInWindow;
    if ($ok) {
        $hits[] = $now;
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($hits));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

$perIp = (int) ($config['limit_per_ip_10min'] ?? 5);
$globalDaily = (int) ($config['limit_global_daily'] ?? 200);
if (!allow($stateDir . '/ip_' . hash('sha256', $ip) . '.json', $perIp, 600)
    || !allow($stateDir . '/global.json', $globalDaily, 86400)) {
    header('Retry-After: 600');
    respond(429, ['ok' => false, 'error' => 'rate_limited']);
}

// --- сообщение -------------------------------------------------------------
$when = (new DateTime('now', new DateTimeZone('Asia/Almaty')))->format('d.m.Y H:i');
$text = "📩 Новая заявка с сайта\n\n"
    . 'Имя: ' . ($name !== '' ? $name : '—') . "\n"
    . 'Телефон: ' . ($phone !== '' ? $phone : '—') . "\n"
    . 'Тип объекта: ' . ($objectType !== '' ? $objectType : '—');
if ($comment !== '') {
    $text .= "\nКомментарий: " . $comment;
}
$text .= "\n\n" . $when . ' (Алматы)';

$api = rtrim((string) ($config['telegram_api'] ?? 'https://api.telegram.org'), '/');
$url = $api . '/bot' . $config['bot_token'] . '/sendMessage';
$payload = http_build_query([
    'chat_id' => $config['chat_id'],
    'text' => $text,
    'disable_web_page_preview' => 'true',
]);

/**
 * Один запрос к Telegram. Возвращает [разобранный ответ|null, код ошибки транспорта|0].
 */
function telegramSend(string $url, string $payload): array
{
    $errno = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 8,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
    }
    $decoded = is_string($response) ? json_decode($response, true) : null;
    return [is_array($decoded) ? $decoded : null, $errno];
}

// Telegram изредка не отвечает или отдаёт 5xx/429 — одна повторная попытка через секунду.
// Постоянные ошибки (неверный токен/чат, 4xx) не повторяем.
$decoded = null;
for ($attempt = 1; $attempt <= 2; $attempt++) {
    [$decoded, $errno] = telegramSend($url, $payload);
    if (($decoded['ok'] ?? false) === true) {
        break;
    }
    $errorCode = (int) ($decoded['error_code'] ?? 0);
    // токен в лог не пишем
    error_log(sprintf(
        'lead.php: Telegram, попытка %d: %s',
        $attempt,
        $decoded !== null ? ($errorCode . ' ' . ($decoded['description'] ?? 'unknown')) : ('нет ответа, curl errno ' . $errno)
    ));
    $retryable = $decoded === null || $errorCode === 429 || $errorCode >= 500;
    if ($attempt === 2 || !$retryable) {
        respond(502, ['ok' => false, 'error' => 'telegram_failed']);
    }
    sleep(1);
}

respond(200, ['ok' => true]);
