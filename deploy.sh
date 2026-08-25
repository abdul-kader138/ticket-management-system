#!/usr/bin/env bash

set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/ticket-management-system}"
BRANCH="${BRANCH:-main}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"

cd "$APP_DIR"

if [[ ! -f artisan || ! -f composer.json || ! -f .env ]]; then
    echo "Error: $APP_DIR is not a configured Laravel application (.env is required)." >&2
    exit 1
fi

maintenance_enabled=false

finish() {
    exit_code=$?

    if [[ "$maintenance_enabled" == true ]]; then
        "$PHP_BIN" artisan up || true
    fi

    if (( exit_code != 0 )); then
        echo "Deployment failed (exit code $exit_code)." >&2
    fi
}

trap finish EXIT

echo "Deploying $BRANCH to $APP_DIR..."

git fetch origin "$BRANCH"
git checkout "$BRANCH"
git merge --ff-only "origin/$BRANCH"

if [[ -f package-lock.json ]]; then
    if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
        echo "Error: Node.js and npm are required to build frontend assets." >&2
        echo "Install Node.js 22 LTS on this server, then run ./deploy.sh again." >&2
        exit 1
    fi

    if ! node -e 'const [major, minor] = process.versions.node.split(".").map(Number); process.exit(major > 22 || major === 22 && minor >= 12 || major === 20 && minor >= 19 ? 0 : 1)'; then
        echo "Error: Node.js 20.19+ or 22.12+ is required (found $(node --version))." >&2
        echo "Install Node.js 22 LTS on this server, then run ./deploy.sh again." >&2
        exit 1
    fi
fi

"$PHP_BIN" artisan down --retry=60
maintenance_enabled=true

COMPOSER_ALLOW_SUPERUSER=1 "$COMPOSER_BIN" install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

if [[ -f package-lock.json ]]; then
    npm ci
    npm run build
fi

"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan db:seed --force
"$PHP_BIN" artisan storage:link
"$PHP_BIN" artisan optimize
"$PHP_BIN" artisan queue:restart

# Installs/keeps the persistent queue worker current. queue:restart above
# only signals an ALREADY-RUNNING worker to gracefully restart between
# jobs — it starts nothing on its own, so this is what actually processes
# anything dispatched to the queue (see the queue-worker unit file's own
# comment on why an explicit restart, not just queue:restart, is needed to
# pick up fresh code — same class of issue as php-fpm's opcache holding
# onto a stale build after a deploy).
if [[ -f deploy/ticket-management-system-queue-worker.service ]] && command -v systemctl >/dev/null 2>&1; then
    install -m 644 deploy/ticket-management-system-queue-worker.service /etc/systemd/system/ticket-management-system-queue-worker.service
    systemctl daemon-reload
    systemctl enable ticket-management-system-queue-worker
    systemctl restart ticket-management-system-queue-worker
fi

# Drives routes/console.php's scheduled tasks (bookings:expire-holds, and
# whatever gets added after it) — without this timer, Schedule::command()
# entries are registered but nothing ever calls schedule:run to fire them.
if [[ -f deploy/ticket-management-system-scheduler.service && -f deploy/ticket-management-system-scheduler.timer ]] && command -v systemctl >/dev/null 2>&1; then
    install -m 644 deploy/ticket-management-system-scheduler.service /etc/systemd/system/ticket-management-system-scheduler.service
    install -m 644 deploy/ticket-management-system-scheduler.timer /etc/systemd/system/ticket-management-system-scheduler.timer
    systemctl daemon-reload
    systemctl enable --now ticket-management-system-scheduler.timer
fi

# Nightly database backup — see deploy/backup.sh and docs/ROADMAP.md, Phase
# 10. Requires mysqldump on PATH; silently does nothing useful without it,
# same as the other optional units above.
if [[ -f deploy/ticket-management-system-backup.service && -f deploy/ticket-management-system-backup.timer ]] && command -v systemctl >/dev/null 2>&1; then
    install -m 644 deploy/ticket-management-system-backup.service /etc/systemd/system/ticket-management-system-backup.service
    install -m 644 deploy/ticket-management-system-backup.timer /etc/systemd/system/ticket-management-system-backup.timer
    systemctl daemon-reload
    systemctl enable --now ticket-management-system-backup.timer
fi

if id "$WEB_USER" >/dev/null 2>&1; then
    chown -R "$WEB_USER:$WEB_GROUP" storage bootstrap/cache
    chmod -R ug+rwX storage bootstrap/cache
else
    echo "Error: web-server user '$WEB_USER' does not exist." >&2
    exit 1
fi

nginx -t
systemctl restart nginx

"$PHP_BIN" artisan up
maintenance_enabled=false

echo "Deployment completed successfully."
