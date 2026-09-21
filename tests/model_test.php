<?php
require __DIR__ . '/lib.php';
require SESEXT_INC . '/sesext_model.php';

$disc = sesext_discover(sesext_fixture_root(sesext_catan_lines()));
$defaultText = file_get_contents(SESEXT_DEFAULTS . '/config.yaml');
$default = sesext_yaml_parse($defaultText);

// ---- durations and presets
eq(sesext_duration_seconds('1m30s'), 90.0, 'duration: 1m30s');
eq(sesext_duration_seconds('3m0s'), 180.0, 'duration: 3m0s');
eq(sesext_duration_seconds('1h'), 3600.0, 'duration: 1h');
eq(sesext_duration_seconds('500ms'), 0.5, 'duration: ms');
foreach (['', 'abc', '5', '1 m', '-1s', '1x'] as $bad) { eq(sesext_duration_seconds($bad), null, "duration: '$bad' is invalid"); }
eq(sesext_preset_for('poll_interval', '90s'), '1m30s', 'preset: 90s is the same as 1m30s');
eq(sesext_preset_for('poll_interval', '60s'), '1m', 'preset: 60s is 1m');
eq(sesext_preset_for('poll_interval', '7m'), 'custom', 'preset: an unusual value is custom');
eq(sesext_preset_for('poll_attempts', 3), 3, 'preset: number');
eq(sesext_preset_for('poll_attempts', 9), 'custom', 'preset: number outside the list');
eq(sesext_preset_for('poll_backoff_time', '180s'), '3m0s', 'preset: 180s is 3m0s');

// ---- loading
$tmp = sys_get_temp_dir() . '/sesext-model-' . getmypid(); mkdir($tmp);
register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($tmp)));
mkdir("$tmp/etc/sesmon-ext", 0777, true); // a fake server: the built-in notification script exists
file_put_contents("$tmp/etc/sesmon-ext/notify.sh", "#!/bin/sh\n"); chmod("$tmp/etc/sesmon-ext/notify.sh", 0755);
file_put_contents("$tmp/a.yaml", "devices: &d\n  - address: x\n");
eq(sesext_load("$tmp/a.yaml")['error'][0], 'unsupported', 'load: anchors are unsupported');
file_put_contents("$tmp/b.yaml", "devices:\n  - address: \"x\n");
eq(sesext_load("$tmp/b.yaml")['error'][0], 'syntax', 'load: syntax error');
eq(sesext_load("$tmp/nope.yaml")['error'][0], 'missing', 'load: missing file');
file_put_contents("$tmp/c.yaml", "devices: 5\n");
eq(sesext_load("$tmp/c.yaml")['error'][0], 'unsupported', 'load: devices must be a list');
file_put_contents("$tmp/d.yaml", "devices:\n  - address: x\n    config: 5\n");
eq(sesext_load("$tmp/d.yaml")['error'][0], 'unsupported', 'load: config must be a mapping');
file_put_contents("$tmp/e.yaml", $defaultText);
$l = sesext_load("$tmp/e.yaml");
eq($l['error'], null, 'load: default config loads'); eq($l['raw'], $defaultText, 'load: raw text is returned untouched');

// ---- the failure from catan: enabled placeholder address
$broken = $default; $broken['devices'][0]['enabled'] = true;
$p = sesext_problems($broken, $disc);
eq(count($p), 1, 'problems: one');
eq($p[0]['code'], 'unresolvable', 'problems: the placeholder address does not resolve');
eq($p[0]['name'], 'JBOD', 'problems: named by description');
eq(sesext_problems($default, $disc), [], 'problems: the shipped default (device disabled) is fine');

// ---- view
$v = sesext_view($broken, $disc, fn($p) => false);
eq(count($v['devices']), 3, 'view: a row per configured device');
eq($v['devices'][0]['status']['level'], 'error', 'view: unresolvable row is an error');
eq(array_column($v['available'], 'dev'), ['/dev/sg8', '/dev/sg34'], 'view: both enclosures are available when nothing resolves');
eq($v['devices'][0]['notifier']['mode'], 'unraid', 'view: built-in notifier recognised');
eq($v['devices'][1]['notifier']['mode'], 'log', 'view: no script_notifier means log only');
eq($v['devices'][0]['fields']['poll_interval']['preset'], '1m30s', 'view: preset chosen');
eq($v['devices'][1]['config']['poll_interval'], '1m30s', 'view: defaults filled in for devices without config');
$good = $default; $good['devices'][0] = ['address' => '0x500a098008561d10', 'type' => 0, 'description' => 'JBOD', 'enabled' => true];
$v2 = sesext_view($good, $disc);
eq($v2['devices'][0]['status']['level'], 'ok', 'view: real address is ok');
eq(array_column($v2['available'], 'dev'), ['/dev/sg8'], 'view: the monitored enclosure is not offered again');

// ---- applying the form: pick the NetApp on a fresh config
$form = ['devices' => [[
    'orig' => null, 'address' => '0X500A098008561D10', 'description' => 'NetApp DS424', 'enabled' => true,
    'config' => ['poll_interval' => '2m', 'poll_attempts' => '4', 'poll_backoff_notify' => 'false', 'verbose' => true],
    'notifier' => ['mode' => 'unraid', 'config' => ['notify_attempts' => '2']],
]]];
$new = sesext_apply_form(null, $form);
$d0 = $new['devices'][0];
eq($d0['address'], '0x500a098008561d10', 'apply: address is written in lower case');
eq($d0['type'], 0, 'apply: type 0');
eq($d0['config']['poll_interval'], '2m', 'apply: duration');
eq($d0['config']['poll_attempts'], 4, 'apply: numeric string becomes int');
eq($d0['config']['poll_backoff_notify'], false, 'apply: "false" becomes bool');
eq($d0['config']['verbose'], true, 'apply: bool');
eq($d0['config']['output_dir'], '/var/lib/sesmon-ext/netapp-ds424', 'apply: output folder generated from the description');
eq($d0['script_notifier']['script'], '/etc/sesmon-ext/notify.sh', 'apply: built-in notifier');
eq($d0['script_notifier']['config']['notify_attempts'], 2, 'apply: notifier settings');
eq($new['disable_timestamps'], true, 'apply: disable_timestamps defaults to true');
eq(sesext_validate($new, $disc, $tmp)['errors'], [], 'apply: result validates against the catan discovery');

// unique output folders for equal descriptions
$two = sesext_apply_form(null, ['devices' => [
    ['orig' => null, 'address' => '0x500a098008561d10', 'description' => 'JBOD', 'enabled' => true],
    ['orig' => null, 'address' => '0x300705b01098e070', 'description' => 'JBOD', 'enabled' => true]]]);
eq([$two['devices'][0]['config']['output_dir'], $two['devices'][1]['config']['output_dir']], ['/var/lib/sesmon-ext/jbod', '/var/lib/sesmon-ext/jbod-2'], 'apply: output folders are unique');

// ---- unknown settings survive a save
$existing = $default;
$existing['future_global'] = ['a' => 1];
$existing['devices'][0]['future_device'] = 'keep me';
$existing['devices'][0]['config']['future_tunable'] = '5s';
$existing['devices'][0]['config']['output_dir'] = '/var/lib/sesmon-ext/custom-dir';
$existing['devices'][0]['script_notifier']['future_notifier'] = true;
$form = ['devices' => [['orig' => 0, 'address' => '0x500a098008561d10', 'description' => 'JBOD', 'enabled' => true,
    'config' => ['poll_interval' => '5m'], 'notifier' => ['mode' => 'custom', 'script' => '/etc/sesmon-ext/mine.sh']]]];
$saved = sesext_apply_form($existing, $form);
eq($saved['future_global'], ['a' => 1], 'keep: unknown top-level key');
eq($saved['devices'][0]['future_device'], 'keep me', 'keep: unknown device key');
eq($saved['devices'][0]['config']['future_tunable'], '5s', 'keep: unknown tunable');
eq($saved['devices'][0]['script_notifier']['future_notifier'], true, 'keep: unknown notifier key');
eq($saved['devices'][0]['config']['output_dir'], '/var/lib/sesmon-ext/custom-dir', 'keep: an existing output folder is not regenerated');
eq($saved['devices'][0]['script_notifier']['script'], '/etc/sesmon-ext/mine.sh', 'apply: custom script');
eq($saved['devices'][0]['config']['poll_interval'], '5m', 'apply: changed value');
eq(count($saved['devices']), 1, 'apply: devices left out of the form are dropped');
$text = sesext_render($saved);
$reparsed = sesext_yaml_parse($text);
eq($reparsed, $saved, 'render: the written file parses back to exactly the saved configuration');
ok(strpos($text, 'future_tunable: "5s"') !== false, 'render: unknown tunable is in the file');
ok(strpos($text, "Further settings kept") !== false, 'render: unknown keys are labelled');
ok(strpos($text, '# How often to poll the enclosure for data') !== false, 'render: documented keys carry a comment');

// notifier mode "log" removes the notifier; a JSON test device is left alone
$fileDev = $default; 
$saved2 = sesext_apply_form($default, ['devices' => [
    ['orig' => 0, 'address' => '0x500a098012345678', 'description' => 'JBOD', 'enabled' => false, 'notifier' => ['mode' => 'log']],
    ['orig' => 2, 'device' => '/tmp/device.json', 'description' => 'JBOD3', 'enabled' => true, 'config' => ['poll_interval' => '5m'], 'notifier' => ['mode' => 'unraid']]]]);
ok(!isset($saved2['devices'][0]['script_notifier']), 'apply: log only removes the notifier');
eq($saved2['devices'][1]['type'], 1, 'apply: test-file device keeps its type');
ok(!isset($saved2['devices'][1]['config']), 'apply: settings of a test-file device are not touched');
ok(!isset($saved2['devices'][1]['script_notifier']), 'apply: no notifier is added to a test-file device');

// the whole shipped default survives render -> parse
eq(sesext_yaml_parse(sesext_render($default)), $default, 'render: shipped default survives a save unchanged');

// ---- validation
$bad = $good;
$bad['devices'][0]['config'] = ['poll_interval' => 'soon', 'poll_attempts' => 0, 'poll_backoff_notify' => 'yes', 'poll_attempt_timeout' => '0s'];
$r = sesext_validate($bad, $disc);
eq(count($r['errors']), 4, 'validate: four problems reported');
ok(strpos(implode("\n", $r['errors']), 'poll_interval "soon" is not a valid duration') !== false, 'validate: message names the value');
ok(strpos(implode("\n", $r['errors']), 'poll_attempts must be a whole number above zero') !== false, 'validate: attempts above zero');
$none = $good; unset($none['devices'][0]['address']);
ok(strpos(implode("\n", sesext_validate($none, $disc)['errors']), 'choose an enclosure') !== false, 'validate: enabled device needs a target');
$disabledNone = $none; $disabledNone['devices'][0]['enabled'] = false;
eq(sesext_validate($disabledNone, $disc)['errors'], [], 'validate: disabled devices are not judged');
$dupDir = ['devices' => [
    ['address' => '0x500a098008561d10', 'enabled' => true, 'config' => ['output_dir' => '/var/lib/sesmon-ext/x']],
    ['address' => '0x300705b01098e070', 'enabled' => true, 'config' => ['output_dir' => '/var/lib/sesmon-ext/x']]]];
ok(strpos(implode("\n", sesext_validate($dupDir, $disc)['errors']), 'already used by another device') !== false, 'validate: duplicate output folder');
$dupTarget = ['devices' => [
    ['address' => '0x500a098008561d10', 'enabled' => true], ['device' => '/dev/sg34', 'enabled' => true]]];
ok(strpos(implode("\n", sesext_validate($dupTarget, $disc)['errors']), 'already monitored by another device') !== false, 'validate: same enclosure twice (by address and by path)');
$outside = ['devices' => [['address' => '0x500a098008561d10', 'enabled' => true, 'config' => ['output_dir' => '/tmp/elsewhere']]]];
ok(strpos(implode("\n", sesext_validate($outside, $disc)['warnings']), 'dashboard will not show') !== false, 'validate: output folder outside the dashboard base is a warning');
$noScript = $good; $noScript['devices'][0]['script_notifier'] = ['script' => '/etc/sesmon-ext/missing.sh'];
ok(strpos(implode("\n", sesext_validate($noScript, $disc, sesext_root())['errors']), 'does not exist or is not executable') !== false, 'validate: missing notification script');
$unres = $default; $unres['devices'][0]['enabled'] = true;
ok(strpos(implode("\n", sesext_validate($unres, $disc, $tmp)['errors']), 'was not found on this server') !== false, 'validate: unresolvable address is an error');

// ---- custom notification scripts are discovered
foreach (['notify.sh' => 0755, 'mine.sh' => 0755, 'notexec.sh' => 0644, 'other.yaml' => 0755] as $n => $m) { file_put_contents("$tmp/etc/sesmon-ext/$n", "#!/bin/sh\n"); chmod("$tmp/etc/sesmon-ext/$n", $m); }
eq(sesext_notifier_scripts("$tmp/etc/sesmon-ext"), ['/etc/sesmon-ext/mine.sh'], 'scripts: only executable *.sh besides notify.sh');

sesext_test_done();
