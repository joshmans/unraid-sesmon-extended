<?php
// Tiny assertion helpers; every *_test.php requires this and ends with sesext_test_done().
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(function ($no, $str, $file, $line) { if (!(error_reporting() & $no)) { return false; } throw new ErrorException($str, 0, $no, $file, $line); });

define('SESEXT_INC', dirname(__DIR__) . '/source/usr/local/emhttp/plugins/sesmon-ext/include');
define('SESEXT_DEFAULTS', dirname(__DIR__) . '/source/usr/local/emhttp/plugins/sesmon-ext/defaults');
define('SESEXT_FIXTURES', __DIR__ . '/fixtures');

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

/** Build a temporary fake root with /sys/class/scsi_generic from "sgN|attr|value" lines. Returns its path. */
function sesext_fixture_root($lines) {
    $root = sys_get_temp_dir() . '/sesext-test-' . getmypid() . '-' . mt_rand();
    foreach (is_array($lines) ? $lines : explode("\n", $lines) as $l) {
        $l = rtrim($l, "\r\n");
        if ($l === '') { continue; }
        [$sg, $attr, $val] = explode('|', $l, 3);
        $dir = "$root/sys/class/scsi_generic/$sg/device";
        if (!is_dir($dir)) { mkdir($dir, 0777, true); }
        file_put_contents("$dir/$attr", $val . "\n");
    }
    register_shutdown_function(function () use ($root) { exec('rm -rf ' . escapeshellarg($root)); });
    return $root;
}
function sesext_catan_lines() { return file(SESEXT_FIXTURES . '/catan/sysfs.txt', FILE_IGNORE_NEW_LINES); }
