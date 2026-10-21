<?php
// Скопировать на хостинг как  <домашняя папка>/private/admin-config.php  (ВНЕ httpdocs!) и заполнить.
// Этот файл с реальными значениями нельзя коммитить и нельзя класть в httpdocs.
return [
    'admin_user' => 'admin',                 // логин для входа в /admin/
    'admin_password_hash' => '',             // хеш пароля: php server/admin-hash.php  (пароль в файл открытым текстом НЕ пишем)
    'github_token' => '',                    // fine-grained токен: только этот репозиторий, Contents — Read and write
    'github_repo' => 'KeeGooRoomiE/pest-control-landing',
    'github_branch' => 'main',
    // необязательно:
    // 'session_lifetime' => 28800,          // секунд бездействия до выхода (по умолчанию 8 часов)
    // 'state_dir' => '/путь/к/private/admin-state',  // сессии и счётчики попыток входа (по умолчанию private/admin-state)
    // 'github_api' => 'https://api.github.com',      // менять только для тестов
];
