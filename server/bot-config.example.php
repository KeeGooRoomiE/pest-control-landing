<?php
// Скопировать на хостинг как  <домашняя папка>/private/bot-config.php  (ВНЕ httpdocs!) и заполнить.
// Этот файл с реальными значениями нельзя коммитить и нельзя класть в httpdocs.
return [
    'bot_token' => '',          // токен @qaz_pest_bot от @BotFather
    'orders_chat_id' => '',     // ID группы заказов, например -1001234567890
    'webhook_secret' => '',     // случайная строка: php -r 'echo bin2hex(random_bytes(24));'  (A-Z a-z 0-9 _ -)
    'contact_phone' => '+7 705-763-49-69',   // показывается клиенту, если заявку не удалось передать
    // необязательно:
    // 'site_url' => 'https://qazpestcontrol.com/',
    // 'webhook_url' => 'https://qazpestcontrol.com/bot/webhook.php',   // только для server/bot-webhook.php
    // 'products_file' => '/путь/к/httpdocs/api/products.json',          // по умолчанию — рядом с сайтом
    // 'state_dir' => '/путь/к/private/bot-state',                       // по умолчанию private/bot-state
    // 'telegram_api' => 'https://api.telegram.org',                      // менять только для тестов
];
