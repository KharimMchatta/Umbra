#!/bin/sh
# Umbra by Kharim Mchatta, https://github.com/KharimMchatta/Umbra
# Copyright (C) 2026 Kharim Mchatta. Licensed under the GNU GPL version 3 or later, with the
# additional terms in Umbra's NOTICE file (keep this attribution, do not misrepresent the
# origin, mark modified versions). It comes WITHOUT ANY WARRANTY; see the LICENSE file.
#
# Builds the release assets of one tag from the committed tree (never from the working copy):
#   dist/umbra-<tag>.zip         the plugin only: umbra.php, includes/, LICENSE and NOTICE in umbra/
#   dist/htaccess-hardening.conf the server rules
#   dist/SHA256SUMS              the SHA-256 checksum of both, in the format `sha256sum -c` reads
#
# Usage: tools/build-release.sh v2.7.5

set -eu

tag=${1:?usage: tools/build-release.sh vX.Y.Z}

# Work in the repository this script belongs to, wherever it is run from, and refuse a ref that
# is not an Umbra tree before anything in dist/ is removed.
cd "$(dirname "$0")/.."
git rev-parse -q --verify "$tag^{tree}" >/dev/null || { echo "build-release: no such tag or commit: $tag" >&2; exit 1; }
git cat-file -e "$tag:umbra.php" 2>/dev/null || { echo "build-release: $tag has no umbra.php; is this the Umbra repository?" >&2; exit 1; }

rm -rf dist
mkdir dist

git archive --format=zip --prefix=umbra/ -o "dist/umbra-$tag.zip" "$tag" umbra.php includes LICENSE NOTICE
git show "$tag:htaccess-hardening.conf" > dist/htaccess-hardening.conf

cd dist
if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "umbra-$tag.zip" htaccess-hardening.conf > SHA256SUMS
else
    shasum -a 256 "umbra-$tag.zip" htaccess-hardening.conf > SHA256SUMS
fi
cat SHA256SUMS
