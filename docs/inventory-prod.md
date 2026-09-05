# Инвентаризация прода leybo.store (read-only, 26.08.2026)

> Снята через Novamira MCP (временный administrator `leybo_mcp_dev_20260826`).
> Только чтение; никаких изменений на проде не производилось.

## Платформа
| Параметр | Значение |
|---|---|
| WordPress | **7.1** |
| PHP | **8.2.32** |
| Woocommerce | **10.7.0** |
| Домен | `https://leybo.store` |
| Локаль | `ru_RU` |
| Мультисайт | нет |
| Timezone (gmt_offset) | 3 (UTC+3) |
| `WP_ENVIRONMENT_TYPE` | **не задан** (prod-default) |
| Имя БД | `cn93525_leybo` |
| Размер БД / таблиц | **494.6 MB** / 70 таблиц |

## Активная тема
- `isart-theme` (active, template): внутреннее имя темы — **`wordpress-twig-template`**, версия не заполнена, `parent` = нет.
  > По факту `isart-theme` — это и есть Timber/Twig-тема `wordpress-twig-template` (каталог/stylesheet переименован). Отдельная родительская тема не используется.
- Timber через плагин `timber-library` v1.15.2 + встроенная копия `include/Timber` в теме.

### Кастомные файлы темы `isart-theme` (самые важные)
- `functions.php` (16.5 KB), `woocommerce.php`, `single-product.php`, `search.php`, `email-send.php`, `archive-men.php`, `archive-women.php`
- `include/woocommerce-theme-settings.php` (7.3 KB), `include/acf-fields.php` (0), `include/rest-api.php` (0)
- `views/*.twig` + `views/partials/*` — Twig-шаблоны
- `assets/scss` + `config/webpack.config.js`, `package.json` — сборка фронта (node/webpack)
- `woocommerce/*` — переопределяющие шаблоны (cart, checkout, myaccount, loop, single-product, wishlist*)
- Есть временный файл-бэкап `views/home.twig.bak-20260731`

## Активные плагины (29)
`xt-woo-ajax-add-to-cart` · `acf-to-rest-api` · `add-search-to-menu` (Ivory) · `advanced-custom-fields-pro-master` · `ajax-cart-autoupdate-for-woocommerce` · `akismet` · `classic-editor` · `duplicate-page` · `duplicator` · `force-default-variant-for-woocommerce` · `hello` · `load-more-products-for-woocommerce` (1.2.3.8) · `loco-translate` · `novamira` (1.5.0) · `rustolat` · `svg-support` · `timber-library` (1.15.2) · `updraftplus` (1.26.4) · `woo-checkout-field-editor-pro` · `woo-product-variation-gallery` · `woo-variation-swatches` · `woocommerce-ajax-filters` (3.2.0.1) · `woocommerce` · `wordpress-seo` (27.4) · `wp-all-import-pro` (5.0.4) + `wpai-woocommerce-add-on` (4.0.6) · `wp-fastest-cache` (1.4.7) · `wp-scss` (4.0.8) · `yith-woocommerce-wishlist` (4.14.0)

## Каталог и данные
| Метрика | Значение |
|---|---|
| Товаров (product, publish) | **1242** |
| Товаров всего статусов | 1316 |
| Вариаций (product_variation) | **24 437** (~19 вариаций/товар) |
| Категории товаров (product_cat) | 34 |
| Метки товаров | 1151 |
| Атрибутных термов (pa_*) | **4601** |
| Заказов (shop_order) | **0** (и в корзине 0) |
| Пользователей | 3 |
| uploads (размер) | **9492.3 MB (~9.5 GB)** |
| plugins (размер) | 168.5 MB |
| themes (размер) | 20.1 MB |
| debug.log | 1.3 MB (есть) |

## WP-Cron (31 событие)
Кастомные события проекта (префикс `leybo_`):
- `leybo_sync_event` — синхронизация с внешним API
- `leybo_raketa_track_event` — «ракета» — отложенные задачи синхронизации
- `leybo_daily_rate` — ежедневный курс валют
- `leybo_temp_mcp_revoke_user` — **механизм авто-отключения временного MCP-доступа**

Стандартные: WooCommerce Admin, WP SEO reindex, Duplicator/UpdraftPlus cleanup, action_scheduler, etc.

## Кастомный контур — КРИТИЧНО
**Вся бизнес-логика (`leybo-*`) живёт в `wp-content/novamira-sandbox/`** (28 объектов):
`leybo-admin.php` (42KB) · `leybo-build.php` (14KB) · `leybo-catalog.json` (111KB) · `leybo-colors.php` · `leybo-dashboard.php` (31KB) · `leybo-home.php` + `leybo-home.css` · `leybo-legal-consent.php` · `leybo-map.php` / `leybo-map-names.php` (маппинг брендов/названий) · `leybo-price-display.php` · `leybo-pricing.php` · `leybo-rate.php` (курс) · `leybo-size-price.php` / `leybo-size-profile-live.php` (цены/размеры) · `leybo-sync.php` / `leybo-sync-endpoint.php` (синхронизация с внешним API) · `leybo-raketa-cn.php` (актуальный) + `leybo-raketa.php.disabled` (отключён) · `leybo-test-pay.php` (тестовый платёж) · `leybo-verify.php` · `leybo-temp-mcp-access.php` (НЕ трогать — авто-отключение доступа)
Данные-бэкапы: `prices-before-sizefix-2026-08-13.json` (968KB), `leybo-catalog.json` (111KB)

> ⚠️ По ТЗ (жёсткие ограничения, «не оставляй бизнес-логику набором случайных snippets») сверху это **не финальное размещение**: код нужно будет вынести в `isart-theme` и/или отдельный версионируемый проектный плагин (на стенде), а не оставлять в sandbox.

## Замечания и риски (зафиксировано для этапов далее)
1. Бизнес-логика в sandbox (не в плагине/теме) — требует выноса в проектный плагин.
2. 24 437 вариаций при 1242 товарах — высокий риск N+1 / тяжёлых meta_query / пересчётов (этап 4).
3. Заказов 0, пользователей 3 — для обезличивания и тестов структуру заказов нужно досоздавать реалистично, реальных ПДн почти нет.
4. uploads ~9.5 GB — объём для бэкапа/стенда; требуется оценка части на копию.
5. `WP_ENVIRONMENT_TYPE` не задан — на стенде обязательно задать `staging`.
6. Нет отдельного проектного плагина; контур цен/размеров/наличия/импорта/синка — целиком в sandbox.
7. debug.log есть (1.3 MB) — проверить уровень логирования на стенде.
8. Два кандидата на «драке» функциональности: `xt-woo-ajax-add-to-cart` + `ajax-cart-autoupdate-for-woocommerce` + `woocommerce-ajax-filters` + `load-more-products-for-woocommerce` — проверить дублирование на этапах 3–4.
