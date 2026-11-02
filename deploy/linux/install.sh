#!/usr/bin/env bash
#
# Diwan ERP - install on a Linux computer (Ubuntu / Debian / Mint) for office use.
# (Messages are English: terminals do not render Arabic well.)
#
# Paste this line in the Terminal and press Enter:
#
#   sudo bash -c "$(curl -fsSL https://github.com/turkialawlqy/Diwan-ERP/releases/latest/download/install-linux.sh)"
#
# Installs what the system needs (PHP and a MySQL database), downloads the latest
# ready-built release and runs it as a service that starts with the computer, on
# http://localhost:8000 and from office devices on this computer's network address.
# Safe to run again.
#
# Options (environment variables): DIWAN_PORT=8000  DIWAN_DIR=/opt/diwan
#   DIWAN_PACKAGE=path-or-url-of-the-zip   DIWAN_NO_SERVICES=1 (no services, for testing)

set -Eeuo pipefail

REPO=turkialawlqy/Diwan-ERP
PACKAGE_URL=${DIWAN_PACKAGE:-https://github.com/$REPO/releases/latest/download/diwan-erp.zip}
DIR=${DIWAN_DIR:-/opt/diwan}
PORT=${DIWAN_PORT:-8000}
APP_USER=diwan
NO_SERVICES=${DIWAN_NO_SERVICES:-0}

c_info=$'\e[36m'; c_ok=$'\e[32m'; c_warn=$'\e[33m'; c_err=$'\e[31m'; c_off=$'\e[0m'
step() { echo; echo "${c_info}==> $*${c_off}"; }
ok()   { echo "${c_ok}✓ $*${c_off}"; }
warn() { echo "${c_warn}! $*${c_off}"; }
die()  { echo "${c_err}✗ $*${c_off}" >&2; exit 1; }
trap 'echo "${c_err}✗ Setup stopped at line $LINENO. Take a screenshot of the messages above to ask for help, then run the same command again (it is safe to repeat).${c_off}" >&2' ERR
as_app() { sudo -u "$APP_USER" -H "$@"; }

# Ubuntu's Software Updater (aptd) or unattended-upgrades may hold the package
# manager: wait for it instead of stopping (up to 15 minutes), then retry.
APT_LOCKS=(/var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock /var/cache/apt/archives/lock)
apt_busy() {
    local f
    for f in "${APT_LOCKS[@]}"; do
        [[ -e $f ]] || continue
        flock -n "$f" true 2> /dev/null || return 0
    done
    return 1
}
apt_wait() {
    local waited=0
    apt_busy || return 0
    echo "${c_warn}! Another program is updating the system (Software Updater). Waiting for it to finish...${c_off}"
    while apt_busy; do
        if (( waited >= 900 )); then
            echo
            die "The system is still busy updating after 15 minutes. Close Software Updater (or restart the computer), then run the same command again."
        fi
        sleep 10; waited=$((waited + 10)); printf '.'
    done
    echo
}
apt_get() {
    local try
    for try in 1 2 3; do
        apt_wait
        if apt-get -o DPkg::Lock::Timeout=600 "$@"; then return 0; fi
        (( try < 3 )) && sleep 5
    done
    return 1
}

[[ $EUID -eq 0 ]] || die "Run the command with sudo, exactly as in the guide."
command -v apt-get > /dev/null || die "This installer is for Ubuntu, Debian and Mint. For other systems see docs/INSTALL-LINUX.md"
export DEBIAN_FRONTEND=noninteractive

echo "${c_info}══════════════════════════════════════════════"
echo "  Diwan ERP setup"
echo "  Takes 5-15 minutes depending on your internet speed"
echo "══════════════════════════════════════════════${c_off}"

# ------------------------------------------------------------------ 1. packages
step "1/6 Installing PHP and the database"
apt_get update -qq
apt_get install -y -qq curl unzip ca-certificates lsb-release gnupg > /dev/null
php_ok() { command -v php > /dev/null && php -r 'exit(PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80500 ? 0 : 1);'; }
# (Read the whole output first: "grep -q" closing the pipe early would fail it under pipefail.)
POLICY=$(apt-cache policy php8.3-cli 2> /dev/null || true)
PHPV=$([[ $POLICY == *"Candidate: "[0-9]* ]] && echo 8.3 || true)
if [[ -z $PHPV ]]; then
    # Older Ubuntu / Debian: PHP 8.3 from the well-known Ondřej Surý repository.
    . /etc/os-release
    if [[ ${ID:-} == ubuntu || ${ID_LIKE:-} == *ubuntu* ]]; then
        apt_get install -y -qq software-properties-common > /dev/null
        apt_wait
        add-apt-repository -y ppa:ondrej/php > /dev/null
    else
        curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg
        echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/sury-php.list
    fi
    apt_get update -qq
    PHPV=8.3
fi
apt_get install -y -qq "php$PHPV-cli" "php$PHPV-mysql" "php$PHPV-mbstring" "php$PHPV-intl" "php$PHPV-xml" "php$PHPV-zip" \
    "php$PHPV-bcmath" "php$PHPV-gd" "php$PHPV-curl" default-mysql-server default-mysql-client > /dev/null
php_ok || die "Could not install PHP 8.2 - 8.4."
ok "PHP $(php -r 'echo PHP_VERSION;') and the database are ready."

# The database server runs on start-up (no systemd in some containers: start it directly).
if [[ $NO_SERVICES != 1 ]] && command -v systemctl > /dev/null && [[ -d /run/systemd/system ]]; then
    systemctl enable --now mysql 2> /dev/null || systemctl enable --now mariadb
else
    service mysql start > /dev/null 2>&1 || service mariadb start > /dev/null 2>&1 || true
fi

# ------------------------------------------------------------------ 2. the system
step "2/6 Downloading the latest Diwan release"
id "$APP_USER" > /dev/null 2>&1 || useradd --system --home-dir "$DIR" --shell /usr/sbin/nologin "$APP_USER"
mkdir -p "$DIR/releases" "$DIR/shared"
TMP=$(mktemp -d)
if [[ -f $PACKAGE_URL ]]; then
    cp "$PACKAGE_URL" "$TMP/diwan-erp.zip"
else
    curl -fL --progress-bar -o "$TMP/diwan-erp.zip" "$PACKAGE_URL" \
        || die "Could not download Diwan. Check the internet connection (or that a release is published on the project's Releases page)."
    if curl -fsSL -o "$TMP/diwan-erp.zip.sha256" "$PACKAGE_URL.sha256" 2> /dev/null; then
        [[ $(sha256sum "$TMP/diwan-erp.zip" | cut -d' ' -f1) == "$(tr -d '[:space:]' < "$TMP/diwan-erp.zip.sha256")" ]] || die "The download is corrupted. Run the command again."
    fi
fi
unzip -q "$TMP/diwan-erp.zip" -d "$TMP"
VERSION=$(cat "$TMP/diwan-erp/VERSION" 2> /dev/null || echo 0)
RELEASE="$DIR/releases/$(date +%Y%m%d%H%M%S)-$VERSION"
mv "$TMP/diwan-erp" "$RELEASE"
rm -rf "$TMP"
# Data that survives updates: settings and storage (uploads, backups, logs).
if [[ ! -d $DIR/shared/storage ]]; then
    mv "$RELEASE/storage" "$DIR/shared/storage"
else
    rm -rf "$RELEASE/storage"
fi
ln -sfn "$DIR/shared/storage" "$RELEASE/storage"
ln -sfn ../storage/app/public "$RELEASE/public/storage"
ok "Version $VERSION"

# ------------------------------------------------------------------ 3. database & settings
step "3/6 Database and settings"
ENV_FILE=$DIR/shared/.env
if [[ ! -f $ENV_FILE ]]; then
    # A fixed-length read (no endless pipe for pipefail to trip on).
    random() { head -c 96 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | cut -c1-"$1"; }
    DB_PASS=$(random 24)
    BACKUP_PASS=$(random 32)
    mysql -uroot -e "CREATE DATABASE IF NOT EXISTS diwan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        CREATE USER IF NOT EXISTS 'diwan'@'localhost' IDENTIFIED BY '$DB_PASS';
        ALTER USER 'diwan'@'localhost' IDENTIFIED BY '$DB_PASS';
        GRANT ALL PRIVILEGES ON diwan.* TO 'diwan'@'localhost'; FLUSH PRIVILEGES;"
    sed -e "s#__PORT__#$PORT#g" -e "s#__DB_PORT__#3306#g" -e "s#__DB_PASSWORD__#$DB_PASS#g" \
        -e "s#__BACKUP_PASSWORD__#$BACKUP_PASS#g" -e "s#__MYSQLDUMP__#$(command -v mysqldump)#g" -e "s#__MYSQL__#$(command -v mysql)#g" \
        "$RELEASE/.env.local-install.example" > "$ENV_FILE"
    {
        echo "# Diwan ERP - keep these passwords somewhere safe, outside this computer."
        echo "DB_PASSWORD=$DB_PASS"
        echo "BACKUP_PASSWORD=$BACKUP_PASS"
    } > "$DIR/secrets.txt"
    chmod 600 "$DIR/secrets.txt"
fi
ln -sfn "$ENV_FILE" "$RELEASE/.env"
chown -R "$APP_USER:$APP_USER" "$DIR/releases" "$DIR/shared"
chmod 640 "$ENV_FILE"
cd "$RELEASE"
grep -q '^APP_KEY=base64' "$ENV_FILE" || as_app php artisan key:generate --force > /dev/null
as_app php artisan migrate --force --seed > /dev/null
as_app php artisan optimize > /dev/null
ln -sfn "$RELEASE" "$DIR/current.next" && mv -Tf "$DIR/current.next" "$DIR/current"
ok "Database ready. Passwords saved in $DIR/secrets.txt"

# ------------------------------------------------------------------ 4. services
step "4/6 Starting Diwan automatically with the computer"
if [[ $NO_SERVICES == 1 ]] || ! [[ -d /run/systemd/system ]]; then
    warn "No automatic services (systemd not found). To start Diwan by hand:"
    echo "    cd $DIR/current && sudo -u $APP_USER env PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=0.0.0.0 --port=$PORT --no-reload"
else
    unit() { # name, description, command
        cat > "/etc/systemd/system/$1.service" <<UNIT
[Unit]
Description=$2
After=network.target mysql.service mariadb.service

[Service]
User=$APP_USER
WorkingDirectory=$DIR/current
Environment=PHP_CLI_SERVER_WORKERS=4
ExecStart=$3
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT
    }
    unit diwan-web "Diwan ERP web" "/usr/bin/php $DIR/current/artisan serve --host=0.0.0.0 --port=$PORT --no-reload"
    unit diwan-queue "Diwan ERP queue" "/usr/bin/php $DIR/current/artisan queue:work database --queue=zatca,default,webhooks,exports --sleep=3 --tries=3 --max-time=3600"
    unit diwan-scheduler "Diwan ERP scheduler" "/usr/bin/php $DIR/current/artisan schedule:work"
    cat > /etc/systemd/system/diwan-update.service <<UNIT
[Unit]
Description=Diwan ERP update
[Service]
Type=oneshot
ExecStart=/bin/bash $DIR/current/deploy/linux/update.sh
UNIT
    cat > /etc/systemd/system/diwan-update.timer <<UNIT
[Unit]
Description=Diwan ERP daily update
[Timer]
OnCalendar=*-*-* 03:30
Persistent=true
[Install]
WantedBy=timers.target
UNIT
    mkdir -p "$DIR/shared/storage/app/update-queue" && chown "$APP_USER:$APP_USER" "$DIR/shared/storage/app/update-queue" 2> /dev/null || true
    cat > /etc/systemd/system/diwan-update.path <<UNIT
[Unit]
Description=Diwan ERP update requested from the dashboard
[Path]
PathExistsGlob=$DIR/shared/storage/app/update-queue/*
Unit=diwan-update.service
[Install]
WantedBy=multi-user.target
UNIT
    systemctl daemon-reload
    systemctl enable --now diwan-update.path > /dev/null 2>&1 || true
    systemctl enable --now diwan-web diwan-queue diwan-scheduler diwan-update.timer > /dev/null 2>&1
    systemctl restart diwan-web diwan-queue diwan-scheduler
    ok "Diwan is running, and starts with the computer. It updates itself daily at 3:30 AM."
fi

# ------------------------------------------------------------------ 5. office network
step "5/6 Allowing the office devices to connect"
if command -v ufw > /dev/null && ufw status 2> /dev/null | grep -q 'Status: active'; then
    for net in 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16; do ufw allow from "$net" to any port "$PORT" proto tcp > /dev/null; done
    ok "The firewall allows port $PORT from the local network only."
else
    ok "No active firewall is blocking access."
fi

# ------------------------------------------------------------------ 6. shortcut
step "6/6 Applications menu shortcut"
cat > /usr/share/applications/diwan.desktop <<DESK
[Desktop Entry]
Type=Application
Name=الديوان - Diwan
Name[en]=Diwan ERP
Comment=نظام الديوان لإدارة الأعمال
Exec=xdg-open http://localhost:$PORT
Icon=$DIR/current/public/favicon.ico
Terminal=false
Categories=Office;Finance;
DESK
ok "Look for Diwan in the applications menu."

LAN=$(hostname -I 2> /dev/null | tr ' ' '\n' | grep -E '^(10|172\.(1[6-9]|2[0-9]|3[01])|192\.168)\.' | head -1 || true)
echo
echo "${c_ok}══════════════════════════════════════════════════════${c_off}"
echo "${c_ok}  Installed successfully!${c_off}"
echo "  On this computer:            http://localhost:$PORT"
[[ -n $LAN ]] && echo "  From office devices/phones:  http://$LAN:$PORT"
echo "  First time: enter your company details and the manager account."
echo "  Passwords:                   $DIR/secrets.txt (copy it somewhere safe)"
echo "  Update by hand:              sudo bash $DIR/current/deploy/linux/update.sh"
echo "${c_ok}══════════════════════════════════════════════════════${c_off}"
command -v xdg-open > /dev/null && [[ -n ${SUDO_USER:-} ]] && [[ $NO_SERVICES != 1 ]] && sudo -u "$SUDO_USER" xdg-open "http://localhost:$PORT" > /dev/null 2>&1 &
exit 0
