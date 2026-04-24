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
#   5.  Prompts you to edit .env, then runs a dry-run check that the app
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
# Backups live OUTSIDE the webroot. Keeping them in public_html/ worked but
# put copies of .env, the SQLite DB, and app source next to WordPress where
# a misconfigured .htaccess or a stray indexer could expose them. ~/backups/
# is on the same filesystem on every managed host we've seen, so mv is still
# atomic and instant. Override with BACKUP_ROOT if your layout differs.
: "${BACKUP_ROOT:=$HOME/backups/ndasa-donation}"

HIDDEN_DIR="$WEBROOT/$APP_HIDDEN_NAME"
PUBLIC_DIR="$WEBROOT/$PUBLIC_NAME"

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
blue "Backup root:    $BACKUP_ROOT"
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
RESCUED_ENV=""
if [[ -e "$HIDDEN_DIR" || -e "$PUBLIC_DIR" ]]; then
    yellow "An existing install was detected."
    [[ -e "$HIDDEN_DIR" ]] && echo "    $HIDDEN_DIR exists"
    [[ -e "$PUBLIC_DIR" ]] && echo "    $PUBLIC_DIR exists"
    echo
    # Backup targets live in $BACKUP_ROOT (default ~/backups/ndasa-donation)
    # so they don't clutter public_html or risk being served to the web.
    BACKUP_HIDDEN_DIR="$BACKUP_ROOT/$APP_HIDDEN_NAME.bak-$BACKUP_TAG"
    BACKUP_PUBLIC_DIR="$BACKUP_ROOT/$PUBLIC_NAME.bak-$BACKUP_TAG"
    echo "A backup will be written to:"
    echo "    $HIDDEN_DIR  ->  $BACKUP_HIDDEN_DIR"
    echo "    $PUBLIC_DIR  ->  $BACKUP_PUBLIC_DIR"
    if [[ -f "$HIDDEN_DIR/.env" ]]; then
        echo
        green "The active .env will be preserved and restored into the new install."
        echo "    (It is NEVER overwritten by this script.)"
    fi
    if [[ -d "$HIDDEN_DIR/storage" ]]; then
        echo
        green "The storage/ directory (SQLite DB, logs) will be preserved."
        echo "    Donations, page views, admin audit, and app config survive the upgrade."
    fi
    echo
    confirm "Proceed with backup and reinstall?" \
        || { yellow "Aborted."; exit 0; }

    # Rescue the live .env BEFORE moving the hidden dir, so it can be
    # restored into the freshly created hidden dir in step 3. The .env
    # also remains inside the .bak-* directory as a secondary copy.
    if [[ -f "$HIDDEN_DIR/.env" ]]; then
        RESCUED_ENV="$(mktemp -t ndasa-env.XXXXXX)"
        cp -p "$HIDDEN_DIR/.env" "$RESCUED_ENV"
        chmod 600 "$RESCUED_ENV"
        green "Active .env rescued to $RESCUED_ENV"
    fi

    # Rescue the live storage/ directory the same way. Move (not copy) so we
    # don't duplicate multi-MB DB files during the normal path.
    #
    # We also keep a belt-and-braces COPY of the same contents inside the
    # .bak-TAG dir before the rename, so a mid-install failure (composer,
    # rsync, etc.) that triggers the EXIT trap and wipes the rescue tempdir
    # still leaves one readable copy on disk in $BACKUP_ROOT. The copy is
    # removed once the restore step confirms the live storage/ has content.
    RESCUED_STORAGE=""
    STORAGE_SAFETY_COPY=""
    if [[ -d "$HIDDEN_DIR/storage" ]]; then
        RESCUED_STORAGE="$(mktemp -d -t ndasa-storage.XXXXXX)"

        # Safety copy goes INSIDE what will become the .bak dir. cp -a
        # preserves perms/timestamps and handles hidden files.
        STORAGE_SAFETY_COPY="$HIDDEN_DIR/storage.safety-copy"
        if cp -a "$HIDDEN_DIR/storage/." "$STORAGE_SAFETY_COPY/" 2>/dev/null; then
            chmod -R go-rwx "$STORAGE_SAFETY_COPY" 2>/dev/null || true
            green "Storage safety copy staged for backup dir."
        else
            # cp failure is non-fatal — worst case we fall back to the rescue
            # tempdir behavior. But warn loudly so the operator knows.
            STORAGE_SAFETY_COPY=""
            yellow "Could not stage storage safety copy — continuing with tempdir rescue only."
        fi

        # mv contents, not the directory itself, so mktemp's dir (mode 700) is reused.
        if compgen -G "$HIDDEN_DIR/storage/"* > /dev/null \
        || compgen -G "$HIDDEN_DIR/storage/".* > /dev/null; then
            # shellcheck disable=SC2086
            mv "$HIDDEN_DIR/storage/"* "$RESCUED_STORAGE/" 2>/dev/null || true
            # Hidden files (rare but possible, e.g. sqlite-wal/shm journals).
            # Skip the safety-copy dir we just created — it's not real storage.
            find "$HIDDEN_DIR/storage/" -maxdepth 1 -mindepth 1 -name '.*' \
                -exec mv {} "$RESCUED_STORAGE/" \; 2>/dev/null || true
        fi
        green "Active storage/ rescued to $RESCUED_STORAGE"
    fi

    # Ensure the backup root exists and is private to the installing user.
    # chmod 700 blocks other local accounts on shared hosts from reading
    # old .env copies inside snapshot dirs.
    mkdir -p "$BACKUP_ROOT"
    chmod 700 "$BACKUP_ROOT"

    [[ -e "$HIDDEN_DIR" ]] && mv "$HIDDEN_DIR" "$BACKUP_HIDDEN_DIR"
    [[ -e "$PUBLIC_DIR" ]] && mv "$PUBLIC_DIR" "$BACKUP_PUBLIC_DIR"
    green "Existing install backed up to $BACKUP_ROOT/"
else
    confirm "No existing install found. Proceed with fresh install?" \
        || { yellow "Aborted."; exit 0; }
fi
echo

# Guarantee rescued artefacts are cleaned up no matter how we exit. On
# success, the restore step has already moved RESCUED_STORAGE contents
# into the new install and removed the safety copy, so these are no-ops.
# On early-exit failure, the tempdir gets cleaned BUT the safety copy
# in the backup dir is preserved — operators can recover from it by
# copying $BACKUP_HIDDEN_DIR/storage.safety-copy/* into the new install.
cleanup_rescued() {
    local rc=$?
    [[ -n "$RESCUED_ENV" && -f "$RESCUED_ENV" ]] && rm -f "$RESCUED_ENV"
    [[ -n "${RESCUED_STORAGE:-}" && -d "$RESCUED_STORAGE" ]] && rm -rf "$RESCUED_STORAGE"
    if [[ $rc -ne 0 && -n "${STORAGE_SAFETY_COPY:-}" ]]; then
        # Shift safety-copy to backup-dir path (after the mv of HIDDEN_DIR)
        local safety="${BACKUP_HIDDEN_DIR:-$STORAGE_SAFETY_COPY}/storage.safety-copy"
        [[ -d "$safety" ]] || safety="$STORAGE_SAFETY_COPY"
        if [[ -d "$safety" ]]; then
            red "Install aborted. Your data is preserved at:"
            red "   $safety"
            red "To recover after fixing the failure, copy its contents into"
            red "   $HIDDEN_DIR/storage/"
        fi
    fi
}
trap cleanup_rescued EXIT

# ——— Step 1: Hidden app directory ———
bold "[1/5] Creating hidden app directory"
mkdir -p "$HIDDEN_DIR"/{src,config,templates,public,bin,storage}
# Copy the app tree. Each rsync is scoped to a single subdirectory so
# only intended parts land in the deployed tree — `deploy/`, `tests/`,
# and dotfiles are never copied.
rsync -a --delete  "$REPO_DIR/src/"        "$HIDDEN_DIR/src/"
rsync -a --delete  "$REPO_DIR/config/"     "$HIDDEN_DIR/config/"
rsync -a --delete  "$REPO_DIR/templates/"  "$HIDDEN_DIR/templates/"
rsync -a --delete  "$REPO_DIR/public/"     "$HIDDEN_DIR/public/"
# bin/ holds operator CLI tools (stripe-import, check-env-sync). The app
# itself doesn't need them at runtime, but operators do, and /bin/ is
# Require-all-denied by the hidden dir's .htaccess so it isn't web-served.
rsync -a --delete  "$REPO_DIR/bin/"        "$HIDDEN_DIR/bin/"
cp "$REPO_DIR/composer.json" "$HIDDEN_DIR/composer.json"
[[ -f "$REPO_DIR/composer.lock" ]] && cp "$REPO_DIR/composer.lock" "$HIDDEN_DIR/composer.lock"

# Deny-all .htaccess inside the hidden directory.
cp "$REPO_DIR/deploy/apache/ndasa-donation.htaccess" "$HIDDEN_DIR/.htaccess"

# Storage directory: writable by the PHP-FPM user (same as file owner here).
mkdir -p "$HIDDEN_DIR/storage/logs"

# Restore the rescued storage contents if we had a prior install. Donations,
# page_views, stripe_events, app_config, admin_audit, and any logs all flow
# back into the fresh install here. Files are moved (not copied) so a failure
# leaves the rescued dir partially populated and diagnosable.
if [[ -n "${RESCUED_STORAGE:-}" && -d "$RESCUED_STORAGE" ]]; then
    if compgen -G "$RESCUED_STORAGE/"* > /dev/null \
    || compgen -G "$RESCUED_STORAGE/".* > /dev/null; then
        # shellcheck disable=SC2086
        mv "$RESCUED_STORAGE/"* "$HIDDEN_DIR/storage/" 2>/dev/null || true
        find "$RESCUED_STORAGE/" -maxdepth 1 -mindepth 1 -name '.*' \
            -exec mv {} "$HIDDEN_DIR/storage/" \; 2>/dev/null || true
        green "  Previous storage/ contents restored (DB and logs preserved)."
    fi
    # Ensure the tempdir is removed even if the trap runs later.
    rmdir "$RESCUED_STORAGE" 2>/dev/null || true
fi

# If the safety-copy inside the backup dir was created, the restore above
# is now the live authoritative copy. Remove the safety copy from the
# backup dir so we don't persist two copies of the DB indefinitely.
# BACKUP_HIDDEN_DIR only exists when an install was actually replaced.
if [[ -n "${BACKUP_HIDDEN_DIR:-}" && -d "$BACKUP_HIDDEN_DIR/storage.safety-copy" ]]; then
    # Sanity check: only clean up if the live storage/ has at least one
    # file that looks like our DB. If the restore somehow failed silently,
    # we want the safety copy to survive so operators can recover by hand.
    if [[ -f "$HIDDEN_DIR/storage/donations.sqlite" ]] \
    && [[ -s "$HIDDEN_DIR/storage/donations.sqlite" ]]; then
        rm -rf "$BACKUP_HIDDEN_DIR/storage.safety-copy"
        STORAGE_SAFETY_COPY=""
    else
        yellow "Live storage/ looks empty after restore — keeping safety copy at"
        yellow "   $BACKUP_HIDDEN_DIR/storage.safety-copy"
    fi
fi

chmod 700 "$HIDDEN_DIR/storage" "$HIDDEN_DIR/storage/logs"

green "Hidden dir ready at $HIDDEN_DIR"
echo

# ——— Step 2: Composer install ———
bold "[2/5] Installing PHP dependencies"
( cd "$HIDDEN_DIR" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress )
green "vendor/ installed."
echo

# ——— Step 3: .env bootstrap ———
bold "[3/5] Provisioning .env"
if [[ -f "$HIDDEN_DIR/.env" ]]; then
    yellow "  .env already exists — leaving it in place."
elif [[ -n "$RESCUED_ENV" && -f "$RESCUED_ENV" ]]; then
    cp -p "$RESCUED_ENV" "$HIDDEN_DIR/.env"
    green "  Restored the previously active .env — existing config preserved."
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
bold "[4/5] Creating public surface at $PUBLIC_DIR"
mkdir -p "$PUBLIC_DIR"
cp "$REPO_DIR/deploy/shims/index.php"        "$PUBLIC_DIR/index.php"
cp "$REPO_DIR/deploy/shims/webhook.php"      "$PUBLIC_DIR/webhook.php"
cp "$REPO_DIR/deploy/apache/donation.htaccess" "$PUBLIC_DIR/.htaccess"

# Assets: symlink into the hidden dir so there's one copy on disk.
ln -sfn "../${APP_HIDDEN_NAME}/public/assets" "$PUBLIC_DIR/assets"

green "Public surface ready."
echo

# ——— Step 5: Dry-run sanity check ———
bold "[5/5] Dry-run sanity check"
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
echo "     Copy the whsec_... signing secret into STRIPE_LIVE_WEBHOOK_SECRET in .env."
echo "  4. Flip the admin mode toggle to test, make a \$1 test donation with a"
echo "     Stripe test card, and confirm Stripe sends the donor receipt"
echo "     (configured in Stripe Dashboard -> Settings -> Customer emails)."
echo
echo "To roll back: move the newest snapshot pair from $BACKUP_ROOT/ back into"
echo "place, e.g.:"
echo "    mv $BACKUP_ROOT/$APP_HIDDEN_NAME.bak-<TAG> $HIDDEN_DIR"
echo "    mv $BACKUP_ROOT/$PUBLIC_NAME.bak-<TAG>      $PUBLIC_DIR"
