#!/usr/bin/env bash
#
# Diwan ERP - internet access for a Linux install (Cloudflare Tunnel or Tailscale).
#
# Run by systemd (diwan-remote-access.path) when the dashboard drops a request in
#   $DIR/shared/storage/app/remote-access-queue/request   (JSON: mode, domain, secret)
# Installs and starts the connector, tunes .env (HTTPS behind the tunnel, trusted proxy),
# restarts the services, deletes the request (it holds a secret) and writes
#   $DIR/shared/storage/app/remote-access-status.json
#
# Options (environment): DIWAN_DIR=/opt/diwan  DIWAN_PORT=8000
#   DIWAN_DRY_RUN=1  do not install, start or restart anything; commands are listed in DIWAN_DRY_LOG (used by the tests)

set -Eeuo pipefail

DIR=${DIWAN_DIR:-/opt/diwan}
APP_USER=${DIWAN_APP_USER:-diwan}
DRY=${DIWAN_DRY_RUN:-0}
ENV_FILE=$DIR/shared/.env
QUEUE=$DIR/shared/storage/app/remote-access-queue
STATUS=$DIR/shared/storage/app/remote-access-status.json
REQUEST=$QUEUE/request

MODE=none; DOMAIN=; SECRET=; URL=; IP=

# What the dashboard shows (state: running | done | failed). Values never contain quotes: the app validates them.
status() {
    { printf '{"state":"%s","mode":"%s","domain":"%s","url":"%s","ip":"%s","message":"%s","at":%s}\n' \
        "$1" "$MODE" "$DOMAIN" "$URL" "$IP" "${2:-}" "$(date +%s)" > "$STATUS.tmp" \
        && mv -f "$STATUS.tmp" "$STATUS" && chmod 644 "$STATUS"; } 2> /dev/null || true
}
wipe() { [[ -f $REQUEST ]] && { shred -u "$REQUEST" 2> /dev/null || rm -f "$REQUEST"; }; return 0; }
die() { echo "x $*" >&2; wipe; status failed "$*"; exit 1; }
# Nothing is installed or restarted in a dry run.
run() { if [[ $DRY == 1 ]]; then echo "[dry-run] $*" >> "${DIWAN_DRY_LOG:-/dev/null}"; else "$@"; fi; }
trap 'wipe; status failed "Stopped at line $LINENO (see: journalctl -u diwan-remote-access)"' ERR

[[ $DRY == 1 || $EUID -eq 0 ]] || die "Run it as root."
[[ -f $REQUEST ]] || exit 0
exec 9> "${DIWAN_LOCK:-/run/diwan-remote-access.lock}"
flock -n 9 || exit 0

# The request is JSON written by the app; read it with PHP (always present) without a shell eval.
read_field() { php -r '$j = json_decode(file_get_contents($argv[1]), true); echo is_array($j) ? ($j[$argv[2]] ?? "") : "";' "$REQUEST" "$1"; }
MODE=$(read_field mode); DOMAIN=$(read_field domain); SECRET=$(read_field secret)
status running "Applying"

# Whatever came through, only these characters are ever used below.
[[ $MODE =~ ^(none|cloudflare|tailscale)$ ]] || die "Unknown mode."
[[ -z $DOMAIN || $DOMAIN =~ ^[a-z0-9.-]{4,253}$ ]] || die "Invalid domain."
[[ -z $SECRET || $SECRET =~ ^[A-Za-z0-9_=.-]{10,2000}$ ]] || die "Invalid token."
[[ -f $ENV_FILE ]] || die "Settings file not found: $ENV_FILE"

# set_env KEY VALUE: replace the line or add it.
set_env() {
    if grep -q "^$1=" "$ENV_FILE"; then
        local tmp; tmp=$(mktemp)
        awk -v k="$1" -v v="$2" 'BEGIN { FS = OFS = "=" } $1 == k { print k "=" v; next } { print }' "$ENV_FILE" > "$tmp"
        cat "$tmp" > "$ENV_FILE"; rm -f "$tmp"
    else
        printf '%s=%s\n' "$1" "$2" >> "$ENV_FILE"
    fi
}
del_env() { local tmp; tmp=$(mktemp); grep -v "^$1=" "$ENV_FILE" > "$tmp" || true; cat "$tmp" > "$ENV_FILE"; rm -f "$tmp"; }

PORT=${DIWAN_PORT:-}
if [[ -z $PORT ]]; then
    PORT=$(grep -oE -- '--port=[0-9]+' /etc/systemd/system/diwan-web.service 2> /dev/null | head -1 | cut -d= -f2 || true)
    PORT=${PORT:-8000}
fi
WEB_UNIT=${DIWAN_WEB_UNIT:-/etc/systemd/system/diwan-web.service}
# Which address the web service listens on: only this computer behind a tunnel, the network otherwise.
bind_to() { [[ -f $WEB_UNIT ]] && sed -i -E "s#--host=[0-9.]+#--host=$1#" "$WEB_UNIT" || true; }

stop_connectors() {
    if command -v cloudflared > /dev/null 2>&1; then run cloudflared service uninstall > /dev/null 2>&1 || true; fi
    if command -v tailscale > /dev/null 2>&1; then run tailscale down > /dev/null 2>&1 || true; fi
}

lan_env() { # back to the office-network setup written by the installer
    set_env APP_URL "http://localhost:$PORT"; set_env APP_DOMAIN localhost
    set_env FORCE_HTTPS false; set_env SESSION_SECURE_COOKIE false; del_env TRUSTED_PROXIES
    bind_to 0.0.0.0
}

case $MODE in
none)
    stop_connectors
    lan_env
    URL=
    ;;
cloudflare)
    [[ -n $DOMAIN && -n $SECRET ]] || die "Domain and tunnel token are required."
    if ! command -v cloudflared > /dev/null 2>&1 && [[ $DRY != 1 ]]; then
        mkdir -p /usr/share/keyrings
        curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg -o /usr/share/keyrings/cloudflare-main.gpg || die "Could not download the Cloudflare package key (internet connection?)."
        echo "deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main" > /etc/apt/sources.list.d/cloudflared.list
        apt-get update -qq && apt-get install -y -qq cloudflared > /dev/null || die "Could not install cloudflared."
    fi
    run cloudflared service uninstall > /dev/null 2>&1 || true
    run cloudflared service install "$SECRET" > /dev/null || die "cloudflared did not accept the token."
    # Behind the tunnel the app is HTTPS to the outside; only this computer may reach the local port.
    set_env APP_URL "https://$DOMAIN"; set_env APP_DOMAIN "$DOMAIN"
    set_env FORCE_HTTPS true; set_env SESSION_SECURE_COOKIE true; set_env TRUSTED_PROXIES 127.0.0.1
    bind_to 127.0.0.1
    URL=https://$DOMAIN
    ;;
tailscale)
    [[ -n $SECRET ]] || die "Auth key is required."
    if ! command -v tailscale > /dev/null 2>&1 && [[ $DRY != 1 ]]; then
        curl -fsSL https://tailscale.com/install.sh | sh > /dev/null || die "Could not install Tailscale (internet connection?)."
    fi
    run tailscale up --authkey="$SECRET" --accept-dns=false > /dev/null || die "Tailscale did not accept the key."
    if [[ $DRY != 1 ]]; then IP=$(tailscale ip -4 2> /dev/null | head -1 || true); else IP=100.64.0.1; fi
    # Traffic inside Tailscale is encrypted already: keep plain HTTP on the private address, no public link.
    lan_env
    if command -v ufw > /dev/null 2>&1 && ufw status 2> /dev/null | grep -q 'Status: active'; then
        run ufw allow in on tailscale0 to any port "$PORT" proto tcp > /dev/null || true
    fi
    [[ -n $IP ]] && URL=http://$IP:$PORT
    ;;
esac

if [[ $DRY != 1 ]]; then
    # Rebuild the cached settings and restart so the new .env and listening address take effect.
    ( cd "$DIR/current" && sudo -u "$APP_USER" -H php artisan optimize > /dev/null )
    if [[ -d /run/systemd/system ]]; then
        systemctl daemon-reload
        systemctl restart diwan-web diwan-queue diwan-scheduler
    fi
fi

wipe
trap - ERR
status done "OK"
echo "Internet access: $MODE ${URL:+($URL)}"
