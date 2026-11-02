#!/usr/bin/env bash
#
# Diwan ERP - install on a Mac for office use (new installer).
# (Messages are English: terminals do not render Arabic well.)
#
# Open Terminal (Launchpad > Other > Terminal), paste this line and press Enter:
#
#   /bin/bash -c "$(curl -fsSL <link to this file>)"
#
# or, with this file on the Mac:   bash install-mac-new.sh
# (The release workflow publishes deploy/mac/install.sh as install-mac.sh: put this
# file there and the guide's usual line installs with it.)
#
# Installs Homebrew (the standard Mac package manager) if missing, then PHP and a
# MySQL database, downloads the latest ready-built release into ~/Diwan and runs
# it whenever you log in, on http://localhost:8000 and - once the first-time setup
# is done on this Mac - from office devices. Safe to run again: an existing install
# is kept, repaired and updated the safe way.
#
# It keeps a copy of itself as ~/Diwan/bin/diwan, which then looks after Diwan:
#   ~/Diwan/bin/diwan status              versions, services, last update
#   ~/Diwan/bin/diwan update [--force]    update now (it also runs daily at 3:30)
#   ~/Diwan/bin/diwan uninstall [--purge] remove the services (--purge: and all data)
#
# Options: DIWAN_PORT=8000  DIWAN_PACKAGE=path-or-url-of-the-zip  DIWAN_DIR=~/Diwan
#          DIWAN_PHP=path-of-php  DIWAN_NEW_KEY=1 (start over beside an earlier database)

set -Eeuo pipefail

# Raised whenever this file changes: an update whose release carries a newer copy installs it.
DIWAN_SCRIPT_VERSION=3

REPO=turkialawlqy/Diwan-ERP
DEFAULT_PACKAGE=https://github.com/$REPO/releases/latest/download/diwan-erp.zip
PACKAGE=${DIWAN_PACKAGE:-$DEFAULT_PACKAGE}
PHP_FORMULA=php@8.3
MYSQL_FORMULA=mysql@8.4
# The release's libraries run on PHP 8.2 to 8.4 (PhpSpreadsheet 1.30 refuses 8.5).
PHP_MIN=80200
PHP_MAX=80500
KEEP=3
SERVICES=(com.diwan.web com.diwan.queue com.diwan.scheduler)
JOBS=(com.diwan.web com.diwan.queue com.diwan.scheduler com.diwan.update com.diwan.update-now)
SHORTCUT="$HOME/Desktop/الديوان.webloc"

c_info=$'\e[36m'; c_ok=$'\e[32m'; c_warn=$'\e[33m'; c_err=$'\e[31m'; c_off=$'\e[0m'
step() { echo; echo "${c_info}==> $*${c_off}"; }
ok()   { echo "${c_ok}✓ $*${c_off}"; }
warn() { echo "${c_warn}! $*${c_off}"; }
die()  { echo "${c_err}✗ $*${c_off}" >&2; exit 1; }
fail() { status failed "$*"; die "$*"; }

# ------------------------------------------------------------------ places

init_paths() {
    # Run as ~/Diwan/bin/diwan, the install is the folder it sits in.
    local self=${BASH_SOURCE[0]:-}
    if [[ -z ${DIWAN_DIR:-} && $self == */bin/diwan ]]; then
        DIR=$(cd "$(dirname "$self")/.." && pwd)
    else
        DIR=${DIWAN_DIR:-$HOME/Diwan}
    fi
    BIN="$DIR/bin/diwan"
    SHARED="$DIR/shared"
    ENV_FILE="$SHARED/.env"
    CONF="$SHARED/diwan.conf"
    LOGS="$SHARED/storage/logs"
    QUEUE_DIR="$SHARED/storage/app/update-queue"
    STATUS_FILE="$SHARED/storage/app/update-status.json"
    LOCK="$DIR/.update.lock"
    AGENTS="$HOME/Library/LaunchAgents"
    UID_N=$(id -u)
    PHP="" MYSQL_BIN="" PORT="" TMP="" NEW="" CURRENT="" SWITCHED=0 LOCK_HELD=0
    INSTALLED="" VERSION="" BACKUP="" TABLES=""
}

# ------------------------------------------------------------------ settings

conf_get() { # KEY [default]: this installer's own settings (~/Diwan/shared/diwan.conf)
    local line
    line=$(grep -E "^$1=" "$CONF" 2> /dev/null | tail -n 1) || true
    if [[ -n $line ]]; then echo "${line#*=}"; else echo "${2:-}"; fi
}

conf_set() { # KEY VALUE
    mkdir -p "$SHARED"
    { grep -vE "^$1=" "$CONF" 2> /dev/null || true; echo "$1=$2"; } > "$CONF.tmp"
    mv -f "$CONF.tmp" "$CONF"
}

env_get() { # KEY: its value in the app's .env, without quotes
    local line
    line=$(grep -E "^$1=" "$ENV_FILE" 2> /dev/null | tail -n 1) || true
    line=${line#*=}; line=${line#\"}; line=${line%\"}
    echo "$line"
}

env_set() { # KEY VALUE: changed where it stands (or added), the file replaced in one go
    awk -v k="$1" -v v="$2" 'index($0, k "=") == 1 { if (!done) print k "=" v; done = 1; next } { print }
        END { if (!done) print k "=" v }' "$ENV_FILE" > "$ENV_FILE.tmp"
    chmod 600 "$ENV_FILE.tmp"
    mv -f "$ENV_FILE.tmp" "$ENV_FILE"
}

env_valid() { # a settings file that was completed (one cut off by the old installer is empty or keeps __FIELDS__)
    [[ -s $ENV_FILE ]] && grep -qE '^DB_PASSWORD=.' "$ENV_FILE" && ! grep -qE '__[A-Z_]+__' "$ENV_FILE"
}

random() { head -c 96 /dev/urandom | base64 | LC_ALL=C tr -dc 'A-Za-z0-9' | cut -c1-"$1"; }

new_env() { # template: a new settings file with fresh passwords, written in one go
    local db_pass backup_pass
    if [[ -s $ENV_FILE ]]; then
        mv -f "$ENV_FILE" "$ENV_FILE.incomplete-$(date +%Y%m%d%H%M%S)"
        warn "The settings file was incomplete (an earlier setup stopped): a new one is made, the old one is kept beside it."
    fi
    db_pass=$(random 24)
    backup_pass=$(random 32)
    mkdir -p "$SHARED"
    sed -e "s#__PORT__#$PORT#g" -e "s#__DB_PORT__#3306#g" -e "s#__DB_PASSWORD__#$db_pass#g" \
        -e "s#__BACKUP_PASSWORD__#$backup_pass#g" -e "s#__MYSQLDUMP__#$MYSQL_BIN/mysqldump#g" -e "s#__MYSQL__#$MYSQL_BIN/mysql#g" \
        "$1" > "$ENV_FILE.tmp" || die "Could not write the settings file."
    if grep -qE '__[A-Z_]+__' "$ENV_FILE.tmp"; then die "This release's settings template has fields this installer does not know: use a newer installer."; fi
    chmod 600 "$ENV_FILE.tmp"
    mv -f "$ENV_FILE.tmp" "$ENV_FILE"
    {
        echo "# Diwan ERP - keep these passwords somewhere safe, outside this computer."
        echo "DB_PASSWORD=$db_pass"
        echo "BACKUP_PASSWORD=$backup_pass"
    } > "$DIR/secrets.txt"
    chmod 600 "$DIR/secrets.txt"
}

web_port() { # where the web service listens: this installer's settings, else the service, else APP_URL
    local p re_port='--port=([0-9]+)' re_url=':([0-9]+)/?$'
    p=$(conf_get PORT)
    if [[ $p =~ ^[0-9]+$ ]]; then echo "$p"; return 0; fi
    if [[ $(cat "$AGENTS/com.diwan.web.plist" 2> /dev/null) =~ $re_port ]]; then echo "${BASH_REMATCH[1]}"; return 0; fi
    if [[ $(env_get APP_URL) =~ $re_url ]]; then echo "${BASH_REMATCH[1]}"; return 0; fi
    echo 8000
}

# ------------------------------------------------------------------ dashboard status

status() { # state (running | done | failed | uptodate), message: what the owner's dashboard shows
    local msg=${2:-}
    msg=${msg//\\/\\\\}
    msg=${msg//\"/\\\"}
    { mkdir -p "$(dirname "$STATUS_FILE")" \
        && printf '{"state":"%s","from":"%s","to":"%s","message":"%s","at":%s}\n' "$1" "$INSTALLED" "$VERSION" "$msg" "$(date +%s)" > "$STATUS_FILE.tmp" \
        && mv -f "$STATUS_FILE.tmp" "$STATUS_FILE"; } 2> /dev/null || true
}

# ------------------------------------------------------------------ one at a time

started_at() { ps -p "$1" -o lstart= 2> /dev/null | awk '{ $1 = $1; print }' || true; } # (ps pads it with spaces)

lock_take() { # one installer or update at a time; a lock left by one that was cut off (power cut) is taken over
    local owner="" since=""
    mkdir -p "$DIR"
    if ! mkdir "$LOCK" 2> /dev/null; then
        read -r owner since 2> /dev/null < "$LOCK/owner" || true
        # The same process id AND start time: really still running (ids are handed out again after a restart).
        if [[ $owner =~ ^[0-9]+$ && -n $since && $(started_at "$owner") == "$since" ]]; then
            return 1
        fi
        rm -rf "$LOCK"
        mkdir "$LOCK" 2> /dev/null || return 1
    fi
    echo "$$ $(started_at $$)" > "$LOCK/owner"
    LOCK_HELD=1
}

lock_release() {
    if [[ $LOCK_HELD == 1 ]]; then rm -rf "$LOCK"; LOCK_HELD=0; fi
}

on_exit() { # however the script ends: no half-prepared release, no temporary files, no lock left behind
    if [[ -n $NEW && $SWITCHED != 1 ]]; then rm -rf "$NEW"; fi
    if [[ -n $TMP ]]; then rm -rf "$TMP"; fi
    lock_release
}

# ------------------------------------------------------------------ tools

brew_env() { # Homebrew's paths (Apple silicon or Intel)
    local b
    for b in /opt/homebrew/bin/brew /usr/local/bin/brew; do
        if [[ -x $b ]]; then eval "$("$b" shellenv)"; return 0; fi
    done
    command -v brew > /dev/null
}

php_path() { # the PHP Diwan runs on: chosen at install time, else Homebrew's
    local php=${DIWAN_PHP:-}
    if [[ -z $php ]]; then php=$(conf_get PHP); fi
    if [[ -z $php ]]; then php="$(brew --prefix "$PHP_FORMULA" 2> /dev/null)/bin/php"; fi
    echo "$php"
}

php_usable() { # PHP 8.2 to 8.4 with the extensions Diwan needs
    local mods ext
    "$1" -r "exit(PHP_VERSION_ID >= $PHP_MIN && PHP_VERSION_ID < $PHP_MAX ? 0 : 1);" 2> /dev/null || return 1
    mods=$("$1" -m 2> /dev/null) || return 1
    for ext in pdo_mysql mbstring intl gd zip bcmath sodium openssl; do
        grep -qix "$ext" <<< "$mods" || return 1
    done
}

quietly() { # runs a command, showing its output only when it fails (the reason lands in the log)
    local out
    if out=$("$@" 2>&1); then return 0; fi
    echo "$out" | tail -n 30 >&2
    return 1
}

# shellcheck disable=SC2016 # PHP code: its $ are PHP's
newer() { "$PHP" -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$1" "$2"; }

port_busy() { nc -z -G 1 127.0.0.1 "$1" > /dev/null 2>&1; }

mysql_alive() { "$MYSQL_BIN/mysqladmin" -uroot --connect-timeout=2 ping > /dev/null 2>&1; }

root_sql() { "$MYSQL_BIN/mysql" -uroot -N -B -e "$1"; }

sql_quote() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e "s/'/''/g"; } # a value for a '...' MySQL string

# Database chores through a release's own settings and drivers: list the tables, drop some,
# or tell whether the first-time setup has created the company.
# shellcheck disable=SC2016 # PHP code: its $ are PHP's
db_helper() { # release tables | release drop table... | release has-company
    "$PHP" -r '
        [, $base, $cmd] = $argv;
        require "$base/vendor/autoload.php";
        (require "$base/bootstrap/app.php")->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $db = Illuminate\Support\Facades\DB::connection();
        $schema = $db->getSchemaBuilder();
        if ($cmd === "tables") {
            foreach ($schema->getTables($db->getDriverName() === "sqlite" ? null : $db->getDatabaseName()) as $table) {
                echo $table["name"], "\n";
            }
        } elseif ($cmd === "drop") {
            $schema->disableForeignKeyConstraints();
            foreach (array_slice($argv, 3) as $table) {
                $schema->dropIfExists($table);
            }
        } elseif ($cmd === "has-company") {
            echo App\Models\Company::query()->exists() ? "yes" : "no", "\n";
        } else {
            exit(2);
        }
    ' "$@"
}

lan_ip() { # this Mac's address on the office network (the interface the default route uses)
    local ifc ip
    ifc=$(route -n get default 2> /dev/null | awk '/interface:/ { print $2; exit }') || true
    for ifc in ${ifc:+"$ifc"} en0 en1; do
        if ip=$(ipconfig getifaddr "$ifc" 2> /dev/null) && [[ -n $ip ]]; then echo "$ip"; return 0; fi
    done
    return 1
}

kill_tree() { # a process and all it started (artisan serve runs php -S, which runs workers)
    local child
    for child in $(pgrep -P "$1" 2> /dev/null || true); do kill_tree "$child"; done
    kill "$1" 2> /dev/null || true
}

# ------------------------------------------------------------------ launchd

xml() { local s=${1//&/&amp;}; s=${s//</&lt;}; printf '%s' "${s//>/&gt;}"; }

agent_loaded() { launchctl print "gui/$UID_N/$1" > /dev/null 2>&1; }

agent_stop() { # unloads a job and waits until launchd has really let it go
    local i
    launchctl bootout "gui/$UID_N/$1" > /dev/null 2>&1 || true
    for i in $(seq 1 40); do
        if ! agent_loaded "$1"; then return 0; fi
        sleep 0.5
    done
    return 1
}

agent_start() { # retried: right after a bootout launchd may still answer "Input/output error"
    local i
    for i in 1 2 3 4 5; do
        if launchctl bootstrap "gui/$UID_N" "$AGENTS/$1.plist" 2> /dev/null; then return 0; fi
        sleep 2
    done
    launchctl bootstrap "gui/$UID_N" "$AGENTS/$1.plist" # the last try shows launchd's message
}

write_agent() { # label, keep-alive (true|false), extra plist xml, program arguments...
    local label=$1 keep=$2 extra=$3 args="" a
    shift 3
    for a in "$@"; do args+="        <string>$(xml "$a")</string>"$'\n'; done
    mkdir -p "$AGENTS"
    cat > "$AGENTS/$label.plist.tmp" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>$label</string>
    <key>ProgramArguments</key>
    <array>
$args    </array>
    <key>WorkingDirectory</key><string>$(xml "$DIR/current")</string>
    <key>EnvironmentVariables</key>
    <dict><key>PHP_CLI_SERVER_WORKERS</key><string>4</string></dict>
    <key>RunAtLoad</key><$keep/>
    <key>KeepAlive</key><$keep/>
    <key>StandardOutPath</key><string>$(xml "$LOGS/$label.log")</string>
    <key>StandardErrorPath</key><string>$(xml "$LOGS/$label.log")</string>
$extra
</dict>
</plist>
PLIST
    plutil -lint -s "$AGENTS/$label.plist.tmp" || die "Could not write the settings of the service $label."
    mv -f "$AGENTS/$label.plist.tmp" "$AGENTS/$label.plist"
}

queue_agent() { # the queue worker, on every queue the jobs use: tax authority (zatca), mail and alerts, webhooks, exports
    write_agent com.diwan.queue true "" "$PHP" "$DIR/current/artisan" queue:work database --queue=zatca,default,webhooks,exports --sleep=3 --tries=3 --max-time=3600
}

refresh_queue_agent() { # a worker set up by an earlier copy of this tool (it served only "default") is set up again
    local plist="$AGENTS/com.diwan.queue.plist" before
    [[ -f $plist ]] || return 0
    before=$(cat "$plist")
    queue_agent
    if [[ $(cat "$plist") == "$before" ]] && agent_loaded com.diwan.queue; then return 0; fi
    agent_stop com.diwan.queue || warn "launchd is slow to stop com.diwan.queue; continuing."
    agent_start com.diwan.queue || return 1
    ok "The queue service now serves every queue."
}

install_agents() {
    local label
    mkdir -p "$LOGS" "$QUEUE_DIR"
    for label in "${JOBS[@]}"; do
        agent_stop "$label" || warn "launchd is slow to stop $label; continuing."
    done
    write_agent com.diwan.web true "" /bin/bash "$BIN" serve "--port=$PORT"
    queue_agent
    write_agent com.diwan.scheduler true "" "$PHP" "$DIR/current/artisan" schedule:work
    write_agent com.diwan.update false "    <key>StartCalendarInterval</key><dict><key>Hour</key><integer>3</integer><key>Minute</key><integer>30</integer></dict>" \
        /bin/bash "$BIN" update
    # The dashboard's "update now" drops a file in this folder.
    write_agent com.diwan.update-now false "    <key>QueueDirectories</key><array><string>$(xml "$QUEUE_DIR")</string></array>
    <key>ThrottleInterval</key><integer>30</integer>" /bin/bash "$BIN" update
    for label in "${JOBS[@]}"; do agent_start "$label"; done
}

restart_services() {
    local label
    for label in "${SERVICES[@]}"; do
        launchctl kickstart -k "gui/$UID_N/$label" > /dev/null 2>&1 || true
    done
}

wait_up() { # seconds: until the system answers on this Mac
    local i
    for (( i = 0; i < $1; i += 2 )); do
        sleep 2
        if curl -fs -o /dev/null --max-time 5 "http://127.0.0.1:$PORT/up"; then return 0; fi
    done
    return 1
}

rotate_logs() { # launchd never trims the services' output: each log kept under 10 MB, with one older copy
    local f label
    for f in "$LOGS"/com.diwan.*.log; do
        [[ -f $f ]] || continue
        if (( $(stat -f %z "$f") > 10485760 )); then
            mv -f "$f" "$f.1"
            label=$(basename "$f" .log)
            case $label in
                com.diwan.update*) ;; # an update job (maybe this very run): its next run starts a new file
                *) launchctl kickstart -k "gui/$UID_N/$label" > /dev/null 2>&1 || true ;; # reopens its log
            esac
        fi
    done
}

# ------------------------------------------------------------------ releases

latest_version() { # the newest release on GitHub, read from its address (no 60 MB download to find out)
    local url
    url=$(curl -fsSI -o /dev/null -w '%{redirect_url}' --max-time 20 "https://github.com/$REPO/releases/latest") || return 1
    [[ $url == */releases/tag/* ]] || return 1
    url=${url##*/}
    echo "${url#[vV]}"
}

fetch_package() { # [quiet]: the release package, checked and unpacked into $TMP/diwan-erp
    local zip="$TMP/diwan-erp.zip" progress=--progress-bar
    if [[ ${1:-} == quiet ]]; then progress=-sS; fi
    if [[ -f $PACKAGE ]]; then
        cp "$PACKAGE" "$zip" || return 1
    else
        curl -fL --retry 3 "$progress" -o "$zip" "$PACKAGE" || return 1
        if curl -fsSL --retry 3 -o "$zip.sha256" "$PACKAGE.sha256" 2> /dev/null; then
            [[ $(shasum -a 256 "$zip" | cut -d' ' -f1) == "$(tr -d '[:space:]' < "$zip.sha256")" ]] || return 2
        elif [[ $PACKAGE == "$DEFAULT_PACKAGE" ]]; then
            return 3 # the project's releases always publish their checksum
        fi
    fi
    unzip -q "$zip" -d "$TMP" && [[ -f $TMP/diwan-erp/artisan ]] || return 4
}

package_error() {
    case $1 in
        2) echo "The download is corrupted." ;;
        3) echo "The download has no checksum to check it against." ;;
        4) echo "The package is not a Diwan release." ;;
        *) echo "Could not download Diwan (internet connection?)." ;;
    esac
}

prepare_release() { # the unpacked package becomes a release folder sharing storage and settings
    NEW="$DIR/releases/$(date +%Y%m%d%H%M%S)-$VERSION"
    mkdir -p "$DIR/releases"
    mv "$TMP/diwan-erp" "$NEW"
    if [[ ! -d $SHARED/storage ]]; then
        mkdir -p "$SHARED"
        mv "$NEW/storage" "$SHARED/storage"
    else
        # Folders a newer release adds to storage/ appear; nothing already there is touched.
        cp -Rn "$NEW/storage/." "$SHARED/storage/" 2> /dev/null || true
        rm -rf "$NEW/storage"
    fi
    ln -sfn "$SHARED/storage" "$NEW/storage"
    ln -sfn "$ENV_FILE" "$NEW/.env"
    ln -sfn ../storage/app/public "$NEW/public/storage"
}

prune_releases() { # the newest few stay (to go back by hand), never the running one
    local running i old releases=("$DIR/releases"/*/)
    running=$(cd "$DIR/current" && pwd -P)
    # Names start with the date, and the list comes sorted: the oldest are first.
    for (( i = 0; i < ${#releases[@]} - KEEP; i++ )); do
        old=${releases[i]%/}
        if [[ -d $old && $old != "$running" ]]; then rm -rf "$old"; fi
    done
}

script_version() { # DIWAN_SCRIPT_VERSION written in a copy of this script (0 if none)
    local v
    v=$(sed -nE 's/^DIWAN_SCRIPT_VERSION=([0-9]+).*/\1/p' "$1" 2> /dev/null) || true
    v=${v%%$'\n'*}
    echo "${v:-0}"
}

install_tool() { # this script, kept as ~/Diwan/bin/diwan: the services and the owner run it from there
    local self=${BASH_SOURCE[0]:-}
    mkdir -p "$DIR/bin"
    if [[ -f $self && $self -ef $BIN ]]; then return 0; fi
    # A newer copy brought by an update stays.
    if [[ -f $BIN ]] && (( $(script_version "$BIN") > DIWAN_SCRIPT_VERSION )); then return 0; fi
    if [[ -n ${BASH_EXECUTION_STRING:-} ]]; then
        printf '%s\n' "$BASH_EXECUTION_STRING" > "$BIN.new"
    else
        cp "$self" "$BIN.new"
    fi
    bash -n "$BIN.new" || die "The installer's copy of itself is damaged; paste the line again."
    chmod 755 "$BIN.new"
    mv -f "$BIN.new" "$BIN"
}

refresh_tool() { # a release that carries a newer copy of this script: the services use it from now on
    local file v best="" best_v=$DIWAN_SCRIPT_VERSION
    for file in "$1/deploy/mac/install.sh" "$1/install-mac-new.sh"; do
        [[ -f $file ]] || continue
        v=$(script_version "$file")
        if (( v > best_v )) && bash -n "$file" 2> /dev/null; then best=$file; best_v=$v; fi
    done
    [[ -n $best ]] || return 0
    cp "$best" "$BIN.new" && chmod 755 "$BIN.new" && mv -f "$BIN.new" "$BIN" || return 1
    ok "The Diwan tool itself was updated (version $best_v)."
}

take_backup() { # the running version backs itself up; BACKUP is the archive's name
    local out re='(diwan-preupdate-[0-9-]+\.zip)'
    out=$("$PHP" "$CURRENT/artisan" erp:backup --tag=preupdate 2>&1) || { echo "$out" >&2; return 1; }
    [[ $out =~ $re ]] || return 1
    BACKUP=${BASH_REMATCH[1]}
}

restore_database() { # back to the backup taken before the update: the previous version restores its own backup
    local after table extra=()
    status running "Restoring the database"
    if ! "$PHP" "$CURRENT/artisan" erp:restore "$BACKUP" --force > /dev/null; then
        "$PHP" "$CURRENT/artisan" up > /dev/null 2>&1 || true
        return 1
    fi
    # Tables the update created are not in the backup: dropped, or the next attempt stops on "already exists".
    after=$(db_helper "$CURRENT" tables) || return 1
    while IFS= read -r table; do
        if [[ -n $table ]]; then extra+=("$table"); fi
    done < <(comm -13 <(sort <<< "$TABLES") <(sort <<< "$after"))
    if (( ${#extra[@]} )); then db_helper "$CURRENT" drop "${extra[@]}" || return 1; fi
    "$PHP" "$CURRENT/artisan" optimize > /dev/null 2>&1 || true
}

# Installs a newer release next to the running one and switches over. A failed database update,
# or a version that does not answer afterwards, is undone: the database comes back from the backup
# taken just before, and the previous version keeps (or goes back to) running.
update_release() { # 1 = install the package even when it is not newer
    local force=$1 latest pending rc=0
    CURRENT=$(cd "$DIR/current" && pwd -P)
    INSTALLED=$(cat "$CURRENT/VERSION" 2> /dev/null || echo 0)
    rm -f "$QUEUE_DIR"/* 2> /dev/null || true # the dashboard's request is being served
    if [[ $force != 1 && $PACKAGE == "$DEFAULT_PACKAGE" ]] && latest=$(latest_version) && ! newer "$latest" "$INSTALLED"; then
        ok "Diwan is up to date (version $INSTALLED)."
        status uptodate "Up to date"
        return 0
    fi
    status running "Downloading"
    fetch_package quiet || rc=$?
    (( rc == 0 )) || fail "$(package_error "$rc")"
    VERSION=$(cat "$TMP/diwan-erp/VERSION" 2> /dev/null || echo 0)
    # Never an older version by itself (its code would run on a newer database): --force to insist.
    if [[ $force != 1 ]] && ! newer "$VERSION" "$INSTALLED"; then
        ok "Diwan is up to date (version $INSTALLED)."
        status uptodate "Up to date"
        return 0
    fi

    status running "Installing"
    prepare_release
    quietly "$PHP" "$NEW/artisan" optimize || fail "Version $VERSION could not load its settings; version $INSTALLED keeps running."
    pending=$("$PHP" "$NEW/artisan" migrate:status --pending 2>&1) \
        || fail "Could not read the database state, so the update was cancelled; version $INSTALLED keeps running."
    if [[ $pending == *Pending* ]]; then
        status running "Backing up"
        take_backup || fail "The backup failed, so the update was cancelled; version $INSTALLED keeps running."
        TABLES=$(db_helper "$CURRENT" tables) \
            || fail "Could not read the database tables, so the update was cancelled; version $INSTALLED keeps running."
        status running "Updating the database"
        if ! quietly "$PHP" "$NEW/artisan" migrate --force; then
            restore_database || fail "The database update failed and restoring the backup $BACKUP failed too: Diwan may not work. Ask for help (backups: $SHARED/storage/app/backups)."
            fail "The database update of version $VERSION failed; the database was restored and version $INSTALLED keeps running."
        fi
    fi

    status running "Restarting"
    ln -sfn "$NEW" "$DIR/current"
    SWITCHED=1
    restart_services
    if ! wait_up 40; then
        ln -sfn "$CURRENT" "$DIR/current"
        SWITCHED=0
        if [[ -n $BACKUP ]] && ! restore_database; then
            restart_services
            fail "Version $VERSION did not start; switched back to $INSTALLED, but restoring the database backup $BACKUP failed: Diwan may not work. Ask for help."
        fi
        restart_services
        fail "Version $VERSION did not start; switched back to $INSTALLED automatically${BACKUP:+, with the database restored}."
    fi
    prune_releases
    refresh_tool "$NEW" || warn "Could not update the Diwan tool itself; it keeps working as it is."
    status "done" "Updated"
    ok "Updated from $INSTALLED to $VERSION."
}

first_release() { # a new install: download, settings, database, then switch on
    local rc=0 count
    fetch_package || rc=$?
    (( rc == 0 )) || die "$(package_error "$rc") Check the internet connection (or that a release is published on the project's Releases page), then paste the line again."
    VERSION=$(cat "$TMP/diwan-erp/VERSION" 2> /dev/null || echo 0)
    prepare_release
    if ! env_valid; then
        # A database left by an earlier install whose settings file is gone: a new key would make its encrypted data unreadable.
        count=$(root_sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'diwan'" 2> /dev/null) || count=0
        if [[ $count != 0 && ${DIWAN_NEW_KEY:-} != 1 ]]; then
            die "An earlier Diwan database is on this Mac, but its settings file ($ENV_FILE) is missing or incomplete. That file holds the key to the encrypted data: put the old copy back (from your copy of ~/Diwan) and paste the line again. To start over anyway (the encrypted parts of the old data become unreadable), put DIWAN_NEW_KEY=1 and a space before the same line."
        fi
        new_env "$NEW/.env.local-install.example"
    fi
    ensure_database
    cd "$NEW"
    if ! grep -q '^APP_KEY=base64' "$ENV_FILE"; then
        quietly "$PHP" artisan key:generate --force || die "Could not create the application key (messages above)."
    fi
    quietly "$PHP" artisan migrate --force --seed || die "Preparing the database failed (messages above)."
    quietly "$PHP" artisan optimize || die "Preparing Diwan failed (messages above)."
    ln -sfn "$NEW" "$DIR/current" # (BSD mv has no -T: ln -sfn replaces the link in place)
    SWITCHED=1
    ok "Version $VERSION, database ready. Passwords saved in $DIR/secrets.txt"
}

# ------------------------------------------------------------------ installing

ensure_homebrew() {
    if ! brew_env; then
        [[ " $(id -Gn) " == *" admin "* ]] \
            || die "This Mac account is not an administrator, which installing Homebrew needs. Log in with an administrator account (System Settings > Users & Groups), then paste the line again."
        echo "You will be asked for your Mac password (it stays invisible while you type; that is normal), then to press Enter."
        /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
        brew_env || die "Homebrew did not finish installing. Paste the line again."
    fi
    ok "Homebrew is ready."
}

ensure_php() {
    if [[ -n ${DIWAN_PHP:-} ]]; then
        PHP=$DIWAN_PHP
    else
        brew install "$PHP_FORMULA"
        PHP="$(brew --prefix "$PHP_FORMULA")/bin/php"
        # A stale link (an old php@8.x name left pointing at a newer PHP) is mended by reinstalling.
        if ! php_usable "$PHP"; then brew reinstall "$PHP_FORMULA"; fi
    fi
    php_usable "$PHP" || die "PHP at $PHP cannot run Diwan: it needs PHP 8.2 to 8.4 with pdo_mysql, mbstring, intl, gd, zip, bcmath, sodium and openssl. It is: $("$PHP" -r 'echo PHP_VERSION;' 2> /dev/null || echo missing)"
    ok "PHP $("$PHP" -r 'echo PHP_VERSION;') is ready."
}

ensure_mysql() {
    local i log reason="" version
    MYSQL_BIN="$(brew --prefix "$MYSQL_FORMULA")/bin"
    # Another database server on port 3306 (XAMPP, MAMP, another MySQL) would keep ours from starting.
    if port_busy 3306 && ! mysql_alive; then
        die "Port 3306 is used by another database program (often XAMPP or MAMP, or another MySQL). Stop it and turn off its automatic start, then paste the line again."
    fi
    brew install "$MYSQL_FORMULA"
    brew services start "$MYSQL_FORMULA" > /dev/null 2>&1 || brew services restart "$MYSQL_FORMULA" > /dev/null 2>&1 || true
    for i in $(seq 1 45); do
        if mysql_alive; then break; fi
        sleep 2
    done
    if ! mysql_alive; then
        log=""
        for i in "$(brew --prefix)"/var/mysql/*.err; do
            if [[ -f $i && ( -z $log || $i -nt $log ) ]]; then log=$i; fi
        done
        if [[ -n $log ]]; then reason=$(grep '\[ERROR\]' "$log" | tail -n 1 | cut -c1-300) || true; fi
        die "The database did not start.${reason:+ Its log says: $reason}"
    fi
    root_sql 'SELECT 1' > /dev/null 2>&1 \
        || die "MySQL is running but refuses its administrator account (root): someone probably set a password on it. Remove that password, then paste the line again."
    version=$(root_sql 'SELECT VERSION()')
    [[ $version == 8.4* ]] || die "Another MySQL ($version) answers instead of $MYSQL_FORMULA. Stop it (for example: brew services stop mysql), then paste the line again."
    ok "MySQL $version is running (it starts with the Mac)."
}

ensure_database() { # the database and its user, with the password the settings hold (every run: survives a reset MySQL)
    local db user pass
    db=$(env_get DB_DATABASE)
    user=$(env_get DB_USERNAME)
    pass=$(sql_quote "$(env_get DB_PASSWORD)")
    [[ $db =~ ^[A-Za-z0-9_]+$ && $user =~ ^[A-Za-z0-9_]+$ ]] || die "DB_DATABASE or DB_USERNAME in $ENV_FILE looks wrong."
    root_sql "CREATE DATABASE IF NOT EXISTS \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        CREATE USER IF NOT EXISTS '$user'@'localhost' IDENTIFIED BY '$pass';
        ALTER USER '$user'@'localhost' IDENTIFIED BY '$pass';
        GRANT ALL PRIVILEGES ON \`$db\`.* TO '$user'@'localhost'; FLUSH PRIVILEGES;" \
        || die "Could not prepare the database (messages above)."
}

shortcut() {
    cat > "$SHORTCUT.tmp" <<WEBLOC
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict><key>URL</key><string>http://localhost:$PORT</string></dict></plist>
WEBLOC
    mv -f "$SHORTCUT.tmp" "$SHORTCUT"
}

release_guards_setup() { # releases with the fix keep the first-time setup to this Mac themselves
    grep -q fromThisComputer "$DIR/current/app/Http/Controllers/Auth/LocalSetupController.php" 2> /dev/null
}

setup_done() { [[ $(db_helper "$DIR/current" has-company 2> /dev/null) == yes ]]; }

cmd_install() {
    local answering=0 pending=0 lan="" free_kb
    [[ $(uname -s) == Darwin ]] || die "This installer is for Mac. For Windows and Linux see docs/INSTALL.md"
    [[ $EUID -ne 0 ]] || die "Do not use sudo: paste the line exactly as in the guide."
    [[ -n ${BASH_EXECUTION_STRING:-} || -f ${BASH_SOURCE[0]:-} ]] \
        || die "Paste the line exactly as in the guide (/bin/bash -c \"\$(curl ...)\"): piped into bash, the installer cannot keep a copy of itself."
    launchctl print "gui/$UID_N" > /dev/null 2>&1 \
        || die "Run this in Terminal on the Mac itself (logged in on its screen), not over SSH: Diwan runs as a service of the logged-in user."

    mkdir -p "$DIR"
    exec > >(tee -a "$DIR/install.log") 2>&1
    trap 'echo "${c_err}✗ Setup stopped at line $LINENO. To ask for help send $DIR/install.log (or a screenshot of the messages above), then paste the same line again (it is safe to repeat).${c_off}" >&2' ERR
    trap on_exit EXIT

    PORT=${DIWAN_PORT:-$(web_port)}
    if ! [[ $PORT =~ ^[0-9]+$ ]] || (( PORT < 1024 || PORT > 65535 )); then die "DIWAN_PORT must be a number from 1024 to 65535."; fi

    echo "${c_info}══════════════════════════════════════════════"
    echo "  Diwan ERP setup (Mac)"
    echo "  Takes 10-20 minutes the first time"
    echo "══════════════════════════════════════════════${c_off}"
    free_kb=$(df -Pk "$HOME" | awk 'NR == 2 { print $4 }') || true
    (( ${free_kb:-99999999} > 3 * 1024 * 1024 )) || die "Not enough free disk space: Diwan and its tools need about 3 GB."
    if port_busy "$PORT" && ! agent_loaded com.diwan.web; then
        die "Port $PORT is used by another program. Quit it, or install on another port: put DIWAN_PORT=8080 and a space before the same line."
    fi

    step "1/7 Homebrew (the standard Mac package manager)"
    ensure_homebrew

    step "2/7 PHP and the MySQL database"
    ensure_php
    ensure_mysql

    step "3/7 Settings"
    lock_take || die "An update of Diwan is running right now. Wait a few minutes, then paste the line again."
    TMP=$(mktemp -d)
    conf_set PORT "$PORT"
    conf_set PHP "$PHP"
    if env_valid; then
        ensure_database
        # The usual address follows a new port (an address set by hand is left alone).
        if [[ $(env_get APP_URL) =~ ^http://localhost:[0-9]+$ && $(env_get APP_URL) != "http://localhost:$PORT" ]]; then
            env_set APP_URL "http://localhost:$PORT"
        fi
        ok "Settings and database are ready."
    else
        ok "New settings will be made with the release."
    fi

    step "4/7 Diwan"
    if [[ -e $DIR/current ]] && env_valid; then
        (cd "$DIR/current" && "$PHP" artisan optimize > /dev/null) || warn "Could not refresh the settings cache."
        # A package given by hand is installed even when it is not newer.
        update_release "$([[ -n ${DIWAN_PACKAGE:-} ]] && echo 1 || echo 0)"
    else
        first_release
    fi

    step "5/7 Starting Diwan automatically when you log in"
    install_tool
    install_agents
    ok "Diwan runs, starts whenever you log in to the Mac, and updates itself daily at 3:30 AM."

    step "6/7 Desktop shortcut"
    if shortcut 2> /dev/null; then
        ok "A Diwan shortcut is on your Desktop."
    else
        warn "Could not put a shortcut on the Desktop (macOS may have refused access): open http://localhost:$PORT instead."
    fi

    step "7/7 Checking"
    if wait_up 40; then answering=1; fi
    if ! release_guards_setup && ! setup_done; then pending=1; fi
    lan=$(lan_ip) || lan=""
    echo
    if [[ $answering == 1 ]]; then
        echo "${c_ok}══════════════════════════════════════════════════════${c_off}"
        echo "${c_ok}  Installed successfully!${c_off}"
    else
        echo "${c_warn}══════════════════════════════════════════════════════${c_off}"
        echo "${c_warn}  Installed, but Diwan is not answering yet.${c_off}"
        echo "  See $LOGS/com.diwan.web.log, or run: $BIN status"
    fi
    echo "  On this computer:            http://localhost:$PORT"
    if [[ -n $lan ]]; then echo "  From office devices/phones:  http://$lan:$PORT"; fi
    if [[ $pending == 1 ]]; then
        echo "  First time: on THIS Mac, enter your company details and the manager account."
        echo "  Office devices and phones can open Diwan once that is done."
    else
        echo "  First time: enter your company details and the manager account."
    fi
    echo "  If the Mac asks to allow incoming connections for php, choose Allow (needed for office devices)."
    echo "  Passwords:                   $DIR/secrets.txt (copy it somewhere safe)"
    echo "  Look after it:               $BIN status | update | uninstall"
    echo "${c_ok}══════════════════════════════════════════════════════${c_off}"
    open "http://localhost:$PORT" > /dev/null 2>&1 || true
    [[ $answering == 1 ]] || exit 1
}

# ------------------------------------------------------------------ the tool (~/Diwan/bin/diwan)

cmd_update() { # run daily by launchd (com.diwan.update) and when the dashboard asks (com.diwan.update-now)
    local force=0
    if [[ ${1:-} == --force ]]; then force=1; fi
    brew_env || true
    PHP=$(php_path)
    PORT=$(web_port)
    rotate_logs
    # A second request while an update runs leaves quietly: it must not mark that update failed.
    if ! lock_take; then ok "Another update is already running."; return 0; fi
    trap on_exit EXIT
    trap 'status failed "Update stopped unexpectedly (line $LINENO)"; echo "${c_err}✗ Update stopped unexpectedly (line $LINENO).${c_off}" >&2' ERR
    TMP=$(mktemp -d)
    [[ -e $DIR/current ]] || fail "Diwan is not installed in $DIR."
    refresh_queue_agent || warn "Could not set up the queue service again; the next update tries again."
    update_release "$force"
}

# The web service (com.diwan.web). Until the first-time setup has created the company, only this Mac
# can open Diwan: on the office network the first device to reach the setup page would become its owner.
cmd_serve() {
    local port=${1:-} server
    port=${port#--port=}
    [[ $port =~ ^[0-9]+$ ]] || port=$(web_port)
    PHP=$(php_path)
    cd "$DIR/current"
    if release_guards_setup || setup_done; then
        exec "$PHP" artisan serve --host=0.0.0.0 --port="$port" --no-reload
    fi
    echo "$(date '+%F %T') First-time setup not done: only this Mac can open Diwan until it is."
    "$PHP" artisan serve --host=127.0.0.1 --port="$port" --no-reload &
    server=$!
    trap 'kill_tree "$server"; exit 0' TERM INT HUP
    while kill -0 "$server" 2> /dev/null; do
        sleep 10 &
        wait $! || true
        if setup_done; then
            echo "$(date '+%F %T') First-time setup done: Diwan opens to the office network."
            kill_tree "$server"
            wait "$server" 2> /dev/null || true
            exit 0 # launchd starts the service again, this time for the whole network
        fi
    done
    wait "$server"
}

cmd_status() {
    local label state lan
    PORT=$(web_port)
    echo "Diwan $(cat "$DIR/current/VERSION" 2> /dev/null || echo '(not installed)') in $DIR"
    for label in "${JOBS[@]}"; do
        state=$(launchctl print "gui/$UID_N/$label" 2> /dev/null | awk -F' = ' '$1 ~ /^[[:space:]]*state$/ { print $2; exit }') || true
        printf '  %-22s %s\n' "$label" "${state:-not loaded}"
    done
    if curl -fs -o /dev/null --max-time 5 "http://127.0.0.1:$PORT/up"; then
        echo "  This Mac:        http://localhost:$PORT (answering)"
    else
        echo "  This Mac:        http://localhost:$PORT (NOT answering: see $LOGS)"
    fi
    if lan=$(lan_ip); then echo "  Office devices:  http://$lan:$PORT"; fi
    echo "  Last update:     $(cat "$STATUS_FILE" 2> /dev/null || echo 'none yet')"
    echo "  Backups:         $SHARED/storage/app/backups"
}

cmd_uninstall() {
    local label answer="" db user
    for label in "${JOBS[@]}"; do
        agent_stop "$label" || true
        rm -f "$AGENTS/$label.plist"
    done
    rm -f "$SHORTCUT"
    ok "Diwan's services are stopped and removed."
    if [[ ${1:-} != --purge ]]; then
        echo "Your data stays in $DIR and in the MySQL database: installing again brings everything back."
        echo "To delete everything instead: $BIN uninstall --purge"
        return 0
    fi
    [[ -d $DIR/shared && $DIR != "$HOME" && $DIR != / ]] || die "$DIR does not look like a Diwan folder: nothing was deleted."
    echo "${c_warn}This deletes ALL Diwan data on this Mac: the folder $DIR (with its backups and passwords) and the database.${c_off}"
    echo "If you may need them, first copy $SHARED/storage/app/backups and $DIR/secrets.txt somewhere safe."
    read -r -p "Type DELETE to confirm: " answer < /dev/tty || answer=""
    [[ $answer == DELETE ]] || die "Nothing was deleted."
    brew_env || true
    MYSQL_BIN="$(brew --prefix "$MYSQL_FORMULA" 2> /dev/null)/bin"
    db=$(env_get DB_DATABASE)
    user=$(env_get DB_USERNAME)
    if [[ $db =~ ^[A-Za-z0-9_]+$ && $user =~ ^[A-Za-z0-9_]+$ ]] \
        && root_sql "DROP DATABASE IF EXISTS \`$db\`; DROP USER IF EXISTS '$user'@'localhost';" 2> /dev/null; then
        ok "The database is deleted."
    else
        warn "Could not delete the database (is MySQL running?)."
    fi
    rm -rf "$DIR"
    ok "Diwan is deleted. PHP and MySQL stay installed (to remove them: brew services stop $MYSQL_FORMULA; brew uninstall $MYSQL_FORMULA $PHP_FORMULA)."
}

usage() {
    cat <<USAGE
Diwan ERP on this Mac ($DIR)
  $BIN status              versions, services, last update
  $BIN update [--force]    update now (it also runs daily at 3:30)
  $BIN uninstall           stop and remove the services (the data stays)
  $BIN uninstall --purge   also delete all the data
  bash install-mac-new.sh  install, or repair an install (safe to run again)
USAGE
}

main() {
    local cmd=install
    if (( $# )); then cmd=$1; shift; fi
    init_paths
    case $cmd in
        install) cmd_install ;;
        update) cmd_update "$@" ;;
        serve) cmd_serve "$@" ;;
        status) cmd_status ;;
        uninstall) cmd_uninstall "$@" ;;
        help | -h | --help) usage ;;
        *) usage >&2; exit 2 ;;
    esac
}

# Only definitions above: a download cut off halfway runs nothing. (DIWAN_SOURCE_ONLY=1 loads them for tests.)
if [[ ${DIWAN_SOURCE_ONLY:-} != 1 ]]; then main "$@"; exit $?; fi
