import type { APIRoute } from "astro";
import products from "../../data/products.json";

// Каталог в виде файла /api/products.json: его читает Telegram-бот на хостинге (public/bot/),
// поэтому бот всегда видит ровно те товары, что собраны на сайте.
export const GET: APIRoute = () =>
  new Response(JSON.stringify(products), {
    headers: { "Content-Type": "application/json; charset=utf-8" },
  });
