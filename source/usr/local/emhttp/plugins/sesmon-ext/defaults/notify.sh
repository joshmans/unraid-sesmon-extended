#!/bin/bash
# shellcheck disable=SC2034
#
# sesmon notification script
#
# Do not change this unless you know what you are doing.
# Advanced filtering is possible e.g. with "jq" using "SES_ALERT_JSON".
# You can take a look at the JSON files in "/var/lib/sesmon-ext" for reference.
#

# These variables are filled in by the monitoring daemon, do not assign other
# values to them. The comments describe only what each variable will contain:
SES_DEV_PATH="$1" # device path, e.g. /dev/sg/25
SES_DEV_ADDR="$2" # device SAS address, e.g. 0x500a098012345678
SES_DEV_DESCR="$3" # device description, e.g. "My JBOD"
SES_ALERT_MSG="$4" # alert message (textual representation)
SES_ALERT_JSON="$5" # alert JSON (see JSON files for reference)

# Name the drives in the affected bays ("drive bay 10: disk22 + disk23 ..."), so the alert says what to look at. This
# only adds a line: if the helper is missing, fails or has nothing to say (no bay in the alert), the alert is unchanged.
SES_DRIVES=""
if [ -n "$SES_ALERT_JSON" ] && [ -f /usr/local/emhttp/plugins/sesmon-ext/scripts/alert_context.php ]; then
    SES_DRIVES="$(timeout 10 /usr/bin/php -q /usr/local/emhttp/plugins/sesmon-ext/scripts/alert_context.php "$SES_DEV_ADDR" "$SES_ALERT_JSON" "$SES_DEV_PATH" 2>/dev/null)"
fi
if [ -n "$SES_DRIVES" ]; then
    SES_ALERT_MSG="${SES_DRIVES} -- ${SES_ALERT_MSG}"
fi

# Unraid's notification system doesn't like some special characters,
# so we need to replace them for the notification system not to break:
SES_ALERT_MSG="${SES_ALERT_MSG//=/:}" # bugfix for Unraid 7.1.x
SES_ALERT_MSG="${SES_ALERT_MSG//#/:}" # bugfix for Unraid 7.2.x

# Prepare the notification itself for the OS notification system:
NOTIFY="/usr/local/emhttp/plugins/dynamix/scripts/notify"
HOST="$(echo "$HOSTNAME" | awk '{print toupper($0)}')"
EVENT="SCSI Enclosure Alert"
SUBJECT="[${HOST}] SES: [${SES_DEV_PATH}:${SES_DEV_ADDR}] ${SES_DEV_DESCR}"

# Dispatch the notification itself through the OS notification system:
"$NOTIFY" -e "${EVENT}" -s "Alert ${SUBJECT}" -d "${SES_ALERT_MSG}" -i "alert"
