"""Чистые функции бота: разбор payload, валидация, форматирование. Без Telegram и сети."""

from __future__ import annotations

import html
import re
from datetime import datetime, timedelta, timezone
from typing import Any, Mapping, Sequence

PAYLOAD_PREFIX = "order_"
# Казахстан, UTC+5 (фиксированное смещение — не нужен tzdata на сервере).
LOCAL_TZ = timezone(timedelta(hours=5))

_PAYLOAD_ID_RE = re.compile(r"^[A-Za-z0-9_-]{1,64}$")
_CURRENCY_SIGNS = {"KZT": "₸", "RUB": "₽", "USD": "$", "EUR": "€"}


def parse_order_payload(args: Sequence[str] | None) -> str | None:
    """`/start order_<id>` -> `<id>`. Всё остальное (в т.ч. пустой payload) -> None."""
    if not args:
        return None
    payload = args[0]
    if not payload.startswith(PAYLOAD_PREFIX):
        return None
    product_id = payload[len(PAYLOAD_PREFIX):]
    return product_id if _PAYLOAD_ID_RE.match(product_id) else None


def format_price(price: float | int, currency: str = "KZT") -> str:
    sign = _CURRENCY_SIGNS.get(currency, currency)
    return f"{int(price):,}".replace(",", " ") + f" {sign}"


def normalize_phone(raw: str) -> str | None:
    """Приводит номер к виду +7XXXXXXXXXX. Нераспознанное -> None."""
    digits = re.sub(r"\D", "", raw or "")
    if len(digits) == 11 and digits[0] == "8":
        digits = "7" + digits[1:]
    elif len(digits) == 10:
        digits = "7" + digits
    if not 10 <= len(digits) <= 15:
        return None
    return "+" + digits


def clean_text(raw: str | None, limit: int) -> str:
    return " ".join((raw or "").split())[:limit]


def render_order_message(
    *,
    ref: str,
    product: Mapping[str, Any],
    name: str,
    phone: str,
    comment: str,
    user_id: int,
    username: str | None,
    when: datetime | None = None,
) -> str:
    """HTML-сообщение для чата заказов. Любой пользовательский текст экранируется."""
    esc = html.escape
    when = (when or datetime.now(LOCAL_TZ)).astimezone(LOCAL_TZ)
    price = format_price(product.get("price", 0), product.get("currency", "KZT"))

    contact_links = [f'<a href="tg://user?id={user_id}">профиль</a>']
    if username:
        contact_links.insert(0, f"@{esc(username)}")

    lines = [
        f"🛒 <b>Новый заказ #{esc(ref)}</b>",
        "",
        f"<b>Товар:</b> {esc(str(product.get('name', '')))}",
        f"<b>ID:</b> <code>{esc(str(product.get('id', '')))}</code>",
        f"<b>Цена:</b> {esc(price)}",
        "",
        f"<b>Клиент:</b> {esc(name)}",
        f"<b>Телефон:</b> {esc(phone)}",
        f"<b>Telegram:</b> {' · '.join(contact_links)}",
    ]
    if comment:
        lines.append(f"<b>Комментарий:</b> {esc(comment)}")
    lines += ["", f"<i>{when:%d.%m.%Y %H:%M} (Алматы)</i>"]
    return "\n".join(lines)
