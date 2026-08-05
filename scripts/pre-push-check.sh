#!/usr/bin/env bash
# Pre-push guard: auto-run vue-tsc --noEmit on frontend dirs that have changes.
# Fails the push if TypeScript errors are found.
#
# Usage: called by .git/hooks/pre-push, or manually: bash scripts/pre-push-check.sh

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

# ---------- 1. Find node (Windows / Linux / macOS) ----------
find_node() {
  # Try PATH first
  if command -v node >/dev/null 2>&1; then
    command -v node
    return 0
  fi
  # Common locations on Windows
  local candidates=(
    "$USERPROFILE/.local/bin/nodejs/node.exe"
    "$LOCALAPPDATA/Programs/nodejs/node.exe"
    "C:/Program Files/nodejs/node.exe"
    "C:/Program Files (x86)/nodejs/node.exe"
    "$APPDATA/nvm/*/node.exe"
    "$HOME/.local/bin/nodejs/node"
    "/usr/local/bin/node"
    "/usr/bin/node"
    "/opt/homebrew/bin/node"
  )
  for p in "${candidates[@]}"; do
    # expand glob
    for found in $p; do
      if [ -x "$found" ] || [ -f "$found" ]; then
        echo "$found"
        return 0
      fi
    done
  done
  return 1
}

NODE_BIN="$(find_node || true)"
if [ -z "$NODE_BIN" ]; then
  echo "⚠️  [pre-push] node not found — skipping TypeScript check"
  echo "   Install Node.js or ensure 'node' is in PATH."
  exit 0
fi

NPM_BIN="$(dirname "$NODE_BIN")/npm"
if [ ! -x "$NPM_BIN" ] && [ ! -f "$NPM_BIN" ]; then
  NPM_BIN="$(dirname "$NODE_BIN")/npm.cmd"
fi

echo "🔍 [pre-push] Using node: $NODE_BIN"
"$NODE_BIN" --version

# ---------- 2. Detect changed frontend dirs ----------
has_changes() {
  local dir="$1"
  local base="${2:-HEAD}"
  # Check staged + unstaged + unpushed changes
  if git diff --name-only "$base"..HEAD -- "$dir" | grep -q '\.\(vue\|ts\|tsx\|js\|json\)$'; then
    return 0
  fi
  if git diff --name-only -- "$dir" | grep -q '\.\(vue\|ts\|tsx\|js\|json\)$'; then
    return 0
  fi
  if git diff --cached --name-only -- "$dir" | grep -q '\.\(vue\|ts\|tsx\|js\|json\)$'; then
    return 0
  fi
  return 1
}

CHANGED_DIRS=()
for dir in admin user; do
  if [ -d "$dir/node_modules" ] && has_changes "$dir"; then
    CHANGED_DIRS+=("$dir")
  elif [ ! -d "$dir/node_modules" ]; then
    echo "⚠️  [pre-push] $dir/node_modules missing — skipping (run 'npm install' first)"
  fi
done

if [ ${#CHANGED_DIRS[@]} -eq 0 ]; then
  echo "✅ [pre-push] No frontend changes to check"
  exit 0
fi

echo "📦 [pre-push] Checking: ${CHANGED_DIRS[*]}"

# ---------- 3. Run vue-tsc --noEmit per dir ----------
FAILED=0
for dir in "${CHANGED_DIRS[@]}"; do
  echo ""
  echo "——————————————————————————"
  echo "  vue-tsc --noEmit  →  $dir"
  echo "——————————————————————————"
  if (cd "$dir" && "$NODE_BIN" "./node_modules/vue-tsc/bin/vue-tsc.js" --noEmit 2>&1); then
    echo "✅ $dir  type-check passed"
  else
    echo ""
    echo "❌ $dir  has TypeScript errors — push aborted!"
    FAILED=1
  fi
done

echo ""
if [ "$FAILED" -eq 1 ]; then
  echo "========================================="
  echo "  PUSH BLOCKED — fix TS errors above"
  echo "  (use 'git push --no-verify' to force)"
  echo "========================================="
  exit 1
fi

echo "🎉 [pre-push] All checks passed — safe to push"
