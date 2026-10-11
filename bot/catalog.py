"""Каталог товаров для бота: тот же products.json, что использует сайт.

Источник — URL (по умолчанию raw GitHub репозитория сайта) или путь к файлу.
Данные кешируются на `ttl` секунд; если обновление не удалось — отдаём
последнюю удачную копию, чтобы бот не падал из-за сбоя сети.
"""

from __future__ import annotations

import asyncio
import json
import logging
import time
from pathlib import Path
from typing import Any

import httpx

log = logging.getLogger(__name__)


class CatalogError(Exception):
    """Каталог недоступен и кеша ещё нет."""


class Catalog:
    def __init__(self, source: str, ttl: float = 300.0) -> None:
        self._source = source
        self._ttl = ttl
        self._cache: list[dict[str, Any]] | None = None
        self._loaded_at = 0.0
        self._lock = asyncio.Lock()

    async def get(self, product_id: str) -> dict[str, Any] | None:
        for product in await self._load():
            if product.get("id") == product_id:
                return product
        return None

    async def _load(self) -> list[dict[str, Any]]:
        async with self._lock:
            fresh = self._cache is not None and time.monotonic() - self._loaded_at < self._ttl
            if fresh:
                return self._cache  # type: ignore[return-value]
            try:
                data = await self._fetch()
                if not isinstance(data, list):
                    raise ValueError("products.json должен быть списком")
                self._cache = [p for p in data if isinstance(p, dict)]
                self._loaded_at = time.monotonic()
            except Exception as exc:
                if self._cache is None:
                    raise CatalogError(str(exc)) from exc
                log.warning("Не удалось обновить каталог, использую кеш: %s", exc)
                # повторная попытка не раньше чем через 30 с, а не на каждое сообщение
                self._loaded_at = time.monotonic() - self._ttl + 30
            return self._cache

    async def _fetch(self) -> Any:
        if self._source.startswith(("http://", "https://")):
            async with httpx.AsyncClient(timeout=10, follow_redirects=True) as client:
                response = await client.get(self._source)
                response.raise_for_status()
                return response.json()
        return await asyncio.to_thread(lambda: json.loads(Path(self._source).read_text("utf-8")))
