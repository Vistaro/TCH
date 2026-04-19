#!/usr/bin/env bash
# scripts/deploy.sh <env>
#
# Rsyncs the local working tree to the dev or prod hosting root.
# Run from the local TCH repo root. Uses the ed25519 deploy key.
#
#   bash scripts/deploy.sh dev     # push local → ~/public_html/dev-TCH/dev/
#   bash scripts/deploy.sh prod    # push local → ~/public_html/tch/
#
# Excludes:
#   - .git/           — big, server doesn't use git-pull
#   - .env            — credentials live server-side only
#   - vendor/         — composer on server
#   - node_modules/   — if present
#   - .claude/        — local agent config
#   - _archive/       — repo history, not live code
#   - database/backups/ — local scratch
#   - Chat History/   — never committed
#   - docs/Brand/     — big assets, not served
#   - brand.pdf       — big asset, not served
#   - audio_extract*  — local audio files
#   - *.mp3 *.wav *.m4a — per repo .gitignore
#
# PROD is gated: requires AGENT_SHIP_APPROVED=yes so `bash scripts/deploy.sh prod`
# alone won't fire — the agent sets the env var explicitly as part of a
# ship-event sequence that Ross has approved.
set -euo pipefail

ENV="${1:?Usage: deploy.sh <dev|prod>}"
[[ "$ENV" == "dev" || "$ENV" == "prod" ]] || {
    echo "ERROR: env must be 'dev' or 'prod' (got: $ENV)" >&2
    exit 1
}

SSH_USER="intelligentae.co.uk"
SSH_HOST="ssh.gb.stackcp.com"
SSH_KEY="$HOME/.ssh/intelligentae_deploy_ed25519"

if [[ "$ENV" == "dev" ]]; then
    REMOTE_PATH="/home/sites/9a/7/72a61afa93/public_html/dev-TCH/dev/"
elif [[ "$ENV" == "prod" ]]; then
    REMOTE_PATH="/home/sites/9a/7/72a61afa93/public_html/tch/"
    if [[ "${AGENT_SHIP_APPROVED:-}" != "yes" ]]; then
        echo "REFUSED: prod deploy requires AGENT_SHIP_APPROVED=yes in env" >&2
        echo "  (ship-event rule — prod deploys are never one-command autonomous)" >&2
        exit 3
    fi
fi

[[ -f "$SSH_KEY" ]] || {
    echo "ERROR: ssh key not found at $SSH_KEY" >&2
    exit 1
}
[[ -f "composer.json" && -d "templates" ]] || {
    echo "ERROR: run from TCH repo root (composer.json + templates/ expected here)" >&2
    exit 1
}

echo "Deploying local → $ENV at $SSH_USER@$SSH_HOST:$REMOTE_PATH"
echo

# Prefer rsync (delta transfer, --delete) when available. Fall back to
# tar-over-ssh for environments without rsync (Git Bash on Windows, etc.).
# Both use the same exclude list.
EXCLUDES=(
    '.git'
    '.env'
    'vendor'
    'node_modules'
    '.claude'
    '_archive'
    'database/backups'
    'Chat History'
    'docs/Brand'
    'brand.pdf'
    'audio_extract*'
    '*.mp3'
    '*.wav'
    '*.m4a'
    '.vscode'
    '.last-backup-timestamp'
)

if command -v rsync >/dev/null 2>&1; then
    RSYNC_EXCLUDES=()
    for e in "${EXCLUDES[@]}"; do RSYNC_EXCLUDES+=(--exclude="$e"); done
    rsync -avz "${RSYNC_EXCLUDES[@]}" \
        -e "ssh -i $SSH_KEY -o StrictHostKeyChecking=accept-new" \
        ./ "$SSH_USER@$SSH_HOST:$REMOTE_PATH"
else
    echo "  (rsync not available — using tar-over-ssh fallback)"
    TAR_EXCLUDES=()
    for e in "${EXCLUDES[@]}"; do TAR_EXCLUDES+=(--exclude="$e"); done
    tar -czf - "${TAR_EXCLUDES[@]}" . \
      | ssh -i "$SSH_KEY" -o StrictHostKeyChecking=accept-new \
            "$SSH_USER@$SSH_HOST" "cd $REMOTE_PATH && tar -xzf -"
fi

echo
echo "Deploy complete → $ENV"
echo "  $SSH_USER@$SSH_HOST:$REMOTE_PATH"
