# pest-control-landing

Одностраничный лендинг для компании по борьбе с вредителями. Astro + Tailwind CSS, деплой на GitHub Pages через GitHub Actions.

## Стек

- [Astro](https://astro.build) (статическая сборка), Node.js 22.12+ (в CI используется 24)
- Tailwind CSS v4 (`@tailwindcss/vite`)
- Ванильный TypeScript для интерактива (карусель, форма заявки) — без UI-фреймворка

## Структура

```
src/
  components/
    ui/         — переиспользуемые объекты: Button, SectionHeading, Container
    sections/   — секции страницы: Header, Hero (внутри — DeliveryBar), Services, Categories, ProductCarousel, Brands, About, CTAForm, Footer
  config/
    leadForm.ts — публичный конфиг для отправки заявок (см. "Отправка заявок с формы" ниже)
  data/
    site.ts     — контакты компании, часы работы, пункты меню
    products.json — товары (id, name, price, currency, image, description): единственный источник для карусели, модалок и бота
  assets/       — логотип, фото (hero, услуги, товары в groceries/), логотипы брендов
  layouts/
    Layout.astro
  pages/
    index.astro — сборка всех секций в одну страницу с якорями
public/         — фавикон и иконки (favicon.ico, icon-32/192.png, apple-touch-icon.png)
public/bot/     — Telegram-бот приёма заказов на PHP (webhook на хостинге, см. «Бот заказов»)
archive/        — архив: прежний Python-бот (archive/bot-python), в CI и сборке не участвует
tests/bot/      — тесты бота: php tests/bot/run.php
.github/workflows/ — deploy.yml (сборка и деплой), lead-telegram.yml (форма контактов → Telegram), bot-tests.yml (тесты бота)
```

Сайт одностраничный: пункты меню — якорные ссылки (`#hero`, `#services`, `#categories`, `#products`, `#brands`, `#about`, `#delivery`, `#contacts`).

## Разработка

```sh
npm install
npm run dev       # localhost:4321
npm run build     # сборка в ./dist
npm run preview   # предпросмотр собранной версии
```

Требуется Node.js **22.12+** (задано в `package.json` → `engines`).

## Деплой

Пуш в `main` запускает `.github/workflows/deploy.yml`: сборка и публикация на GitHub Pages (запасной адрес `https://keegooroomie.github.io/pest-control-landing`) и сборка под боевой домен с публикацией в ветку `production` (см. следующий раздел). Адрес сайта и `base` задаются переменными окружения `SITE_URL`/`SITE_BASE` (`astro.config.mjs`), править код при смене домена не нужно.

## Хостинг (Plesk), домен и SEO

Боевой сайт — `https://qazpestcontrol.com`, хостинг Plesk. Публикация:

```
git push в main → GitHub Actions:
   ├─ сборка для GitHub Pages (запасной адрес на github.io)
   └─ сборка под домен (SITE_BASE=/) → ветка `production` (только готовый сайт)
Plesk → Git → Pull updates (ветка production) → httpdocs
```

Ветка `production` содержит только собранные файлы (без исходников, `archive/`, `.github/`), её формирует `.github/scripts/publish-production.sh` обычными коммитами (без force-push).

**Разовая настройка Plesk** (Websites & Domains → qazpestcontrol.com):

1. **Git**: репозиторий `https://github.com/KeeGooRoomiE/pest-control-landing.git`, ветка **`production`** (не `main`), каталог развёртывания — корень (`httpdocs`). Режим: автоматический (через webhook, см. ниже) или вручную кнопкой *Pull updates*.
2. **Очистить `httpdocs`** от того, что скопировано из `main` (`src/`, `bot/`, `archive/`, `.github/`, `package.json`, `CLAUDE.md` и т.д.), и удалить `bitrixsetup.php` и `index.html`-заглушку хостинга — иначе исходники и установщик Bitrix останутся доступны из интернета. `cgi-bin` не трогать.
3. **SSL/TLS**: выпустить сертификат Let's Encrypt для домена и `www`; затем в *Hosting Settings* включить «Permanent SEO-safe 301 redirect from HTTP to HTTPS» и выбрать предпочитаемый домен (с `www` или без). Редиректы намеренно не вынесены в `.htaccess`, чтобы не сломать сайт до выпуска сертификата.
4. **Заявки с формы** (`public/api/lead.php`, нужен PHP на домене): создать файл `private/lead-config.php` **в домашней папке хостинга, рядом с `httpdocs`, но не внутри неё** (Файловый менеджер Plesk), по образцу `server/lead-config.example.php`: токен бота `@qaz_pest_bot`, ID группы заказов, разрешённые хосты. Права на файл — только владелец. Без этого файла форма покажет посетителю «Не удалось отправить заявку, позвоните нам», а в логе PHP будет `lead.php: конфиг не найден`.
5. Автоматическое обновление (необязательно): в Plesk Git скопировать *Webhook URL* и добавить его в GitHub → Settings → Webhooks (событие *push*, ветка production). Если панель Plesk снаружи недоступна — использовать *Pull updates* вручную или расписание (Scheduled Tasks).

`public/.htaccess` (404-страница, кеширование, сжатие, заголовки безопасности) работает в режиме Apache; в режиме «только nginx» его игнорируют — тогда эти директивы задаются в настройках nginx в Plesk.

**GitHub Pages** (`keegooroomie.github.io/pest-control-landing`) остаётся запасным адресом. Когда домен заработает, лучше отключить Pages (Settings → Pages) и убрать задачи `build`/`deploy` из `deploy.yml`, чтобы не было двух копий сайта для поисковиков.

Необязательные переменные (GitHub → Settings → Secrets and variables → Actions → **Variables**; пока не заданы — соответствующего кода на странице нет):

| Переменная | Что делает |
| --- | --- |
| `PRODUCTION_SITE_URL` | адрес боевого сайта для canonical/sitemap/OG (по умолчанию `https://qazpestcontrol.com`) |
| `PUBLIC_GOOGLE_SITE_VERIFICATION` | мета-тег подтверждения Google Search Console |
| `PUBLIC_YANDEX_VERIFICATION` | мета-тег подтверждения Яндекс Вебмастера |
| `PUBLIC_YM_ID` | номер счётчика Яндекс Метрики (цели: `phone_click`, `email_click`, `order_click`, `lead_submitted`) |
| `PUBLIC_GA_ID` | идентификатор GA4 (`G-XXXXXXX`), те же цели |

Перед включением счётчиков решить вопрос с уведомлением о cookie. После запуска домена — отправить `sitemap-index.xml` в Search Console и Яндекс Вебмастер.

## Отправка заявок с формы

У сайта два способа отправки; какой используется, определяет переменная сборки:

- **Боевой сайт (qazpestcontrol.com)** — `PUBLIC_LEAD_ENDPOINT=/api/lead.php`. Форма шлёт JSON на `public/api/lead.php`, тот проверяет запрос (метод, Origin, размер, honeypot, лимит частоты по IP и общий дневной лимит) и отправляет сообщение в Telegram-группу от имени бота. Токен бота и ID чата лежат только в `private/lead-config.php` на сервере. В публичном JS и в репозитории токенов нет (сборка прерывается, если токен найден в `dist`).
- **Запасной адрес на GitHub Pages** — форма шлёт `repository_dispatch` в GitHub (`.github/workflows/lead-telegram.yml` → Telegram). Для этого в сборку подставляется публичный fine-grained PAT (`PAGES_DISPATCH_TOKEN`, только этот репозиторий, `Contents: Read and write`). Именно из-за него сборку для Pages нельзя коммитить в репозиторий. Когда Pages будет отключён, токен можно отозвать.

Локальные проверки без Telegram: `php -S 127.0.0.1:8090 -t public` с `LEAD_CONFIG=путь/к/конфигу.php`, где `telegram_api` указывает на локальный подставной сервер.

### Секреты и переменные репозитория

| Секрет | Назначение |
| --- | --- |
| `PAGES_DISPATCH_TOKEN` | fine-grained PAT для запасной отправки через GitHub (только Pages-сборка) |
| `ORDERS_BOT_TOKEN` | токен `@qaz_pest_bot` для `lead-telegram.yml` |
| `PIPLINE_BOT_SECRET` | токен бота уведомлений о деплое (и запасной для заявок) |
| `CHAT_ID` | ID Telegram-группы (заявки и уведомления о деплое) |
| `LEADS_CHAT_ID` | *необязательно* — отдельная группа только для заявок |

## Админка каталога (`/admin/`)

PHP-админка на том же хостинге: один пользователь, вход по логину и паролю, правка товаров (название, цена, описание, фото, порядок, добавление и удаление). Код — `public/admin/`.

```
/admin/ → «Сохранить» → один коммит в main (products.json + фото, GitHub API)
        → Actions собирает сайт → ветка production → вебхук → Plesk (≈1–2 мин)
        → бот читает /api/products.json с того же деплоя — обновляется вместе с сайтом
```

- **Источник правды — git.** Данных на сервере нет, история и откат — обычный `git revert`. Бот `@qaz_pest_bot` синхронизируется сам: каталог берётся из `/api/products.json`, который собирается вместе с сайтом.
- **Фото** сжимаются на сервере (GD) до 1200 px по длинной стороне и кладутся в `src/assets/groceries/` как `<id>-<хеш>.webp`. При замене и удалении товара старый файл удаляется, если на него не ссылается другой товар.
- **id товара** создаётся из названия один раз и не меняется (на него ссылается бот: `start=order_<id>`). После удаления товара старые ссылки на него в боте перестают работать.
- **Защита:** пароль хранится только хешем, CSRF-токен на каждом POST, cookie `HttpOnly`/`SameSite=Strict`, блокировка входа после 5 неудач за 15 минут, `noindex`, `Disallow: /admin/` в robots.txt, служебные файлы закрыты `.htaccess`. Дополнительно стоит закрыть `/admin` паролем Plesk («Защищённые паролем каталоги»).

**Настройка (один раз):**
1. Создать fine-grained токен GitHub: только репозиторий `pest-control-landing`, права `Contents: Read and write` (остальное не нужно), срок — максимум, напоминание о перевыпуске.
2. У себя в терминале: `php server/admin-hash.php` — получить хеш пароля (пароль вводится скрытно).
3. Скопировать `server/admin-config.example.php` на хостинг как `/var/www/vhosts/qazpestcontrol.com/private/admin-config.php`, вписать логин, хеш и токен. Файл — вне `httpdocs`, права 600/640, в git не коммитить.
4. После деплоя открыть `https://qazpestcontrol.com/admin/`.

Локальная проверка без GitHub: `php -S 127.0.0.1:8193 -t public` с `ADMIN_CONFIG=путь/к/конфигу.php`, где `github_api` указывает на локальный подставной сервер.

## Бот заказов (`@qaz_pest_bot`)

PHP-webhook на хостинге сайта (`public/bot/`): Telegram сам присылает каждое сообщение на `https://qazpestcontrol.com/bot/webhook.php`, скрипт отвечает и завершается. Постоянный процесс и VPS не нужны.

```
сайт: «Заказать» → t.me/qaz_pest_bot?start=order_<id>
бот: имя → телефон (или кнопка «Отправить номер») → комментарий → сообщение в группу заказов
каталог: /api/products.json (собирается вместе с сайтом из src/data/products.json)
```

- **Секреты** — только в `private/bot-config.php` на сервере (шаблон `server/bot-config.example.php`). Файл без `<?php` в начале не исполняется (чтобы токен не отдался как текст).
- **Защита:** запросы принимаются только с секретным заголовком `X-Telegram-Bot-Api-Secret-Token`, дубли обновлений игнорируются (один заказ не оформится дважды), не больше 5 заказов в час от одного человека, состояние диалога лежит в `private/bot-state/` вне webroot (сессия живёт 24 часа).
- **Тесты:** `php tests/bot/run.php` (без сети и токенов; в CI — `bot-tests.yml`).
- **Старый Python-бот** (long polling на VPS) сохранён в `archive/bot-python/` как история и запасной вариант.

**Переключение с VPS на хостинг (один раз):**
1. Создать `private/bot-config.php` на хостинге из шаблона: токен и `orders_chat_id` взять из `.env` бота на VPS (или из `lead-config.php`, если бот тот же); `webhook_secret` — `php -r 'echo bin2hex(random_bytes(24));'`.
2. Дождаться деплоя (в `https://qazpestcontrol.com/bot/webhook.php` GET отвечает 405, `/api/products.json` отдаёт каталог).
3. **Остановить бота на VPS:** `sudo systemctl stop qaz-bot && sudo systemctl disable qaz-bot` (одновременно polling и webhook работать не могут).
4. Зарегистрировать webhook у себя в терминале: `php server/bot-webhook.php путь/к/bot-config.php set`, затем `… info` (ошибок быть не должно).
5. Проверить: «Заказать» на сайте → пройти диалог → заявка в группе.

Вернуться на VPS: `php server/bot-webhook.php … delete`, затем запустить старого бота (`archive/bot-python/README.md`).

## Ассеты и контент

- Товары — `src/data/products.json`, фото — `src/assets/groceries/` (имя файла указывается в поле `image`). Новый товар: добавить запись (id в формате kebab-case: он же попадает в ссылку на бота `start=order_<id>`, допустимы `A–Z a–z 0–9 _ -`, до 58 символов) и фото.
- Бот заказов (`public/bot/`) читает каталог из `/api/products.json`, который собирается из того же `products.json`, поэтому новые товары подхватываются вместе с деплоем сайта, без правок бота.
- Логотипы брендов — `src/assets/brands/` (список — в `src/components/sections/Brands.astro`).
