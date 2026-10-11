# Бот заказов QAZ Pest Control

Клиент жмёт «Заказать» на сайте → открывается бот по ссылке вида
`https://t.me/<имя_бота>?start=order_<id товара>` → бот спрашивает имя, телефон и
комментарий → заявка с контактами клиента уходит в группу заказов.

> Telegram не поддерживает параметры вида `/start?order=id`: после `start=` идёт одна
> строка (`A–Z a–z 0–9 _ -`, до 64 символов). Поэтому формат — `start=order_<id>`.
> id товаров из `src/data/products.json` (kebab-case) под это ограничение подходят.

Товар бот берёт из того же `products.json`, что и сайт (по умолчанию — raw-ссылка на
`main` репозитория, кеш 5 минут), так что новые товары подхватываются без правок бота.

## Настройка

1. В [@BotFather](https://t.me/BotFather): `/newbot` → получить токен.
2. Создать группу заказов, добавить туда бота и менеджеров.
3. Узнать ID группы (число со знаком минус, у супергрупп `-100…`): например, временно
   добавить в группу `@RawDataBot` или посмотреть `chat.id` в
   `https://api.telegram.org/bot<токен>/getUpdates` после сообщения в группе.
4. `cp bot/.env.example bot/.env` и заполнить `BOT_TOKEN`, `ORDERS_CHAT_ID`.

## Запуск на сервере

Нужен Python 3.10+.

```bash
cd bot
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
.venv/bin/python main.py
```

Постоянная работа — systemd (`/etc/systemd/system/qaz-bot.service`):

```ini
[Unit]
Description=QAZ Pest Control order bot
After=network-online.target

[Service]
WorkingDirectory=/opt/pest-control-landing/bot
EnvironmentFile=/opt/pest-control-landing/bot/.env
ExecStart=/opt/pest-control-landing/bot/.venv/bin/python main.py
Restart=always
RestartSec=5
User=qazbot

[Install]
WantedBy=multi-user.target
```

`systemctl daemon-reload && systemctl enable --now qaz-bot`, логи — `journalctl -u qaz-bot -f`.
Бот работает через long polling: входящие порты и HTTPS не нужны, только исходящий
доступ к `api.telegram.org` и `raw.githubusercontent.com`.

## Что приходит в группу

Номер заявки, товар (название, id, цена), имя, телефон, `@username` и ссылка на профиль
клиента, комментарий, время (Алматы). Номер заявки (`#A1B2C3`) бот называет и клиенту.
Если у клиента нет `@username` или скрыт профиль — связывайтесь по телефону.

## Тесты

Без сети и без Telegram, из корня репозитория:

```bash
bot/.venv/bin/python -m unittest discover -s bot -p "test_*.py"
```
