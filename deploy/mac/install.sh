#!/usr/bin/env bash
#
# Diwan ERP - install on a Mac for office use.
# (Messages are English: terminals do not render Arabic well.)
#
# Open Terminal (Launchpad > Other > Terminal), paste this line and press Enter:
#
#   /bin/bash -c "$(curl -fsSL https://github.com/turkialawlqy/Diwan-ERP/releases/latest/download/install-mac.sh)"
#
# Installs Homebrew (the standard Mac package manager) if missing, then PHP and a
# MySQL database, downloads the latest ready-built release into ~/Diwan and runs
# it whenever you log in, on http://localhost:8000 and from office devices.
# Safe to run again.
#
# Options: DIWAN_PORT=8000  DIWAN_PACKAGE=path-or-url-of-the-zip

set -Eeuo pipefail

REPO=turkialawlqy/Diwan-ERP
PACKAGE_URL=${DIWAN_PACKAGE:-https://github.com/$REPO/releases/latest/download/diwan-erp.zip}
DIR="$HOME/Diwan"
PORT=${DIWAN_PORT:-8000}
AGENTS="$HOME/Library/LaunchAgents"

c_info=$'\e[36m'; c_ok=$'\e[32m'; c_warn=$'\e[33m'; c_err=$'\e[31m'; c_off=$'\e[0m'
step() { echo; echo "${c_info}==> $*${c_off}"; }
ok()   { echo "${c_ok}✓ $*${c_off}"; }
warn() { echo "${c_warn}! $*${c_off}"; }
die()  { echo "${c_err}✗ $*${c_off}" >&2; exit 1; }
trap 'echo "${c_err}✗ Setup stopped at line $LINENO. Take a screenshot of the messages above to ask for help, then paste the same line again (it is safe to repeat).${c_off}" >&2' ERR

[[ $(uname -s) == Darwin ]] || die "This installer is for Mac. For Windows and Linux see docs/INSTALL.md"
[[ $EUID -ne 0 ]] || die "Do not use sudo: paste the line exactly as in the guide."

echo "${c_info}══════════════════════════════════════════════"
echo "  Diwan ERP setup (Mac)"
echo "  Takes 10-20 minutes the first time"
echo "══════════════════════════════════════════════${c_off}"

# ------------------------------------------------------------------ 1. Homebrew
step "1/6 Homebrew (the standard Mac package manager)"
if ! command -v brew > /dev/null && [[ ! -x /opt/homebrew/bin/brew && ! -x /usr/local/bin/brew ]]; then
    echo "You will be asked for your Mac password (it stays invisible while you type; that is normal), then to press Enter."
    /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
fi
for b in /opt/homebrew/bin/brew /usr/local/bin/brew; do [[ -x $b ]] && eval "$("$b" shellenv)" && break; done
command -v brew > /dev/null || die "Homebrew did not finish installing. Paste the line again."
ok "Homebrew is ready."

# ------------------------------------------------------------------ 2. PHP & MySQL
step "2/6 Installing PHP and the MySQL database"
brew install php@8.3 mysql@8.4 > /dev/null
PHP="$(brew --prefix php@8.3)/bin/php"
MYSQL_BIN="$(brew --prefix mysql@8.4)/bin"
brew services start mysql@8.4 > /dev/null 2>&1 || brew services restart mysql@8.4 > /dev/null
for _ in $(seq 1 30); do "$MYSQL_BIN/mysqladmin" -uroot ping > /dev/null 2>&1 && break; sleep 2; done
"$MYSQL_BIN/mysqladmin" -uroot ping > /dev/null 2>&1 || die "The database did not start. Restart the Mac, then paste the line again."
ok "PHP $("$PHP" -r 'echo PHP_VERSION;') and MySQL are running (MySQL starts with the Mac)."

# ------------------------------------------------------------------ 3. the system
step "3/6 Downloading the latest Diwan release"
mkdir -p "$DIR/releases" "$DIR/shared"
TMP=$(mktemp -d)
if [[ -f $PACKAGE_URL ]]; then
    cp "$PACKAGE_URL" "$TMP/diwan-erp.zip"
else
    curl -fL --progress-bar -o "$TMP/diwan-erp.zip" "$PACKAGE_URL" \
        || die "Could not download Diwan. Check the internet connection (or that a release is published on the project's Releases page)."
    if curl -fsSL -o "$TMP/diwan-erp.zip.sha256" "$PACKAGE_URL.sha256" 2> /dev/null; then
        [[ $(shasum -a 256 "$TMP/diwan-erp.zip" | cut -d' ' -f1) == "$(tr -d '[:space:]' < "$TMP/diwan-erp.zip.sha256")" ]] || die "The download is corrupted. Paste the line again."
    fi
fi
unzip -q "$TMP/diwan-erp.zip" -d "$TMP"
VERSION=$(cat "$TMP/diwan-erp/VERSION" 2> /dev/null || echo 0)
RELEASE="$DIR/releases/$(date +%Y%m%d%H%M%S)-$VERSION"
mv "$TMP/diwan-erp" "$RELEASE"
rm -rf "$TMP"
if [[ ! -d $DIR/shared/storage ]]; then
    mv "$RELEASE/storage" "$DIR/shared/storage"
else
    rm -rf "$RELEASE/storage"
fi
ln -sfn "$DIR/shared/storage" "$RELEASE/storage"
ln -sfn ../storage/app/public "$RELEASE/public/storage"
ok "Version $VERSION"

# ------------------------------------------------------------------ 4. database & settings
step "4/6 Database and settings"
ENV_FILE=$DIR/shared/.env
if [[ ! -f $ENV_FILE ]]; then
    random() { head -c 96 /dev/urandom | base64 | LC_ALL=C tr -dc 'A-Za-z0-9' | cut -c1-"$1"; }
    DB_PASS=$(random 24)
    BACKUP_PASS=$(random 32)
    "$MYSQL_BIN/mysql" -uroot -e "CREATE DATABASE IF NOT EXISTS diwan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        CREATE USER IF NOT EXISTS 'diwan'@'localhost' IDENTIFIED BY '$DB_PASS';
        ALTER USER 'diwan'@'localhost' IDENTIFIED BY '$DB_PASS';
        GRANT ALL PRIVILEGES ON diwan.* TO 'diwan'@'localhost'; FLUSH PRIVILEGES;"
    sed -e "s#__PORT__#$PORT#g" -e "s#__DB_PORT__#3306#g" -e "s#__DB_PASSWORD__#$DB_PASS#g" \
        -e "s#__BACKUP_PASSWORD__#$BACKUP_PASS#g" -e "s#__MYSQLDUMP__#$MYSQL_BIN/mysqldump#g" -e "s#__MYSQL__#$MYSQL_BIN/mysql#g" \
        "$RELEASE/.env.local-install.example" > "$ENV_FILE"
    {
        echo "# Diwan ERP - keep these passwords somewhere safe, outside this computer."
        echo "DB_PASSWORD=$DB_PASS"
        echo "BACKUP_PASSWORD=$BACKUP_PASS"
    } > "$DIR/secrets.txt"
    chmod 600 "$DIR/secrets.txt" "$ENV_FILE"
fi
ln -sfn "$ENV_FILE" "$RELEASE/.env"
cd "$RELEASE"
grep -q '^APP_KEY=base64' "$ENV_FILE" || "$PHP" artisan key:generate --force > /dev/null
"$PHP" artisan migrate --force --seed > /dev/null
"$PHP" artisan optimize > /dev/null
ln -sfn "$RELEASE" "$DIR/current" # (BSD mv has no -T: ln -sfn replaces the link in place)
ok "Database ready. Passwords saved in ~/Diwan/secrets.txt"

# ------------------------------------------------------------------ 5. background services
step "5/6 Starting Diwan automatically when you log in"
mkdir -p "$AGENTS"
LOGS="$DIR/shared/storage/logs"
agent() { # label, keepalive(true/false), extra plist xml, program args...
    local label=$1 keep=$2 extra=$3; shift 3
    local args=""
    for a in "$@"; do args+="        <string>$a</string>"$'\n'; done
    cat > "$AGENTS/$label.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>$label</string>
    <key>ProgramArguments</key>
    <array>
$args    </array>
    <key>WorkingDirectory</key><string>$DIR/current</string>
    <key>EnvironmentVariables</key>
    <dict><key>PHP_CLI_SERVER_WORKERS</key><string>4</string></dict>
    <key>RunAtLoad</key><$keep/>
    <key>KeepAlive</key><$keep/>
    <key>StandardOutPath</key><string>$LOGS/$label.log</string>
    <key>StandardErrorPath</key><string>$LOGS/$label.log</string>
$extra
</dict>
</plist>
PLIST
    launchctl bootout "gui/$(id -u)/$label" > /dev/null 2>&1 || true
    launchctl bootstrap "gui/$(id -u)" "$AGENTS/$label.plist"
}
agent com.diwan.web true "" "$PHP" "$DIR/current/artisan" serve --host=0.0.0.0 "--port=$PORT" --no-reload
agent com.diwan.queue true "" "$PHP" "$DIR/current/artisan" queue:work database --queue=zatca,default,webhooks,exports --sleep=3 --tries=3 --max-time=3600
agent com.diwan.scheduler true "" "$PHP" "$DIR/current/artisan" schedule:work
agent com.diwan.update false "    <key>StartCalendarInterval</key><dict><key>Hour</key><integer>3</integer><key>Minute</key><integer>30</integer></dict>" \
    /bin/bash "$DIR/current/deploy/mac/update.sh"
mkdir -p "$DIR/shared/storage/app/update-queue"
agent com.diwan.update-now false "    <key>QueueDirectories</key><array><string>$DIR/shared/storage/app/update-queue</string></array>
    <key>ThrottleInterval</key><integer>30</integer>" /bin/bash "$DIR/current/deploy/mac/update.sh"
ok "Diwan is running, starts whenever you log in to the Mac, and updates itself daily at 3:30 AM."

# ------------------------------------------------------------------ 6. shortcut
step "6/6 Desktop shortcut"
cat > "$HOME/Desktop/الديوان.webloc" <<WEBLOC
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict><key>URL</key><string>http://localhost:$PORT</string></dict></plist>
WEBLOC
ok "A Diwan shortcut is on your Desktop."

for _ in $(seq 1 15); do curl -fsS -o /dev/null "http://127.0.0.1:$PORT/up" 2> /dev/null && break; sleep 1; done
LAN=$(ipconfig getifaddr en0 2> /dev/null || ipconfig getifaddr en1 2> /dev/null || true)
echo
echo "${c_ok}══════════════════════════════════════════════════════${c_off}"
echo "${c_ok}  Installed successfully!${c_off}"
echo "  On this computer:            http://localhost:$PORT"
[[ -n $LAN ]] && echo "  From office devices/phones:  http://$LAN:$PORT"
echo "  First time: enter your company details and the manager account."
echo "  If the Mac asks to allow incoming connections for php, choose Allow (needed for office devices)."
echo "  Passwords:                   ~/Diwan/secrets.txt (copy it somewhere safe)"
echo "${c_ok}══════════════════════════════════════════════════════${c_off}"
open "http://localhost:$PORT" || true
