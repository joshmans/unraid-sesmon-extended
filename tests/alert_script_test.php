<?php
// The alert path end to end: sesmon calls notify.sh, which asks alert_context.php which drives are in the bays, and hands the
// message to Unraid's notification command (replaced here by a stub that records what it was given). Also the installer's
// rule for replacing an installed notify.sh.
require __DIR__ . '/lib.php';

$plugin = dirname(__DIR__) . '/source/usr/local/emhttp/plugins/sesmon-ext';
$root = sesext_catan_root();
$netapp = json_decode(file_get_contents(SESEXT_FIXTURES . '/catan/ses-netapp.json'), true);
$dev = ['type' => 0, 'path' => '/dev/sg34', 'address' => '0x500a098008561d10', 'description' => 'NETAPP DS424IOM12A'];
$dir = "$root/var/lib/sesmon-ext/netapp"; mkdir($dir, 0777, true);
file_put_contents("$dir/current.json", json_encode(['device' => $dev, 'raw' => $netapp])); file_put_contents("$dir/current_parsed.json", json_encode(['device' => $dev]));

// a notify.sh whose absolute paths point at the repo and a stub
$stub = "$root/notify-stub.sh"; $log = "$root/notify.log";
file_put_contents($stub, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > " . escapeshellarg($log) . "\n"); chmod($stub, 0755);
function make_notify($plugin, $root, $stub, $helperPath) {
    $s = file_get_contents("$plugin/defaults/notify.sh");
    $s = str_replace('NOTIFY="/usr/local/emhttp/plugins/dynamix/scripts/notify"', 'NOTIFY="' . $stub . '"', $s);
    $s = str_replace('/usr/local/emhttp/plugins/sesmon-ext/scripts/alert_context.php', $helperPath, $s);
    $s = str_replace('/usr/bin/php', PHP_BINARY, $s);
    $f = "$root/notify-under-test.sh"; file_put_contents($f, $s); chmod($f, 0755);
    return $f;
}
function run_alert($script, $root, $log, $addr, $path, $msg, $json) {
    @unlink($log);
    exec('SESEXT_ROOT=' . escapeshellarg($root) . ' bash ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', [$path, $addr, 'NetApp', $msg, $json])) . ' 2>&1', $out, $code);
    return ['code' => $code, 'args' => is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [], 'out' => implode("\n", $out)];
}
$change = fn($id, $t, $n) => ['id' => $id, 'element_type' => $t, 'element_type_number' => $n, 'element_type_desc' => 'x'];
$report = fn($chs) => json_encode(['device' => $dev, 'detected_at' => 'now', 'changes' => $chs]);
$msg = '[element="1#10" type="Device slot" number=10 / Before: (status=1 status_txt="OK") / After: (status=2 status_txt="Critical")]';
$script = make_notify($plugin, $root, $stub, "$plugin/scripts/alert_context.php");

$r = run_alert($script, $root, $log, $dev['address'], '/dev/sg34', $msg, $report([$change('1#10', 1, 10)]));
eq($r['code'], 0, 'alert: notify.sh succeeds'); eq($r['args'][0] ?? null, '-e', 'alert: the notification command is called as before');
$desc = $r['args'][array_search('-d', $r['args']) + 1] ?? '';
ok(strpos($desc, 'Affected drives - drive bay 10: disk22 + disk23 (SEAGATE ST14000NM0001, one drive presenting 2 disks) -- ') === 0, 'alert: the message starts with the drives in the bay: ' . $desc);
ok(strpos($desc, '[element:"1:10"') !== false && strpos($desc, '=') === false && strpos($desc, '#') === false, 'alert: sesmon\'s own text follows, with the characters Unraid\'s notifier cannot take already replaced');
eq($r['args'][array_search('-e', $r['args']) + 1] ?? null, 'SCSI Enclosure Alert', 'alert: the event name is unchanged');

$r = run_alert($script, $root, $log, $dev['address'], '/dev/sg34', '[element="3#2" fan]', $report([$change('3#2', 3, 2)]));
$desc = $r['args'][array_search('-d', $r['args']) + 1] ?? '';
ok(strpos($desc, 'Affected drives') === false && strpos($desc, '[element:"3:2"') === 0, 'alert: a fan alert is passed on unchanged');
$r = run_alert($script, $root, $log, $dev['address'], '/dev/sg34', 'plain text', 'not json');
ok(($r['args'][array_search('-d', $r['args']) + 1] ?? '') === 'plain text', 'alert: a report that is not JSON leaves the message alone');
$r = run_alert($script, $root, $log, $dev['address'], '/dev/sg34', 'back-off notice', '');
ok(($r['args'][array_search('-d', $r['args']) + 1] ?? '') === 'back-off notice', 'alert: a notification without a report (a back-off) is unchanged');
$missing = make_notify($plugin, $root, $stub, "$root/no-such-helper.php");
$r = run_alert($missing, $root, $log, $dev['address'], '/dev/sg34', 'msg', $report([$change('1#10', 1, 10)]));
ok($r['code'] === 0 && ($r['args'][array_search('-d', $r['args']) + 1] ?? '') === 'msg', 'alert: with the helper missing the alert still goes out, unchanged');
$r = run_alert($script, $root, $log, '0x0000000000000001', '/dev/sg99', 'msg', $report([$change('1#10', 1, 10)]));
ok(($r['args'][array_search('-d', $r['args']) + 1] ?? '') === 'msg', 'alert: an enclosure that is not monitored leaves the message alone');

// the helper by itself: never fails
exec(escapeshellarg(PHP_BINARY) . ' -q ' . escapeshellarg("$plugin/scripts/alert_context.php") . ' 2>&1', $o, $c); eq([$c, $o], [0, []], 'helper: no arguments: prints nothing and exits 0');
exec('SESEXT_ROOT=/nonexistent ' . escapeshellarg(PHP_BINARY) . ' -q ' . escapeshellarg("$plugin/scripts/alert_context.php") . ' x y z 2>&1', $o2, $c2); eq($c2, 0, 'helper: on a server without any of the data it still exits 0');

// the installer's rule for an installed notify.sh (the snippet of doinst.sh, run against temp folders)
$doinst = file_get_contents(dirname(__DIR__) . '/source/install/doinst.sh');
$start = strpos($doinst, '# The shipped notify.sh names'); $end = strpos($doinst, "cp -nr \$DOCROOT/defaults/* \$BOOT/config/") + strlen("cp -nr \$DOCROOT/defaults/* \$BOOT/config/");
ok($start !== false && $end > $start, 'installer: the notify.sh rule is in doinst.sh');
$snippet = substr($doinst, $start, $end - $start);
$prev = "$plugin/notify.previous.sha256";
$old = "#!/bin/bash\n# an earlier shipped notify.sh\n";
$sim = function ($installed) use ($snippet, $plugin, $old) {
    $t = sys_get_temp_dir() . '/sesext-doinst-' . getmypid() . '-' . mt_rand(); mkdir("$t/boot/config", 0777, true); mkdir("$t/doc/defaults", 0777, true);
    copy("$plugin/defaults/notify.sh", "$t/doc/defaults/notify.sh");
    file_put_contents("$t/doc/notify.previous.sha256", "# earlier versions\n" . hash('sha256', $old) . "  2026.09.20\n");
    if ($installed !== null) { file_put_contents("$t/boot/config/notify.sh", $installed); }
    exec('BOOT=' . escapeshellarg("$t/boot") . ' DOCROOT=' . escapeshellarg("$t/doc") . ' sh -c ' . escapeshellarg($snippet) . ' 2>&1', $o, $c);
    $after = @file_get_contents("$t/boot/config/notify.sh"); exec('rm -rf ' . escapeshellarg($t));
    return $after; // (the exit status is that of the closing cp -n, which fails when it skips a file: irrelevant, doinst.sh does not stop on errors)
};
$current = file_get_contents("$plugin/defaults/notify.sh");
$after = $sim($old); eq($after, $current, 'installer: an unedited earlier notify.sh is replaced by the current one');
$after = $sim($old . "# my own change\n"); eq($after, $old . "# my own change\n", 'installer: a notify.sh the user edited is left alone');
$after = $sim(null); eq($after, $current, 'installer: a missing notify.sh is added');
$after = $sim($current); eq($after, $current, 'installer: the current notify.sh stays');
ok(strpos(file_get_contents($prev), '9a6f38e1e4c53edec5c296ec5e40f42d479d8fc4d859cf17e658384e2ecc3976') !== false, 'installer: the notify.sh of the 2026.09.20 release is on the list');

sesext_test_done();
