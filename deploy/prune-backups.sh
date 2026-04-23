#!/usr/bin/env bash
#
# NDASA Donation Platform — prune old install backups.
#
# install.sh creates timestamped backup directories whenever an existing
# install is replaced. Since April 2026 those snapshots live in
#
#   ~/backups/ndasa-donation/.ndasa-donation.bak-YYYYMMDD-HHMMSS/
#   ~/backups/ndasa-donation/donation.bak-YYYYMMDD-HHMMSS/
#
# (override via BACKUP_ROOT). Older installs wrote them directly under
# public_html/; point BACKUP_ROOT=$HOME/public_html once to sweep those
# legacy dirs too.
#
# Safety rails:
#   • Dry-run is the default. Nothing is deleted unless --execute is passed.
#   • Only directory names matching the exact "(.ndasa-donation|donation).bak-
#     YYYYMMDD-HHMMSS" pattern are candidates. Any dir that doesn't match is
#     ignored even if someone named it something similar.
#   • The live .ndasa-donation/ and donation/ directories are never touched
#     (they aren't inside $BACKUP_ROOT under the default layout anyway, but
#     the legacy-sweep path hits public_html where the live dirs DO live —
#     the pattern guard keeps them safe).
#   • --keep N keeps the N newest snapshots PER SERIES (default: 3). Both
#     series are retained independently so --keep 3 means 3 hidden + 3 public.
#   • --older-than DAYS only considers snapshots older than N days.
#     Combined with --keep, BOTH conditions must hold for a dir to be deleted
#     (i.e. "older than DAYS AND beyond the --keep retention").
#   • Reports per-dir size and cumulative freed space.
#
# Usage:
#   ./prune-backups.sh                       # dry-run, keep newest 3 per series
#   ./prune-backups.sh --keep 5              # dry-run, keep newest 5
#   ./prune-backups.sh --older-than 7        # dry-run, only dirs >7 days old
#   ./prune-backups.sh --execute             # actually delete
#   ./prune-backups.sh --keep 1 --execute    # keep only the newest
#
# One-time legacy sweep (old backups that ended up in public_html):
#   BACKUP_ROOT=$HOME/public_html ./prune-backups.sh --keep 0 --execute
#
set -euo pipefail

# ——— Defaults ———
: "${BACKUP_ROOT:=$HOME/backups/ndasa-donation}"
KEEP=3
OLDER_THAN_DAYS=0
EXECUTE=0

# ——— Pretty output ———
red()    { printf '\033[31m%s\033[0m\n' "$*"; }
green()  { printf '\033[32m%s\033[0m\n' "$*"; }
yellow() { printf '\033[33m%s\033[0m\n' "$*"; }
blue()   { printf '\033[34m%s\033[0m\n' "$*"; }
bold()   { printf '\033[1m%s\033[0m\n' "$*"; }

usage() {
    sed -n '3,30p' "$0" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

# ——— Arg parsing ———
while [[ $# -gt 0 ]]; do
    case "$1" in
        --keep)
            [[ $# -ge 2 ]] || { red "--keep requires a number"; exit 2; }
            KEEP="$2"
            [[ "$KEEP" =~ ^[0-9]+$ ]] || { red "--keep must be a non-negative integer"; exit 2; }
            shift 2
            ;;
        --older-than)
            [[ $# -ge 2 ]] || { red "--older-than requires a number of days"; exit 2; }
            OLDER_THAN_DAYS="$2"
            [[ "$OLDER_THAN_DAYS" =~ ^[0-9]+$ ]] || { red "--older-than must be a non-negative integer"; exit 2; }
            shift 2
            ;;
        --execute|--delete)
            EXECUTE=1
            shift
            ;;
        -h|--help)
            usage 0
            ;;
        *)
            red "Unknown argument: $1"
            usage 2
            ;;
    esac
done

if [[ ! -d "$BACKUP_ROOT" ]]; then
    # Not an error — the root only exists once install.sh has run at least
    # once with the new layout. A fresh install with no prior backups is
    # a perfectly valid state.
    green "BACKUP_ROOT ($BACKUP_ROOT) does not exist — nothing to prune."
    exit 0
fi

# ——— Find candidate directories ———
#
# Match only directory names that look like: <prefix>.bak-YYYYMMDD-HHMMSS
# Prefix must be exactly ".ndasa-donation" or "donation". Regex is strict
# so a hand-created folder like "donation.bak-old" is left alone.
#
# Using find -print0 + bash arrays to be safe with any characters; in
# practice these names are ASCII timestamps, but cost is trivial.
shopt -s nullglob
mapfile -d '' candidates < <(
    find "$BACKUP_ROOT" -maxdepth 1 -type d \
        \( -name '.ndasa-donation.bak-*' -o -name 'donation.bak-*' \) \
        -regextype posix-extended \
        -regex '.*\.bak-[0-9]{8}-[0-9]{6}$' \
        -print0
)

if [[ ${#candidates[@]} -eq 0 ]]; then
    green "No backup directories found in $BACKUP_ROOT — nothing to prune."
    exit 0
fi

# ——— Partition into two series, sort each by timestamp (newest first) ———
#
# install.sh produces backup pairs: one .ndasa-donation.bak-TS and one
# donation.bak-TS with the same timestamp. Retention is applied PER SERIES
# so --keep 3 keeps the three newest of each (six total), not three
# interleaved dirs that might leave one series fully pruned.
declare -a hidden_series=()
declare -a public_series=()
for d in "${candidates[@]}"; do
    base=$(basename "$d")
    if [[ "$base" == .ndasa-donation.bak-* ]]; then
        hidden_series+=("$d")
    elif [[ "$base" == donation.bak-* ]]; then
        public_series+=("$d")
    fi
done

# Sort each series descending by the embedded timestamp (lexical == chrono
# for YYYYMMDD-HHMMSS). Guard empty arrays — sort of nothing is fine but
# printf of an empty array with set -u yields an unbound error on bash<4.4.
if [[ ${#hidden_series[@]} -gt 0 ]]; then
    mapfile -t hidden_series < <(printf '%s\n' "${hidden_series[@]}" | sort -r)
fi
if [[ ${#public_series[@]} -gt 0 ]]; then
    mapfile -t public_series < <(printf '%s\n' "${public_series[@]}" | sort -r)
fi

now_ts=$(date +%s)
older_than_ts=0
if [[ "$OLDER_THAN_DAYS" -gt 0 ]]; then
    older_than_ts=$(( now_ts - (OLDER_THAN_DAYS * 86400) ))
fi

declare -a to_keep=()
declare -a to_delete=()

# Classify each series independently using the same --keep / --older-than
# rules. Helper function avoids duplicating the loop body.
classify_series() {
    local series_name="$1"
    shift
    local -a series=("$@")
    for i in "${!series[@]}"; do
        local dir="${series[$i]}"
        if [[ $i -lt $KEEP ]]; then
            to_keep+=("$dir")
            continue
        fi
        if [[ $OLDER_THAN_DAYS -gt 0 ]]; then
            local dir_ts
            dir_ts=$(stat -c '%Y' "$dir" 2>/dev/null || echo 0)
            if [[ $dir_ts -gt $older_than_ts ]]; then
                to_keep+=("$dir")
                continue
            fi
        fi
        to_delete+=("$dir")
    done
}
[[ ${#hidden_series[@]} -gt 0 ]] && classify_series "hidden" "${hidden_series[@]}"
[[ ${#public_series[@]} -gt 0 ]] && classify_series "public" "${public_series[@]}"

# ——— Report ———
bold "=== NDASA backup pruner ==="
echo "  BACKUP_ROOT:     $BACKUP_ROOT"
echo "  Keep newest:     $KEEP"
if [[ $OLDER_THAN_DAYS -gt 0 ]]; then
    echo "  Older than:      ${OLDER_THAN_DAYS} days"
fi
echo "  Mode:            $([[ $EXECUTE -eq 1 ]] && echo 'EXECUTE (will delete)' || echo 'DRY-RUN (no deletions)')"
echo "  Found backups:   ${#candidates[@]}  (${#hidden_series[@]} hidden, ${#public_series[@]} public)"
echo

# Keep list.
if [[ ${#to_keep[@]} -gt 0 ]]; then
    green "Keeping (${#to_keep[@]}):"
    for d in "${to_keep[@]}"; do
        sz=$(du -sh "$d" 2>/dev/null | awk '{print $1}')
        printf "    %-8s  %s\n" "$sz" "$(basename "$d")"
    done
    echo
fi

# Delete list + cumulative size.
total_kb=0
if [[ ${#to_delete[@]} -gt 0 ]]; then
    yellow "Would delete (${#to_delete[@]}):"
    for d in "${to_delete[@]}"; do
        sz=$(du -sh "$d" 2>/dev/null | awk '{print $1}')
        kb=$(du -sk  "$d" 2>/dev/null | awk '{print $1}')
        total_kb=$(( total_kb + kb ))
        printf "    %-8s  %s\n" "$sz" "$(basename "$d")"
    done
    # Convert cumulative KB to a friendly unit.
    if [[ $total_kb -ge 1048576 ]]; then
        total_friendly=$(awk "BEGIN{printf \"%.1f GiB\", $total_kb/1048576}")
    elif [[ $total_kb -ge 1024 ]]; then
        total_friendly=$(awk "BEGIN{printf \"%.1f MiB\", $total_kb/1024}")
    else
        total_friendly="${total_kb} KiB"
    fi
    echo
    yellow "Cumulative: ${total_friendly}"
    echo
else
    green "No directories eligible for deletion under these rules."
    exit 0
fi

# ——— Execute ———
if [[ $EXECUTE -eq 1 ]]; then
    echo "Deleting…"
    for d in "${to_delete[@]}"; do
        # Belt-and-braces: re-check the name pattern right before rm.
        # Paranoia, but rm -rf on the wrong path is unrecoverable.
        base=$(basename "$d")
        if [[ ! "$base" =~ ^(\.ndasa-donation|donation)\.bak-[0-9]{8}-[0-9]{6}$ ]]; then
            red "  SKIP unexpected name: $d"
            continue
        fi
        if [[ "$(dirname "$d")" != "$BACKUP_ROOT" ]]; then
            red "  SKIP unexpected parent: $d"
            continue
        fi
        rm -rf -- "$d"
        echo "  deleted  $base"
    done
    green "Done. Freed ~${total_friendly}."
else
    blue "Dry-run — pass --execute to actually delete."
fi
