#!/usr/bin/env bash
#
# Diwan ERP - updates the Linux install to the latest release.
#
#   sudo bash /opt/diwan/current/deploy/linux/update.sh
#
# Runs daily (diwan-update.timer). Prepares the new release next to the running
# one, backs up the database when it changes, then switches over; if the system
# does not answer afterwards it switches back by itself.

set -Eeuo pipefail

REPO=turkialawlqy/Diwan-ERP
PACKAGE_URL=${DIWAN_PACKAGE:-https://github.com/$REPO/releases/latest/download/diwan-erp.zip}
DIR=${DIWAN_DIR:-/opt/diwan}
APP_USER=diwan
KEEP=3

c_ok=$'\e[32m'; c_err=$'\e[31m'; c_off=$'\e[0m'
ok()  { echo "${c_ok}✓ $*${c_off}"; }
STATUS="$DIR/shared/storage/app/update-status.json"
# What the dashboard shows the owner (state: running | done | failed | uptodate).
status() {
    { printf '{"state":"%s","from":"%s","to":"%s","message":"%s","at":%s}\n' "$1" "${INSTALLED:-}" "${VERSION:-}" "${2:-}" "$(date +%s)" > "$STATUS.tmp" \
        && mv -f "$STATUS.tmp" "$STATUS" && chmod 644 "$STATUS"; } 2> /dev/null || true
}
die() { echo "${c_err}✗ $*${c_off}" >&2; status failed "$*"; exit 1; }
as_app() { sudo -u "$APP_USER" -H "$@"; }

[[ $EUID -eq 0 ]] || die "Run it with sudo."
exec 9> /run/diwan-update.lock
flock -n 9 || die "Another update is already running."

# The dashboard asks for an update by dropping a file in this folder (watched by diwan-update.path).
rm -f "$DIR"/shared/storage/app/update-queue/* 2> /dev/null || true
if [[ -d /run/systemd/system && ! -f /etc/systemd/system/diwan-update.path ]]; then
    cat > /etc/systemd/system/diwan-update.path <<UNIT
[Unit]
Description=Diwan ERP update requested from the dashboard
[Path]
PathExistsGlob=$DIR/shared/storage/app/update-queue/*
Unit=diwan-update.service
[Install]
WantedBy=multi-user.target
UNIT
    mkdir -p "$DIR/shared/storage/app/update-queue" && chown "$APP_USER:$APP_USER" "$DIR/shared/storage/app/update-queue"
    systemctl daemon-reload && systemctl enable --now diwan-update.path > /dev/null 2>&1 || true
fi

# Queue workers set up by earlier installers served only the "default" queue: tax-authority
# submissions (zatca), webhooks and data exports waited in the jobs table for good.
QUEUE_ARG=--queue=zatca,default,webhooks,exports
refresh_queue_unit() {
    local unit=/etc/systemd/system/diwan-queue.service
    [[ -d /run/systemd/system && -f $unit ]] || return 0
    if grep -qE -- "^ExecStart=.* $QUEUE_ARG( |$)" "$unit"; then return 0; fi
    sed -E -e '/^ExecStart=/s/ --queue=[^ ]*//' -e "/^ExecStart=/s/ queue:work( database)?/& $QUEUE_ARG/" "$unit" > "$unit.tmp" || return 1
    if ! grep -qE -- "^ExecStart=.* $QUEUE_ARG( |$)" "$unit.tmp"; then
        rm -f "$unit.tmp"
        return 1
    fi
    mv -f "$unit.tmp" "$unit" || return 1
    systemctl daemon-reload || return 1
    systemctl restart diwan-queue || return 1
    ok "The queue service now serves every queue."
}
refresh_queue_unit || echo "${c_err}✗ Could not set up the queue service (diwan-queue) again.${c_off}" >&2

CURRENT=$(readlink -f "$DIR/current")
INSTALLED=$(cat "$CURRENT/VERSION" 2> /dev/null || echo 0)

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
if [[ -f $PACKAGE_URL ]]; then
    cp "$PACKAGE_URL" "$TMP/diwan-erp.zip"
else
    curl -fsSL -o "$TMP/diwan-erp.zip" "$PACKAGE_URL" || die "Could not download the update (internet connection?)."
    if curl -fsSL -o "$TMP/sha" "$PACKAGE_URL.sha256" 2> /dev/null; then
        [[ $(sha256sum "$TMP/diwan-erp.zip" | cut -d' ' -f1) == "$(tr -d '[:space:]' < "$TMP/sha")" ]] || die "The downloaded update is corrupted."
    fi
fi
status running "Downloading"
unzip -q "$TMP/diwan-erp.zip" -d "$TMP"
VERSION=$(cat "$TMP/diwan-erp/VERSION" 2> /dev/null || echo 0)
if [[ $VERSION == "$INSTALLED" && ${1:-} != --force ]]; then
    ok "Diwan is up to date (version $INSTALLED)."
    status uptodate "Up to date"
    exit 0
fi

status running "Installing"
NEW="$DIR/releases/$(date +%Y%m%d%H%M%S)-$VERSION"
mv "$TMP/diwan-erp" "$NEW"
rm -rf "$NEW/storage"
ln -sfn "$DIR/shared/storage" "$NEW/storage"
ln -sfn "$DIR/shared/.env" "$NEW/.env"
ln -sfn ../storage/app/public "$NEW/public/storage"
chown -R "$APP_USER:$APP_USER" "$NEW"

# Anything failing before the switch leaves the running version untouched.
trap 'rm -rf "$TMP" "$NEW"; status failed "Update stopped; the previous version keeps running"; echo "${c_err}✗ Update stopped; Diwan keeps running the previous version, unchanged.${c_off}" >&2' ERR
as_app php "$NEW/artisan" optimize > /dev/null
if as_app php "$NEW/artisan" migrate:status --pending 2> /dev/null | grep -q Pending; then
    as_app php "$NEW/artisan" erp:backup --tag=preupdate > /dev/null || die "The backup failed, so the update was cancelled."
    as_app php "$NEW/artisan" migrate --force > /dev/null
fi
trap 'rm -rf "$TMP"' ERR

restart() {
    if [[ -d /run/systemd/system ]]; then
        systemctl restart diwan-web diwan-queue diwan-scheduler 2> /dev/null || true
    fi
}
ln -sfn "$NEW" "$DIR/current.next" && mv -Tf "$DIR/current.next" "$DIR/current"
restart

# The port the web service really listens on (its systemd unit), else the one in APP_URL.
PORT=8000
port_re='--port=([0-9]+)'
url_re=':([0-9]+)/?"?$'
if [[ $(cat /etc/systemd/system/diwan-web.service 2> /dev/null) =~ $port_re ]]; then
    PORT=${BASH_REMATCH[1]}
elif [[ $(grep -E '^APP_URL=' "$DIR/shared/.env") =~ $url_re ]]; then
    PORT=${BASH_REMATCH[1]}
fi
healthy=0
for _ in 1 2 3 4 5 6 7 8 9 10; do
    sleep 2
    curl -fs -o /dev/null --max-time 10 "http://127.0.0.1:$PORT/up" && { healthy=1; break; }
done
if [[ $healthy != 1 && -d /run/systemd/system ]]; then
    ln -sfn "$CURRENT" "$DIR/current.next" && mv -Tf "$DIR/current.next" "$DIR/current"
    restart
    rm -rf "$NEW"
    die "Version $VERSION did not start; switched back to $INSTALLED automatically."
fi

# Keep the last $KEEP releases.
ls -1d "$DIR/releases"/*/ | sed 's#/$##' | sort -r | tail -n +$((KEEP + 1)) | while read -r old; do
    [[ $old == "$(readlink -f "$DIR/current")" ]] || rm -rf "$old"
done
status done "Updated"
ok "Updated from $INSTALLED to $VERSION."
