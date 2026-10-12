# pest-control-landing

Одностраничный лендинг для компании по борьбе с вредителями. Astro + Tailwind CSS, деплой на GitHub Pages через GitHub Actions.

## Стек

- [Astro](https://astro.build) (статическая сборка), Node.js 22+
- Tailwind CSS v4 (`@tailwindcss/vite`)
- Ванильный TypeScript для интерактива (карусель, форма заявки) — без UI-фреймворка

## Структура

```
src/
  components/
    ui/         — переиспользуемые объекты: Button, Card, Badge, SectionHeading, CheckItem, IconTile, Container
    sections/   — секции страницы: Header, Hero, Categories, DeliveryBar, ProductCarousel, Services, Brands, About, CTAForm, Footer
  config/
    leadForm.ts — публичный конфиг для отправки заявок (см. "Отправка заявок с формы" ниже)
  data/
    site.ts     — контакты компании, часы работы, пункты меню
  layouts/
    Layout.astro
  pages/
    index.astro — сборка всех секций в одну страницу с якорями
```

Сайт одностраничный: пункты меню — якорные ссылки (`#hero`, `#categories`, `#brands`, `#services`, `#about`, `#delivery`, `#contacts`).

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
2. `.github/workflows/lead-email.yml` (имя файла историческое) слушает `repository_dispatch` и отправляет заявку в Telegram-группу через бота (тот же бот и чат, что и уведомления о деплое; чат заявок можно вынести отдельным секретом `LEADS_CHAT_ID`). Данные из `client_payload` считаются недоверенными — в shell они попадают только через переменные окружения.
3. Токен для вызова `dispatches` API подставляется Vite/Astro в клиентский JS на этапе сборки (`npm run build` в `deploy.yml`) из переменной окружения `PUBLIC_GITHUB_DISPATCH_TOKEN`, которая берётся из репозиторного секрета `PAGES_DISPATCH_TOKEN` — в исходниках репозитория токена нет (см. `src/components/sections/CTAForm.astro`, `import.meta.env.PUBLIC_GITHUB_DISPATCH_TOKEN`).

**Важно про безопасность:** после сборки этот токен виден в открытом виде в JS на публичной странице — так и задумано (заказчик в курсе и согласен на этот риск), но именно поэтому:
- используем **только** fine-grained PAT, выпущенный на один этот репозиторий, с правом **Contents: Read and write** и без доступа к другим репозиториям/организациям;
- не используем classic PAT с `repo` scope — он даёт доступ ко всем репозиториям аккаунта.

### Нужные секреты репозитория (Settings → Secrets and variables → Actions)

| Секрет | Назначение |
| --- | --- |
| `PAGES_DISPATCH_TOKEN` | fine-grained PAT только на этот репозиторий, `Contents: Read and write` — подставляется в публичный JS при деплое |
| `PIPLINE_BOT_SECRET` | токен Telegram-бота, от имени которого уходят заявки и уведомления о деплое |
| `CHAT_ID` | ID Telegram-группы (заявки и уведомления о деплое) |
| `LEADS_CHAT_ID` | *необязательно* — отдельная группа только для заявок с формы; если не задан, используется `CHAT_ID` |

## Ассеты

Реальные изображения (логотип, фото товаров, иконки, фото специалистов) клиент передаёт по мере готовности. Куда класть каждый файл — см. внутренний `.claude/ASSETS.md` (не в репозитории). До получения реальных материалов используются заглушки, помеченные `TODO` в коде.
