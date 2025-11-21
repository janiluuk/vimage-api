#!/usr/bin/env bash
set -euo pipefail

BASE_BRANCH=${1:-main}

if ! git remote get-url origin >/dev/null 2>&1; then
  echo "No 'origin' remote configured; please add it before rebasing." >&2
  exit 1
fi

echo "Fetching $BASE_BRANCH from origin..."
git fetch origin "$BASE_BRANCH"

echo "Rebasing current branch onto origin/${BASE_BRANCH}..."
git rebase "origin/${BASE_BRANCH}"
