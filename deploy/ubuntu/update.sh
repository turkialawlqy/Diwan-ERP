#!/usr/bin/env bash
#
# Diwan ERP - updates the server to the latest version without stopping the system.
#
#   sudo bash /var/www/diwan/deploy/ubuntu/update.sh              update
#   sudo bash /var/www/diwan/deploy/ubuntu/update.sh --rollback   back to the previous release
#   sudo bash /var/www/diwan/deploy/ubuntu/update.sh --adopt      only convert the install to releases
#
# Each update is prepared in a new release folder next to the running one (code,
# libraries, interface build, caches) while users keep working. When everything
# is ready, /var/www/diwan is switched to the new release in one instant step,
# then PHP and the background processes reload gracefully. If anything fails
# before the switch, the running release stays as it is; if the system does not
# answer after the switch, it goes back to the previous release by itself.
#
#   /var/www/diwan            -> link to the running release
#   /var/www/diwan-releases/  releases (only the running one is kept)
#   /var/www/diwan-shared/    what releases share: .env and storage

set -Eeuo pipefail

APP_DIR=/var/www/diwan
RELEASES=/var/www/diwan-releases
SHARED=/var/www/diwan-shared
KEEP=1  # only the running release: older ones are deleted after a successful update
APP_USER=www-data
APP_HOME=/var/www

c_info=$'\e[36m'; c_ok=$'\e[32m'; c_warn=$'\e[33m'; c_err=$'\e[31m'; c_off=$'\e[0m'
step() { echo; echo "${c_info}==> $*${c_off}"; }
ok()   { echo "${c_ok}✓ $*${c_off}"; }
warn() { echo "${c_warn}! $*${c_off}"; }
die()  { echo "${c_err}✗ $*${c_off}" >&2; exit 1; }
as_app() { sudo -u "$APP_USER" -H env HOME="$APP_HOME" COMPOSER_HOME="$APP_HOME/.composer" "$@"; }
# Heavy build steps run at low priority so the live site stays responsive meanwhile.
as_app_low() { as_app nice -n 15 ionice -c3 "$@"; }
# PhpSpreadsheet 1.x (Excel import) declares PHP < 8.5; every other package supports 8.5.
composer_flags() {
    if php -r 'exit(PHP_VERSION_ID >= 80500 ? 0 : 1);' 2>/dev/null; then
        echo "--ignore-platform-req=php"
    fi
}
trap 'echo "${c_err}✗ Update stopped at line $LINENO. The system still runs the previous release, unchanged.${c_off}" >&2' ERR

[[ $EUID -eq 0 ]] || die "Run it as root: sudo bash update.sh"
exec 9> /run/diwan-update.lock
flock -n 9 || die "Another update is already running."

PHP_V=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')

# Points $APP_DIR at a release in one atomic rename, then reloads code gracefully:
# PHP-FPM finishes in-flight requests on the old code; queue workers finish their
# current job, then supervisor starts them again on the new release.
switch_to() {
    local release=$1
    ln -sfn "$release" "$APP_DIR.next"
    mv -Tf "$APP_DIR.next" "$APP_DIR"
    systemctl reload "php$PHP_V-fpm"
    as_app php "$APP_DIR/artisan" queue:restart > /dev/null || true
    supervisorctl restart diwan:diwan-scheduler > /dev/null || true
}

# The health URL of the running site, through the local web server.
healthy() {
    local domain
    domain=$(grep -E '^APP_DOMAIN=' "$SHARED/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d "\"'" || true)
    [[ -n $domain ]] || return 0
    for _ in 1 2 3 4 5; do
        if curl -fsSL -k -o /dev/null --max-time 15 --resolve "$domain:80:127.0.0.1" --resolve "$domain:443:127.0.0.1" "http://$domain/up"; then
            return 0
        fi
        sleep 2
    done
    return 1
}

# One-time move from a plain checkout at $APP_DIR to releases + a symlink.
adopt() {
    [[ -L $APP_DIR ]] && return 0
    [[ -d $APP_DIR/.git ]] || die "$APP_DIR is not a Diwan install."
    step "Converting the install to releases (one time)"
    mkdir -p "$RELEASES" "$SHARED"
    chown "$APP_USER:$APP_USER" "$RELEASES" "$SHARED"
    if [[ ! -L $APP_DIR/storage ]]; then
        mv "$APP_DIR/storage" "$SHARED/storage"
        ln -s "$SHARED/storage" "$APP_DIR/storage"
    fi
    if [[ -f $APP_DIR/.env && ! -L $APP_DIR/.env ]]; then
        mv "$APP_DIR/.env" "$SHARED/.env"
        ln -s "$SHARED/.env" "$APP_DIR/.env"
    fi
    chown -h "$APP_USER:$APP_USER" "$APP_DIR/storage"
    [[ -L $APP_DIR/.env ]] && chown -h "$APP_USER:$APP_USER" "$APP_DIR/.env"
    local first
    first="$RELEASES/$(date +%Y%m%d%H%M%S)-$(as_app git -C "$APP_DIR" rev-parse --short HEAD)"
    mv "$APP_DIR" "$first"
    ln -s "$first" "$APP_DIR"
    # Paths cached by the old location are rebuilt for the release's own path.
    as_app php "$first/artisan" optimize:clear > /dev/null
    as_app php "$first/artisan" optimize > /dev/null
    switch_to "$first"
    ok "Current release: $first"
}

prune() {
    local current
    current=$(readlink -f "$APP_DIR")
    { ls -1d "$RELEASES"/*/ 2>/dev/null || true; } | sed 's#/$##' | sort -r | tail -n +$((KEEP + 1)) | while read -r old; do
        [[ $old == "$current" ]] || rm -rf "$old"
    done
}

case ${1:-} in
    --adopt)
        adopt
        exit 0
        ;;
    --rollback)
        adopt
        CURRENT=$(readlink -f "$APP_DIR")
        # The newest release older than the running one (release names start with their time).
        PREVIOUS=$({ ls -1d "$RELEASES"/*/ 2>/dev/null || true; } | sed 's#/$##' | sort | awk -v c="$CURRENT" '$0 < c' | tail -1)
        [[ -n $PREVIOUS ]] || die "There is no previous release to go back to."
        switch_to "$PREVIOUS"
        ok "Back on: $(basename "$PREVIOUS") (the database was not changed)"
        exit 0
        ;;
    "") ;;
    *) die "Unknown option: $1" ;;
esac

adopt
CURRENT=$(readlink -f "$APP_DIR")
BRANCH=$(as_app git -C "$CURRENT" rev-parse --abbrev-ref HEAD)

step "Checking for updates on $BRANCH"
as_app git -C "$CURRENT" fetch -q origin "$BRANCH"
BEFORE=$(as_app git -C "$CURRENT" rev-parse HEAD)
AFTER=$(as_app git -C "$CURRENT" rev-parse "origin/$BRANCH")
if [[ $BEFORE == "$AFTER" ]]; then
    ok "No new updates."
    exit 0
fi

step "Preparing the new release (the system keeps running meanwhile)"
NEW="$RELEASES/$(date +%Y%m%d%H%M%S)-$(as_app git -C "$CURRENT" rev-parse --short "$AFTER")"
# A failed preparation leaves no half-built release behind.
trap 'rm -rf "$NEW"; echo "${c_err}✗ Update stopped at line $LINENO. The system still runs the previous release, unchanged.${c_off}" >&2' ERR
# A copy of the running release keeps its libraries and front-end build: only what changed is rebuilt.
as_app cp -a "$CURRENT" "$NEW"
as_app git -C "$NEW" reset -q --hard "$AFTER"
as_app git -C "$NEW" clean -qfd -e storage -e .env -e vendor -e public/build
ok "$(as_app git -C "$NEW" log --oneline -1)"
# Each release links its own public/storage to the shared files (not to an older release).
as_app ln -sfn ../storage/app/public "$NEW/public/storage"

if ! cmp -s "$CURRENT/composer.lock" "$NEW/composer.lock" || ! cmp -s "$CURRENT/composer.json" "$NEW/composer.json"; then
    step "Updating PHP libraries"
    mapfile -t COMPOSER_FLAGS < <(composer_flags)
    as_app_low composer install --working-dir="$NEW" --no-dev --optimize-autoloader --no-interaction "${COMPOSER_FLAGS[@]}"
else
    as_app composer dump-autoload --working-dir="$NEW" --optimize --no-dev --no-interaction -q
fi

BUILD_STAMP=$NEW/public/build/.source-hash
BUILD_HASH=$(as_app git -C "$NEW" ls-tree -r HEAD -- resources/js resources/css package.json package-lock.json vite.config.js tailwind.config.js postcss.config.js | sha256sum | cut -d' ' -f1)
if [[ -f $NEW/public/build/manifest.json && -f $BUILD_STAMP && $(cat "$BUILD_STAMP") == "$BUILD_HASH" ]]; then
    ok "The interface did not change; skipping the build."
else
    step "Building the interface (several minutes; the system keeps running)"
    swapon --show | grep -q . || swapon /swapfile 2>/dev/null || true
    as_app_low env NODE_OPTIONS=--max-old-space-size=1536 sh -c "cd '$NEW' && npm ci --no-audit --no-fund --loglevel=error && npm run build && rm -rf node_modules"
    as_app sh -c "echo $BUILD_HASH > '$BUILD_STAMP'"
    # Browsers still on the old pages may lazy-load old chunks: keep them next to the new ones.
    [[ -d $CURRENT/public/build/assets ]] && as_app cp -an "$CURRENT/public/build/assets/." "$NEW/public/build/assets/" 2>/dev/null || true
fi

step "Preparing the new release"
as_app php "$NEW/artisan" optimize:clear > /dev/null
as_app php "$NEW/artisan" optimize

if as_app php "$NEW/artisan" migrate:status --pending 2>/dev/null | grep -q Pending; then
    step "Backup, then database migrations"
    if ! as_app php "$NEW/artisan" erp:backup --tag=preupdate; then
        if [[ ${DIWAN_SKIP_BACKUP:-} != 1 ]]; then
            rm -rf "$NEW"
            die "The backup failed, so the update was cancelled; the system runs the previous release. (To continue without it: DIWAN_SKIP_BACKUP=1)"
        fi
        warn "Continuing without a backup (DIWAN_SKIP_BACKUP=1)."
    fi
    as_app php "$NEW/artisan" migrate --force
fi
trap 'echo "${c_err}✗ Update stopped at line $LINENO.${c_off}" >&2' ERR

step "Switching to the new release"
switch_to "$NEW"
if healthy; then
    ok "Updated with no downtime: $(as_app git -C "$NEW" log --oneline -1 --format='%h %s')"
else
    warn "The system did not answer after the switch; going back to the previous release automatically."
    switch_to "$CURRENT"
    rm -rf "$NEW"
    die "The previous release is back. See: tail -n 50 $SHARED/storage/logs/laravel-$(date +%F).log"
fi
prune
