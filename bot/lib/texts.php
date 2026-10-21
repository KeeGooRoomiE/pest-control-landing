<?php
declare(strict_types=1);
defined('BOT_APP') || exit;

/* Чистые функции бота: разбор payload, валидация, форматирование. Без Telegram и сети. */

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** `/start order_<id>` -> `<id>`. Всё остальное (в т.ч. пустой payload) -> null. */
function parse_order_payload(?string $arg): ?string
{
    if ($arg === null || !str_starts_with($arg, 'order_')) {
        return null;
    }
    $id = substr($arg, 6);
    return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1 ? $id : null;
}

function format_price(int|float $price, string $currency = 'KZT'): string
{
    $signs = ['KZT' => '₸', 'RUB' => '₽', 'USD' => '$', 'EUR' => '€'];
    return number_format((int) $price, 0, '', ' ') . ' ' . ($signs[$currency] ?? $currency);
}

/** Приводит номер к виду +7XXXXXXXXXX. Нераспознанное -> null. */
function normalize_phone(string $raw): ?string
{
    $digits = preg_replace('/\D/', '', $raw) ?? '';
    if (strlen($digits) === 11 && $digits[0] === '8') {
        $digits = '7' . substr($digits, 1);
    } elseif (strlen($digits) === 10) {
        $digits = '7' . $digits;
    }
    return strlen($digits) >= 10 && strlen($digits) <= 15 ? '+' . $digits : null;
}

function clean_text(?string $raw, int $limit): string
{
    return mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $raw) ?? ''), 0, $limit);
}

/** HTML-сообщение для чата заказов. Любой пользовательский текст экранируется. */
function render_order_message(
    string $ref,
    array $product,
    string $name,
    string $phone,
    string $comment,
    int $userId,
    ?string $username,
    ?DateTimeInterface $when = null,
): string {
    $when = DateTimeImmutable::createFromInterface($when ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('+05:00'));
    $price = format_price((float) ($product['price'] ?? 0), (string) ($product['currency'] ?? 'KZT'));

    $links = ['<a href="tg://user?id=' . $userId . '">профиль</a>'];
    if ($username !== null && $username !== '') {
        array_unshift($links, '@' . esc($username));
    }
    $lines = [
        '🛒 <b>Новый заказ #' . esc($ref) . '</b>',
        '',
        '<b>Товар:</b> ' . esc((string) ($product['name'] ?? '')),
        '<b>ID:</b> <code>' . esc((string) ($product['id'] ?? '')) . '</code>',
        '<b>Цена:</b> ' . esc($price),
        '',
        '<b>Клиент:</b> ' . esc($name),
        '<b>Телефон:</b> ' . esc($phone),
        '<b>Telegram:</b> ' . implode(' · ', $links),
    ];
    if ($comment !== '') {
        $lines[] = '<b>Комментарий:</b> ' . esc($comment);
    }
    array_push($lines, '', '<i>' . $when->format('d.m.Y H:i') . ' (Алматы)</i>');
    return implode("\n", $lines);
}
