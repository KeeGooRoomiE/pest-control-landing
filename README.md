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
.github/workflows/ — deploy.yml (сборка и деплой), lead-email.yml (форма контактов → Telegram; имя файла историческое)
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

Пуш в `main` собирает сайт и публикует его на GitHub Pages через `.github/workflows/deploy.yml`. Сейчас сайт живёт на `https://<org>.github.io/pest-control-landing` (`base` в `astro.config.mjs`). Когда клиент передаст доступ к DNS домена — поменять `site`/`base` в `astro.config.mjs`, добавить `public/CNAME`, убрать `base`.

## Отправка заявок с формы

Форма в блоке "Контакты" не имеет бэкенда (GitHub Pages — статический хостинг). Отправка реализована так:

1. Форма на клиенте шлёт `POST` на `https://api.github.com/repos/<repo>/dispatches` (`repository_dispatch`) — см. `src/components/sections/CTAForm.astro`.
2. `.github/workflows/lead-email.yml` (имя файла историческое) слушает `repository_dispatch` и отправляет заявку в Telegram-группу через бота (бот заказов `@qaz_pest_bot`, токен — секрет `ORDERS_BOT_TOKEN`; пока его нет, используется бот деплоя; чат заявок можно вынести отдельным секретом `LEADS_CHAT_ID`). Данные из `client_payload` считаются недоверенными — в shell они попадают только через переменные окружения.
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
