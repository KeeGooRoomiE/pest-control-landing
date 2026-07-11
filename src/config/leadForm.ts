// Публичный (client-side) конфиг для отправки заявок через GitHub Actions.
//
// Токен для вызова repository_dispatch читается из import.meta.env.PUBLIC_GITHUB_DISPATCH_TOKEN
// (см. src/components/sections/CTAForm.astro) — Vite/Astro подставляет его в собранный клиентский
// JS из переменной окружения PUBLIC_GITHUB_DISPATCH_TOKEN на этапе `npm run build`
// (см. .github/workflows/deploy.yml, секрет PAGES_DISPATCH_TOKEN). В исходниках токена нет.
//
// Токен осознанно оказывается в открытом виде в публичном JS — заказчик в курсе риска
// (см. README.md → "Отправка заявок с формы"). Поэтому используем ТОЛЬКО fine-grained PAT,
// выданный на один этот репозиторий, с правом "Contents: Read and write" и ничем больше.
// Не используйте classic PAT (repo scope) — он даёт доступ ко всем репозиториям аккаунта.
export const GITHUB_DISPATCH_REPO = "keegooroomie/pest-control-landing";
export const GITHUB_DISPATCH_EVENT_TYPE = "lead-form-submitted";
