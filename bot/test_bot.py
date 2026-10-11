"""Тесты без сети и без Telegram: python -m unittest discover -s bot -p "test_*.py" """

from __future__ import annotations

import asyncio
import json
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import AsyncMock, MagicMock

from telegram.error import TelegramError
from telegram.ext import ConversationHandler

import main
from catalog import Catalog, CatalogError
from texts import (
    clean_text,
    format_price,
    normalize_phone,
    parse_order_payload,
    render_order_message,
)

PRODUCT = {"id": "kult", "name": 'Культ <"тест">', "price": 25500, "currency": "KZT"}


class TextsTest(unittest.TestCase):
    def test_payload(self):
        self.assertEqual(parse_order_payload(["order_kult"]), "kult")
        self.assertEqual(parse_order_payload(["order_tom-kot-bro-tomtoks-leto-6kg"]), "tom-kot-bro-tomtoks-leto-6kg")
        for bad in (None, [], [""], ["kult"], ["order_"], ["order_a b"], ["order_" + "x" * 65], ["order_a/b"]):
            self.assertIsNone(parse_order_payload(bad), bad)

    def test_price(self):
        self.assertEqual(format_price(26000), "26 000 ₸")
        self.assertEqual(format_price(4500, "KZT"), "4 500 ₸")
        self.assertEqual(format_price(10, "XYZ"), "10 XYZ")

    def test_phone(self):
        self.assertEqual(normalize_phone("+7 705-763-49-69"), "+77057634969")
        self.assertEqual(normalize_phone("8 (705) 763 49 69"), "+77057634969")
        self.assertEqual(normalize_phone("7057634969"), "+77057634969")
        self.assertEqual(normalize_phone("+380 44 123 45 67"), "+380441234567")
        for bad in ("", "abc", "12345", "1" * 16):
            self.assertIsNone(normalize_phone(bad), bad)

    def test_clean_text(self):
        self.assertEqual(clean_text("  a \n b  ", 10), "a b")
        self.assertEqual(clean_text("x" * 10, 3), "xxx")
        self.assertEqual(clean_text(None, 3), "")

    def test_order_message_escapes_html(self):
        text = render_order_message(
            ref="AB12CD",
            product=PRODUCT,
            name="<b>Иван</b>",
            phone="+77057634969",
            comment="a & b",
            user_id=42,
            username="ivan_k",
            when=datetime(2026, 10, 11, 10, 0, tzinfo=timezone.utc),
        )
        self.assertIn("#AB12CD", text)
        self.assertIn("Культ &lt;&quot;тест&quot;&gt;", text)
        self.assertIn("&lt;b&gt;Иван&lt;/b&gt;", text)
        self.assertNotIn("<b>Иван</b>", text)
        self.assertIn("a &amp; b", text)
        self.assertIn("25 500 ₸", text)
        self.assertIn("@ivan_k", text)
        self.assertIn("tg://user?id=42", text)
        self.assertIn("11.10.2026 15:00", text)  # UTC+5

    def test_order_message_without_username_and_comment(self):
        text = render_order_message(
            ref="X", product=PRODUCT, name="n", phone="p", comment="", user_id=1, username=None
        )
        self.assertNotIn("@", text)
        self.assertNotIn("Комментарий", text)


class CatalogTest(unittest.TestCase):
    def _file(self, data) -> str:
        tmp = tempfile.NamedTemporaryFile("w", suffix=".json", delete=False, encoding="utf-8")
        json.dump(data, tmp)
        tmp.close()
        self.addCleanup(Path(tmp.name).unlink)
        return tmp.name

    def test_lookup_from_file(self):
        catalog = Catalog(self._file([PRODUCT, {"id": "other"}]))
        self.assertEqual(asyncio.run(catalog.get("kult"))["price"], 25500)
        self.assertIsNone(asyncio.run(catalog.get("nope")))

    def test_missing_source_raises(self):
        with self.assertRaises(CatalogError):
            asyncio.run(Catalog("/nonexistent/products.json").get("kult"))

    def test_stale_cache_survives_failed_refresh(self):
        path = self._file([PRODUCT])
        catalog = Catalog(path, ttl=0)

        async def scenario():
            self.assertIsNotNone(await catalog.get("kult"))
            Path(path).write_text("not json", encoding="utf-8")
            catalog._loaded_at = -1e9  # принудительно протухший кеш
            return await catalog.get("kult")

        self.assertIsNotNone(asyncio.run(scenario()))


def make_update(text=None, contact=None, chat_type="private"):
    message = MagicMock()
    message.text = text
    message.contact = contact
    message.reply_text = AsyncMock()
    user = SimpleNamespace(id=42, username="ivan_k", first_name="Иван")
    return SimpleNamespace(
        effective_message=message,
        effective_chat=SimpleNamespace(type=chat_type),
        effective_user=user,
    )


def make_context(args=None, catalog_product=PRODUCT):
    catalog = MagicMock()
    catalog.get = AsyncMock(return_value=catalog_product)
    config = main.Config("t", -100500, "src", "https://site.example/", "+7 700 000 00 00")
    bot = MagicMock()
    bot.send_message = AsyncMock()
    return SimpleNamespace(
        args=args or [],
        user_data={},
        bot=bot,
        bot_data={"config": config, "catalog": catalog},
    )


class FlowTest(unittest.TestCase):
    def test_happy_path_sends_order_to_group(self):
        ctx = make_context(["order_kult"])

        async def scenario():
            self.assertEqual(await main.start(make_update(), ctx), main.ASK_NAME)
            self.assertEqual(await main.got_name(make_update("Иван"), ctx), main.ASK_PHONE)
            self.assertEqual(await main.got_phone(make_update("8 705 763 49 69"), ctx), main.ASK_COMMENT)
            last = make_update("2 канистры")
            self.assertEqual(await main.got_comment(last, ctx), ConversationHandler.END)
            return last

        last = asyncio.run(scenario())
        ctx.bot.send_message.assert_awaited_once()
        chat_id, text = ctx.bot.send_message.await_args.args
        self.assertEqual(chat_id, -100500)
        self.assertIn("+77057634969", text)
        self.assertIn("2 канистры", text)
        self.assertIn("Заявка #", last.effective_message.reply_text.await_args.args[0])
        self.assertEqual(ctx.user_data, {})

    def test_phone_via_contact_button_and_skip_comment(self):
        ctx = make_context(["order_kult"])
        contact = SimpleNamespace(phone_number="77057634969")

        async def scenario():
            await main.start(make_update(), ctx)
            await main.got_name(make_update("Иван"), ctx)
            await main.got_phone(make_update(contact=contact), ctx)
            return await main.got_comment(make_update("Пропустить"), ctx)

        self.assertEqual(asyncio.run(scenario()), ConversationHandler.END)
        text = ctx.bot.send_message.await_args.args[1]
        self.assertIn("+77057634969", text)
        self.assertNotIn("Комментарий", text)

    def test_bad_phone_reasks(self):
        ctx = make_context(["order_kult"])

        async def scenario():
            await main.start(make_update(), ctx)
            await main.got_name(make_update("Иван"), ctx)
            return await main.got_phone(make_update("abc"), ctx)

        self.assertEqual(asyncio.run(scenario()), main.ASK_PHONE)
        ctx.bot.send_message.assert_not_awaited()

    def test_start_without_payload_and_unknown_product(self):
        ctx = make_context([])
        self.assertEqual(asyncio.run(main.start(make_update(), ctx)), ConversationHandler.END)
        ctx = make_context(["order_nope"], catalog_product=None)
        self.assertEqual(asyncio.run(main.start(make_update(), ctx)), ConversationHandler.END)

    def test_ignores_group_chats(self):
        ctx = make_context(["order_kult"])
        update = make_update(chat_type="supergroup")
        self.assertEqual(asyncio.run(main.start(update, ctx)), ConversationHandler.END)
        update.effective_message.reply_text.assert_not_awaited()

    def test_group_send_failure_tells_user_and_ends(self):
        ctx = make_context(["order_kult"])
        ctx.bot.send_message.side_effect = TelegramError("boom")

        async def scenario():
            await main.start(make_update(), ctx)
            await main.got_name(make_update("Иван"), ctx)
            await main.got_phone(make_update("+77057634969"), ctx)
            last = make_update("-")
            result = await main.got_comment(last, ctx)
            return result, last

        result, last = asyncio.run(scenario())
        self.assertEqual(result, ConversationHandler.END)
        self.assertIn("Не удалось", last.effective_message.reply_text.await_args.args[0])
        self.assertIn("+7 700 000 00 00", last.effective_message.reply_text.await_args.args[0])

    def test_application_builds_with_handlers(self):
        config = main.Config("123456:TEST", -1, "src", "https://s/", "")
        app = main.build_application(config, catalog=MagicMock())
        self.assertTrue(app.handlers)


if __name__ == "__main__":
    unittest.main()
