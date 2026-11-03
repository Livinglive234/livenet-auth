#!/usr/bin/env bash
# Build, tag and publish a release in one go.
# Usage: ./publish.sh 1.4.1
#
# It refuses to run unless you are on main, with nothing uncommitted, and in sync with GitHub.
# It asks before publishing, because the release is public.
set -euo pipefail

VERSION="${1:?usage: ./publish.sh <version>}"
cd "$(dirname "$0")"

[ "$(git rev-parse --abbrev-ref HEAD)" = "main" ] || { echo "Switch to the main branch first."; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo "Commit or stash your changes first."; exit 1; }
git fetch -q origin
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || { echo "main is not in sync with GitHub (push or pull first)."; exit 1; }
if git rev-parse "v$VERSION" >/dev/null 2>&1; then echo "Tag v$VERSION already exists."; exit 1; fi

read -r -p "Publish v$VERSION to GitHub (public)? [y/N] " answer
[ "$answer" = "y" ] || { echo "Cancelled."; exit 1; }

./release.sh "$VERSION" >/dev/null     # bumps plugin.json, builds dist/livenet-auth.zip, writes update.json
git add -A
git commit -q -m "Release v$VERSION"
git tag "v$VERSION"
git push -q origin main "v$VERSION"
gh release create "v$VERSION" dist/livenet-auth.zip --title "v$VERSION" --notes "Release v$VERSION"

echo
echo "Published v$VERSION. The panel will offer the update on its Plugins page within about 10 minutes."
