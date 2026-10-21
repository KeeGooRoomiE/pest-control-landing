<?php
// Хеш пароля для admin-config.php. Запускать у себя в терминале:  php server/admin-hash.php
// Пароль вводится скрытно и нигде не сохраняется; в конфиг кладём только полученный хеш.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Только из командной строки.\n");
}
fwrite(STDOUT, 'Пароль (не короче 12 символов): ');
system('stty -echo');
$password = trim((string) fgets(STDIN));
system('stty echo');
fwrite(STDOUT, "\n");
if (mb_strlen($password) < 12) {
    fwrite(STDERR, "Слишком короткий пароль.\n");
    exit(1);
}
fwrite(STDOUT, "admin_password_hash:\n" . password_hash($password, PASSWORD_DEFAULT) . "\n");
