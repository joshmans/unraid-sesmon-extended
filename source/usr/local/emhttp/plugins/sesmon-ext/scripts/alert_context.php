<?php
/* Part of sesmon-ext (fork of sesmon-unRAID by desertwitch). GPL 2.
 *
 * Prints one line naming the drives in the bays that an alert is about, for notify.sh to put in front of sesmon's
 * message. Usage: alert_context.php <SAS address> <change report JSON> [device path]
 * (the arguments notify.sh has from sesmon: $2, $5 and $1). Prints nothing when there is nothing to add, and never fails:
 * an alert must go out whatever happens here.
 */
try {
    require_once __DIR__ . '/../include/sesext_bays.php';
    echo sesext_alert_context($argv[1] ?? '', $argv[3] ?? '', $argv[2] ?? '');
} catch (Throwable $t) {
    error_log('sesmon-ext alert_context: ' . $t->getMessage());
}
exit(0);
