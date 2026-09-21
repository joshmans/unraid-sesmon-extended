#!/bin/sh
# Serves the Configuration page on http://127.0.0.1:${PORT:-8088} against a fake copy of catan:
# the real sysfs data, a stand-in sesmon and service script, and the example config with its
# example device switched on (the failure this plugin exists to fix); CONFIG=fresh serves what a fresh install gets
# instead. Needs only php-cli.
# ?theme=black shows the dark theme.
here="$(cd "$(dirname "$0")" && pwd)"
root="${TMPDIR:-/tmp}/sesext-harness"
rm -rf "$root"; mkdir -p "$root/etc/sesmon-ext" "$root/boot/config/plugins/sesmon-ext/config"
php -r '
  require "'"$here"'/../lib.php";
  foreach (file(SESEXT_FIXTURES."/catan/sysfs.txt", FILE_IGNORE_NEW_LINES) as $l) {
    [$sg,$a,$v] = explode("|", $l, 3); $d = "'"$root"'/sys/class/scsi_generic/$sg/device";
    if (!is_dir($d)) mkdir($d, 0777, true); file_put_contents("$d/$a", "$v\n");
  }'
cp "$here/../../source/usr/local/emhttp/plugins/sesmon-ext/defaults/notify.sh" "$root/etc/sesmon-ext/notify.sh"; chmod 755 "$root/etc/sesmon-ext/notify.sh"
printf '#!/bin/bash\necho mine\n' > "$root/etc/sesmon-ext/mine.sh"; chmod 755 "$root/etc/sesmon-ext/mine.sh"
if [ "$CONFIG" = "fresh" ]; then
  # what a fresh install gets: the config the installer ships
  cp "$here/../../source/usr/local/emhttp/plugins/sesmon-ext/defaults/config.yaml" "$root/etc/sesmon-ext/config.yaml"
else
  # the full example config with its placeholder device switched on (the failure this plugin exists to fix)
  sed 's/enabled: false/enabled: true/;' "$here/../fixtures/example-config.yaml" | awk '/Device 2/{f=1} f&&/enabled: true/{sub(/true/,"false")} {print}' > "$root/etc/sesmon-ext/config.yaml"
fi
cp "$root/etc/sesmon-ext/config.yaml" "$root/boot/config/plugins/sesmon-ext/config/config.yaml"
# what the daemon would have written: the real snapshot from catan and a sample alert
mkdir -p "$root/var/lib/sesmon-ext/netapp-ds424iom12a"
cp "$here/../fixtures/catan/current_parsed.json" "$root/var/lib/sesmon-ext/netapp-ds424iom12a/current_parsed.json"
cp "$here/../fixtures/catan/change-sample.json" "$root/var/lib/sesmon-ext/netapp-ds424iom12a/change-20260920-120000.json"
export SESEXT_ROOT="$root" SESEXT_SESMON="$here/../fixtures/fake-sesmon.sh" SESEXT_RC="$here/../fixtures/fake-rc.sh"
echo "fake server in $root; open http://127.0.0.1:${PORT:-8088}/ (Enclosures) or /devices (Enclosure Devices)"
exec php -d short_open_tag=1 -S "127.0.0.1:${PORT:-8088}" "$here/router.php"
