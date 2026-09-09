# LEYBO staging: синхронизация файлов с хоста в контейнер.
# Сайт живёт в Docker volume (leybo_site_data) — правки на хосте попадают
# на стенд только через этот скрипт. После синка контейнер перезапускается
# (opcache.validate_timestamps=0 — иначе правки PHP не подхватятся).
#
# Использование:
#   powershell -File sync.ps1            # быстрая синхронизация кода (тема, mu-plugins, языки, sandbox)
#   powershell -File sync.ps1 -Full      # полная синхронизация всего site/
#
param([switch]$Full)
$ErrorActionPreference = "Stop"

$root  = Split-Path -Parent $MyInvocation.MyCommand.Path
$site  = Join-Path $root "site"
$paths = @(
    "wp-content\themes\isart-theme",
    "wp-content\mu-plugins",
    "wp-content\languages",
    "wp-content\novamira-sandbox"
)
if ($Full) { $paths = @("") }

foreach ($p in $paths) {
    $src = if ($p) { Join-Path $site $p } else { $site }
    $dst = "/var/www/html/" + (($p -replace "\\","/") + "/")
    Write-Host "sync $p -> container"
    docker cp "$src\." "leybo-web:$dst"
}

Write-Host "restart web (opcache validate_timestamps=0)..."
docker compose -f (Join-Path $root "docker-compose.yml") restart web | Out-Null
Write-Host "Готово. Стенд: http://localhost:8081/"
