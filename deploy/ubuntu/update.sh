#!/usr/bin/env bash
#
# «الديوان» — تحديث خادم Ubuntu إلى آخر نسخة من الفرع المثبت.
#
#   sudo bash /var/www/diwan/deploy/ubuntu/update.sh
#
# يسحب الكود، ويعيد تثبيت المكتبات وبناء الواجهة فقط عند تغيرها، ويطبق
# ترحيلات قاعدة البيانات، ثم يعيد تشغيل العمليات الخلفية. يأخذ نسخة احتياطية
# من القاعدة قبل الترحيل.

set -Eeuo pipefail

APP_DIR=/var/www/diwan
APP_USER=www-data
APP_HOME=/var/www

c_info=$'\e[36m'; c_ok=$'\e[32m'; c_err=$'\e[31m'; c_off=$'\e[0m'
step() { echo; echo "${c_info}==> $*${c_off}"; }
ok()   { echo "${c_ok}✓ $*${c_off}"; }
as_app() { sudo -u "$APP_USER" -H env HOME="$APP_HOME" COMPOSER_HOME="$APP_HOME/.composer" "$@"; }
artisan() { as_app php "$APP_DIR/artisan" "$@"; }
# PhpSpreadsheet 1.x (Excel import) declares PHP < 8.5; every other package supports 8.5.
composer_flags() {
    if php -r 'exit(PHP_VERSION_ID >= 80500 ? 0 : 1);' 2>/dev/null; then
        echo "  (PHP $(php -r 'echo PHP_VERSION;'): تجاوز قيد إصدار PHP لمكتبة Excel؛ بقية المكتبات تدعمه)" >&2
        echo "--ignore-platform-req=php"
    fi
}
trap 'echo "${c_err}✗ توقف التحديث عند السطر $LINENO. النظام في وضع الصيانة: أصلح المشكلة ثم أعد التشغيل، أو أعده للعمل بـ: sudo -u www-data php $APP_DIR/artisan up${c_off}" >&2' ERR

[[ $EUID -eq 0 ]] || { echo "شغّله بصلاحية root: sudo bash update.sh"; exit 1; }
cd "$APP_DIR"
BRANCH=$(as_app git -C "$APP_DIR" rev-parse --abbrev-ref HEAD)
BEFORE=$(as_app git -C "$APP_DIR" rev-parse HEAD)

step "سحب آخر نسخة من $BRANCH"
as_app git -C "$APP_DIR" fetch origin "$BRANCH"
AFTER=$(as_app git -C "$APP_DIR" rev-parse "origin/$BRANCH")
if [[ $BEFORE == "$AFTER" ]]; then
    ok "لا توجد تحديثات جديدة."
    exit 0
fi
CHANGED=$(as_app git -C "$APP_DIR" diff --name-only "$BEFORE" "$AFTER")

artisan down --retry=60 || true
step "نسخة احتياطية قبل الترحيل"
if ! artisan erp:backup --tag=preupdate; then
    echo "تعذرت النسخة الاحتياطية. يكمل التحديث بعد 10 ثوانٍ (Ctrl+C للإلغاء)."
    sleep 10
fi

as_app git -C "$APP_DIR" merge --ff-only "origin/$BRANCH"
ok "$(as_app git -C "$APP_DIR" log --oneline -1)"

if grep -qE '^composer\.(json|lock)$' <<< "$CHANGED"; then
    step "تحديث مكتبات PHP"
    mapfile -t COMPOSER_FLAGS < <(composer_flags)
    as_app composer install --working-dir="$APP_DIR" --no-dev --optimize-autoloader --no-interaction "${COMPOSER_FLAGS[@]}"
fi
if grep -qE '^(package(-lock)?\.json|vite\.config\.js|tailwind\.config\.js|postcss\.config\.js|resources/(js|css)/)' <<< "$CHANGED"; then
    step "بناء الواجهة"
    as_app sh -c "cd $APP_DIR && npm ci --no-audit --no-fund && npm run build && rm -rf node_modules"
fi

step "ترحيلات قاعدة البيانات والتهيئة"
artisan migrate --force
artisan optimize
supervisorctl restart 'diwan:*'
PHP_V=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
systemctl reload "php$PHP_V-fpm"
artisan up
ok "تم التحديث إلى $(as_app git -C "$APP_DIR" log --oneline -1 --format='%h %s')"
