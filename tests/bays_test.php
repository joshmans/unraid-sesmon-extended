<?php
// Which drive is in which bay: real data from catan (a NetApp DS424 shelf and the HBA's VirtualSES).
require __DIR__ . '/lib.php';
require SESEXT_INC . '/sesext_bays.php';

$netapp = json_decode(file_get_contents(SESEXT_FIXTURES . '/catan/ses-netapp.json'), true);
$virtual = json_decode(file_get_contents(SESEXT_FIXTURES . '/catan/ses-virtualses.json'), true);
const NETAPP = '0x500a098008561d10'; const VIRTUAL = '0x300705b01098e070';

// ---- addresses, sizes, Unraid's ini
eq(sesext_addr_hex(5767432718301666577), '0x500a098008561d11', 'address: an int from sg_ses becomes hex');
eq(sesext_addr_hex('5767432718301666577'), '0x500a098008561d11', 'address: a decimal string does too');
eq(sesext_addr_hex('18446744073709551615'), '0xffffffffffffffff', 'address: a value beyond int64 (a string) converts without a bignum extension');
eq(sesext_addr_hex(0), '', 'address: zero means none'); eq(sesext_addr_hex('0'), '', 'address: "0" means none'); eq(sesext_addr_hex(null), '', 'address: null');
eq(sesext_addr_hex('0X500A098008561D10'), '', 'address: unparsable text is not an address');
eq(sesext_addr_hex('0x500A098008561D10'), '0x500a098008561d10', 'address: hex text is lower cased');
eq(sesext_size_text(14000519643136), '14 TB', 'size: 14 TB'); eq(sesext_size_text(8001563222016), '8 TB', 'size: 8 TB');
eq(sesext_size_text(1024209543168), '1 TB', 'size: 1.02 TB reads 1 TB'); eq(sesext_size_text(512110190592), '512 GB', 'size: 512 GB');
eq(sesext_size_text(4000787030016), '4 TB', 'size: 4 TB'); eq(sesext_size_text(12000138625024), '12 TB', 'size: 12 TB'); eq(sesext_size_text(null), '', 'size: unknown');
$ini = sesext_ini_sections(SESEXT_FIXTURES . '/catan/disks.ini');
eq(count($ini), 35, 'ini: 35 sections'); eq($ini['parity']['device'], 'sda', 'ini: quoted section and quoted value'); eq($ini['disk3']['temp'], '33', 'ini: value');
eq(sesext_ini_sections('/nonexistent'), [], 'ini: missing file');

// ---- the fake server
$root = sesext_catan_root();
$unraid = sesext_unraid_disks($root);
eq($unraid['sdl']['name'], 'disk3', 'unraid: sdl is disk3'); eq($unraid['sdl']['temp_c'], 33, 'unraid: temperature'); eq($unraid['sdl']['role'], 'data', 'unraid: role');
eq($unraid['sda']['role'], 'parity', 'unraid: parity role');
eq([$unraid['sdah']['role'], $unraid['sdah']['temp_c'], $unraid['sdah']['standby']], ['flash', null, false], 'unraid: the USB flash has no temperature and is not spun down (temp * only means "no reading")');
eq(count(array_filter($unraid, fn($d) => $d['standby'])), 0, 'unraid: no disk on catan is in standby right now');
$sleepy = sesext_fixture_root([]); mkdir("$sleepy/var/local/emhttp", 0777, true);
file_put_contents("$sleepy/var/local/emhttp/disks.ini", str_replace(['temp="33"', "spundown=\"0\"\nstatus=\"DISK_OK\"\ntype=\"Data\""], ['temp="*"', "spundown=\"1\"\nstatus=\"DISK_OK\"\ntype=\"Data\""], file_get_contents(SESEXT_FIXTURES . '/catan/disks.ini')));
$sl = sesext_unraid_disks($sleepy); eq(count(array_filter($sl, fn($d) => $d['standby'])) > 0, true, 'unraid: a spun down disk is flagged');
eq($sl['sdl']['temp_c'], null, 'unraid: a spun down disk has no temperature (nothing is read to find one)');
mkdir("$root/dev/disk/by-id", 0777, true);
symlink('../../sdb', "$root/dev/disk/by-id/ata-SPCC_Solid_State_Disk_TESTSERIAL1"); symlink('../../sdb', "$root/dev/disk/by-id/wwn-0x5000000000000001"); symlink('../../sdb1', "$root/dev/disk/by-id/ata-SPCC_Solid_State_Disk_TESTSERIAL1-part1");
eq(sesext_by_id($root)['sdb'], 'SPCC_Solid_State_Disk_TESTSERIAL1', 'by-id: the model and serial name, not the wwn and not a partition');

$disc = sesext_discover($root); $drives = sesext_drives($root, $disc);
eq(count($drives['units']), 34, 'drives: 34 disks (36 nodes minus the two enclosures)');
$twin = $drives['byAddress']['0x5000c500d96689c1'];
eq(array_column($twin, 'name'), ['disk22', 'disk23'], 'drives: one dual-actuator drive is two units under one address');
eq($twin[0]['title'], 'SEAGATE ST14000NM0001', 'drives: vendor and model'); eq($twin[0]['size'], '7 TB', 'drives: each half of the dual-actuator drive is 7 TB');
eq($twin[0]['url'], '/Main/Device?name=disk22', 'drives: Unraid page of the disk'); eq($twin[0]['role'], 'data', 'drives: role');
$ssd = $drives['byAddress']['0x300605b01098e070'][0];
eq([$ssd['name'], $ssd['role'], $ssd['unraid'], $ssd['title'], $ssd['ssd'], $ssd['size']], ['sdb', 'unassigned', false, 'SPCC Solid State', true, '1 TB'], 'drives: an SSD outside the array is named by its device (vendor ATA left out)');
eq([$ssd['ident'], $ssd['url'], $ssd['temp_c']], ['SPCC_Solid_State_Disk_TESTSERIAL1', null, null], 'drives: identified by-id, no Unraid page, no temperature');

// ---- the SES slot data
$slots = sesext_ses_slots($netapp);
eq(count($slots), 24, 'ses: 24 drive slots (the overall entry is skipped)');
eq($slots['1#10']['status'], 3, 'ses: bay 10 is noncritical'); eq($slots['1#10']['phys'][0]['address'], '0x5000c500d96689c1', 'ses: the drive address is the slot\'s own SAS address');
eq($slots['1#10']['phys'][0]['attached'], '0x500a098008561d11', 'ses: the attached address is the shelf\'s own port, not the drive');
eq(sesext_ses_slots($virtual)['23#8']['phys'][0]['address'], '0x500a098008561d11', 'ses: an HBA port without a drive lists the far end of its cable as its address');
eq(sesext_ses_slots(['raw' => $netapp]), $slots, 'ses: sesmon\'s snapshot wraps the same data in "raw"');
eq(count(sesext_ses_slots($virtual)), 48, 'ses: the VirtualSES has 48 array device slots');

// ---- the NetApp shelf
$n = sesext_bays($netapp, NETAPP, ['root' => $root, 'disc' => $disc, 'drives' => $drives]);
eq([$n['kind'], $n['label'], count($n['bays'])], ['shelf', 'NETAPP DS424IOM12A', 24], 'netapp: a shelf with 24 bays');
$b = fn($id) => $n['bays'][$id];
eq([$b('1#0')['label'], $b('1#0')['slot'], array_column($b('1#0')['drives'], 'name')], ['Drive bay 0', 0, ['parity']], 'netapp: bay 0 is parity');
eq(array_column($b('1#2')['drives'], 'name'), ['disk1'], 'netapp: bay 2 is disk1'); eq(array_column($b('1#9')['drives'], 'name'), ['disk8'], 'netapp: bay 9 is disk8');
eq(array_column($b('1#10')['drives'], 'name'), ['disk22', 'disk23'], 'netapp: bay 10 is disk22 and disk23');
eq([$b('1#10')['multi'], $b('1#10')['total_size'], $b('1#2')['total_size']], [true, '14 TB', ''], 'netapp: the physical drive in bay 10 is 14 TB (two 7 TB halves); a single-disk bay has no total');
eq(array_column($b('1#16')['drives'], 'name'), ['disk24', 'disk11'], 'netapp: bay 16 is disk24 and disk11');
$multi = array_keys(array_filter($n['bays'], fn($x) => $x['multi'])); sort($multi);
eq($multi, ['1#10', '1#11', '1#12', '1#13', '1#14', '1#15', '1#16', '1#17'], 'netapp: exactly the eight bays 10 to 17 hold one drive presenting two disks');
$warn = array_keys(array_filter($n['bays'], fn($x) => $x['status'] === 3)); sort($warn);
eq($warn, $multi, 'netapp: they are exactly the bays the shelf reports as noncritical');
eq(array_filter($n['bays'], fn($x) => !$x['drives']) === array_filter($n['bays'], fn($x) => $x['status'] === 5), true, 'netapp: the bays without a drive are the ones reported as not installed');
eq(count(array_filter($n['bays'], fn($x) => $x['drives'])), 18, 'netapp: 18 populated bays, all named');
ok(is_int($b('1#2')['drives'][0]['temp_c']), 'netapp: a drive temperature comes from Unraid');
eq(array_unique(array_column($n['bays'], 'linked_to')), [null], 'netapp: a shelf links nowhere');

// ---- the HBA's virtual enclosure
$v = sesext_bays($virtual, VIRTUAL, ['root' => $root, 'disc' => $disc, 'drives' => $drives]);
eq([$v['kind'], $v['label'], $v['hba']['firmware'], $v['hba']['name'], $v['hba']['chip']], ['hba', 'BROADCOM VirtualSES', '24.00.04.00', '430-16i SAS HBA', 'SAS3416'], 'hba: what it is, and the HBA firmware');
$p = fn($id) => $v['bays'][$id];
eq([$p('23#0')['label'], $p('23#0')['slot'], array_column($p('23#0')['drives'], 'name')], ['Port 12', 12, ['sdb']], 'hba: element 0 is port 12, with the SSD on it');
eq(count(array_filter($v['bays'], fn($x) => $x['drives'])), 7, 'hba: seven ports hold a drive');
eq($p('23#1')['drives'], [], 'hba: port 8 has no drive'); eq($p('23#1')['status'], 5, 'hba: ...and reads not installed');
eq($p('23#8')['linked_to'], 'NETAPP DS424IOM12A', 'hba: port 4 is cabled to the NetApp (the far end of its cable is the shelf\'s port)');
eq(count(array_filter($v['bays'], fn($x) => $x['linked_to'] !== null)), 4, 'hba: four ports are cabled to the shelf');
ok(!$p('23#0')['multi'], 'hba: one drive per port');

// ---- last known: a drive that vanished from sysfs is still named while its bay reports a device
$cache = sesext_bay_cache_update([], $n, 1000);
ok(isset($cache[NETAPP]['1#2']) && $cache[NETAPP]['1#2']['at'] === 1000, 'cache: the drives seen are remembered with the time');
ok(!isset($cache[NETAPP]['1#2']['drives'][0]['temp_c']), 'cache: temperatures are not cached (they change every poll)');
eq(isset($cache[NETAPP]['1#18']), false, 'cache: an empty bay has no entry');
eq(sesext_bay_cache_update($cache, $n, 2000), $cache, 'cache: nothing changed, nothing rewritten (the time stays)');
$root2 = sesext_fixture_root(array_values(array_filter(sesext_catan_lines(), fn($l) => strpos($l, 'sg10|') !== 0))); // disk1 vanishes
mkdir("$root2/var/local/emhttp", 0777, true); copy(SESEXT_FIXTURES . '/catan/disks.ini', "$root2/var/local/emhttp/disks.ini");
$gone = sesext_bays($netapp, NETAPP, ['root' => $root2, 'cache' => $cache]);
eq(array_column($gone['bays']['1#2']['drives'], 'name'), ['disk1'], 'last known: the vanished drive is still named');
eq($gone['bays']['1#2']['last_known'], 1000, 'last known: and marked, with when it was last seen');
$noCache = sesext_bays($netapp, NETAPP, ['root' => $root2]); eq($noCache['bays']['1#2']['drives'], [], 'last known: without a cache the bay simply has no drive');
$emptied = $cache; $slotsEmpty = $netapp;
foreach ($slotsEmpty['join_of_diagnostic_pages']['element_list'] as &$e) { if (($e['element_type']['i'] ?? 0) === 1 && ($e['element_number'] ?? -1) === 2) { $e['status_descriptor']['status'] = ['i' => 5, 'meaning' => 'Not installed']; } } unset($e);
$empty = sesext_bays($slotsEmpty, NETAPP, ['root' => $root2, 'cache' => $cache]);
eq([$empty['bays']['1#2']['drives'], $empty['bays']['1#2']['last_known']], [[], null], 'last known: a bay that reads "not installed" is empty, not blamed on its old drive');
eq(isset(sesext_bay_cache_update($cache, $empty, 3000)[NETAPP]['1#2']), false, 'cache: and the entry is forgotten');

// ---- from sesmon's folders, and the cache file
$dir = "$root/var/lib/sesmon-ext/netapp-ds424iom12a"; mkdir($dir, 0777, true);
$dev = ['type' => 0, 'path' => '/dev/sg34', 'address' => NETAPP, 'description' => 'NETAPP DS424IOM12A'];
file_put_contents("$dir/current.json", json_encode(['device' => $dev, 'captured_at' => '2026-09-20T12:00:00Z', 'raw' => $netapp]));
file_put_contents("$dir/current_parsed.json", json_encode(['device' => $dev, 'captured_at' => '2026-09-20T12:00:00Z', 'raw' => new stdClass]));
mkdir("$root/var/lib/sesmon-ext/incomplete"); // a folder without data is skipped
$all = sesext_all_bays($root);
eq(array_keys($all), ['netapp-ds424iom12a'], 'folders: one enclosure with data');
eq(array_column($all['netapp-ds424iom12a']['bays']['1#10']['drives'], 'name'), ['disk22', 'disk23'], 'folders: bays computed from sesmon\'s snapshot');
eq($all['netapp-ds424iom12a']['path'], '/dev/sg34', 'folders: the device path is carried');
ok(is_file(sesext_bay_cache_path($root)), 'cache: the file is written below /var/lib/sesmon-ext/.cache');
eq(sesext_bay_cache_load($root)[NETAPP]['1#10']['drives'][1]['name'], 'disk23', 'cache: it can be read back');
eq(array_keys(sesext_all_bays($root, false)), ['netapp-ds424iom12a'], 'folders: the .cache directory is not mistaken for an enclosure');

// ---- alert text
$report = fn($changes) => json_encode(['device' => $dev, 'detected_at' => 'now', 'changes' => $changes]);
$ch = fn($id, $type, $num) => ['id' => $id, 'element_type' => $type, 'element_type_number' => $num];
eq(sesext_alert_context(NETAPP, '/dev/sg34', $report([$ch('1#10', 1, 10)]), $root), 'Affected drives - drive bay 10: disk22 + disk23 (SEAGATE ST14000NM0001, one drive presenting 2 disks)', 'alert: a dual-actuator bay names both disks');
eq(sesext_alert_context(NETAPP, '', $report([$ch('1#2', 1, 2)]), $root), 'Affected drives - drive bay 2: disk1 (HGST HUH728080AL4200)', 'alert: a single drive, found by address alone');
eq(sesext_alert_context('', '/dev/sg34', $report([$ch('1#0', 1, 0)]), $root), 'Affected drives - drive bay 0: parity (HITACHI H0H72108CLAR8000)', 'alert: found by device path alone');
eq(sesext_alert_context(NETAPP, '/dev/sg34', $report([$ch('1#2', 1, 2), $ch('1#3', 1, 3), $ch('3#2', 3, 2)]), $root), 'Affected drives - drive bay 2: disk1 (HGST HUH728080AL4200); drive bay 3: disk2 (HGST HUH728080AL4200)', 'alert: several bays, and a fan in the same report adds nothing');
eq(sesext_alert_context(NETAPP, '/dev/sg34', $report([$ch('3#2', 3, 2), $ch('4#5', 4, 5)]), $root), '', 'alert: a report without a bay has no drive line');
eq(sesext_alert_context('0x1', '/dev/sg99', $report([$ch('1#2', 1, 2)]), $root), '', 'alert: an enclosure that is not monitored');
eq(sesext_alert_context(NETAPP, '', 'not json', $root), '', 'alert: garbage instead of a report'); eq(sesext_alert_context(NETAPP, '', null, $root), '', 'alert: no report (a back-off notification has none)');
eq(sesext_alert_context(NETAPP, '', $report([$ch('1#18', 1, 18)]), $root), 'Affected drives - drive bay 18: no drive known', 'alert: an empty bay says so');
sesext_bay_cache_save($cache, $root2);
$dir2 = "$root2/var/lib/sesmon-ext/netapp-ds424iom12a"; mkdir($dir2, 0777, true);
file_put_contents("$dir2/current.json", json_encode(['device' => $dev, 'raw' => $netapp])); file_put_contents("$dir2/current_parsed.json", json_encode(['device' => $dev]));
eq(sesext_alert_context(NETAPP, '', $report([$ch('1#2', 1, 2)]), $root2), 'Affected drives - drive bay 2: disk1 (HGST HUH728080AL4200) [last known]', 'alert: the failed drive is still named, marked as last known');

sesext_test_done();
