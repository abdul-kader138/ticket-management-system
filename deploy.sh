#!/usr/bin/env bash
#
# Zero-to-serving deployment for this Laravel/Filament app.
#
#   ./deploy.sh                     # full deploy of origin/main
#   BRANCH=release ./deploy.sh      # deploy a different branch
#   ./deploy.sh --no-build          # skip npm ci + vite build (backend-only change)
#   ./deploy.sh --no-seed           # skip `db:seed --force`
#   ./deploy.sh --no-services       # don't (re)install/restart systemd units
#   ./deploy.sh --no-migrate        # skip `migrate --force`
#
# Every step is env-overridable (see the Configuration block). Safe to run
# repeatedly — migrations, seeders and unit installs are all idempotent.

set -Eeuo pipefail

# ── Configuration ────────────────────────────────────────────────────────────
APP_DIR="${APP_DIR:-/var/www/ticket-management-system}"
BRANCH="${BRANCH:-main}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"
# Left blank = autodetect (php8.4-fpm, php8.3-fpm, …). Set explicitly to skip detection.
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-}"
# Hit after the app comes back up; deploy fails if this doesn't return 200.
# Empty = skip the check. /up is Laravel's built-in health endpoint (bootstrap/app.php).
HEALTHCHECK_URL="${HEALTHCHECK_URL:-http://127.0.0.1/up}"
# How long `artisan down` tells clients to wait before retrying.
DOWN_RETRY="${DOWN_RETRY:-60}"

RUN_BUILD=true
RUN_SEED=true
RUN_SERVICES=true
RUN_MIGRATE=true

for arg in "$@"; do
    case "$arg" in
        --no-build)    RUN_BUILD=false ;;
        --no-seed)     RUN_SEED=false ;;
        --no-services) RUN_SERVICES=false ;;
        --no-migrate)  RUN_MIGRATE=false ;;
        --branch=*)    BRANCH="${arg#*=}" ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

# ── Helpers ─────────────────────────────────────────────────────────────────
step()   { printf '\n\033[1;36m▸ %s\033[0m\n' "$*"; }
info()   { printf '  %s\n' "$*"; }
have()   { command -v "$1" >/dev/null 2>&1; }
artisan() { "$PHP_BIN" artisan "$@"; }

detect_php_fpm() {
    [[ -n "$PHP_FPM_SERVICE" ]] && { echo "$PHP_FPM_SERVICE"; return; }
    have systemctl || return 0
    local v candidate
    v="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
    for candidate in "php${v}-fpm" php8.4-fpm php8.3-fpm php-fpm; do
        if systemctl list-unit-files "${candidate}.service" >/dev/null 2>&1 \
            && systemctl status "${candidate}" >/dev/null 2>&1; then
            echo "$candidate"
            return
        fi
    done
}

install_unit() { # install_unit <src-in-deploy/> ; returns 0 if a file was installed
    local name="$1"
    [[ -f "deploy/$name" ]] || return 1
    install -m 644 "deploy/$name" "/etc/systemd/system/$name"
}

# ── Preflight ───────────────────────────────────────────────────────────────
cd "$APP_DIR"

if [[ ! -f artisan || ! -f composer.json || ! -f .env ]]; then
    echo "Error: $APP_DIR is not a configured Laravel application (.env is required)." >&2
    exit 1
fi

if ! grep -qE '^APP_KEY=base64:.+' .env; then
    echo "Error: APP_KEY is not set in .env — run 'php artisan key:generate' once, then redeploy." >&2
    exit 1
fi

have git || { echo "Error: git is required." >&2; exit 1; }
have "$COMPOSER_BIN" || { echo "Error: composer ('$COMPOSER_BIN') not found on PATH." >&2; exit 1; }

if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
    echo "Error: working tree at $APP_DIR has uncommitted changes — refusing to deploy over them." >&2
    git status --short >&2
    exit 1
fi

if [[ "$RUN_BUILD" == true && -f package-lock.json ]]; then
    if ! have node || ! have npm; then
        echo "Error: Node.js and npm are required to build frontend assets (or pass --no-build)." >&2
        exit 1
    fi
    if ! node -e 'const [a,b]=process.versions.node.split(".").map(Number);process.exit(a>22||a===22&&b>=12||a===20&&b>=19?0:1)'; then
        echo "Error: Node.js 20.19+ or 22.12+ required (found $(node --version))." >&2
        exit 1
    fi
fi

PHP_FPM_SERVICE="$(detect_php_fpm)"

# ── Maintenance mode (restored on any exit) ─────────────────────────────────
maintenance_enabled=false
finish() {
    local code=$?
    if [[ "$maintenance_enabled" == true ]]; then
        artisan up || true
    fi
    if (( code != 0 )); then
        echo -e "\n\033[1;31mDeployment failed (exit $code). App has been brought back up.\033[0m" >&2
    fi
}
trap finish EXIT

echo -e "\033[1mDeploying '$BRANCH' to $APP_DIR\033[0m"
[[ -n "$PHP_FPM_SERVICE" ]] && info "PHP-FPM service: $PHP_FPM_SERVICE" || info "PHP-FPM service: (none detected — will skip reload)"

step "Enabling maintenance mode"
artisan down --retry="$DOWN_RETRY"
maintenance_enabled=true

# ── Pull code ──────────────────────────────────────────────────────────────
step "Fetching origin/$BRANCH"
git fetch --prune origin "$BRANCH"
git checkout "$BRANCH"
git merge --ff-only "origin/$BRANCH"
info "Now at $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"

# ── PHP dependencies ───────────────────────────────────────────────────────
step "Installing PHP dependencies (composer)"
COMPOSER_ALLOW_SUPERUSER=1 "$COMPOSER_BIN" install \
    --no-dev --no-interaction --prefer-dist --optimize-autoloader

# ── Frontend build ─────────────────────────────────────────────────────────
if [[ "$RUN_BUILD" == true && -f package-lock.json ]]; then
    step "Building frontend assets (npm ci + vite build)"
    npm ci --no-audit --no-fund
    npm run build
else
    step "Skipping frontend build"
fi

# ── Application ────────────────────────────────────────────────────────────
step "Clearing stale caches"
artisan optimize:clear
artisan filament:optimize-clear

if [[ "$RUN_MIGRATE" == true ]]; then
    step "Running database migrations"
    artisan migrate --force --no-interaction
else
    step "Skipping migrations"
fi

if [[ "$RUN_SEED" == true ]]; then
    step "Seeding (roles/permissions, default admin — idempotent)"
    artisan db:seed --force --no-interaction
else
    step "Skipping seed"
fi

step "Linking storage & priming caches"
artisan storage:link
artisan optimize                 # config + route + view + event cache
artisan filament:optimize        # Filament component + blade-icon cache
artisan queue:restart            # signal running workers to pick up new code

# ── Filesystem permissions ────────────────────────────────────────────────
step "Fixing permissions on storage/ and bootstrap/cache/"
if id "$WEB_USER" >/dev/null 2>&1; then
    chown -R "$WEB_USER:$WEB_GROUP" storage bootstrap/cache
    chmod -R ug+rwX storage bootstrap/cache
else
    echo "Error: web-server user '$WEB_USER' does not exist." >&2
    exit 1
fi

# ── Background services (systemd) ─────────────────────────────────────────
if [[ "$RUN_SERVICES" == true ]] && have systemctl; then
    step "Installing & restarting systemd units"

    # Horizon-supervised queue workers. `systemctl restart` runs the unit's
    # ExecStop (horizon:terminate) so in-flight jobs finish, then starts a
    # fresh master on the new code — queue:restart above alone can't do that.
    if install_unit ticket-management-system-queue-worker.service; then
        systemctl daemon-reload
        systemctl enable ticket-management-system-queue-worker
        systemctl restart ticket-management-system-queue-worker
        info "queue worker (horizon): restarted"
    fi

    # Drives routes/console.php's Schedule::command() entries.
    if install_unit ticket-management-system-scheduler.service \
        && install_unit ticket-management-system-scheduler.timer; then
        systemctl daemon-reload
        systemctl enable --now ticket-management-system-scheduler.timer
        info "scheduler timer: enabled"
    fi

    # Nightly DB backup (docs/ROADMAP.md, Phase 10). Needs mysqldump on PATH.
    if install_unit ticket-management-system-backup.service \
        && install_unit ticket-management-system-backup.timer; then
        systemctl daemon-reload
        systemctl enable --now ticket-management-system-backup.timer
        info "backup timer: enabled"
    fi
else
    step "Skipping systemd unit management"
fi

# ── Web tier ─────────────────────────────────────────────────────────────
if have systemctl; then
    if [[ -n "$PHP_FPM_SERVICE" ]]; then
        step "Reloading $PHP_FPM_SERVICE (drops stale opcache)"
        systemctl reload "$PHP_FPM_SERVICE" || systemctl restart "$PHP_FPM_SERVICE"
    fi

    if have nginx; then
        step "Testing & reloading nginx"
        nginx -t
        systemctl reload nginx || systemctl restart nginx
    fi
fi

# ── Back online ────────────────────────────────────────────────────────────
step "Disabling maintenance mode"
artisan up
maintenance_enabled=false

# ── Health check ──────────────────────────────────────────────────────────
if [[ -n "$HEALTHCHECK_URL" ]] && have curl; then
    step "Health check: $HEALTHCHECK_URL"
    for attempt in 1 2 3 4 5; do
        code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 "$HEALTHCHECK_URL" || true)"
        if [[ "$code" == "200" ]]; then
            info "OK (200)"
            break
        fi
        if (( attempt == 5 )); then
            echo "Error: health check failed after 5 attempts (last status: ${code:-no response})." >&2
            exit 1
        fi
        info "attempt $attempt: ${code:-no response} — retrying in 3s"
        sleep 3
    done
fi

step "Deployment summary"
artisan about --only=environment,cache,drivers || true

echo -e "\n\033[1;32m✓ Deployment completed successfully — $(git rev-parse --short HEAD)\033[0m"
