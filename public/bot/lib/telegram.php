<?php
declare(strict_types=1);
defined('BOT_APP') || exit;

/** Минимальный клиент Bot API. Токен в логи не попадает. */
class Telegram
{
    public function __construct(private string $token, private string $api = 'https://api.telegram.org')
    {
    }

    /**
     * Возвращает result при успехе, null при ошибке. Один повтор через секунду при сетевом сбое, 5xx и 429.
     */
    public function call(string $method, array $params): ?array
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $ch = curl_init(rtrim($this->api, '/') . '/bot' . $this->token . '/' . $method);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
            ]);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded) && ($decoded['ok'] ?? false) === true) {
                return is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
            }
            $code = (int) ($decoded['error_code'] ?? 0);
            error_log(sprintf(
                'bot: Telegram %s, попытка %d: %s',
                $method,
                $attempt,
                is_array($decoded) ? ($code . ' ' . ($decoded['description'] ?? '')) : ('нет ответа, curl errno ' . $errno)
            ));
            if ($attempt === 2 || !($decoded === null || $code === 429 || $code >= 500)) {
                return null;
            }
            sleep(1);
        }
        return null;
    }
}
