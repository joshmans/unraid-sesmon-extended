#!/bin/bash
# ./build.sh [version]   builds dist/sesmon-ext-<version>.txz and stamps
# sesmon-ext.plg with the version, SHA-256 and CHANGELOG.md
# (the vendored sesmon and sg3_utils packages in packages/ are not touched)
# NOSTAMP=1 ./build.sh <version>   builds only, for dev installs: leaves the plg alone
set -e
NAME=sesmon-ext
VERSION="${1:-$(date +%Y.%m.%d)}"
cd "$(dirname "$0")"

rm -rf package-temp dist
mkdir -p package-temp dist
cp -R source/* package-temp/
find package-temp -type d -exec chmod 755 {} \;
find package-temp -type f -exec chmod 644 {} \;
chmod 755 package-temp/etc/rc.d/rc.sesmon-ext package-temp/install/doinst.sh
chmod 755 package-temp/usr/local/emhttp/plugins/$NAME/scripts/*

# the list of the package's own files below the web folder: install/doinst.sh removes what an earlier version
# left behind and this one no longer ships (an upgrade only overwrites files)
(cd "package-temp/usr/local/emhttp/plugins/$NAME" && find . -type f ! -name .manifest | LC_ALL=C sort > .manifest)
chmod 644 "package-temp/usr/local/emhttp/plugins/$NAME/.manifest"

# the archive must extract as root:root whoever builds it; bsdtar (macOS) and GNU tar
# spell that differently, and COPYFILE_DISABLE keeps macOS from adding ._ files
if tar --version 2>/dev/null | grep -qi bsdtar; then
    OWNER=(--uid 0 --gid 0 --uname root --gname root)
else
    OWNER=(--owner=root --group=root --numeric-owner)
fi
COPYFILE_DISABLE=1 tar "${OWNER[@]}" -C package-temp -cJf "dist/$NAME-$VERSION.txz" etc install usr
rm -rf package-temp

if command -v sha256sum >/dev/null; then SHA256=$(sha256sum "dist/$NAME-$VERSION.txz" | cut -d' ' -f1); else SHA256=$(shasum -a 256 "dist/$NAME-$VERSION.txz" | cut -d' ' -f1); fi

if [ -n "$NOSTAMP" ]; then
    echo "built dist/$NAME-$VERSION.txz (sha256 $SHA256), plg left untouched"
    exit 0
fi

# the plg ships the changelog; "]]>" is the one string that would end the CDATA early
python3 - "$VERSION" "$SHA256" <<'PY'
import re, sys
version, sha256 = sys.argv[1:3]
s = open('sesmon-ext.plg').read()
log = open('CHANGELOG.md').read().replace(']]>', ']]]]><![CDATA[>')
s = re.sub(r'(<!ENTITY version\s+")[^"]*(">)', lambda m: m.group(1) + version + m.group(2), s)
s = re.sub(r'(<!ENTITY sha256\s+")[^"]*(">)', lambda m: m.group(1) + sha256 + m.group(2), s)
s = re.sub(r'(<CHANGES><!\[CDATA\[\n).*?(\n\]\]></CHANGES>)', lambda m: m.group(1) + log.strip() + m.group(2), s, flags=re.S)
open('sesmon-ext.plg', 'w').write(s)
PY
python3 -c "import xml.etree.ElementTree as E; E.parse('sesmon-ext.plg')"
echo "built dist/$NAME-$VERSION.txz (sha256 $SHA256)"
