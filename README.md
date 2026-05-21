# YCP Checkout Купить в 1 клик

> Полная интеграция **WooCommerce** с **Яндекс Чекаутом** по официальному протоколу [Yandex Commerce Protocol (YCP)](https://yandex.ru/support/merchants-ru-ycp/).
> Покупатель оформляет заказ в виджете Яндекса — заказ автоматически создаётся в вашей WooCommerce.

[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0%2B-96588a.svg)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-GPL%20v2-green.svg)](LICENSE.txt)
[![Release](https://img.shields.io/github/v/release/perfinn/YCP-Yandex-Commerce-Woocommerce)](https://github.com/perfinn/YCP-Yandex-Commerce-Woocommerce/releases)

---

## ✨ Возможности

- 🔌 **Все 10 эндпоинтов YCP v1** — полная поддержка протокола, никаких «заглушек»
- 🛒 **Автоматическое создание заказов** в WooCommerce из Яндекс Чекаута
- 🔐 **Bearer-токен авторизация** (с fallback на `X-API-Key`)
- 🔁 **Идемпотентность** по `session_id` — один заказ на сессию, безопасно при ретраях
- 💾 **HPOS-совместимость** — работает с новым хранилищем заказов WooCommerce
- 📦 **Габариты по умолчанию** — товары без указанных размеров получают разумные дефолты для расчёта СДЭК
- 📧 **Письма с корректной суммой** — флоу `checkout-draft → pending`, без писем «Новый заказ на 0 ₽»
- 📊 **Лог последних 100 запросов** прямо в админке — отладка одной кнопкой
- ⚙️ **Все настройки склада из админки** — никакого хардкода

## 🚀 Установка

### Способ 1: ZIP-архив
1. Скачайте свежий ZIP из [Releases](https://github.com/perfinn/YCP-Yandex-Commerce-Woocommerce/releases/latest)
2. WordPress Admin → **Плагины → Добавить новый → Загрузить плагин** → выберите ZIP
3. Активируйте

### Способ 2: git clone
```bash
cd wp-content/plugins/
git clone https://github.com/perfinn/YCP-Yandex-Commerce-Woocommerce.git yandex-ycp-woo
```
Активируйте плагин в админке.

## ⚙️ Настройка

После активации перейдите в **Инструменты → YCP Yandex Commerce**:

1. Получите Bearer-токен в [кабинете Яндекс Чекаута](https://merchants.yandex.ru/) → «Настройки интеграции → URL для API и токен доступа».
2. Вставьте токен в поле «Bearer-токен от Яндекса» → Сохранить.
3. Заполните данные склада (название, адрес, телефон).
4. Скопируйте «URL для Яндекса» (вида `https://ваш-сайт.ru/ycp/`) и вставьте его в кабинете Яндекса в поле **«URL для API»**.
5. Нажмите в Яндексе **«Проверить подключение»** — должен получить `200 OK`.

## 📋 Реализованные эндпоинты

| Метод | Путь | Назначение |
|---|---|---|
| `GET`  | `/api/v1/warehouses` | список складов магазина |
| `POST` | `/api/v1/checkout/basket/check` | проверка корзины (цены, наличие, габариты) |
| `POST` | `/api/v1/checkout/delivery/options` | варианты доставки (курьер/ПВЗ) |
| `GET`  | `/api/v1/checkout/delivery/pickup_points` | список собственных ПВЗ |
| `POST` | `/api/v1/checkout` | создание сессии чекаута → создаётся WC-заказ |
| `POST` | `/api/v1/checkout/placed` | подтверждение оформления (online/COD) |
| `POST` | `/api/v1/checkout/cancel` | отмена сессии |
| `GET`  | `/api/v1/order` | история статусов доставки |
| `POST` | `/api/v1/order/cancel` | отмена оформленного заказа |
| `POST` | `/api/v1/order/delivered` | отметка о доставке |

## 🧪 Тестирование вручную

```bash
# Healthcheck
curl -H "Authorization: Bearer ВАШ_ТОКЕН" https://ваш-сайт.ru/ycp/

# Список складов
curl -H "Authorization: Bearer ВАШ_ТОКЕН" \
     https://ваш-сайт.ru/ycp/api/v1/warehouses

# Проверка корзины
curl -X POST -H "Authorization: Bearer ВАШ_ТОКЕН" \
     -H "Content-Type: application/json" \
     -d '{"items":[{"id":"123","quantity":1}],"locality":"Москва"}' \
     https://ваш-сайт.ru/ycp/api/v1/checkout/basket/check
```

## 🔧 Технические детали

- **Совместимость:** WordPress 5.8+, WooCommerce 6.0+, PHP 7.4+
- **HPOS:** работает с обоими хранилищами заказов
- **Rewrite-правила** создаются при активации, единоразовый flush
- **Кэширование:** ответы помечены `nocache_headers()`
- **Логи:** хранятся в опции `ycpy_log`, ротация по последним 100 записям

## 🧩 Маппинг товаров

Плагин ищет товар в WooCommerce в таком порядке:
1. По **SKU** (поле «Артикул»)
2. По **ID товара** (если ID числовой)

В Яндексе как `offerId`/`id` указывайте либо SKU, либо WC product ID — оба варианта поддержаны.

## 🐛 Отладка

Если заказ не оформляется:

1. Откройте **Инструменты → YCP Yandex Commerce → Лог последних запросов**
2. Найдите запись с типом `EXCEPTION` — там полное сообщение об ошибке
3. Тело запроса от Яндекса там же — увидите, что именно прислали

## 📬 Поддержка

По техническим вопросам, кастомным доработкам и сопровождению:

**📧 [info@perfinn.ru](mailto:info@perfinn.ru)**

В письме указывайте версию WordPress, WooCommerce, версию плагина и фрагмент лога из админки плагина.

## 📜 Лицензия

GPL-2.0 — см. [LICENSE.txt](LICENSE.txt).

## 🤝 Вклад в проект

PR'ы, issue, фичреквесты — добро пожаловать. Создавайте [issue](https://github.com/perfinn/YCP-Yandex-Commerce-Woocommerce/issues) или присылайте pull request.

---

⭐ **Полезный плагин? Поставьте звезду на GitHub!**
