<?php
// Tiny assertion helpers; every *_test.php requires this and ends with sesext_test_done().
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(function ($no, $str, $file, $line) { if (!(error_reporting() & $no)) { return false; } throw new ErrorException($str, 0, $no, $file, $line); });

define('SESEXT_INC', dirname(__DIR__) . '/source/usr/local/emhttp/plugins/sesmon-ext/include');
define('SESEXT_DEFAULTS', dirname(__DIR__) . '/source/usr/local/emhttp/plugins/sesmon-ext/defaults');
define('SESEXT_FIXTURES', __DIR__ . '/fixtures');
define('SESEXT_EXAMPLE', SESEXT_FIXTURES . '/example-config.yaml'); // the daemon's full example: rich sample data for the parser and the form

$GLOBALS['sesext_pass'] = 0;
$GLOBALS['sesext_fail'] = 0;

function ok($cond, $name) {
    if ($cond) { $GLOBALS['sesext_pass']++; return; }
    $GLOBALS['sesext_fail']++;
    echo "  FAIL: $name\n";
}
function eq($actual, $expected, $name) {
    if ($actual === $expected) { $GLOBALS['sesext_pass']++; return; }
    $GLOBALS['sesext_fail']++;
    echo "  FAIL: $name\n    expected: " . json_encode($expected, JSON_UNESCAPED_SLASHES) . "\n    actual:   " . json_encode($actual, JSON_UNESCAPED_SLASHES) . "\n";
}
function throws($fn, $class, $name) {
    try { $fn(); } catch (Throwable $t) {
        if ($t instanceof $class) { $GLOBALS['sesext_pass']++; return $t->getMessage(); }
        $GLOBALS['sesext_fail']++; echo "  FAIL: $name (threw " . get_class($t) . ": " . $t->getMessage() . ")\n"; return null;
    }
    $GLOBALS['sesext_fail']++; echo "  FAIL: $name (did not throw)\n"; return null;
}
function sesext_test_done() {
    $name = basename($_SERVER['argv'][0]);
    echo sprintf("%s: %d passed, %d failed\n", $name, $GLOBALS['sesext_pass'], $GLOBALS['sesext_fail']);
    exit($GLOBALS['sesext_fail'] ? 1 : 0);
}

/**
 * Build a temporary fake root. Lines are "sgN|attr|value" (a file below /sys/class/scsi_generic/sgN/device; attr may be a
 * nested path such as block/sdb/size) or "@/absolute/path|value" (a file anywhere below the root). Returns its path.
 */
function sesext_fixture_root($lines, $fixed = null) {
    $root = $fixed ?? sys_get_temp_dir() . '/sesext-test-' . getmypid() . '-' . mt_rand();
    foreach (is_array($lines) ? $lines : explode("\n", $lines) as $l) {
        $l = rtrim($l, "\r\n");
        if ($l === '') { continue; }
        if ($l[0] === '@') {
            [$path, $val] = explode('|', substr($l, 1), 2);
            $file = $root . $path;
        } else {
            [$sg, $attr, $val] = explode('|', $l, 3);
            $file = "$root/sys/class/scsi_generic/$sg/device/$attr";
        }
        if (!is_dir(dirname($file))) { mkdir(dirname($file), 0777, true); }
        file_put_contents($file, $val . "\n");
    }
    if ($fixed === null) { register_shutdown_function(function () use ($root) { exec('rm -rf ' . escapeshellarg($root)); }); }
    return $root;
}
/** The catan sysfs data: what the enclosure discovery reads (sysfs.txt) plus drives, hctl and the HBA (sysfs-extra.txt). */
function sesext_catan_lines($extra = true) {
    $l = file(SESEXT_FIXTURES . '/catan/sysfs.txt', FILE_IGNORE_NEW_LINES);
    return $extra ? array_merge($l, file(SESEXT_FIXTURES . '/catan/sysfs-extra.txt', FILE_IGNORE_NEW_LINES)) : $l;
}
/** A fake catan with Unraid's state file in place. */
function sesext_catan_root() {
    $root = sesext_fixture_root(sesext_catan_lines());
    mkdir("$root/var/local/emhttp", 0777, true);
    copy(SESEXT_FIXTURES . '/catan/disks.ini', "$root/var/local/emhttp/disks.ini");
    return $root;
}
