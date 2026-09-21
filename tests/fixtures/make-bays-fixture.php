<?php
// Regenerates tests/fixtures/catan/bays.json (what the bays endpoint returns for the two monitored enclosures of the fake catan).
// Run after changing the bay logic: php tests/fixtures/make-bays-fixture.php
require __DIR__ . '/../lib.php';
require SESEXT_INC . '/sesext_bays.php';
$root = sesext_catan_root();
$netDev = ['type' => 0, 'path' => '/dev/sg34', 'address' => '0x500a098008561d10', 'description' => 'NETAPP DS424IOM12A'];
$virDev = ['type' => 0, 'path' => '/dev/sg8', 'address' => '0x300705b01098e070', 'description' => 'Internal HBA ports'];
foreach (['netapp-ds424iom12a' => [$netDev, 'ses-netapp.json'], 'internal-hba-ports' => [$virDev, 'ses-virtualses.json']] as $folder => [$dev, $file]) {
    $d = "$root/var/lib/sesmon-ext/$folder"; mkdir($d, 0777, true);
    file_put_contents("$d/current.json", json_encode(['device' => $dev, 'raw' => json_decode(file_get_contents(SESEXT_FIXTURES . "/catan/$file"), true)]));
    file_put_contents("$d/current_parsed.json", json_encode(['device' => $dev]));
}
file_put_contents(SESEXT_FIXTURES . '/catan/bays.json', json_encode(sesext_all_bays($root, false), JSON_UNESCAPED_SLASHES));
echo "wrote bays.json\n";
