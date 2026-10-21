<?php
declare(strict_types=1);
defined('ADMIN_APP') || exit;

const IMAGE_MAX_BYTES = 8 * 1024 * 1024;
const IMAGE_MAX_PIXELS = 40_000_000;
const IMAGE_MAX_SIDE = 1200;

/**
 * Загруженное фото → webp (или png/jpeg, если GD без webp), длинная сторона ≤ 1200 px.
 * @return array{0:string,1:string} [байты, расширение]
 */
function process_image(string $tmpFile, int $size): array
{
    if ($size <= 0 || $size > IMAGE_MAX_BYTES) {
        throw new RuntimeException('Фото должно быть не больше 8 МБ.');
    }
    $info = @getimagesize($tmpFile);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('Подходят только фото JPG, PNG или WebP.');
    }
    if ($info[0] * $info[1] > IMAGE_MAX_PIXELS) {
        throw new RuntimeException('Слишком большое разрешение фото.');
    }
    $data = (string) file_get_contents($tmpFile);
    $src = @imagecreatefromstring($data);
    if ($src === false) {
        throw new RuntimeException('Не удалось прочитать фото.');
    }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmpFile);
        $rotate = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($rotate !== 0 && ($rotated = imagerotate($src, $rotate, 0)) !== false) {
            $src = $rotated;
        }
    }
    imagepalettetotruecolor($src);
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1.0, IMAGE_MAX_SIDE / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    ob_start();
    if (function_exists('imagewebp')) {
        imagewebp($dst, null, 85);
        $ext = 'webp';
    } else {
        imagepng($dst, null, 8);
        $ext = 'png';
    }
    $out = (string) ob_get_clean();
    if ($out === '') {
        throw new RuntimeException('Не удалось обработать фото.');
    }
    return [$out, $ext];
}
