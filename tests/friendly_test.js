// Tests of js/sesext_friendly.js against real data from a NetApp DS424IOM12A (run by run.sh when node is available).
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const F = require('../source/usr/local/emhttp/plugins/sesmon-ext/js/sesext_friendly.js');
const snap = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/catan/current_parsed.json'), 'utf8'));

let pass = 0, fail = 0;
function eq(actual, expected, name) {
    try { assert.deepStrictEqual(actual, expected); pass++; }
    catch (e) { fail++; console.log('  FAIL: ' + name + '\n    expected: ' + JSON.stringify(expected) + '\n    actual:   ' + JSON.stringify(actual)); }
}

// ---- names
eq(F.componentName(1, 10, 'Device slot'), 'Drive bay 10', 'name: drive bay');
eq(F.componentName(3, 3, 'Cooling'), 'Cooling fan 3', 'name: fan');
eq(F.componentName(2, 1, 'Power supply'), 'Power supply 1', 'name: PSU');
eq(F.componentName(2, -1, 'Power supply'), 'Power supplies (overall)', 'name: overall entry');
eq(F.componentName(131, 1, 'Vendor specific [0x83]'), 'Vendor specific [0x83] 1', 'name: unknown type falls back to its own description');

// ---- statuses
eq(F.statusInfo(1, 'OK', 1).label, 'OK', 'status 1');
eq(F.statusInfo(3, 'Noncritical', 1).label, 'Warning', 'status 3 is a warning');
eq(F.statusInfo(3, 'Noncritical', 1).bucket, 'warn', 'status 3 bucket');
eq(F.statusInfo(4, 'Unrecoverable', 2).label, 'Failed', 'status 4 is a failure');
eq(F.statusInfo(5, 'Not installed', 1).label, 'Empty', 'not installed reads Empty for a bay');
eq(F.statusInfo(5, 'Not installed', 25).label, 'Not connected', 'not installed reads Not connected for a SAS connector');
eq(F.statusInfo(5, 'Not installed', 2).label, 'Not installed', 'not installed elsewhere');
eq(F.statusInfo(0, 'Unsupported', 4).label, 'Not reported', 'status 0');
eq(F.statusInfo(99, 'Weird', 4).label, 'Weird', 'an unknown status code keeps the daemon\'s word');
eq(F.statusInfo(99, 'Weird', 4).bucket, 'other', 'an unknown status code is "other"');
eq(F.statusInfo(3, 'Noncritical', 1).tip.includes('does not say which condition'), true, 'warning tooltip is honest about not knowing the cause');
eq(F.statusInfo(3, 'Noncritical', 1).tip.includes('SES status 3, "Noncritical"'), true, 'tooltip keeps the raw status for reference');

// ---- readings and flags
eq(F.reading({temperature: '42 C'}), '42 °C', 'reading: temperature');
eq(F.reading({temperature: '-20 C'}), '-20 °C', 'reading: negative temperature');
eq(F.reading({voltage: '12.18'}), '12.18 V', 'reading: voltage');
eq(F.reading({amperage: '3.43'}), '3.43 A', 'reading: current');
eq(F.reading({status: 1}), '', 'reading: none');
eq(F.flags({prdfail: 0, disabled: 0, swap: 0}), [], 'flags: none set');
eq(F.flags({prdfail: 1, disabled: 0, swap: 1}).map(f => f.label), ['Failure predicted', 'Swapped'], 'flags: only the set ones');

// ---- temperature units
eq(F.temperature('42 C'), '42 °C', 'unit: Celsius by default');
eq(F.temperature('42 C', 'C'), '42 °C', 'unit: Celsius');
eq(F.temperature('42 C', 'F'), '108 °F', 'unit: 42 C is 107.6 F, rounded to 108');
eq(F.temperature('0 C', 'F'), '32 °F', 'unit: freezing point');
eq(F.temperature('100 C', 'F'), '212 °F', 'unit: boiling point');
eq(F.temperature('-20 C', 'F'), '-4 °F', 'unit: negative values');
eq(F.temperature('-40 C', 'F'), '-40 °F', 'unit: -40 is the same in both');
eq(F.temperature('41 C', 'F'), '106 °F', 'unit: 41 C is 105.8 F, rounded to 106');
eq(F.temperature('36.6 C', 'F'), '98 °F', 'unit: a decimal Celsius value converts (97.88 rounds to 98)');
eq(F.temperature('42 C', 'K'), '42 °C', 'unit: an unknown unit falls back to Celsius');
eq(F.temperature('hot', 'F'), 'hot', 'unit: text that is not a temperature is shown as reported');
eq(F.reading({temperature: '47 C'}, 'F'), '117 °F', 'unit: reading in Fahrenheit');
eq(F.reading({voltage: '12.18'}, 'F'), '12.18 V', 'unit: voltage is unaffected');
eq(F.reading({amperage: '3.43'}, 'F'), '3.43 A', 'unit: current is unaffected');

// ---- the real enclosure
const g = F.group(snap.raw);
eq(g.hidden, 15, 'real data: the unsupported overall entries of all 15 types are hidden');
eq(g.groups.map(x => x.title), ['Drive bays', 'Power supplies', 'Cooling', 'Temperatures', 'Voltages', 'Currents', 'SAS connectors', 'Enclosure controllers', 'Enclosure', 'Displays', 'Vendor specific [0x83]', 'Vendor specific [0x85]', 'Vendor specific [0x8c]', 'Vendor specific [0x8d]', 'Vendor specific [0x8e]'], 'real data: groups in reading order, vendor specific last');
const bays = g.groups[0];
eq(bays.items.length, 24, 'real data: 24 drive bays (overall entry hidden)');
eq(bays.items.map(i => i.number).slice(0, 3), [0, 1, 2], 'real data: bays sorted numerically');
eq(bays.items[10].number, 10, 'real data: sorted numerically, not as text (10 after 9)');
eq(bays.counts, {ok: 10, warn: 8, empty: 6}, 'real data: bay counts (10 OK, 8 warning, 6 empty)');
eq(bays.items.filter(i => i.status.bucket === 'warn').map(i => i.number), [10, 11, 12, 13, 14, 15, 16, 17], 'real data: the warning bays are 10 to 17');
eq(bays.items[18].status.label, 'Empty', 'real data: bay 18 is empty');
eq(bays.items.every(i => i.flags.length === 0), true, 'real data: no flags set anywhere');
eq(g.counts, {ok: 60, warn: 8, empty: 21}, 'real data: header counts without the hidden entries');
eq(F.summarize(g.counts).map(s => s.n + ' ' + s.label), ['8 Warning', '60 OK', '21 Not installed'], 'real data: header summary');
const temps = g.groups.find(x => x.title === 'Temperatures');
eq(temps.items.length, 12, 'real data: 12 temperature sensors');
eq(temps.items.map(i => i.reading).slice(0, 3), ['20 \u00b0C', '31 \u00b0C', '29 \u00b0C'], 'real data: temperature readings with units, in sensor order (values as captured in the fixture)');
eq(temps.items[5].reading, '47 °C', 'real data: sensor 5 is 47 °C');
eq(g.groups.find(x => x.title === 'Voltages').items[1].reading, '12.18 V', 'real data: voltage reading');
eq(g.groups.find(x => x.title === 'Currents').items[0].reading, '3.43 A', 'real data: current reading');
eq(g.groups.find(x => x.title === 'SAS connectors').counts, {ok: 1, empty: 7}, 'real data: one connector in use, seven not connected');
eq(g.groups.find(x => x.title === 'SAS connectors').items.filter(i => i.status.label === 'Not connected').length, 7, 'real data: connectors read Not connected');
eq(g.groups.find(x => x.title === 'Power supplies').items.length, 4, 'real data: 4 power supplies');

const gF = F.group(snap.raw, 'F');
eq(gF.groups.find(x => x.title === 'Temperatures').items.map(i => i.reading).slice(0, 3), ['68 °F', '88 °F', '84 °F'], 'real data in Fahrenheit: 20, 31 and 29 C');
eq(gF.groups.find(x => x.title === 'Temperatures').items[5].reading, '117 °F', 'real data in Fahrenheit: 47 C is 117 F');
eq(gF.groups.find(x => x.title === 'Voltages').items[1].reading, '12.18 V', 'real data in Fahrenheit: voltages unchanged');
eq(gF.counts, g.counts, 'the unit does not change any counts');

// ---- robustness
eq(F.group({}), {groups: [], hidden: 0, counts: {}}, 'empty input');
eq(F.group(null), {groups: [], hidden: 0, counts: {}}, 'null input');
eq(F.group({'1#0': null, '1#1': {element_type: 1, element_type_number: 1}}).groups, [], 'entries without a status are skipped');
const odd = F.group({'77#2': {element_type: 77, element_type_number: 2, element_type_desc: 'Mystery', status: 1, status_desc: 'OK'}});
eq(odd.groups[0].title, 'Mystery', 'unknown type: group named after the daemon\'s description');
eq(odd.groups[0].items[0].name, 'Mystery 2', 'unknown type: item name');
eq(F.group({'1#-1': {element_type: 1, element_type_number: -1, status: 2, status_desc: 'Critical'}}).hidden, 0, 'an overall entry that reports a real status is shown, not hidden');

// ---- alert changes
const ch = F.describeChange({id: '3#2', element_type: 3, element_type_number: 2, element_type_desc: 'Cooling',
    before: {status: 1, status_desc: 'OK', prdfail: 0, disabled: 0, swap: 0}, after: {status: 2, status_desc: 'Critical', prdfail: 1, disabled: 0, swap: 0}});
eq(ch.name, 'Cooling fan 2', 'change: component name');
eq([ch.before.label, ch.after.label], ['OK', 'Critical'], 'change: before and after in words');
eq(ch.flags.map(f => f.label), ['Failure predicted'], 'change: flag that appeared');
const ch2 = F.describeChange({id: '4#5', element_type: 4, element_type_number: 5, element_type_desc: 'Temperature sensor',
    before: {status: 1, status_desc: 'OK', temperature: '41 C'}, after: {status: 3, status_desc: 'Noncritical', temperature: '58 C'}});
eq(ch2.reading, '41 °C → 58 °C', 'change: reading before and after');
const ch2f = F.describeChange({id: '4#5', element_type: 4, element_type_number: 5, element_type_desc: 'Temperature sensor',
    before: {status: 1, status_desc: 'OK', temperature: '41 C'}, after: {status: 3, status_desc: 'Noncritical', temperature: '58 C'}}, 'F');
eq(ch2f.reading, '106 °F → 136 °F', 'change: reading before and after in Fahrenheit');
const ch3 = F.describeChange({id: '1#3', element_type: 1, element_type_number: 3, element_type_desc: 'Device slot', after: {status: 1, status_desc: 'OK'}});
eq(ch3.before, null, 'change: an element that appeared has no before');
eq(ch3.after.label, 'OK', 'change: ...and its after');

console.log('friendly_test.js: ' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
