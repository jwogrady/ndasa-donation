#!/usr/bin/env bash
# NDASA donation platform — production diagnostic (READ-ONLY).
# Safe to run on prod: no writes, no restarts, no secrets printed.
# Usage: bash ~/ndasa-donation/deploy/diagnose.sh
set -u

HIDDEN_DIR="$HOME/public_html/.ndasa-donation"
PUBLIC_DIR="$HOME/public_html/donation"
ENV_FILE="$HIDDEN_DIR/.env"
APP_LOG="$HIDDEN_DIR/storage/logs/app.log"
SQLITE_DB="$HIDDEN_DIR/storage/donations.sqlite"

bold()   { printf '\n\033[1m== %s ==\033[0m\n' "$*"; }
ok()     { printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn()   { printf '  \033[33m!\033[0m %s\n' "$*"; }
bad()    { printf '  \033[31m✗\033[0m %s\n' "$*"; }
info()   { printf '    %s\n' "$*"; }

# —————————————————————————————————————————————————————————
bold "1. Paths & ownership"
for p in "$HIDDEN_DIR" "$PUBLIC_DIR" "$ENV_FILE" "$HIDDEN_DIR/storage" "$HIDDEN_DIR/vendor"; do
    if [[ -e "$p" ]]; then
        stat_out=$(stat -c '%a %U:%G' "$p" 2>/dev/null || stat -f '%Lp %Su:%Sg' "$p")
        ok "exists: $p  ($stat_out)"
    else
        bad "MISSING: $p"
    fi
done

# —————————————————————————————————————————————————————————
bold "2. .env sanity (values redacted)"
if [[ ! -r "$ENV_FILE" ]]; then
    bad "Cannot read $ENV_FILE as user $(whoami)."
else
    mode=$(stat -c '%a' "$ENV_FILE" 2>/dev/null || stat -f '%Lp' "$ENV_FILE")
    if [[ "$mode" == "600" ]]; then ok ".env mode is 600"; else warn ".env mode is $mode (expected 600)"; fi

    # STRIPE_PUBLISHABLE_KEY is NOT required — Checkout is hosted, no JS client key needed.
    # Read keys up front so the sanity loop and later sections share one source.
    # Mode-prefixed names win; legacy unprefixed names are the fallback the
    # app itself uses (see src/Admin/AppConfig.php::resolveStripeCredentials).
    read_env() { awk -F= -v k="$1" '$1==k{sub(/^[^=]*=/,""); gsub(/^["'"'"']|["'"'"']$/,""); print; exit}' "$ENV_FILE"; }

    live_sk=$(read_env STRIPE_LIVE_SECRET_KEY)
    live_wh=$(read_env STRIPE_LIVE_WEBHOOK_SECRET)
    test_sk=$(read_env STRIPE_TEST_SECRET_KEY)
    test_wh=$(read_env STRIPE_TEST_WEBHOOK_SECRET)
    legacy_sk=$(read_env STRIPE_SECRET_KEY)
    legacy_wh=$(read_env STRIPE_WEBHOOK_SECRET)

    # Effective live credentials: mode-prefixed wins, else legacy fallback.
    eff_live_sk="${live_sk:-$legacy_sk}"
    eff_live_wh="${live_wh:-$legacy_wh}"

    # APP_URL and DB_PATH are simple required vars.
    for k in APP_URL DB_PATH; do
        line=$(grep -E "^${k}=" "$ENV_FILE" | head -1 || true)
        if [[ -z "$line" ]]; then bad "$k is MISSING from .env"; continue; fi
        val=${line#*=}; val=${val%\"}; val=${val#\"}; val=${val%\'}; val=${val#\'}
        if [[ -z "$val" || "$val" == "REPLACE_ME" ]]; then
            bad "$k is empty or REPLACE_ME"
            continue
        fi
        case "$k" in
            APP_URL) ok "APP_URL = $val" ;;
            DB_PATH)
                ok "DB_PATH = $val"
                [[ -e "$val" ]] || warn "DB_PATH file does not yet exist (first request will create it)"
                ;;
        esac
    done

    # Live credentials.
    if [[ -z "$eff_live_sk" || "$eff_live_sk" == "REPLACE_ME" ]]; then
        bad "Live secret key missing (expected STRIPE_LIVE_SECRET_KEY, or legacy STRIPE_SECRET_KEY)"
    else
        src="STRIPE_LIVE_SECRET_KEY"
        [[ -z "$live_sk" ]] && src="STRIPE_SECRET_KEY (legacy fallback)"
        if   [[ "$eff_live_sk" == sk_live_* ]]; then ok "$src = sk_live_… (LIVE mode)"
        elif [[ "$eff_live_sk" == sk_test_* ]]; then warn "$src = sk_test_… (TEST mode — is this intended on prod?)"
        elif [[ "$eff_live_sk" == rk_*      ]]; then warn "$src is a restricted key (rk_…) — may lack Checkout scope"
        else                                         bad  "$src does not start with sk_ / rk_"
        fi
    fi
    if [[ -z "$eff_live_wh" || "$eff_live_wh" == "REPLACE_ME" ]]; then
        bad "Live webhook secret missing (expected STRIPE_LIVE_WEBHOOK_SECRET, or legacy STRIPE_WEBHOOK_SECRET)"
    else
        src="STRIPE_LIVE_WEBHOOK_SECRET"
        [[ -z "$live_wh" ]] && src="STRIPE_WEBHOOK_SECRET (legacy fallback)"
        if [[ "$eff_live_wh" == whsec_* ]]; then ok "$src = whsec_… (length=${#eff_live_wh})"
        else                                     bad "$src does not start with whsec_"
        fi
    fi

    # Test credentials — optional, but if present validate them too.
    if [[ -n "$test_sk" && "$test_sk" != "REPLACE_ME" ]]; then
        if   [[ "$test_sk" == sk_test_* ]]; then ok "STRIPE_TEST_SECRET_KEY = sk_test_… (TEST mode)"
        else                                     warn "STRIPE_TEST_SECRET_KEY does not start with sk_test_"
        fi
    fi
    if [[ -n "$test_wh" && "$test_wh" != "REPLACE_ME" ]]; then
        if [[ "$test_wh" == whsec_* ]]; then ok "STRIPE_TEST_WEBHOOK_SECRET = whsec_… (length=${#test_wh})"
        else                                 warn "STRIPE_TEST_WEBHOOK_SECRET does not start with whsec_"
        fi
    fi

    # Downstream Stripe API probe uses the live key (the prod mode that matters).
    sk="$eff_live_sk"
    whsec="$eff_live_wh"
fi

# —————————————————————————————————————————————————————————
bold "3. PHP runtime"
command -v php >/dev/null && ok "php on PATH: $(php -r 'echo PHP_VERSION;')" || bad "php not on PATH"
php -m 2>/dev/null | grep -qi '^curl$'     && ok "ext-curl"     || bad "ext-curl missing"
php -m 2>/dev/null | grep -qi '^json$'     && ok "ext-json"     || bad "ext-json missing"
php -m 2>/dev/null | grep -qi '^openssl$'  && ok "ext-openssl"  || bad "ext-openssl missing"
php -m 2>/dev/null | grep -qi '^mbstring$' && ok "ext-mbstring" || bad "ext-mbstring missing"
php -m 2>/dev/null | grep -qi '^pdo_sqlite$' && ok "ext-pdo_sqlite" || bad "ext-pdo_sqlite missing"

# —————————————————————————————————————————————————————————
bold "4. Config bootstrap dry-run"
if [[ -f "$HIDDEN_DIR/config/app.php" ]]; then
    out=$(php -d display_errors=1 -d log_errors=0 "$HIDDEN_DIR/config/app.php" 2>&1)
    rc=$?
    if [[ $rc -eq 0 && -z "$out" ]]; then
        ok "config/app.php loads cleanly"
    else
        bad "config/app.php FAILED (rc=$rc)"
        info "$out"
    fi
else
    bad "config/app.php not found under $HIDDEN_DIR"
fi

# —————————————————————————————————————————————————————————
bold "5. Stripe API reachability (GET /v1/account)"
if [[ -n "${sk:-}" && "$sk" != "REPLACE_ME" ]]; then
    resp=$(curl -s -o /tmp/ndasa-stripe-diag.$$ -w '%{http_code}' \
        https://api.stripe.com/v1/account \
        -u "${sk}:" )
    body=$(cat /tmp/ndasa-stripe-diag.$$ 2>/dev/null); rm -f /tmp/ndasa-stripe-diag.$$
    case "$resp" in
        200) ok  "Stripe /v1/account returned 200 — secret key works"
             acct=$(printf '%s' "$body" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo ($j["id"]??"?")." / livemode=".var_export($j["livemode"]??null,true);' 2>/dev/null)
             info "$acct" ;;
        401) bad "Stripe returned 401 — INVALID SECRET KEY" ;;
        403) bad "Stripe returned 403 — key lacks permission (restricted key?)" ;;
        000) bad "Could not reach api.stripe.com (egress/TLS/DNS issue)" ;;
        *)   warn "Stripe returned HTTP $resp"; info "$body" ;;
    esac
else
    warn "Skipped — no usable STRIPE_SECRET_KEY"
fi

# —————————————————————————————————————————————————————————
bold "6. Public endpoints (local curl)"
app_url=$(awk -F= '$1=="APP_URL"{sub(/^[^=]*=/,""); gsub(/^["'"'"']|["'"'"']$/,""); print; exit}' "$ENV_FILE" 2>/dev/null)
if [[ -n "$app_url" ]]; then
    code=$(curl -sI -o /dev/null -w '%{http_code}' "$app_url/")
    [[ "$code" == "200" ]] && ok "GET $app_url/ → 200" || warn "GET $app_url/ → $code"
    code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$app_url/webhook.php")
    [[ "$code" == "400" ]] && ok "POST $app_url/webhook.php → 400 (expected: missing signature)" \
                           || warn "POST $app_url/webhook.php → $code (expected 400)"
    code=$(curl -sI -o /dev/null -w '%{http_code}' "$app_url/webhook.php")
    [[ "$code" == "405" ]] && ok "GET  $app_url/webhook.php → 405 (expected: POST-only)" \
                           || warn "GET  $app_url/webhook.php → $code (expected 405)"
else
    warn "Skipped — no APP_URL"
fi

# —————————————————————————————————————————————————————————
bold "7. Recent errors (last 40 lines)"
for log in "$APP_LOG" "$HOME/logs/error_log" "$HOME/public_html/error_log" "$HOME/public_html/donation/error_log"; do
    if [[ -r "$log" ]]; then
        printf '  --- %s ---\n' "$log"
        tail -n 40 "$log" | sed 's/^/    /'
        echo
    fi
done

# —————————————————————————————————————————————————————————
bold "Done."
echo "If anything above is RED, start there. The most common root causes are:"
echo "  • STRIPE_LIVE_SECRET_KEY invalid / wrong mode (or legacy STRIPE_SECRET_KEY)"
echo "  • .env not readable by PHP-FPM user (owner/mode)"
echo "  • storage/ not writable → SQLite open fails before Stripe is called"
echo "  • Stale OPcache after editing .env — reload PHP-FPM if keys look right"
