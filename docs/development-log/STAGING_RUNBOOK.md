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
3. Импортировать дамп в базу только после проверки, что он обезличен:

```powershell
Get-Content -Raw "C:\path\to\staging.sql" | docker compose exec -T db mysql -uleybo -pleybo_pass leybo
```

4. Проверить URL и staging-константы в `wp-config.php`, затем повторить проверки изоляции и данных.
5. Запустить приложение:

```powershell
docker compose up -d
```

6. Выполнить smoke-проверку главной, `/shop/`, каталога, товара, `/cart/`, `/checkout/`, `/my-account/` и `/wp-admin/`.

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

## Известные ограничения

- WP-Cron отключен; события синхронизации не выполняются автоматически.
- Внешний HTTP заблокирован на уровне WordPress и MU-плагина.
- Все WooCommerce gateway должны оставаться отключенными. `leybo_testpay_key` может генерироваться sandbox-кодом при загрузке, но это не разрешает оплату без cookie/прав и не является production-секретом.
- Локальные ключи интеграций очищены; реальные ключи нельзя добавлять в эту копию.
- Текущий админский bootstrap отвечает медленно и требует отдельного performance/compatibility исправления.
