#!/usr/bin/env bash
#
# NDASA Donation Platform — server-side installer.
#
# Run this from the repo checkout on the server, e.g.:
#     cd ~/ndasa-donation && ./deploy/install.sh
#
# What it does:
#   1.  Detects the WordPress webroot (public_html/) and confirms layout.
#   2.  Creates public_html/.ndasa-donation/ (hidden, deny-all) and
#       populates it with a copy of the app.
#   3.  Installs Composer dependencies into .ndasa-donation/vendor.
#   4.  Creates public_html/donation/ (public surface) with shims, assets
#       symlink, and .htaccess.
#   5.  Installs the WP mu-plugin that bridges .env into WP Mail SMTP.
#   6.  Prompts you to edit .env, then runs a dry-run check that the app
#       can load its config without errors.
#
# Safe to re-run: every step is idempotent. It will print what it is about
# to do before making changes, and pause for y/N confirmation before
# writing into public_html/.
#
set -euo pipefail

# ——— Defaults (override via env vars if needed) ———
: "${REPO_DIR:=$(cd "$(dirname "$0")/.." && pwd)}"
: "${WEBROOT:=$HOME/public_html}"
: "${APP_HIDDEN_NAME:=.ndasa-donation}"
: "${PUBLIC_NAME:=donation}"
: "${BACKUP_TAG:=$(date +%Y%m%d-%H%M%S)}"

HIDDEN_DIR="$WEBROOT/$APP_HIDDEN_NAME"
PUBLIC_DIR="$WEBROOT/$PUBLIC_NAME"
MU_DIR="$WEBROOT/wp-content/mu-plugins"

# ——— Pretty output ———
red()    { printf '\033[31m%s\033[0m\n' "$*"; }
green()  { printf '\033[32m%s\033[0m\n' "$*"; }
yellow() { printf '\033[33m%s\033[0m\n' "$*"; }
blue()   { printf '\033[34m%s\033[0m\n' "$*"; }
bold()   { printf '\033[1m%s\033[0m\n' "$*"; }

confirm() {
    local prompt="${1:-Continue?}"
    read -r -p "$(yellow "$prompt [y/N] ")" reply
    [[ "$reply" =~ ^[Yy]$ ]]
}

fail() {
    red "ERROR: $*"
    exit 1
}

# ——— Pre-flight checks ———
bold "=== NDASA Donation Platform installer ==="
echo

blue "Source repo:    $REPO_DIR"
blue "Web root:       $WEBROOT"
blue "Hidden app dir: $HIDDEN_DIR"
blue "Public dir:     $PUBLIC_DIR"
blue "mu-plugins dir: $MU_DIR"
echo

[[ -d "$REPO_DIR/src" && -f "$REPO_DIR/composer.json" ]] \
    || fail "REPO_DIR ($REPO_DIR) does not look like the donation repo."

[[ -d "$WEBROOT" ]] \
    || fail "WEBROOT ($WEBROOT) does not exist."

[[ -f "$WEBROOT/wp-config.php" ]] \
    || fail "WEBROOT ($WEBROOT) is not a WordPress install (no wp-config.php)."

command -v php >/dev/null \
    || fail "php not on PATH."

PHP_MAJOR_MINOR=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
[[ "$(php -r 'echo PHP_VERSION_ID >= 80200 ? 1 : 0;')" == "1" ]] \
    || fail "PHP 8.2+ required (found $PHP_MAJOR_MINOR)."
green "PHP version OK ($PHP_MAJOR_MINOR)."

command -v composer >/dev/null \
    || fail "composer not on PATH."
green "Composer OK."

# Required PHP extensions.
for ext in pdo_sqlite openssl mbstring curl; do
    php -r "exit(extension_loaded('$ext') ? 0 : 1);" \
        || fail "PHP extension missing: $ext"
done
green "Required PHP extensions present."
echo

# ——— Existing install handling ———
if [[ -e "$HIDDEN_DIR" || -e "$PUBLIC_DIR" ]]; then
    yellow "An existing install was detected."
    [[ -e "$HIDDEN_DIR" ]] && echo "    $HIDDEN_DIR exists"
    [[ -e "$PUBLIC_DIR" ]] && echo "    $PUBLIC_DIR exists"
    echo
    echo "A backup tag will be appended before anything is overwritten:"
    echo "    $HIDDEN_DIR  ->  ${HIDDEN_DIR}.bak-${BACKUP_TAG}"
    echo "    $PUBLIC_DIR  ->  ${PUBLIC_DIR}.bak-${BACKUP_TAG}"
    echo
    confirm "Proceed with backup and reinstall?" \
        || { yellow "Aborted."; exit 0; }
    [[ -e "$HIDDEN_DIR" ]] && mv "$HIDDEN_DIR" "${HIDDEN_DIR}.bak-${BACKUP_TAG}"
    [[ -e "$PUBLIC_DIR" ]] && mv "$PUBLIC_DIR" "${PUBLIC_DIR}.bak-${BACKUP_TAG}"
    green "Existing install backed up."
else
    confirm "No existing install found. Proceed with fresh install?" \
        || { yellow "Aborted."; exit 0; }
fi
echo

# ——— Step 1: Hidden app directory ———
bold "[1/6] Creating hidden app directory"
mkdir -p "$HIDDEN_DIR"/{src,config,templates,public,storage}
# Copy the app tree. Exclude node_modules, tests, deploy tooling, dotfiles.
rsync -a --delete \
    --exclude='/.git/' \
    --exclude='/.github/' \
    --exclude='/.claude/' \
    --exclude='/deploy/' \
    --exclude='/tests/' \
    --exclude='/storage/' \
    --exclude='/.env' \
    --exclude='/.env.example' \
    "$REPO_DIR/src/"       "$HIDDEN_DIR/src/"
rsync -a --delete  "$REPO_DIR/config/"    "$HIDDEN_DIR/config/"
rsync -a --delete  "$REPO_DIR/templates/" "$HIDDEN_DIR/templates/"
rsync -a --delete  "$REPO_DIR/public/"    "$HIDDEN_DIR/public/"
cp "$REPO_DIR/composer.json" "$HIDDEN_DIR/composer.json"
[[ -f "$REPO_DIR/composer.lock" ]] && cp "$REPO_DIR/composer.lock" "$HIDDEN_DIR/composer.lock"

# Deny-all .htaccess inside the hidden directory.
cp "$REPO_DIR/deploy/apache/ndasa-donation.htaccess" "$HIDDEN_DIR/.htaccess"

# Storage directory: writable by the PHP-FPM user (same as file owner here).
mkdir -p "$HIDDEN_DIR/storage/logs"
chmod 700 "$HIDDEN_DIR/storage" "$HIDDEN_DIR/storage/logs"

green "Hidden dir ready at $HIDDEN_DIR"
echo

# ——— Step 2: Composer install ———
bold "[2/6] Installing PHP dependencies"
( cd "$HIDDEN_DIR" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress )
green "vendor/ installed."
echo

# ——— Step 3: .env bootstrap ———
bold "[3/6] Provisioning .env"
if [[ -f "$HIDDEN_DIR/.env" ]]; then
    yellow "  .env already exists — leaving it in place."
else
    # Seed from template, substituting the detected DB path.
    DB_ABS="$HIDDEN_DIR/storage/donations.sqlite"
    sed "s|^DB_PATH=.*|DB_PATH=$DB_ABS|" \
        "$REPO_DIR/deploy/.env.template" > "$HIDDEN_DIR/.env"
    green "  .env seeded from template."
    yellow "  You MUST edit it now to fill in the REPLACE_ME values."
    yellow "  Path: $HIDDEN_DIR/.env"
fi
chmod 600 "$HIDDEN_DIR/.env"
echo

# ——— Step 4: Public surface ———
bold "[4/6] Creating public surface at $PUBLIC_DIR"
mkdir -p "$PUBLIC_DIR"
cp "$REPO_DIR/deploy/shims/index.php"        "$PUBLIC_DIR/index.php"
cp "$REPO_DIR/deploy/shims/webhook.php"      "$PUBLIC_DIR/webhook.php"
cp "$REPO_DIR/deploy/apache/donation.htaccess" "$PUBLIC_DIR/.htaccess"

# Assets: symlink into the hidden dir so there's one copy on disk.
ln -sfn "../${APP_HIDDEN_NAME}/public/assets" "$PUBLIC_DIR/assets"

green "Public surface ready."
echo

# ——— Step 5: WordPress mu-plugin ———
bold "[5/6] Installing WordPress mu-plugin"
if [[ ! -d "$MU_DIR" ]]; then
    mkdir -p "$MU_DIR"
    green "  Created $MU_DIR"
fi
cp "$REPO_DIR/deploy/wp/ndasa-shared-env.php" "$MU_DIR/ndasa-shared-env.php"
green "  Installed $MU_DIR/ndasa-shared-env.php"
echo
yellow "  Once .env has valid SMTP values, WP will read them via this plugin."
yellow "  You can then clear the password field in the WP Mail SMTP UI;"
yellow "  it will fall back to the WPMS_SMTP_PASS constant defined in code."
echo

# ——— Step 6: Dry-run sanity check ———
bold "[6/6] Dry-run sanity check"
if grep -q 'REPLACE_ME' "$HIDDEN_DIR/.env"; then
    yellow "  .env still contains REPLACE_ME placeholders. Edit it, then run:"
    yellow "      php -r 'require \"$HIDDEN_DIR/config/app.php\"; echo \"OK\\n\";'"
else
    if php -r "
        \$_ENV['_NDASA_DRYRUN']=1;
        error_reporting(E_ALL);
        ini_set('display_errors','1');
        require '$HIDDEN_DIR/config/app.php';
        echo \"Config loads clean.\n\";
    " >/dev/null 2>&1; then
        green "  Config loads cleanly. App is ready."
    else
        yellow "  Config did not load cleanly — run this to see the error:"
        yellow "      php -d display_errors=1 $HIDDEN_DIR/config/app.php"
    fi
fi
echo

bold "=== Install complete ==="
echo
echo "Next steps:"
echo "  1. Edit $HIDDEN_DIR/.env and replace every REPLACE_ME with a real value."
echo "  2. Visit https://ndasafoundation.org/donation/ in a browser — expect the donation form."
echo "  3. In Stripe dashboard -> Developers -> Webhooks, add an endpoint:"
echo "        URL:      https://ndasafoundation.org/donation/webhook.php"
echo "        API ver:  2026-03-25.dahlia"
echo "        Events:   checkout.session.completed"
echo "                  checkout.session.async_payment_succeeded"
echo "                  checkout.session.async_payment_failed"
echo "                  charge.refunded"
echo "                  payment_intent.payment_failed"
echo "     Copy the whsec_... signing secret into STRIPE_WEBHOOK_SECRET in .env."
echo "  4. In WP admin -> WP Mail SMTP, send a test email to confirm the"
echo "     mu-plugin credentials are working. Then clear the password field"
echo "     in the UI; the constant from the mu-plugin is what matters."
echo "  5. Make a real \$1 test donation in Stripe test mode and confirm"
echo "     both the donor receipt (from Stripe) and the staff notification"
echo "     (from ReceiptMailer) arrive."
echo
echo "To roll back: rename ${HIDDEN_DIR} and ${PUBLIC_DIR} to their .bak-* peers."
