#!/usr/bin/env bash
# scripts/migrate.sh <env> <migration-id>
#
# Server-side migration runner. Adapted from Governance's portfolio-wide
# skeleton (see _global/output/agent-messages/2026-04-18-1630-governance-
# to-tch-migration-runner-pattern-reply.md).
#
# Execution flow:
#   1. Resolve migration .sql from id (e.g. "039" → database/039_*.sql)
#   2. Take pre-migration mysqldump snapshot → ~/backups/pre-migration/
#   3. Write manifest sidecar (migration id, git SHA, env, timestamp)
#   4. Abort if snapshot is empty (no snapshot, no migration)
#   5. Apply migration via mysql
#   6. Retention: keep last 5 snapshots per env, auto-prune older
#
# Rollback unit = (git SHA + DB dump) paired in the manifest.
#
# Invocation:
#   Local → SSH:
#     ssh <key> <host> 'cd ~/public_html/dev-TCH/dev && \
#       AGENT_GIT_SHA=$(local_sha) ./scripts/migrate.sh dev 039'
#
# TCH deployment quirk: the project folder on the server is rsync'd,
# not a git clone. So `git rev-parse HEAD` on-server returns nothing.
# We pass the local SHA in via AGENT_GIT_SHA env var. If that's unset,
# the manifest records "unknown" and a warning is emitted.
set -euo pipefail

ENV="${1:?Usage: migrate.sh <dev|prod> <migration-id>}"
MIG_ID="${2:?Usage: migrate.sh <dev|prod> <migration-id>}"

[[ "$ENV" == "dev" || "$ENV" == "prod" ]] || {
    echo "ERROR: env must be 'dev' or 'prod' (got: $ENV)" >&2
    exit 1
}

# Load DB creds from server-side .env.
#
# Only DB_* lines are parsed — other keys (APP_NAME etc.) can contain
# spaces, quotes, and shell metacharacters that would break `source .env`.
# Values may optionally be wrapped in single or double quotes; strip those.
[[ -f ".env" ]] || {
    echo "ERROR: no .env in $(pwd) — run from project root" >&2
    exit 1
}

parse_env_var() {
    local key="$1"
    local line value
    line=$(grep -E "^${key}=" .env | head -1)
    [[ -n "$line" ]] || { echo ""; return; }
    value="${line#${key}=}"
    # Strip surrounding single or double quotes if present
    if [[ "$value" =~ ^\".*\"$ ]]; then value="${value:1:${#value}-2}"; fi
    if [[ "$value" =~ ^\'.*\'$ ]]; then value="${value:1:${#value}-2}"; fi
    echo "$value"
}

DB_HOST=$(parse_env_var DB_HOST)
DB_NAME=$(parse_env_var DB_NAME)
DB_USER=$(parse_env_var DB_USER)
DB_PASS=$(parse_env_var DB_PASS)

[[ -n "$DB_HOST" ]] || { echo "ERROR: DB_HOST missing from .env" >&2; exit 1; }
[[ -n "$DB_NAME" ]] || { echo "ERROR: DB_NAME missing from .env" >&2; exit 1; }
[[ -n "$DB_USER" ]] || { echo "ERROR: DB_USER missing from .env" >&2; exit 1; }
[[ -n "$DB_PASS" ]] || { echo "ERROR: DB_PASS missing from .env" >&2; exit 1; }

# Sanity — refuse to run a prod-tagged invocation against a dev DB or vice versa.
# We key off the DB_NAME containing "_dev-" as a heuristic.
if [[ "$ENV" == "prod" && "$DB_NAME" == *"_dev-"* ]]; then
    echo "ERROR: env=prod but .env points at a dev-named DB ($DB_NAME). Aborting." >&2
    exit 3
fi
if [[ "$ENV" == "dev" && "$DB_NAME" != *"_dev-"* ]]; then
    echo "ERROR: env=dev but .env DB_NAME ($DB_NAME) doesn't look like dev. Aborting." >&2
    exit 3
fi

# Resolve migration file
MIG_FILE=$(ls database/${MIG_ID}_*.sql 2>/dev/null | head -1 || true)
[[ -n "${MIG_FILE:-}" && -f "$MIG_FILE" ]] || {
    echo "ERROR: migration file not found for id '$MIG_ID' under database/" >&2
    exit 1
}

# Pre-migration snapshot
SHA="${AGENT_GIT_SHA:-unknown}"
TS=$(date -u +%Y%m%dT%H%M%SZ)
SNAP_DIR="$HOME/backups/pre-migration"
mkdir -p "$SNAP_DIR"
SNAP_FILE="${SNAP_DIR}/${TS}-${MIG_ID}-${ENV}.sql.gz"
MANIFEST="${SNAP_FILE%.sql.gz}.manifest.txt"

echo "[1/3] Snapshot: $DB_NAME on $DB_HOST → $SNAP_FILE"
mysqldump \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    2>/dev/null \
    | gzip > "$SNAP_FILE"

if [[ ! -s "$SNAP_FILE" ]]; then
    echo "ERROR: snapshot empty — refusing to migrate" >&2
    rm -f "$SNAP_FILE"
    exit 2
fi

SNAP_SIZE=$(du -h "$SNAP_FILE" | cut -f1)

cat > "$MANIFEST" <<EOF
migration_id: $MIG_ID
migration_file: $MIG_FILE
git_sha: $SHA
env: $ENV
timestamp_utc: $TS
db_host: $DB_HOST
db_name: $DB_NAME
snapshot: $SNAP_FILE
snapshot_size: $SNAP_SIZE
triggered_by: migrate.sh $ENV $MIG_ID
EOF

if [[ "$SHA" == "unknown" ]]; then
    echo "      WARNING: AGENT_GIT_SHA not set — manifest will say 'unknown'." >&2
fi

# Retention: keep last 5 MIGRATION snapshots per env.
# Strict pattern <ts>-<migration-id>-<env>.sql.gz — matches the files
# this script produces. Ad-hoc dumps with other filename shapes (e.g.
# 20260420T092243Z-PRE-PUSH-v0925-prod.sql.gz) are exempt from pruning.
ls -t "$SNAP_DIR" 2>/dev/null \
    | grep -E "^[0-9]{8}T[0-9]{6}Z-[0-9]+-${ENV}\.sql\.gz$" \
    | tail -n +6 | while read -r old; do
        rm -f "$SNAP_DIR/$old" "${SNAP_DIR}/${old%.sql.gz}.manifest.txt"
        echo "      Retention: pruned $old"
done

echo "[2/3] Applying $MIG_FILE to $DB_NAME..."
mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$MIG_FILE"

echo "[3/3] OK — migration $MIG_ID applied on $ENV."
echo "      Snapshot: $SNAP_FILE ($SNAP_SIZE)"
echo "      Manifest: $MANIFEST"
echo "      Rollback: gunzip < \"$SNAP_FILE\" | mysql -h $DB_HOST -u $DB_USER -p\"\$DB_PASS\" $DB_NAME"
