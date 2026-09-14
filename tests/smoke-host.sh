#!/usr/bin/env bash
#
# Installs this package into a throwaway `laravel new` app, boots it, and
# provisions a tenant — the second consumer the suite cannot be. Everything it
# does is `docs/host-requirements.md`; a step needed here that the doc does not
# state is a docs bug, not a script bug.
#
# Usage: tests/smoke-host.sh [scratch-dir]    (default /tmp/numerosis-smoke)

set -euo pipefail

PACKAGE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCRATCH="${1:-/tmp/numerosis-smoke}"
HOST_DIR="$SCRATCH/host"
APEX="numerosis-smoke.test"
TENANT="acme"
PORT="${SMOKE_PORT:-8123}"
DB_NAME="numerosis_smoke"
DB_CONTAINER="${SMOKE_DB_CONTAINER:-numerosis-mysql-1}"
SERVE_PID=""

mysql_root() { docker exec -i "$DB_CONTAINER" mysql -uroot -proot "$@" 2>/dev/null; }

fail() { echo "SMOKE FAIL: $*" >&2; exit 1; }

cleanup() {
    if [ -n "$SERVE_PID" ]; then
        kill "$SERVE_PID" 2>/dev/null || true
    fi

    mysql_root -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; DROP DATABASE IF EXISTS \`tenant$TENANT\`;" || true
    rm -rf "$SCRATCH"
}
trap cleanup EXIT

if [ -e "$SCRATCH" ]; then
    fail "$SCRATCH already exists — pass another path, or delete it"
fi

docker ps --format '{{.Names}}' | grep -qx "$DB_CONTAINER" || fail "MySQL container $DB_CONTAINER is not running"

echo "==> fresh Laravel app in $HOST_DIR"
mkdir -p "$SCRATCH"
(cd "$SCRATCH" && composer create-project laravel/laravel host --no-interaction --quiet)
rm -rf "$HOST_DIR/.git"

echo "==> path repositories and install"
cd "$HOST_DIR"
composer config repositories.numerosis path "$PACKAGE_DIR"
composer config repositories.numerosis-packages path "$PACKAGE_DIR/packages/*"
composer config minimum-stability dev
composer config prefer-stable true
composer require nvade/numerosis:@dev -W --no-interaction --quiet

echo "==> env"
mysql_root -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\`;"
for pair in "APP_URL=http://$APEX" 'DB_CONNECTION=mysql' 'DB_HOST=127.0.0.1' 'DB_PORT=3306' \
    "DB_DATABASE=$DB_NAME" 'DB_USERNAME=root' 'DB_PASSWORD=root' 'SESSION_CONNECTION=central' \
    'STRIPE_KEY=pk_test_smoke' 'STRIPE_SECRET=sk_test_smoke' 'STRIPE_WEBHOOK_SECRET=whsec_smoke'; do
    key="${pair%%=*}"

    if grep -q "^$key=" .env; then
        sed -i "s|^$key=.*|$pair|" .env
    else
        printf '%s\n' "$pair" >> .env
    fi
done

echo "==> the five fresh-app defaults docs/host-requirements.md names"
rm -f database/migrations/0001_01_01_000000_create_users_table.php \
      database/migrations/0001_01_01_000002_create_jobs_table.php
php artisan make:session-table --no-interaction >/dev/null
printf '<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n' > routes/web.php
cat > bootstrap/app.php <<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Nvade\Numerosis\Numerosis;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        using: Numerosis::routes(...),
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        Numerosis::middleware($middleware);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Numerosis::exceptions($exceptions);
    })->create();
PHP
npm install --silent >/dev/null
npm run build >/dev/null

echo "==> migrate, publish, install"
php artisan migrate --no-interaction --quiet
php artisan vendor:publish --tag=numerosis-public-assets --no-interaction --quiet
php artisan numerosis:install --no-interaction >/dev/null
php artisan numerosis:install --verify-only --no-interaction >/dev/null \
    || fail "numerosis:install --verify-only exited non-zero"

echo "==> boot"
php artisan serve --host=127.0.0.1 --port="$PORT" > "$SCRATCH/serve.log" 2>&1 &
SERVE_PID=$!
sleep 4

request() { curl -s -o "$SCRATCH/response.html" -w '%{http_code}' -H "Host: $1" "http://127.0.0.1:$PORT$2"; }

[ "$(request "$APEX" /login)" = '200' ] || fail 'central /login did not return 200'
grep -q '<title>Log in</title>' "$SCRATCH/response.html" || fail 'central /login is not the login page'

echo "==> provision a tenant and request it on its own host"
OWNER=$(php artisan tinker --execute '
    echo \App\Models\Central\CentralUser::create([
        "name" => "Smoke",
        "email" => "smoke@example.test",
        "password" => bcrypt("password"),
    ])->global_id;
' | tail -1 | tr -d '[:space:]')
php artisan tenancy:provision "$TENANT" --sync --owner="$OWNER" --no-interaction >/dev/null

[ "$(request "$TENANT.$APEX" /)" = '200' ] \
    || fail "tenant host did not return 200 — see $SCRATCH/host/storage/logs/laravel.log"

if grep -q 'Server Error' "$SCRATCH/response.html"; then
    fail 'tenant host rendered an error page'
fi

echo 'SMOKE PASS'
