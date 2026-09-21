<?php
// Builds the fake server for the harness at the path given: the real sysfs data of catan (enclosures, drives, the HBA), Unraid's
// state file, and what sesmon would have written for both enclosures (the NetApp shelf and the HBA's virtual enclosure).
require __DIR__ . '/../lib.php';
$root = $argv[1];
sesext_fixture_root(sesext_catan_lines(), $root);
@mkdir("$root/var/local/emhttp", 0777, true);
copy(SESEXT_FIXTURES . '/catan/disks.ini', "$root/var/local/emhttp/disks.ini");
$parsed = json_decode(file_get_contents(SESEXT_FIXTURES . '/catan/current_parsed.json'), true);
$vparsed = json_decode(file_get_contents(SESEXT_FIXTURES . '/catan/parsed-virtualses.json'), true);
$ses = fn($f) => json_decode(file_get_contents(SESEXT_FIXTURES . "/catan/$f"), true);
$folders = [
    'netapp-ds424iom12a' => [$parsed, $ses('ses-netapp.json')],
    'internal-hba-ports' => [$vparsed, $ses('ses-virtualses.json')],
];
foreach ($folders as $name => [$p, $raw]) {
    $d = "$root/var/lib/sesmon-ext/$name"; mkdir($d, 0777, true);
    file_put_contents("$d/current_parsed.json", json_encode($p));
    file_put_contents("$d/current.json", json_encode(['device' => $p['device'], 'captured_at' => $p['captured_at'], 'raw' => $raw]));
}
copy(SESEXT_FIXTURES . '/catan/change-sample.json', "$root/var/lib/sesmon-ext/netapp-ds424iom12a/change-20260920-120000.json");
