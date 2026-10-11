"""Бот приёма заказов QAZ Pest Control.

Сайт ведёт на `https://t.me/<bot>?start=order_<id товара>`. Бот находит товар в
products.json, спрашивает у клиента имя, телефон и комментарий, после чего
отправляет заявку в группу заказов вместе с контактами клиента.

Запуск: см. bot/README.md. Все секреты — только через переменные окружения.
"""

from __future__ import annotations

import logging
import os
import secrets
from dataclasses import dataclass
from html import escape as _esc

from dotenv import load_dotenv
from telegram import (
    KeyboardButton,
    ReplyKeyboardMarkup,
    ReplyKeyboardRemove,
    Update,
)
from telegram.constants import ChatType, ParseMode
from telegram.error import TelegramError
from telegram.ext import (
    Application,
    CommandHandler,
    ContextTypes,
    ConversationHandler,
    MessageHandler,
    filters,
)

from catalog import Catalog, CatalogError
from texts import (
    clean_text,
    format_price,
    normalize_phone,
    parse_order_payload,
    render_order_message,
)

log = logging.getLogger("qaz-bot")

ASK_NAME, ASK_PHONE, ASK_COMMENT = range(3)
SKIP = "Пропустить"

DEFAULT_PRODUCTS_SOURCE = (
    "https://raw.githubusercontent.com/KeeGooRoomiE/pest-control-landing/main/src/data/products.json"
)
DEFAULT_SITE_URL = "https://keegooroomie.github.io/pest-control-landing/"


@dataclass(frozen=True)
class Config:
    bot_token: str
    orders_chat_id: int
    products_source: str
    site_url: str
    contact_phone: str

    @classmethod
    def from_env(cls) -> "Config":
        token = os.environ.get("BOT_TOKEN", "").strip()
        chat_id = os.environ.get("ORDERS_CHAT_ID", "").strip()
        if not token or not chat_id:
            raise SystemExit("Задайте BOT_TOKEN и ORDERS_CHAT_ID (см. bot/.env.example)")
        try:
            orders_chat_id = int(chat_id)
        except ValueError:
            raise SystemExit("ORDERS_CHAT_ID должен быть числом, например -1001234567890")
        return cls(
            bot_token=token,
            orders_chat_id=orders_chat_id,
            products_source=os.environ.get("PRODUCTS_SOURCE", DEFAULT_PRODUCTS_SOURCE),
            site_url=os.environ.get("SITE_URL", DEFAULT_SITE_URL),
            contact_phone=os.environ.get("CONTACT_PHONE", "").strip(),
        )


def _deps(context: ContextTypes.DEFAULT_TYPE) -> tuple[Config, Catalog]:
    return context.bot_data["config"], context.bot_data["catalog"]


def _fallback_hint(config: Config) -> str:
    return f" Или позвоните: {config.contact_phone}." if config.contact_phone else ""


async def start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    message, chat, user = update.effective_message, update.effective_chat, update.effective_user
    if not message or not chat or not user or chat.type != ChatType.PRIVATE:
        return ConversationHandler.END

    config, catalog = _deps(context)
    context.user_data.clear()
    product_id = parse_order_payload(context.args)

    if product_id is None:
        await message.reply_text(
            "Здравствуйте! Это бот QAZ Pest Control.\n"
            f"Выберите товар на сайте {config.site_url} и нажмите «Заказать» — "
            "я оформлю заявку.",
            reply_markup=ReplyKeyboardRemove(),
        )
        return ConversationHandler.END

    try:
        product = await catalog.get(product_id)
    except CatalogError:
        log.exception("Каталог недоступен")
        await message.reply_text(
            "Каталог временно недоступен, попробуйте чуть позже." + _fallback_hint(config)
        )
        return ConversationHandler.END

    if product is None:
        await message.reply_text(
            "Не нашёл такой товар — возможно, он снят с продажи. "
            f"Выберите другой на сайте: {config.site_url}"
        )
        return ConversationHandler.END

    context.user_data["product"] = product
    price = format_price(product.get("price", 0), product.get("currency", "KZT"))
    keyboard = (
        ReplyKeyboardMarkup([[KeyboardButton(user.first_name)]], resize_keyboard=True, one_time_keyboard=True)
        if user.first_name
        else ReplyKeyboardRemove()
    )
    await message.reply_text(
        f"Оформляем заказ:\n<b>{_esc(str(product.get('name', '')))}</b> — {_esc(price)}\n\n"
        "Как к вам обращаться?",
        parse_mode=ParseMode.HTML,
        reply_markup=keyboard,
    )
    return ASK_NAME


async def got_name(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    name = clean_text(update.effective_message.text, 100)
    if not name:
        await update.effective_message.reply_text("Напишите, пожалуйста, имя текстом.")
        return ASK_NAME
    context.user_data["name"] = name
    await update.effective_message.reply_text(
        "Оставьте номер телефона для связи — нажмите кнопку или напишите номер.",
        reply_markup=ReplyKeyboardMarkup(
            [[KeyboardButton("📱 Отправить мой номер", request_contact=True)]],
            resize_keyboard=True,
            one_time_keyboard=True,
        ),
    )
    return ASK_PHONE


async def got_phone(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    message = update.effective_message
    raw = message.contact.phone_number if message.contact else (message.text or "")
    phone = normalize_phone(raw)
    if phone is None:
        await message.reply_text("Не похоже на номер телефона. Пример: +7 705 123 45 67")
        return ASK_PHONE
    context.user_data["phone"] = phone
    await message.reply_text(
        "Добавьте комментарий (количество, адрес доставки, вопросы) или нажмите «Пропустить».",
        reply_markup=ReplyKeyboardMarkup([[SKIP]], resize_keyboard=True, one_time_keyboard=True),
    )
    return ASK_COMMENT


async def got_comment(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    message, user = update.effective_message, update.effective_user
    config, _ = _deps(context)
    text = clean_text(message.text, 500)
    comment = "" if text.lower() == SKIP.lower() else text

    data = context.user_data
    product = data.get("product")
    if not product or "name" not in data or "phone" not in data:
        await message.reply_text(
            "Что-то пошло не так, начнём заново: нажмите «Заказать» на сайте.",
            reply_markup=ReplyKeyboardRemove(),
        )
        return ConversationHandler.END

    ref = secrets.token_hex(3).upper()
    order_text = render_order_message(
        ref=ref,
        product=product,
        name=data["name"],
        phone=data["phone"],
        comment=comment,
        user_id=user.id,
        username=user.username,
    )
    try:
        await context.bot.send_message(
            config.orders_chat_id,
            order_text,
            parse_mode=ParseMode.HTML,
            disable_web_page_preview=True,
        )
    except TelegramError:
        log.exception("Не удалось отправить заказ #%s в чат заказов", ref)
        await message.reply_text(
            "Не удалось передать заявку менеджеру." + _fallback_hint(config) + " Попробуйте ещё раз позже.",
            reply_markup=ReplyKeyboardRemove(),
        )
        return ConversationHandler.END

    log.info("Заказ #%s передан в чат заказов", ref)
    await message.reply_text(
        f"Спасибо! Заявка #{ref} принята. Менеджер свяжется с вами в ближайшее время.",
        reply_markup=ReplyKeyboardRemove(),
    )
    data.clear()
    return ConversationHandler.END


async def cancel(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data.clear()
    await update.effective_message.reply_text("Заказ отменён.", reply_markup=ReplyKeyboardRemove())
    return ConversationHandler.END


async def on_error(update: object, context: ContextTypes.DEFAULT_TYPE) -> None:
    log.error("Необработанная ошибка", exc_info=context.error)


def build_application(config: Config, catalog: Catalog | None = None) -> Application:
    async def post_init(app: Application) -> None:
        me = await app.bot.get_me()
        log.info("Бот @%s запущен", me.username)
        try:
            chat = await app.bot.get_chat(config.orders_chat_id)
            log.info("Чат заказов: %s", chat.title or chat.id)
        except TelegramError:
            log.warning(
                "Бот не видит чат заказов %s — добавьте его в группу и проверьте ORDERS_CHAT_ID",
                config.orders_chat_id,
            )

    app = Application.builder().token(config.bot_token).post_init(post_init).build()
    app.bot_data["config"] = config
    app.bot_data["catalog"] = catalog or Catalog(config.products_source)

    text = filters.TEXT & ~filters.COMMAND
    app.add_handler(
        ConversationHandler(
            entry_points=[CommandHandler("start", start)],
            states={
                ASK_NAME: [MessageHandler(text, got_name)],
                ASK_PHONE: [MessageHandler(filters.CONTACT | text, got_phone)],
                ASK_COMMENT: [MessageHandler(text, got_comment)],
            },
            fallbacks=[CommandHandler("cancel", cancel)],
            allow_reentry=True,
        )
    )
    app.add_error_handler(on_error)
    return app


def main() -> None:
    load_dotenv()
    logging.basicConfig(
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
        level=os.environ.get("LOG_LEVEL", "INFO"),
    )
    # httpx на уровне INFO печатает URL запросов вместе с токеном бота — глушим.
    logging.getLogger("httpx").setLevel(logging.WARNING)
    build_application(Config.from_env()).run_polling(allowed_updates=Update.ALL_TYPES)


if __name__ == "__main__":
    main()
