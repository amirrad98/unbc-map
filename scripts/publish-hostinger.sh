#!/usr/bin/env bash
set -euo pipefail

# The destination is fixed: source branches must never reach public_html.
if [ "$#" -ne 0 ]; then
  echo 'This publisher takes no arguments; its destination is fixed.' >&2
  exit 1
fi
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
branch=hostinger-nhhr
root=$(git rev-parse --show-toplevel)
cd "$root"
node "$script_dir/prepare-hostinger.mjs" --check dist

release_dir=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/nhhr-release.XXXXXX")
temporary_branch=
cleanup() {
  git worktree remove --force "$release_dir" >/dev/null 2>&1 || rmdir "$release_dir" 2>/dev/null || true
  if [ -n "$temporary_branch" ]; then git branch -D "$temporary_branch" >/dev/null 2>&1 || true; fi
}
trap cleanup EXIT

if git ls-remote --exit-code --heads origin "refs/heads/$branch" > /dev/null; then
  git fetch origin "refs/heads/$branch:refs/remotes/origin/$branch"
  git worktree add --detach "$release_dir" "refs/remotes/origin/$branch"
else
  status=$?
  if [ "$status" -ne 2 ]; then
    echo 'Could not inspect deployment branch; refusing to publish.' >&2
    exit "$status"
  fi
  temporary_branch="codex/$(basename "$release_dir")"
  git worktree add --orphan -b "$temporary_branch" "$release_dir"
fi

# Replace the entire old artifact, including retired PHP and authentication files.
# This directory is a temporary worktree, never a hosting or source directory.
find "$release_dir" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
cp -R dist/. "$release_dir/"
git -C "$release_dir" add --all
if git -C "$release_dir" diff --cached --quiet && git -C "$release_dir" rev-parse --verify HEAD >/dev/null 2>&1; then
  echo 'The deployment branch already contains this release.'
  exit 0
fi
git -C "$release_dir" -c user.name='github-actions[bot]' \
  -c user.email='41898282+github-actions[bot]@users.noreply.github.com' \
  commit -m "Publish NHHR $(git rev-parse HEAD)"
# A rejected or concurrent push is an error. Never replace remote history.
git -C "$release_dir" push origin "HEAD:refs/heads/$branch"
