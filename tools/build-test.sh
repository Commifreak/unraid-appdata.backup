#!/bin/bash
# Test packages for the testing branch: tools/build-test.sh <version> [git-ref]
# HEAD builds stamp appdata.backup.plg; other refs (e.g. a rollback to a release) get dist/appdata.backup-<version>.plg.
set -euo pipefail
version=${1:?usage: tools/build-test.sh <version> [git-ref]}
ref=${2:-HEAD}
root=$(git rev-parse --show-toplevel)
work=$(mktemp -d); trap 'rm -rf "$work"' EXIT
mkdir -p "$root/dist"
pkg="$root/dist/appdata.backup-$version.tgz"
git -C "$root" archive "$ref" src | tar -x -C "$work"
rm -f "$work/src/build.sh"
find "$work/src" -type d -exec chmod 755 {} +
find "$work/src" -type f -exec chmod 644 {} +
cd "$work/src"
# member names without ./ and owned by root, like the official packages; no macOS metadata
if tar --version | grep -q bsdtar; then
  COPYFILE_DISABLE=1 tar --no-xattrs --no-mac-metadata --uid 0 --gid 0 --uname root --gname root -czf "$pkg" *
else
  tar --owner=0 --group=0 --numeric-owner --no-xattrs -czf "$pkg" *
fi
sha=$(shasum -a 256 "$pkg" | cut -d' ' -f1)
plg="$root/appdata.backup.plg"
if [ "$ref" != HEAD ]; then cp "$plg" "$root/dist/appdata.backup-$version.plg"; plg="$root/dist/appdata.backup-$version.plg"; fi
perl -pi -e 's/(<!ENTITY version\s+")[^"]*/${1}'"$version"'/; s/(<!ENTITY sha256\s+")[^"]*/${1}'"$sha"'/' "$plg"
echo "$pkg"
echo "sha256 $sha"
echo "manifest $plg"
