<?php
require __DIR__ . '/lib.php';

$root = sesext_fixture_root(sesext_catan_lines());
putenv("SESEXT_ROOT=$root");
putenv('SESEXT_SESMON=' . SESEXT_FIXTURES . '/fake-sesmon.sh');
putenv('SESEXT_RC=' . SESEXT_FIXTURES . '/fake-rc.sh');
require SESEXT_INC . '/sesext_ops.php';

$etc = "$root/etc/sesmon-ext"; $boot = "$root/boot/config/plugins/sesmon-ext/config";
mkdir($etc, 0777, true);
file_put_contents("$etc/notify.sh", "#!/bin/sh\necho hi\n"); chmod("$etc/notify.sh", 0755);
$defaultText = file_get_contents(SESEXT_DEFAULTS . '/config.yaml');
file_put_contents("$etc/config.yaml", $defaultText);

// ---- names
foreach (['config.yaml', 'notify.sh', 'my-script.sh', 'a.b.yaml'] as $n) { ok(sesext_valid_name($n), "name ok: $n"); }
foreach (['../config.yaml', 'a/b.yaml', '.hidden.sh', 'config.txt', 'config', '', 'x.yaml/../../etc/passwd', "a\0.yaml", 'a b.yaml'] as $n) { ok(!sesext_valid_name($n), 'name refused: ' . json_encode($n)); }
foreach (['../../etc/passwd', '/etc/passwd'] as $n) {
    ok(!sesext_read_file($n)['ok'], "read refused: $n");
    ok(!sesext_save_file($n, 'x')['ok'], "save refused: $n");
}

// ---- state with the shipped default
$s = sesext_state();
ok($s['form_ok'], 'state: form usable'); eq($s['error'], null, 'state: no load error');
eq(count($s['devices']), 3, 'state: three configured devices');
eq($s['problems'], [], 'state: the shipped default has no problems');
eq(array_column($s['available'], 'dev'), ['/dev/sg8', '/dev/sg34'], 'state: both enclosures offered');
eq($s['shared_drive_addresses'], 8, 'state: shared drive addresses are counted, not treated as problems');
eq($s['raw'], $defaultText, 'state: raw text');
eq($s['scripts'], [], 'state: no custom scripts yet');
eq($s['files'], ['config.yaml', 'notify.sh'], 'state: editable files');

// ---- raw save: rejected file changes nothing
$before = file_get_contents("$etc/config.yaml");
$r = sesext_save_file('config.yaml', "devices:\n  - BADKEY: 1\n");
ok(!$r['ok'], 'raw save: sesmon rejects a bad key'); ok(strpos($r['errors'][0], 'BADKEY') !== false, 'raw save: the daemon\'s message is shown');
eq(file_get_contents("$etc/config.yaml"), $before, 'raw save: /etc file untouched after rejection');
ok(!file_exists("$boot/config.yaml"), 'raw save: nothing written to the flash after rejection');

// ---- raw save: accepted, both copies written, then a backup on the next save
$good = "devices:\n  - address: \"0x500a098008561d10\"\n    type: 0\n    description: \"X\"\n    enabled: true\n";
$r = sesext_save_file('config.yaml', $good);
ok($r['ok'], 'raw save: accepted'); ok(isset($r['test']), 'raw save: sesmon test result is returned');
ok(strpos($r['test']['output'], 'multiple devices') === false, 'raw save: harmless shared-address warnings are filtered from the test output');
eq(file_get_contents("$etc/config.yaml"), $good, 'raw save: /etc copy'); eq(file_get_contents("$boot/config.yaml"), $good, 'raw save: flash copy');
ok(!file_exists("$boot/config.yaml.bak"), 'raw save: no backup on the first save');
sesext_save_file('config.yaml', $good . "# second\n");
eq(file_get_contents("$boot/config.yaml.bak"), $good, 'raw save: previous version kept as .bak');
sesext_save_file('config.yaml', str_replace("\n", "\r\n", $good));
eq(file_get_contents("$etc/config.yaml"), $good, 'raw save: carriage returns removed');
eq(substr(sprintf('%o', fileperms("$etc/config.yaml")), -4), '0644', 'raw save: yaml mode');

// ---- scripts: syntax checked, executable
$r = sesext_save_file('mine.sh', "#!/bin/bash\nif then fi\n");
ok(!$r['ok'], 'script: syntax error rejected'); ok(!file_exists("$etc/mine.sh"), 'script: rejected script not written');
$r = sesext_save_file('mine.sh', "#!/bin/bash\necho ok");
ok($r['ok'], 'script: valid script saved'); ok(is_executable("$etc/mine.sh"), 'script: saved executable');
eq(file_get_contents("$etc/mine.sh"), "#!/bin/bash\necho ok\n", 'script: trailing newline added');
eq(sesext_notifier_scripts(), ['/etc/sesmon-ext/mine.sh'], 'script: now offered as a notifier');

// ---- form save from the shipped default
file_put_contents("$etc/config.yaml", $defaultText); @unlink("$boot/config.yaml"); @unlink("$boot/config.yaml.bak");
$form = ['devices' => [[
    'orig' => 0, 'address' => '0x500a098008561d10', 'description' => 'NetApp DS424', 'enabled' => true,
    'config' => ['poll_interval' => '5m'], 'notifier' => ['mode' => 'unraid'],
], ['orig' => 1, 'device' => '/dev/sg25', 'description' => 'JBOD2', 'enabled' => false]]];
$r = sesext_save_form($form);
ok($r['ok'], 'form save: accepted: ' . json_encode($r['errors'] ?? []));
$saved = sesext_load("$etc/config.yaml");
eq($saved['error'], null, 'form save: the written file loads');
eq($saved['config']['devices'][0]['address'], '0x500a098008561d10', 'form save: address written');
eq($saved['config']['devices'][0]['config']['poll_interval'], '5m', 'form save: setting written');
eq(count($saved['config']['devices']), 2, 'form save: the device left out of the form is gone');
eq(file_get_contents("$boot/config.yaml"), file_get_contents("$etc/config.yaml"), 'form save: flash and /etc copies match');
ok(strpos(file_get_contents("$etc/config.yaml"), '# How often to poll') !== false, 'form save: file is annotated');

// ---- form save refused: validation errors, nothing written
$before = file_get_contents("$etc/config.yaml");
$r = sesext_save_form(['devices' => [['orig' => null, 'address' => '0x500a098012345678', 'description' => 'ghost', 'enabled' => true, 'config' => ['poll_interval' => 'soon']]]]);
ok(!$r['ok'], 'form save: invalid form refused');
ok(count($r['errors']) === 2, 'form save: both problems reported (bad duration, address not found)');
eq(file_get_contents("$etc/config.yaml"), $before, 'form save: file untouched after refusal');
$r = sesext_save_form(['devices' => [['orig' => null, 'address' => '0x500a098008561d10', 'description' => 'a', 'enabled' => true, 'notifier' => ['mode' => 'custom', 'script' => '/etc/sesmon-ext/nope.sh']]]]);
ok(!$r['ok'] && strpos($r['errors'][0], 'nope.sh') !== false, 'form save: unknown custom script refused');

// ---- form save when the file cannot be understood by the form
file_put_contents("$etc/config.yaml", "devices: &x\n  - address: a\n");
$r = sesext_save_form($form);
ok(!$r['ok'] && strpos($r['errors'][0], 'raw editor') !== false, 'form save: refuses to overwrite a file it cannot read');
$s = sesext_state();
ok(!$s['form_ok'], 'state: form disabled for an unsupported file'); eq($s['error'][0], 'unsupported', 'state: reason reported');
eq($s['raw'], "devices: &x\n  - address: a\n", 'state: raw text still available');
eq(count($s['available']), 2, 'state: enclosures are still discoverable');
$r = sesext_save_file('config.yaml', $defaultText);
ok($r['ok'], 'raw save repairs it'); ok(sesext_state()['form_ok'], 'state: form is back after the raw save');

// ---- the daemon
eq(sesext_service('restart')['ok'], true, 'service: restart runs the script');
eq(trim(sesext_service('restart')['output']), 'fake rc: restart', 'service: output returned');
ok(!sesext_service('fail')['ok'], 'service: unknown action refused');

// ---- a missing file: the form starts empty
unlink("$etc/config.yaml");
$s = sesext_state();
ok($s['form_ok'], 'missing file: form usable'); eq($s['devices'], [], 'missing file: no devices'); eq(count($s['available']), 2, 'missing file: enclosures offered');
$r = sesext_save_form(['devices' => [['orig' => null, 'address' => '0x500a098008561d10', 'description' => 'NetApp', 'enabled' => true, 'notifier' => ['mode' => 'unraid']]]]);
ok($r['ok'], 'missing file: form save creates the file'); ok(file_exists("$etc/config.yaml") && file_exists("$boot/config.yaml"), 'missing file: written to both places');

// ---- the router
eq(sesext_handle('nope', [])['ok'], false, 'handle: unknown action');
eq(sesext_handle('save_form', ['form' => 'not json'])['ok'], false, 'handle: garbage form');
eq(sesext_handle('state', [])['form_ok'], true, 'handle: state');
ok(isset(sesext_handle('status', [])['running']), 'handle: status');

sesext_test_done();
