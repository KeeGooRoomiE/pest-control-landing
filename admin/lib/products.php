<?php
declare(strict_types=1);
defined('ADMIN_APP') || exit;

/** Формат файла как у существующего products.json: отступ 2 пробела, перевод строки в конце. */
function products_json(array $products): string
{
    $json = json_encode(array_values($products), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Не удалось сформировать products.json.');
    }
    $json = preg_replace_callback('/^( +)/m', fn(array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json);
    return $json . "\n";
}

/** Слаг для id нового товара (транслитерация). id потом не меняется: на него ссылается бот. */
function slugify(string $name): string
{
    static $map = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh',
        'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
        'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu',
        'я' => 'ya', 'ә' => 'a', 'ғ' => 'g', 'қ' => 'k', 'ң' => 'n', 'ө' => 'o', 'ұ' => 'u', 'ү' => 'u',
        'һ' => 'h', 'і' => 'i',
    ];
    $s = strtr(mb_strtolower($name), $map);
    $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    return substr($s, 0, 60) !== '' ? rtrim(substr($s, 0, 60), '-') : 'product';
}

function unique_id(string $base, array $products): string
{
    $taken = array_column($products, 'id');
    $id = $base;
    for ($i = 2; in_array($id, $taken, true); $i++) {
        $id = $base . '-' . $i;
    }
    return $id;
}

/** Проверка полей формы. Возвращает [данные, ошибки]. */
function validate_product_input(array $post): array
{
    $errors = [];
    $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) ($post['name'] ?? '')) ?? '');
    if ($name === '' || mb_strlen($name) > 200) {
        $errors[] = 'Название обязательно, не длиннее 200 символов.';
    }
    $priceRaw = preg_replace('/[\s\x{00A0}]/u', '', (string) ($post['price'] ?? '')) ?? '';
    if (!preg_match('/^\d{1,9}$/', $priceRaw)) {
        $errors[] = 'Цена — целое число в тенге, без букв и копеек.';
    }
    $description = str_replace(["\r\n", "\r"], "\n", (string) ($post['description'] ?? ''));
    $description = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $description) ?? '');
    $description = preg_replace("/\n{3,}/", "\n\n", $description) ?? '';
    if (mb_strlen($description) > 6000) {
        $errors[] = 'Описание слишком длинное (максимум 6000 символов).';
    }
    return [['name' => $name, 'price' => (int) $priceRaw, 'description' => $description], $errors];
}

function image_in_use(array $products, ?string $image, ?string $exceptId = null): bool
{
    if ($image === null || $image === '') {
        return false;
    }
    foreach ($products as $p) {
        if (($p['id'] ?? null) !== $exceptId && ($p['image'] ?? null) === $image) {
            return true;
        }
    }
    return false;
}
