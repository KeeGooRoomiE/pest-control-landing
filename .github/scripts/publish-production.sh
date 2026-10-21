#!/usr/bin/env bash
# Публикует содержимое каталога сборки в ветку production обычным коммитом (без force-push).
# Plesk (Git → Pull updates) забирает эту ветку прямо в httpdocs, поэтому в ней лежит ТОЛЬКО
# готовый сайт — без исходников, bot/, .github/ и служебных файлов.
#
# Использование: publish-production.sh <каталог-сборки> <url-репозитория> [ветка] [сообщение]
set -euo pipefail

SRC="${1:?укажите каталог сборки}"
REMOTE="${2:?укажите URL репозитория}"
BRANCH="${3:-production}"
MESSAGE="${4:-deploy}"

[ -f "$SRC/index.html" ] || { echo "::error::В $SRC нет index.html — сборка не завершилась" >&2; exit 1; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

if git ls-remote --exit-code --heads "$REMOTE" "$BRANCH" >/dev/null 2>&1; then
  git clone --quiet --depth 1 --branch "$BRANCH" "$REMOTE" "$WORK"
else
  git init --quiet "$WORK"
  git -C "$WORK" checkout --quiet -b "$BRANCH"
  git -C "$WORK" remote add origin "$REMOTE"
fi

# Заменяем содержимое целиком: удалённые из сборки файлы не должны оставаться на сервере.
find "$WORK" -mindepth 1 -maxdepth 1 -not -name .git -exec rm -rf {} +
cp -a "$SRC"/. "$WORK"/

git -C "$WORK" add -A
if git -C "$WORK" diff --cached --quiet; then
  echo "Изменений в сборке нет — публиковать нечего"
  exit 0
fi

git -C "$WORK" \
  -c user.name="github-actions[bot]" \
  -c user.email="41898282+github-actions[bot]@users.noreply.github.com" \
  commit --quiet -m "$MESSAGE"
git -C "$WORK" push --quiet origin "$BRANCH"
echo "Опубликовано в ветку $BRANCH"
