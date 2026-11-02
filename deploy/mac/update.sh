#!/usr/bin/env bash
#
# Diwan ERP - updates the Mac install to the latest release.
#
#   bash ~/Diwan/current/deploy/mac/update.sh
#
# Runs daily (com.diwan.update). Prepares the new release next to the running
# one, backs up the database when it changes, then switches over; if the database
# update fails or the system does not answer afterwards, it restores that backup
# and switches back by itself.
#
# Options: DIWAN_PACKAGE=path-or-url-of-the-zip  DIWAN_DIR=~/Diwan  DIWAN_PHP=path-of-php

set -Eeuo pipefail

REPO=turkialawlqy/Diwan-ERP
PACKAGE_URL=${DIWAN_PACKAGE:-https://github.com/$REPO/releases/latest/download/diwan-erp.zip}
DIR=${DIWAN_DIR:-$HOME/Diwan}
KEEP=3

c_ok=$'\e[32m'; c_err=$'\e[31m'; c_off=$'\e[0m'
ok()  { echo "${c_ok}✓ $*${c_off}"; }
STATUS="$DIR/shared/storage/app/update-status.json"
# What the dashboard shows the owner (state: running | done | failed | uptodate).
status() {
    { printf '{"state":"%s","from":"%s","to":"%s","message":"%s","at":%s}\n' "$1" "${INSTALLED:-}" "${VERSION:-}" "${2:-}" "$(date +%s)" > "$STATUS.tmp" \
        && mv -f "$STATUS.tmp" "$STATUS"; } 2> /dev/null || true
}
die() { echo "${c_err}✗ $*${c_off}" >&2; status failed "$*"; exit 1; }

for b in /opt/homebrew/bin/brew /usr/local/bin/brew; do [[ -x $b ]] && eval "$("$b" shellenv)" && break; done
PHP=${DIWAN_PHP:-$(brew --prefix php@8.3)/bin/php}
if [[ ! -f $HOME/Library/LaunchAgents/com.diwan.update-now.plist ]]; then
    mkdir -p "$DIR/shared/storage/app/update-queue" "$HOME/Library/LaunchAgents"
    cat > "$HOME/Library/LaunchAgents/com.diwan.update-now.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
    <key>Label</key><string>com.diwan.update-now</string>
    <key>ProgramArguments</key><array><string>/bin/bash</string><string>$DIR/current/deploy/mac/update.sh</string></array>
    <key>QueueDirectories</key><array><string>$DIR/shared/storage/app/update-queue</string></array>
    <key>ThrottleInterval</key><integer>30</integer>
</dict></plist>
PLIST
    launchctl bootstrap "gui/$(id -u)" "$HOME/Library/LaunchAgents/com.diwan.update-now.plist" > /dev/null 2>&1 || true
fi
LOCK="$DIR/.update.lock"
# The lock records the updater's process id and start time, so a lock left by an update that
# was cut off (power cut, forced shutdown) is taken over instead of blocking every later update;
# the start time tells it from a new process given the same id after a restart.
# A second request while one runs leaves quietly: it must not mark that update failed.
started_at() { ps -p "$1" -o lstart= 2> /dev/null | awk '{ $1 = $1; print }' || true; } # (ps pads it with spaces)
if ! mkdir "$LOCK" 2> /dev/null; then
    owner="" since=""
    read -r owner since 2> /dev/null < "$LOCK/owner" || true
    if [[ $owner =~ ^[0-9]+$ && -n $since && $(started_at "$owner") == "$since" ]]; then
        ok "Another update is already running."
        exit 0
    fi
    rm -rf "$LOCK"
    mkdir "$LOCK" 2> /dev/null || { ok "Another update is already running."; exit 0; }
fi
echo "$$ $(started_at $$)" > "$LOCK/owner"
TMP=$(mktemp -d)
NEW=""
SWITCHED=0
# However the script ends, a prepared release that is not the running one goes away.
cleanup() {
    rm -rf "$TMP" "$LOCK"
    if [[ -n $NEW && $SWITCHED != 1 ]]; then rm -rf "$NEW"; fi
}
trap cleanup EXIT

# Queue workers set up by earlier installers served only the "default" queue: tax-authority
# submissions (zatca), webhooks and data exports waited in the jobs table for good.
QUEUE_ARG=--queue=zatca,default,webhooks,exports
refresh_queue_agent() {
    local plist="$HOME/Library/LaunchAgents/com.diwan.queue.plist" domain job n=0 at="" arg
    domain="gui/$(id -u)"
    job="$domain/com.diwan.queue"
    [[ -f $plist ]] || return 0
    # Run over SSH, launchd's session of the logged-in user is out of reach: the next daily run does it.
    launchctl print "$domain" > /dev/null 2>&1 || return 0
    if grep -q "<string>$QUEUE_ARG</string>" "$plist" && launchctl print "$job" > /dev/null 2>&1; then return 0; fi
    while arg=$(/usr/libexec/PlistBuddy -c "Print :ProgramArguments:$n" "$plist" 2> /dev/null); do
        if [[ $arg == --queue=* ]]; then at=$n; fi
        n=$((n + 1))
    done
    if [[ -n $at ]]; then
        /usr/libexec/PlistBuddy -c "Set :ProgramArguments:$at $QUEUE_ARG" "$plist" || return 1
    else
        /usr/libexec/PlistBuddy -c "Add :ProgramArguments:$n string $QUEUE_ARG" "$plist" || return 1
    fi
    # launchd reads the plist again only when the job is loaded again.
    launchctl bootout "$job" > /dev/null 2>&1 || true
    for _ in $(seq 1 40); do
        launchctl print "$job" > /dev/null 2>&1 || break
        sleep 0.5
    done
    for _ in 1 2 3 4 5; do
        if launchctl bootstrap "$domain" "$plist" 2> /dev/null; then
            ok "The queue service now serves every queue."
            return 0
        fi
        sleep 2 # (right after a bootout launchd may still answer "Input/output error")
    done
    launchctl bootstrap "$domain" "$plist" # the last try shows launchd's message
}
refresh_queue_agent || echo "${c_err}✗ Could not set up the queue service again; the next update tries again.${c_off}" >&2

# The dashboard asks for an update by dropping a file in this folder (launchd QueueDirectories).
rm -f "$DIR"/shared/storage/app/update-queue/* 2> /dev/null || true
CURRENT=$(cd "$DIR/current" && pwd -P)
INSTALLED=$(cat "$CURRENT/VERSION" 2> /dev/null || echo 0)

status running "Downloading"
if [[ -f $PACKAGE_URL ]]; then
    cp "$PACKAGE_URL" "$TMP/diwan-erp.zip"
else
    curl -fsSL -o "$TMP/diwan-erp.zip" "$PACKAGE_URL" || die "Could not download the update (internet connection?)."
    if curl -fsSL -o "$TMP/sha" "$PACKAGE_URL.sha256" 2> /dev/null; then
        [[ $(shasum -a 256 "$TMP/diwan-erp.zip" | cut -d' ' -f1) == "$(tr -d '[:space:]' < "$TMP/sha")" ]] || die "The downloaded update is corrupted."
    fi
fi
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

# Small database chores through a release's own settings: list the tables, or drop some.
db() { # release tables | release drop table...
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
        }
    ' "$@"
}

# Puts the database back as it was before the update: the previous version restores its
# own backup, then the tables the update created (absent from the backup) are dropped,
# or the next attempt would stop on "table already exists".
restore_database() {
    status running "Restoring the database"
    if ! "$PHP" "$CURRENT/artisan" erp:restore "$BACKUP" --force > /dev/null; then
        "$PHP" "$CURRENT/artisan" up > /dev/null 2>&1 || true
        return 1
    fi
    local after table extra=()
    after=$(db "$CURRENT" tables) || return 1
    while IFS= read -r table; do
        if [[ -n $table ]]; then extra+=("$table"); fi
    done < <(comm -13 <(sort <<< "$TABLES") <(sort <<< "$after"))
    if (( ${#extra[@]} )); then db "$CURRENT" drop "${extra[@]}" || return 1; fi
    "$PHP" "$CURRENT/artisan" optimize > /dev/null 2>&1 || true
}

# Anything failing before the switch leaves the running version untouched.
trap 'status failed "Update stopped; the previous version keeps running"; echo "${c_err}✗ Update stopped; Diwan keeps running the previous version, unchanged.${c_off}" >&2' ERR
"$PHP" "$NEW/artisan" optimize > /dev/null
BACKUP=""
TABLES=""
PENDING=$("$PHP" "$NEW/artisan" migrate:status --pending 2>&1) || die "Could not read the database state, so the update was cancelled."
if [[ $PENDING == *Pending* ]]; then
    status running "Backing up"
    out=$("$PHP" "$CURRENT/artisan" erp:backup --tag=preupdate 2>&1) || die "The backup failed, so the update was cancelled."
    backup_re='(diwan-preupdate-[0-9-]+\.zip)'
    if [[ $out =~ $backup_re ]]; then BACKUP=${BASH_REMATCH[1]}; fi
    [[ -n $BACKUP ]] || die "The backup failed, so the update was cancelled."
    TABLES=$(db "$CURRENT" tables) || die "Could not read the database tables, so the update was cancelled."
    status running "Updating the database"
    if ! "$PHP" "$NEW/artisan" migrate --force > /dev/null; then
        restore_database || die "The database update failed and restoring the backup $BACKUP failed too: Diwan may not work. Ask for help."
        die "The database update failed; the database was restored and version $INSTALLED keeps running."
    fi
fi
trap 'status failed "Update stopped unexpectedly (line $LINENO)"' ERR

restart() {
    for label in com.diwan.web com.diwan.queue com.diwan.scheduler; do
        launchctl kickstart -k "gui/$(id -u)/$label" > /dev/null 2>&1 || true
    done
}
ln -sfn "$NEW" "$DIR/current"
SWITCHED=1
restart

# The port the web service really listens on (its launchd job), else the one in APP_URL.
PORT=8000
port_re='--port=([0-9]+)'
url_re=':([0-9]+)/?"?$'
if [[ $(cat "$HOME/Library/LaunchAgents/com.diwan.web.plist" 2> /dev/null) =~ $port_re ]]; then
    PORT=${BASH_REMATCH[1]}
elif [[ $(grep -E '^APP_URL=' "$DIR/shared/.env") =~ $url_re ]]; then
    PORT=${BASH_REMATCH[1]}
fi
healthy=0
for _ in 1 2 3 4 5 6 7 8 9 10; do
    sleep 2
    curl -fs -o /dev/null --max-time 10 "http://127.0.0.1:$PORT/up" && { healthy=1; break; }
done
if [[ $healthy != 1 ]]; then
    ln -sfn "$CURRENT" "$DIR/current"
    SWITCHED=0
    if [[ -n $BACKUP ]] && ! restore_database; then
        restart
        die "Version $VERSION did not start; switched back to $INSTALLED, but restoring the database backup $BACKUP failed: Diwan may not work. Ask for help."
    fi
    restart
    die "Version $VERSION did not start; switched back to $INSTALLED automatically${BACKUP:+ and restored the database}."
fi

ls -1d "$DIR/releases"/*/ | sed 's#/$##' | sort -r | tail -n +$((KEEP + 1)) | while read -r old; do
    [[ $old == "$(cd "$DIR/current" && pwd -P)" ]] || rm -rf "$old"
done
status done "Updated"
ok "Updated from $INSTALLED to $VERSION."
