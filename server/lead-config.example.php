<?php
// Скопировать на хостинг как  <домашняя папка>/private/lead-config.php  (ВНЕ httpdocs!) и заполнить.
// Этот файл с реальными значениями нельзя коммитить и нельзя класть в httpdocs.
return [
    'bot_token' => '',          // токен Telegram-бота (@qaz_pest_bot), от которого уходят заявки
    'chat_id' => '',            // ID группы заказов, например -1001234567890
    'allowed_hosts' => ['qazpestcontrol.com', 'www.qazpestcontrol.com'],
    // необязательно:
    // 'limit_per_ip_10min' => 5,     // заявок с одного IP за 10 минут
    // 'limit_global_daily' => 200,   // заявок всего в сутки (защита группы от флуда)
    // 'state_dir' => '/путь/к/private/ratelimit',  // где хранить счётчики лимитов (по умолчанию private/ratelimit)
    // 'telegram_api' => 'https://api.telegram.org',  // менять только для тестов
];
