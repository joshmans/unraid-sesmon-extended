<?php
require __DIR__ . '/lib.php';
require SESEXT_INC . '/sesext_yaml.php';

// --- the shipped default config parses into the expected structure
$def = sesext_yaml_parse(file_get_contents(SESEXT_DEFAULTS . '/config.yaml'));
eq($def['disable_timestamps'], true, 'default: disable_timestamps');
eq(count($def['devices']), 3, 'default: three devices');
eq($def['devices'][0]['address'], '0x500a098012345678', 'default: address kept as string');
eq($def['devices'][0]['enabled'], false, 'default: example device is disabled');
eq($def['devices'][0]['config']['poll_interval'], '1m30s', 'default: duration is a string');
eq($def['devices'][0]['config']['poll_attempts'], 3, 'default: int');
eq($def['devices'][0]['config']['poll_backoff_notify'], true, 'default: bool');
eq($def['devices'][0]['script_notifier']['script'], '/etc/sesmon-ext/notify.sh', 'default: nested map');
eq($def['devices'][0]['script_notifier']['config']['notify_attempts'], 3, 'default: map in map in list item');
eq($def['devices'][1]['device'], '/dev/sg25', 'default: second device');
eq($def['devices'][2]['type'], 1, 'default: json test device type');

// --- scalars
$s = sesext_yaml_parse("a: 'it''s'\nb: \"x \\\"q\\\" y\"\nc: plain text # comment\nd: \"has # hash\"\ne: 0x500a098012345678\nf: ~\ng:\nh: 1.5\ni: -3\n");
eq($s['a'], "it's", 'single quote escape');
eq($s['b'], 'x "q" y', 'double quote escape');
eq($s['c'], 'plain text', 'inline comment stripped');
eq($s['d'], 'has # hash', 'hash inside quotes kept');
eq($s['e'], '0x500a098012345678', 'plain hex stays a string');
eq($s['f'], null, 'tilde is null'); eq($s['g'], null, 'empty is null');
eq($s['h'], 1.5, 'float'); eq($s['i'], -3, 'negative int');

// --- structure variants
eq(sesext_yaml_parse("devices:\n- a: 1\n  b: 2\n- a: 3\n"), ['devices' => [['a' => 1, 'b' => 2], ['a' => 3]]], 'sequence at the key indent');
eq(sesext_yaml_parse("devices:\n    - a: 1\n      b: 2\n"), ['devices' => [['a' => 1, 'b' => 2]]], 'deeper indentation');
eq(sesext_yaml_parse("x: []\ny: {}\n")['x'], [], 'empty flow list');
ok(sesext_yaml_parse("y: {}\n")['y'] instanceof stdClass, 'empty flow map');
eq(sesext_yaml_parse("---\nk: v\n"), ['k' => 'v'], 'leading document marker');
eq(sesext_yaml_parse("# only a comment\n\n"), null, 'comment-only document');
eq(sesext_yaml_parse("l:\n  - one\n  - two\n"), ['l' => ['one', 'two']], 'scalar list');

// --- refused constructs
foreach ([
    "a: &x 1\n" => 'anchor', "a: *x\n" => 'alias', "a: !!str 1\n" => 'tag', "a: |\n  text\n" => 'block scalar',
    "a: [1, 2]\n" => 'flow list', "a: {b: 1}\n" => 'flow map', "a: 1\n---\nb: 2\n" => 'second document',
    "a: yes\n" => 'yes bool', "\ta: 1\n" => 'tab indent', "a: \"\\u00e9\"\n" => 'unicode escape',
] as $text => $what) {
    throws(fn() => sesext_yaml_parse($text), SesextYamlUnsupported::class, "unsupported: $what");
}
// --- syntax errors
foreach ([
    "a: 1\na: 2\n" => 'duplicate key', "a: \"open\n" => 'unterminated', "a: 1\n b: 2\n" => 'bad indentation', "just text\n" => 'not a mapping',
] as $text => $what) {
    throws(fn() => sesext_yaml_parse($text), SesextYamlError::class, "error: $what");
}

// --- writer: schema order, comments, unknown keys kept, list items
$schema = ['order' => ['name', 'items'], 'comments' => ['name' => ['The name']], 'children' => ['items' => ['order' => ['id', 'tag'], 'comments' => ['tag' => ['A tag']]]]];
$data = ['items' => [['tag' => 'x', 'id' => 1, 'extra' => true], ['id' => 2]], 'name' => 'n "q"', 'zzz' => ['k' => 'v']];
$out = implode("\n", sesext_yaml_emit($data, 0, $schema)) . "\n";
eq(sesext_yaml_parse($out), ['name' => 'n "q"', 'items' => [['id' => 1, 'tag' => 'x', 'extra' => true], ['id' => 2]], 'zzz' => ['k' => 'v']], 'writer output re-parses to the same data');
ok(strpos($out, "# The name\nname:") !== false, 'writer: comment above key');
ok(strpos($out, "- id: 1\n") !== false, 'writer: first key on the dash line');
ok(strpos($out, "\n    # A tag\n    tag:") !== false, 'writer: comment inside list item aligned with keys');
ok(strpos($out, "Further settings kept") !== false, 'writer: unknown keys are labelled');
ok(strpos($out, "zzz:\n  k: \"v\"") !== false, 'writer: unknown nested map kept');

// --- round trip: parse -> emit (no schema) -> parse gives the same tree
$again = sesext_yaml_parse(implode("\n", sesext_yaml_emit($def, 0)) . "\n");
eq($again, $def, 'default config survives parse -> emit -> parse');

sesext_test_done();
