<?php
require __DIR__ . '/lib.php';
require SESEXT_INC . '/sesext_discover.php';

// ---- the real catan data
$root = sesext_fixture_root(sesext_catan_lines());
$d = sesext_discover($root);
eq(count($d['nodes']), 36, 'catan: 36 sg nodes');
eq(count($d['enclosures']), 2, 'catan: two enclosures found, no drives');
eq(array_column($d['enclosures'], 'dev'), ['/dev/sg8', '/dev/sg34'], 'catan: enclosures sorted by sg number');
$netapp = $d['enclosures'][1];
eq($netapp['address'], '0x500a098008561d10', 'catan: NetApp address');
eq($netapp['label'], 'NETAPP DS424IOM12A', 'catan: vendor and model are trimmed and joined');
eq($netapp['use'], ['address', '0x500a098008561d10'], 'catan: unique address is used as the identifier');
eq($d['enclosures'][0]['label'], 'BROADCOM VirtualSES', 'catan: virtual SES enclosure');
eq(count($d['duplicates']), 8, 'catan: eight shared drive addresses (the warnings in the syslog)');
ok(!isset($d['duplicates']['0x500a098008561d10']), 'catan: enclosure addresses are not among the duplicates');
eq($d['nodes']['/dev/sg24']['type'], 0, 'catan: drives are type 0');

// ---- statuses against the real data
$st = fn($dev) => sesext_device_status($dev, $d);
eq($st(['enabled' => true, 'address' => '0x500a098008561d10'])[1], 'ok', 'status: real address resolves');
eq($st(['enabled' => true, 'address' => '0x500a098008561d10'])[3], '/dev/sg34', 'status: resolved device path');
eq($st(['enabled' => true, 'address' => '0x500a098012345678'])[1], 'unresolvable', 'status: the example address from the default config');
eq($st(['enabled' => true, 'address' => '0x500a098012345678'])[0], 'error', 'status: unresolvable is an error');
eq($st(['enabled' => false, 'address' => '0x500a098012345678'])[0], 'off', 'status: disabled devices are not judged');
eq($st(['enabled' => true, 'address' => '0X500A098008561D10'])[1], 'case', 'status: upper case address is flagged (the daemon compares exactly)');
eq($st(['enabled' => true, 'address' => '0x5000c500d963b32d'])[1], 'ambiguous', 'status: a shared address cannot be looked up');
eq($st(['enabled' => true, 'address' => '0x5000c500d963b32d', 'device' => '/dev/sg24'])[1], 'ambiguous-fallback', 'status: shared address with a path falls back to the path');
eq($st(['enabled' => true, 'address' => '0x500a098012345678', 'device' => '/dev/sg34'])[1], 'address-missing-fallback', 'status: missing address with a valid path falls back');
eq($st(['enabled' => true, 'device' => '/dev/sg34'])[1], 'ok-path', 'status: path only');
eq($st(['enabled' => true, 'device' => '/dev/sg99'])[1], 'path-missing', 'status: missing path');
$uniqueDrive = null;
foreach ($d['nodes'] as $n) { if ($n['type'] === 0 && $n['address'] !== null && !isset($d['duplicates'][$n['address']])) { $uniqueDrive = $n; break; } }
ok($uniqueDrive !== null, 'catan: has a drive with an address of its own');
eq($st(['enabled' => true, 'address' => $uniqueDrive['address']])[1], 'not-enclosure', 'status: an address that belongs to a drive is flagged');
eq($st(['enabled' => true, 'address' => $uniqueDrive['address']])[0], 'warn', 'status: ...as a warning');
ok(!isset($d['nodes']['/dev/sg35']['address']) || $d['nodes']['/dev/sg35']['address'] === null, 'catan: the USB stick has no SAS address');

// ---- JSON test files
eq(sesext_device_status(['enabled' => true, 'type' => 1, 'device' => '/tmp/x.json'], $d, fn($p) => true)[1], 'testfile', 'json file present');
eq(sesext_device_status(['enabled' => true, 'type' => 1, 'device' => '/tmp/x.json'], $d, fn($p) => false)[1], 'testfile-missing', 'json file missing is a warning');
eq(sesext_device_status(['enabled' => true, 'type' => 0], $d)[1], 'no-target', 'neither address nor path');

// ---- synthetic: a dual-pathed enclosure and one without a SAS address
$lines = sesext_catan_lines();
array_push($lines,
    'sg40|type|13', 'sg40|vendor|ACME', 'sg40|model|Dual Path JBOD', 'sg40|rev|1', 'sg40|sas_address|0x5000000000000abc',
    'sg41|type|13', 'sg41|vendor|ACME', 'sg41|model|Dual Path JBOD', 'sg41|rev|1', 'sg41|sas_address|0x5000000000000ABC',
    'sg42|type|13', 'sg42|vendor|SATA', 'sg42|model|Plain Enclosure', 'sg42|rev|2');
$d2 = sesext_discover(sesext_fixture_root($lines));
eq(count($d2['enclosures']), 5, 'synthetic: five enclosures');
$dual = array_values(array_filter($d2['enclosures'], fn($e) => $e['address'] === '0x5000000000000abc'));
eq(count($dual), 2, 'synthetic: both paths of the dual-pathed enclosure are listed');
eq($dual[0]['unique'], false, 'synthetic: dual-pathed enclosure is not unique');
eq($dual[0]['use'], ['device', '/dev/sg40'], 'synthetic: offered by device path instead of address');
eq($d2['duplicates']['0x5000000000000abc'], ['/dev/sg40', '/dev/sg41'], 'synthetic: addresses are compared case-insensitively');
$plain = array_values(array_filter($d2['enclosures'], fn($e) => $e['dev'] === '/dev/sg42'))[0];
eq($plain['address'], null, 'synthetic: enclosure without a SAS address');
eq($plain['use'], ['device', '/dev/sg42'], 'synthetic: ...is offered by device path');
eq(sesext_device_status(['enabled' => true, 'device' => '/dev/sg42'], $d2)[1], 'ok-path', 'synthetic: path-only device resolves');
eq(sesext_device_status(['enabled' => true, 'address' => '0x5000000000000abc'], $d2)[1], 'ambiguous', 'synthetic: dual-pathed address is ambiguous');

// ---- dashboard tile names: the HBA's virtual enclosure is named after the HBA
$hbaDev = ['path' => '/dev/sg8', 'address' => '0x300705b01098e070'];
$shelfDev = ['path' => '/dev/sg34', 'address' => '0x500a098008561d10'];
eq($d['enclosures'][0]['title'], '430-16i SAS HBA (SAS3416)', 'title: the HBA model (board and chip) names the virtual enclosure');
eq($d['enclosures'][1]['title'], '', 'title: a shelf has none');
eq(sesext_tile_name('BROADCOM VirtualSES', 'jbod', $hbaDev, $d), '430-16i SAS HBA (SAS3416)', 'tile: the VirtualSES label is replaced by the HBA model');
eq(sesext_tile_name('Internal HBA ports', 'jbod', $hbaDev, $d), '430-16i SAS HBA (SAS3416)', 'tile: so is the generated description');
eq(sesext_tile_name('', 'jbod', $hbaDev, $d), '430-16i SAS HBA (SAS3416)', 'tile: and an empty description');
eq(sesext_tile_name('Rack HBA', 'jbod', $hbaDev, $d), 'Rack HBA', 'tile: a description someone typed is kept');
eq(sesext_tile_name('NETAPP DS424IOM12A', 'jbod', $shelfDev, $d), 'NETAPP DS424IOM12A', 'tile: a shelf keeps its description');
eq(sesext_tile_name('', 'jbod', $shelfDev, $d), 'jbod', 'tile: a shelf without a description is named by its folder');
eq(sesext_tile_name('BROADCOM VirtualSES', 'jbod', ['path' => '/dev/sg99', 'address' => '0x1'], $d), 'BROADCOM VirtualSES', 'tile: an enclosure that is gone keeps what it had');
eq(sesext_hba_title(['name' => '430-16i SAS HBA SAS3416', 'chip' => 'SAS3416']), '430-16i SAS HBA SAS3416', 'title: the chip is not repeated');
eq(sesext_hba_title(['name' => '', 'chip' => 'SAS3416']), 'SAS3416', 'title: chip only');
eq(sesext_hba_title(null), '', 'title: no HBA information');

// ---- an empty server
$d3 = sesext_discover(sesext_fixture_root([]));
eq($d3['enclosures'], [], 'empty: no enclosures');
eq(sesext_device_status(['enabled' => true, 'address' => '0x1'], $d3)[1], 'unresolvable', 'empty: nothing resolves');

sesext_test_done();
