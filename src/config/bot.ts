// Публичный конфиг бота заказов (bot/ в этом репозитории). Здесь только username — не секрет.
export const BOT_USERNAME = "qaz_pest_bot";

// Telegram принимает в start= одну строку [A-Za-z0-9_-] до 64 символов; bot/ ждёт формат order_<id>.
const ORDER_PREFIX = "order_";
const MAX_PAYLOAD_LENGTH = 64;

export function orderLink(productId: string): string {
  const payload = `${ORDER_PREFIX}${productId}`;
  if (!/^[A-Za-z0-9_-]+$/.test(payload) || payload.length > MAX_PAYLOAD_LENGTH) {
    // Падаем на сборке, а не выпускаем мёртвую ссылку на сайт.
    throw new Error(`id товара "${productId}" не подходит для ссылки на бота (A-Za-z0-9_-, до ${MAX_PAYLOAD_LENGTH - ORDER_PREFIX.length} символов)`);
  }
  return `https://t.me/${BOT_USERNAME}?start=${payload}`;
}
