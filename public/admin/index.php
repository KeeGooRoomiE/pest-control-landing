<?php
declare(strict_types=1);

/*
 * Админка каталога товаров QAZ Pest Control: /admin/
 * Правит src/data/products.json и фото в репозитории через GitHub API; сайт и бот подхватывают
 * изменения сами (README → «Админка»). Секреты — в <домашняя папка>/private/admin-config.php.
 */

define('ADMIN_APP', true);
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/github.php';
require __DIR__ . '/lib/products.php';
require __DIR__ . '/lib/images.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' https://raw.githubusercontent.com data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $query = ''): never
{
    header('Location: /admin/' . $query, true, 303);
    exit;
}

function flash(string $type, string $text): void
{
    $_SESSION['flash'] = [$type, $text];
}

function page(string $title, string $body, int $status = 200): never
{
    http_response_code($status);
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    $flashHtml = $flash ? '<p class="flash ' . h($flash[0]) . '" role="status">' . h($flash[1]) . '</p>' : '';
    echo <<<HTML
<!doctype html>
<html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$title} — QAZ Pest Control</title>
<style>
:root{--g:#1f6e3d;--gd:#14512c;--r:#d3232f;--ink:#0f1b2d;--mut:#64748b;--line:#e2e8f0;--bg:#f6f8f7}
*{box-sizing:border-box}body{margin:0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:var(--bg)}
header.top{background:var(--g);color:#fff}header.top div{max-width:880px;margin:0 auto;padding:12px 16px;display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap}
header.top a,header.top button{color:#fff}header.top b{font-size:18px}
main{max-width:880px;margin:0 auto;padding:20px 16px 60px}
h1{font-size:24px;margin:0 0 16px}
.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:12px}
.flash{padding:12px 14px;border-radius:10px;margin:0 0 16px}.flash.ok{background:#e7f5ec;color:var(--gd)}.flash.err{background:#fde8ea;color:#8f1721}
.note{color:var(--mut);font-size:14px;margin:0 0 16px}
label{display:block;font-weight:600;margin:14px 0 6px}
input[type=text],input[type=password],textarea{width:100%;font:inherit;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;background:#fff}
textarea{min-height:220px;resize:vertical}
input:focus,textarea:focus,button:focus-visible,a:focus-visible{outline:3px solid #8fd3a9;outline-offset:1px}
.btn{display:inline-block;font:inherit;font-weight:600;padding:10px 16px;border-radius:10px;border:0;background:var(--g);color:#fff;text-decoration:none;cursor:pointer}
.btn:hover{background:var(--gd)}.btn.sec{background:#fff;color:var(--ink);border:1px solid #cbd5e1}.btn.sec:hover{background:#f1f5f9}.btn.del{background:#fff;color:var(--r);border:1px solid #f0b4b9}
.btn.sm{padding:6px 10px;font-size:14px}
.link{background:none;border:0;font:inherit;text-decoration:underline;cursor:pointer;padding:0}
.row{display:flex;gap:14px;align-items:center}.row .grow{flex:1;min-width:0}
.thumb{width:64px;height:64px;border-radius:10px;object-fit:contain;background:#f1f5f9;border:1px solid var(--line);flex:none}
.ph{display:flex;align-items:center;justify-content:center;color:var(--mut);font-size:12px}
.price{color:var(--mut);font-size:14px}.name{font-weight:600;overflow-wrap:anywhere}
.acts{display:flex;gap:6px;flex-wrap:wrap;align-items:center}.acts form{margin:0}
.top-acts{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.preview{width:160px;height:160px;object-fit:contain;background:#f1f5f9;border:1px solid var(--line);border-radius:12px;display:block;margin:8px 0}
.hint{color:var(--mut);font-size:14px;margin:4px 0 0}
@media(max-width:560px){.row{align-items:flex-start}.thumb{width:52px;height:52px}}
</style></head><body>
HTML;
    echo $GLOBALS['topbar'] ?? '';
    echo "<main>{$flashHtml}{$body}</main></body></html>";
    exit;
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function topbar(): string
{
    return '<header class="top"><div><b>QAZ Pest Control · админка</b><span><a href="/" target="_blank" rel="noopener">Открыть сайт ↗</a> · '
        . '<form method="post" action="/admin/" style="display:inline">' . csrf_field()
        . '<input type="hidden" name="action" value="logout"><button class="link" type="submit">Выйти</button></form></span></div></header>';
}

function login_page(?string $error = null, int $status = 200): never
{
    $err = $error ? '<p class="flash err" role="alert">' . h($error) . '</p>' : '';
    page('Вход', '<h1>Вход в админку</h1>' . $err
        . '<form method="post" action="/admin/" class="card" autocomplete="on">' . csrf_field()
        . '<input type="hidden" name="action" value="login">'
        . '<label for="u">Логин</label><input id="u" type="text" name="user" autocomplete="username" required autofocus>'
        . '<label for="p">Пароль</label><input id="p" type="password" name="password" autocomplete="current-password" required>'
        . '<p><button class="btn" type="submit">Войти</button></p></form>', $status);
}

function thumb_url(GitHub $gh, ?string $image): ?string
{
    return $image ? 'https://raw.githubusercontent.com/' . $gh->repo() . '/' . $gh->branch() . '/' . GitHub::IMAGES_DIR . rawurlencode($image) : null;
}

function move_form(string $id, string $dir): string
{
    $label = $dir === 'up' ? ['↑', 'Выше'] : ['↓', 'Ниже'];
    return '<form method="post" action="/admin/">' . csrf_field() . '<input type="hidden" name="action" value="move">'
        . '<input type="hidden" name="dir" value="' . $dir . '"><input type="hidden" name="id" value="' . h($id) . '">'
        . '<button class="btn sec sm" type="submit" aria-label="' . $label[1] . '">' . $label[0] . '</button></form>';
}

function list_page(GitHub $gh): never
{
    try {
        $products = $gh->products();
    } catch (GitHubError $e) {
        page('Каталог', '<h1>Каталог</h1><p class="flash err">' . h($e->getMessage()) . '</p>', 502);
    }
    $rows = '';
    $last = count($products) - 1;
    foreach ($products as $i => $p) {
        $id = h($p['id'] ?? '');
        $url = thumb_url($gh, $p['image'] ?? null);
        $img = $url ? '<img class="thumb" loading="lazy" src="' . h($url) . '" alt="">' : '<div class="thumb ph">нет фото</div>';
        $price = number_format((int) ($p['price'] ?? 0), 0, '', ' ') . ' ₸';
        $up = $i > 0 ? move_form((string) ($p['id'] ?? ''), 'up') : '';
        $down = $i < $last ? move_form((string) ($p['id'] ?? ''), 'down') : '';
        $rows .= '<div class="card"><div class="row">' . $img . '<div class="grow"><div class="name">' . h($p['name'] ?? '') . '</div><div class="price">' . $price . '</div></div></div>'
            . '<div class="acts" style="margin-top:12px"><a class="btn sm" href="/admin/?p=edit&amp;id=' . rawurlencode((string) ($p['id'] ?? '')) . '">Изменить</a>'
            . $up . $down . '</div></div>';
    }
    if ($rows === '') {
        $rows = '<p class="card">Каталог пуст. Добавьте первый товар.</p>';
    }
    page('Каталог', '<h1>Товары (' . count($products) . ')</h1>'
        . '<p class="note">Изменения появятся на сайте через 1–2 минуты, в Telegram-боте — в течение 10 минут. Порядок здесь = порядок в карусели.</p>'
        . '<div class="top-acts"><a class="btn" href="/admin/?p=new">+ Добавить товар</a></div>' . $rows);
}

function form_page(GitHub $gh, ?array $product, array $values = [], array $errors = [], int $status = 200): never
{
    $isNew = $product === null;
    $v = $values + [
        'name' => $product['name'] ?? '',
        'price' => isset($product['price']) ? (string) $product['price'] : '',
        'description' => $product['description'] ?? '',
    ];
    $err = $errors ? '<div class="flash err" role="alert">' . implode('<br>', array_map('h', $errors)) . '</div>' : '';
    $image = $product['image'] ?? null;
    $url = thumb_url($gh, $image);
    $current = $url ? '<img class="preview" src="' . h($url) . '" alt="Текущее фото">'
        . '<label style="font-weight:400;margin:6px 0"><input type="checkbox" name="remove_image" value="1"> Убрать фото</label>' : '<p class="hint">Фото пока нет.</p>';
    $idField = $isNew ? '' : '<input type="hidden" name="id" value="' . h($product['id']) . '">';
    $del = $isNew ? '' : '<form method="post" action="/admin/" style="margin-top:12px" onsubmit="return confirm(\'Удалить товар насовсем? Ссылки на него в боте перестанут работать.\')">'
        . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . h($product['id']) . '">'
        . '<button class="btn del" type="submit">Удалить товар</button></form>';
    page($isNew ? 'Новый товар' : 'Изменить товар', '<h1>' . ($isNew ? 'Новый товар' : 'Изменить товар') . '</h1>' . $err
        . '<form method="post" action="/admin/" enctype="multipart/form-data" class="card">' . csrf_field()
        . '<input type="hidden" name="action" value="save">' . $idField
        . '<label for="name">Название</label><input id="name" type="text" name="name" maxlength="200" required value="' . h($v['name']) . '">'
        . '<label for="price">Цена, ₸</label><input id="price" type="text" name="price" inputmode="numeric" required value="' . h($v['price']) . '">'
        . '<label for="description">Описание</label><textarea id="description" name="description">' . h($v['description']) . '</textarea>'
        . '<p class="hint">Абзацы разделяйте пустой строкой.</p>'
        . '<label for="image">Фото</label>' . $current
        . '<input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp">'
        . '<p class="hint">JPG, PNG или WebP до 8 МБ. Сожмём автоматически (до 1200 px). Лучше на светлом или прозрачном фоне.</p>'
        . '<p style="margin-top:20px" class="acts"><button class="btn" type="submit">Сохранить и опубликовать</button>'
        . '<a class="btn sec" href="/admin/">Отмена</a></p></form>' . $del, $status);
}

// --- запуск ----------------------------------------------------------------
$config = load_config();
if ($config === null) {
    http_response_code(503);
    echo 'Админка не настроена: нет или пуст файл private/admin-config.php.';
    exit;
}
session_boot($config);
$gh = new GitHub(
    $config['github_token'],
    $config['github_repo'],
    (string) ($config['github_branch'] ?? 'main'),
    (string) ($config['github_api'] ?? 'https://api.github.com'),
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // превышен post_max_size: PHP отбрасывает тело целиком
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('err', 'Файл слишком большой для сервера. Уменьшите фото и повторите.');
        redirect();
    }
    if (!csrf_valid()) {
        if (empty($_SESSION['user'])) {
            login_page('Сессия устарела, войдите снова.', 400);
        }
        flash('err', 'Сессия устарела, повторите действие.');
        redirect();
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        if (login_blocked($config)) {
            header('Retry-After: 900');
            login_page('Слишком много попыток входа. Подождите 15 минут.', 429);
        }
        if (!try_login($config, (string) ($_POST['user'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            login_page('Неверный логин или пароль.', 401);
        }
        redirect();
    }
    if (empty($_SESSION['user'])) {
        login_page(null, 401);
    }
    $GLOBALS['topbar'] = topbar();

    try {
        switch ($action) {
            case 'logout':
                $_SESSION = [];
                session_regenerate_id(true);
                redirect();

            case 'save':
                $id = (string) ($_POST['id'] ?? '');
                [$data, $errors] = validate_product_input($_POST);
                $newImage = null;
                $file = $_FILES['image'] ?? null;
                if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                        $errors[] = 'Фото больше лимита сервера. Уменьшите его.';
                    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                        $errors[] = 'Не удалось загрузить фото, попробуйте ещё раз.';
                    } else {
                        try {
                            $newImage = process_image((string) $file['tmp_name'], (int) $file['size']);
                        } catch (RuntimeException $e) {
                            $errors[] = $e->getMessage();
                        }
                    }
                }
                $removeImage = ($_POST['remove_image'] ?? '') === '1';
                $existing = null;
                if ($id !== '') {
                    foreach ($gh->products() as $p) {
                        if (($p['id'] ?? null) === $id) {
                            $existing = $p;
                        }
                    }
                    if ($existing === null) {
                        flash('err', 'Товар не найден — возможно, его уже удалили.');
                        redirect();
                    }
                }
                if ($errors) {
                    form_page($gh, $existing, $data, $errors, 422);
                }
                $savedName = $gh->commit(function (array $products) use ($id, $data, $newImage, $removeImage): array {
                    $files = [];
                    if ($id === '') {
                        $pid = unique_id(slugify($data['name']), $products);
                        $product = ['id' => $pid, 'name' => $data['name'], 'price' => $data['price'], 'currency' => 'KZT', 'image' => null, 'description' => $data['description']];
                        if ($newImage !== null) {
                            $product['image'] = $pid . '-' . substr(sha1($newImage[0]), 0, 8) . '.' . $newImage[1];
                            $files[GitHub::IMAGES_DIR . $product['image']] = $newImage[0];
                        }
                        $products[] = $product;
                        return [$products, $files, $data['name']];
                    }
                    $idx = array_search($id, array_column($products, 'id'), true);
                    if ($idx === false) {
                        throw new GitHubError('Товар не найден — возможно, его уже удалили.');
                    }
                    $old = $products[$idx]['image'] ?? null;
                    $products[$idx]['name'] = $data['name'];
                    $products[$idx]['price'] = $data['price'];
                    $products[$idx]['description'] = $data['description'];
                    if ($newImage !== null) {
                        $products[$idx]['image'] = $id . '-' . substr(sha1($newImage[0]), 0, 8) . '.' . $newImage[1];
                        $files[GitHub::IMAGES_DIR . $products[$idx]['image']] = $newImage[0];
                    } elseif ($removeImage) {
                        $products[$idx]['image'] = null;
                    }
                    $now = $products[$idx]['image'] ?? null;
                    if ($old !== null && $old !== $now && !image_in_use($products, $old, $id)) {
                        $files[GitHub::IMAGES_DIR . $old] = null;
                    }
                    return [$products, $files, $data['name']];
                }, ($id === '' ? 'ADMIN/ добавлен товар «' : 'ADMIN/ изменён товар «') . $data['name'] . '»');
                flash('ok', 'Сохранено: «' . $savedName . '». На сайте появится через 1–2 минуты.');
                redirect();

            case 'delete':
                $id = (string) ($_POST['id'] ?? '');
                $name = $gh->commit(function (array $products) use ($id): array {
                    $idx = array_search($id, array_column($products, 'id'), true);
                    if ($idx === false) {
                        throw new GitHubError('Товар не найден — возможно, его уже удалили.');
                    }
                    $removed = $products[$idx];
                    array_splice($products, (int) $idx, 1);
                    $files = [];
                    $img = $removed['image'] ?? null;
                    if ($img !== null && !image_in_use($products, $img)) {
                        $files[GitHub::IMAGES_DIR . $img] = null;
                    }
                    return [$products, $files, (string) ($removed['name'] ?? '')];
                }, 'ADMIN/ удалён товар ' . $id);
                flash('ok', 'Удалено: «' . $name . '».');
                redirect();

            case 'move':
                $id = (string) ($_POST['id'] ?? '');
                $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
                $gh->commit(function (array $products) use ($id, $dir): array {
                    $idx = array_search($id, array_column($products, 'id'), true);
                    if ($idx !== false && isset($products[$idx + $dir])) {
                        [$products[$idx], $products[$idx + $dir]] = [$products[$idx + $dir], $products[$idx]];
                    }
                    return [$products, [], null];
                }, 'ADMIN/ порядок товаров: ' . $id);
                flash('ok', 'Порядок изменён. На сайте появится через 1–2 минуты.');
                redirect();

            default:
                redirect();
        }
    } catch (GitHubError $e) {
        flash('err', $e->getMessage());
        redirect();
    }
}

if (empty($_SESSION['user'])) {
    login_page();
}
$GLOBALS['topbar'] = topbar();
$p = (string) ($_GET['p'] ?? '');
if ($p === 'new') {
    form_page($gh, null);
}
if ($p === 'edit') {
    $id = (string) ($_GET['id'] ?? '');
    try {
        foreach ($gh->products() as $item) {
            if (($item['id'] ?? null) === $id) {
                form_page($gh, $item);
            }
        }
    } catch (GitHubError $e) {
        flash('err', $e->getMessage());
        redirect();
    }
    flash('err', 'Товар не найден.');
    redirect();
}
list_page($gh);
