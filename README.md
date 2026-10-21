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
bot/            — Telegram-бот приёма заказов на Python (см. bot/README.md)
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

Ветка `production` содержит только собранные файлы (без исходников, `bot/`, `.github/`), её формирует `.github/scripts/publish-production.sh` обычными коммитами (без force-push).

**Разовая настройка Plesk** (Websites & Domains → qazpestcontrol.com):

1. **Git**: репозиторий `https://github.com/KeeGooRoomiE/pest-control-landing.git`, ветка **`production`** (не `main`), каталог развёртывания — корень (`httpdocs`). Режим: автоматический (через webhook, см. ниже) или вручную кнопкой *Pull updates*.
2. **Очистить `httpdocs`** от того, что скопировано из `main` (`src/`, `bot/`, `.github/`, `package.json`, `CLAUDE.md` и т.д.), и удалить `bitrixsetup.php` и `index.html`-заглушку хостинга — иначе исходники и установщик Bitrix останутся доступны из интернета. `cgi-bin` не трогать.
3. **SSL/TLS**: выпустить сертификат Let's Encrypt для домена и `www`; затем в *Hosting Settings* включить «Permanent SEO-safe 301 redirect from HTTP to HTTPS» и выбрать предпочитаемый домен (с `www` или без). Редиректы намеренно не вынесены в `.htaccess`, чтобы не сломать сайт до выпуска сертификата.
4. Автоматическое обновление (необязательно): в Plesk Git скопировать *Webhook URL* и добавить его в GitHub → Settings → Webhooks (событие *push*, ветка production). Если панель Plesk снаружи недоступна — использовать *Pull updates* вручную или расписание (Scheduled Tasks).

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

Форма в блоке "Контакты" не имеет бэкенда (GitHub Pages — статический хостинг). Отправка реализована так:

1. Форма на клиенте шлёт `POST` на `https://api.github.com/repos/<repo>/dispatches` (`repository_dispatch`) — см. `src/components/sections/CTAForm.astro`.
2. `.github/workflows/lead-telegram.yml` слушает `repository_dispatch` и отправляет заявку в Telegram-группу через бота (бот заказов `@qaz_pest_bot`, токен — секрет `ORDERS_BOT_TOKEN`; пока его нет, используется бот деплоя; чат заявок можно вынести отдельным секретом `LEADS_CHAT_ID`). Данные из `client_payload` считаются недоверенными — в shell они попадают только через переменные окружения.
3. Токен для вызова `dispatches` API подставляется Vite/Astro в клиентский JS на этапе сборки (`npm run build` в `deploy.yml`) из переменной окружения `PUBLIC_GITHUB_DISPATCH_TOKEN`, которая берётся из репозиторного секрета `PAGES_DISPATCH_TOKEN` — в исходниках репозитория токена нет (см. `src/components/sections/CTAForm.astro`, `import.meta.env.PUBLIC_GITHUB_DISPATCH_TOKEN`).

**Важно про безопасность:** после сборки этот токен виден в открытом виде в JS на публичной странице — так и задумано (заказчик в курсе и согласен на этот риск), но именно поэтому:
- используем **только** fine-grained PAT, выпущенный на один этот репозиторий, с правом **Contents: Read and write** и без доступа к другим репозиториям/организациям;
- не используем classic PAT с `repo` scope — он даёт доступ ко всем репозиториям аккаунта.

### Нужные секреты репозитория (Settings → Secrets and variables → Actions)

| Секрет | Назначение |
| --- | --- |
| `PAGES_DISPATCH_TOKEN` | fine-grained PAT только на этот репозиторий, `Contents: Read and write` — подставляется в публичный JS при деплое |
| `ORDERS_BOT_TOKEN` | токен `@qaz_pest_bot`, от его имени уходят заявки с формы (бот должен быть участником группы) |
| `PIPLINE_BOT_SECRET` | токен Telegram-бота уведомлений о деплое (и запасной вариант для заявок) |
| `CHAT_ID` | ID Telegram-группы (заявки и уведомления о деплое) |
| `LEADS_CHAT_ID` | *необязательно* — отдельная группа только для заявок с формы; если не задан, используется `CHAT_ID` |

## Ассеты и контент

- Товары — `src/data/products.json`, фото — `src/assets/groceries/` (имя файла указывается в поле `image`). Новый товар: добавить запись (id в формате kebab-case: он же попадает в ссылку на бота `start=order_<id>`, допустимы `A–Z a–z 0–9 _ -`, до 58 символов) и фото.
- Бот заказов (`bot/`) читает тот же `products.json` с GitHub, поэтому новые товары подхватываются без правок бота.
- Логотипы брендов — `src/assets/brands/` (список — в `src/components/sections/Brands.astro`).
