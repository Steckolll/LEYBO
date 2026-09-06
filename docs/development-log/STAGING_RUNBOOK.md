# Локальный staging LEYBO: запуск и восстановление

Документ не содержит паролей, API-ключей и персональных данных. Все команды выполняются только из каталога `wordpress/` и только для локальной копии.

## Требования

- Docker Desktop с Linux containers.
- Доступный Docker Compose v2.
- Свободный порт `8081`.

## Запуск существующей копии

```powershell
docker compose up -d
docker compose ps
```

Ожидается:

- `leybo-db` имеет статус `healthy`;
- `leybo-web` запущен;
- сайт открывается на `http://localhost:8081/`.

## Проверка изоляции

```powershell
docker compose exec -T web php -r 'require "/var/www/html/wp-load.php"; echo "home=".home_url()."\n"; echo "env=".WP_ENVIRONMENT_TYPE."\n"; echo "cron=".(DISABLE_WP_CRON ? "disabled" : "enabled")."\n"; echo "external_http=".(WP_HTTP_BLOCK_EXTERNAL ? "blocked" : "allowed")."\n";'
```

Проверить HTTP 403 для `wp-config.php`, `wp-content/debug.log`, `wp-content/novamira-sandbox/leybo-catalog.json`, `dup-installer/` и диагностических PHP-файлов. Проверить, что главная содержит `noindex,nofollow`.

Проверить внешний HTTP-блок:

```powershell
docker compose exec -T web php -r 'require "/var/www/html/wp-load.php"; $r=wp_remote_get("https://example.com", ["timeout"=>5]); echo is_wp_error($r) ? "blocked:".$r->get_error_code()."\n" : "allowed\n";'
```

Проверить почту только на тестовом адресе `.invalid`; факт `true` означает, что `wp_mail` перехвачен, а не что письмо доставлено.

## Проверка данных

Проверять только агрегаты:

```powershell
docker compose exec -T db mysql -uleybo -pleybo_pass -D leybo -N -e "SELECT COUNT(*) FROM wp_users; SELECT COUNT(*) FROM wp_posts WHERE post_type='shop_order';"
```

Перед использованием копии:

- email пользователей должны быть в `example.invalid`;
- billing/shipping поля должны быть тестовыми;
- production API keys, tokens и webhook secrets должны быть пустыми или заменены staging-заглушками;
- исходные архивы и дампы не должны попадать в Git или HTTP-доступную директорию.

## Восстановление из SQL-дампа

1. Остановить приложение, не удаляя volume базы:

```powershell
docker compose stop web
```

2. Получить согласованный SQL-дамп вне репозитория. Не добавлять дамп в проект и не выводить его содержимое в логи.
3. Импортировать дамп в базу только после проверки, что он обезличен. ВАЖНО: передавать дамп сырыми байтами — `Get-Content -Raw` перекодирует байты и даёт двойное кодирование кириллицы (испорченная схема `leybo_restore` — следствие именно этого). Правильный способ:

```powershell
cmd /c "docker compose exec -T db mysql -uleybo -pleybo_pass leybo < C:\path\to\staging.sql"
```

4. Проверить кодировку импорта байтами (ожидается HEX `D09A...` для «К...», а не `C390...`):

```powershell
docker compose exec -T db mysql -uleybo -pleybo_pass -D leybo -N -e "SELECT HEX(option_value) FROM wp_options WHERE option_name='blogname';"
```

5. После импорта ОБЯЗАТЕЛЬНО очистить кэш WP Fastest Cache — иначе сайт продолжит отдавать устаревший HTML из периода битых данных:

```powershell
docker compose exec -T web sh -lc "find /var/www/html/wp-content/cache/all -type f -delete; find /var/www/html/wp-content/cache/wpfc-minified -type f -delete"
```

7. Запустить приложение:

```powershell
docker compose up -d
```

8. Выполнить smoke-проверку главной, `/shop/`, каталога, товара, `/cart/`, `/checkout/`, `/my-account/` и `/wp-admin/`.

Также mysqldump в rollback-файл сохранять сырыми байтами, без строкового перекодирования:

```powershell
cmd /c "docker compose exec -T db mysqldump -uleybo -pleybo_pass --single-transaction --no-tablespaces --routines --triggers leybo > C:\path\to\staging-rollback.sql"
```

## Проверенная репетиция 2026-09-05

Восстановление архива от 2026-08-27 выполнено безопасно в отдельную схему `leybo_restore` внутри MySQL-контейнера. Рабочая схема `leybo` не перезаписывалась. После импорта были применены localhost URL, обезличивание пользователей и очистка integration options; затем web временно запускался на `leybo_restore` и был возвращен на `leybo`.

Перед подобной репетицией обязательно сделать дамп текущей staging-схемы вне репозитория с `--no-tablespaces`, если у пользователя базы нет `PROCESS` privilege:

```powershell
docker compose exec -T db mysqldump -uleybo -pleybo_pass --single-transaction --no-tablespaces --routines --triggers leybo | Set-Content -Encoding utf8 "C:\path\to\staging-rollback.sql"
```

Для отдельной схемы используется root только внутри локального контейнера:

```powershell
docker compose exec -T db mysql -uroot -prootpass -e "DROP DATABASE IF EXISTS leybo_restore; CREATE DATABASE leybo_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci; GRANT ALL PRIVILEGES ON leybo_restore.* TO 'leybo'@'%'; FLUSH PRIVILEGES;"
```

После restore нужно вернуть `DB_NAME=leybo` в локальном `wp-config.php` и перезапустить только web-контейнер. Схему `leybo_restore` удалить после принятия результатов отдельным решением, а не автоматически.

## Обязательное правило

Не запускать `docker compose down -v` без отдельного решения: команда удалит volume базы и локальное состояние. Перед разрушительными действиями нужен новый локальный backup volume/БД.

## Производительность стенда (2026-09-05)

Корневая причина медленных страниц — Windows bind mount: `file_exists` в 575 раз, чтение файла в 152 раза медленнее контейнерной ФС. OPcache настроен с `opcache.validate_timestamps=0`.

Правила:

- После ЛЮБОЙ правки PHP-файлов выполнять `docker compose restart web`, иначе изменения не применятся (байткод закэширован).
- Первый запрос после рестарта медленный (перекомпиляция), последующие быстрые.
- Рекомендуется исключить `C:\Users\user\Projects\LEYBO` из проверки Windows Defender: `Add-MpPreference -ExclusionPath "C:\Users\user\Projects\LEYBO"` (запуск PowerShell от администратора).

Замеры в установившемся режиме: фронт 4–5 с, cart/checkout/account 12–13 с, admin ~20 с. Это baseline для этапа 4; MySQL выполняет ~38 запросов на страницу — узкое место не в БД.

## Доступ к админке стенда

Локальные пароли неизвестны (данные обезличены). Для входа сбросить пароль staging-админа (user ID 1) через контейнер:

```powershell
docker compose exec -T web php -r 'require "/var/www/html/wp-load.php"; wp_set_password("<новый-локальный-пароль>", 1);'
```

Пароль держать вне репозитория и вне коммитов. Порт стенда привязан к `127.0.0.1`, доступ возможен только с локальной машины.

## Проверка ссылок на прод (после любого импорта БД)

Историческая замена URL при переносе была частичной, поэтому после restore проверять и при находках заменять `https://leybo.store` → `http://localhost:8081` (для serialized-значений — только serialized-безопасным PHP-проходом):

```powershell
docker compose exec -T db mysql -uleybo -pleybo_pass -D leybo -N -e "SELECT 'menu', COUNT(*) FROM wp_postmeta WHERE meta_key='_menu_item_url' AND meta_value LIKE '%leybo.store%' UNION ALL SELECT 'options', COUNT(*) FROM wp_options WHERE option_value LIKE '%leybo.store%' UNION ALL SELECT 'indexables', COUNT(*) FROM wp_yoast_indexable WHERE permalink LIKE '%leybo.store%' UNION ALL SELECT 'content_links', COUNT(*) FROM wp_posts WHERE post_content LIKE '%http://leybo.store%' OR post_content LIKE '%https://leybo.store%';"
```

После замен обязательно сбросить кэш WP Fastest Cache и проверить живой HTML (0 упоминаний `leybo.store` на `/`, `/shop/`, каталоге; `og:url` и пункты меню — localhost). Кэш-опции (`um_cache_userdata_*`, `_transient_*`) можно удалять целиком — они пересоздаются; помни, что `um_cache_userdata_*` может содержать реальные email (PII).

## Известные ограничения

- WP-Cron отключен; события синхронизации не выполняются автоматически.
- Внешний HTTP заблокирован на уровне WordPress и MU-плагина; запросы к api.wordpress.org мокируются пустым ответом `plugins_api` (иначе сторонние плагины, например XT Framework, падают фатально на заблокированном WP_Error).
- Все WooCommerce gateway должны оставаться отключенными. `leybo_testpay_key` может генерироваться sandbox-кодом при загрузке, но это не разрешает оплату без cookie/прав и не является production-секретом.
- Локальные ключи интеграций очищены; реальные ключи нельзя добавлять в эту копию.
- Локальный стенд работает по HTTP; HTTPS — требование к удаленному staging.
