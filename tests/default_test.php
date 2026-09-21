<?php
// What a fresh install gets: the config the installer ships (defaults/config.yaml).
require __DIR__ . '/lib.php';

$root = sesext_fixture_root(sesext_catan_lines());
putenv("SESEXT_ROOT=$root");
putenv('SESEXT_SESMON=' . SESEXT_FIXTURES . '/fake-sesmon.sh');
require SESEXT_INC . '/sesext_ops.php';

$text = file_get_contents(SESEXT_DEFAULTS . '/config.yaml');
$cfg = sesext_yaml_parse($text);
eq($cfg, ['disable_timestamps' => true, 'devices' => []], 'default: no devices, timestamps off (the syslog adds its own)');
ok(strpos($text, 'JBOD') === false && strpos($text, '0x500a098012345678') === false && strpos($text, '/tmp/device.json') === false, 'default: no example or placeholder devices');
ok(strpos($text, 'address:') === false && strpos($text, 'enabled:') === false, 'default: nothing that could be mistaken for a configured device');

$disc = sesext_discover($root);
$view = sesext_view($cfg, $disc);
eq($view['devices'], [], 'fresh install: no configured rows on the page');
eq(array_column($view['available'], 'dev'), ['/dev/sg8', '/dev/sg34'], 'fresh install: the real enclosures are offered');
eq(sesext_problems($cfg, $disc), [], 'fresh install: nothing is reported as a problem');
eq(sesext_validate($cfg, $disc), ['errors' => [], 'warnings' => []], 'fresh install: validates');
eq(sesext_yaml_parse(sesext_render($cfg)), $cfg, 'fresh install: survives a render and parse unchanged');

// the whole path: install the default, then choose the NetApp
$etc = "$root/etc/sesmon-ext"; mkdir($etc, 0777, true);
file_put_contents("$etc/notify.sh", "#!/bin/sh\n"); chmod("$etc/notify.sh", 0755);
file_put_contents("$etc/config.yaml", $text);
$state = sesext_state();
ok($state['form_ok'] && $state['error'] === null, 'state: the form is usable on a fresh install');
eq(count($state['devices']), 0, 'state: nothing configured'); eq(count($state['available']), 2, 'state: two enclosures to choose from');

$r = sesext_save_form(['devices' => [['orig' => null, 'address' => '0x500a098008561d10', 'description' => 'NetApp DS424IOM12A', 'enabled' => true, 'notifier' => ['mode' => 'unraid']]]]);
ok($r['ok'], 'first save from the default works: ' . json_encode($r['errors'] ?? []));
$saved = sesext_load("$etc/config.yaml")['config'];
eq(count($saved['devices']), 1, 'first save: exactly one device, no placeholders left over');
eq($saved['devices'][0]['address'], '0x500a098008561d10', 'first save: the chosen enclosure');
$after = sesext_state();
eq([count($after['devices']), count($after['available']), $after['problems']], [1, 1, []], 'after the first save: one monitored, one still available, no problems');

// saving with nothing chosen leaves a valid, empty file (the daemon then has nothing to do)
$r = sesext_save_form(['devices' => []]);
ok($r['ok'], 'saving an empty selection is allowed');
eq(sesext_load("$etc/config.yaml")['config']['devices'], [], 'an empty selection is written as an empty list');

sesext_test_done();
