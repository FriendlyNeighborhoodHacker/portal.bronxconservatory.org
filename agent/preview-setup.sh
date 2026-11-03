#!/bin/bash
# agent/preview-setup.sh: run by swe-bridge inside a fresh worktree, and again
# after every agent turn, with PREVIEW_ROOT = the directory the preview vhost
# serves. For this repository that is the worktree's own www/ (PREVIEW_MODE=tree),
# so all the hook does is make the tree servable: the preview config and the
# writable directories the app expects. Idempotent.
#
# By hand:  PREVIEW_ROOT=$PWD/www bash agent/preview-setup.sh   (from a worktree)
set -euo pipefail
CONFIG="${AGENT_DIR:-$HOME/agents/portal.bronxconservatory.org}/config.local.php"
[ -f "$CONFIG" ] || { echo "missing $CONFIG (docs/engineer-agent.md step 7)" >&2; exit 1; }
install -m 600 "$CONFIG" www/config.local.php
mkdir -p www/logs www/cache
