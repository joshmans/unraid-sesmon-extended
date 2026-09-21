/* Part of sesmon-ext (fork of sesmon-unRAID by desertwitch). GPL 2.
 *
 * Turns the raw SES elements that sesmon reports ("1#10", status 3, prdfail 0, ...) into what a person
 * wants to read: names, plain status words with an explanation, readings with units, and notes for the
 * flags that are actually set. Pure functions, no DOM: the page renders the results, the tests (Node)
 * check them against real enclosure data.
 */
(function (root) {
    'use strict';

    // SES element type -> names and the order the groups are shown in
    var TYPES = {
        1:  {one: 'Drive bay',            many: 'Drive bays',            order: 1},
        2:  {one: 'Power supply',         many: 'Power supplies',        order: 2},
        3:  {one: 'Cooling fan',          many: 'Cooling',               order: 3},
        4:  {one: 'Temperature sensor',   many: 'Temperatures',          order: 4},
        18: {one: 'Voltage sensor',       many: 'Voltages',              order: 5},
        19: {one: 'Current sensor',       many: 'Currents',              order: 6},
        25: {one: 'SAS connector',        many: 'SAS connectors',        order: 7},
        7:  {one: 'Enclosure controller', many: 'Enclosure controllers', order: 8},
        14: {one: 'Enclosure',            many: 'Enclosure',             order: 9},
        12: {one: 'Display',              many: 'Displays',              order: 10}
    };

    // the SES status codes; 'bucket' is what the header counts them as
    var STATUS = {
        0: {label: 'Not reported',   cls: 'sesext-status-unsupported',   bucket: 'other',
            tip: 'The enclosure does not report a status for this element.'},
        1: {label: 'OK',             cls: 'sesext-status-ok',            bucket: 'ok',
            tip: 'The enclosure reports this component as working normally.'},
        2: {label: 'Critical',       cls: 'sesext-status-critical',      bucket: 'critical',
            tip: 'The enclosure reports a fault in this component.'},
        3: {label: 'Warning',        cls: 'sesext-status-noncritical',   bucket: 'warn',
            tip: 'SES "non-critical": the enclosure reports a condition that needs attention but is not a failure. It does not say which condition; check the enclosure\'s own LEDs or management interface.'},
        4: {label: 'Failed',         cls: 'sesext-status-unrecoverable', bucket: 'failed',
            tip: 'SES "unrecoverable": the component has failed and cannot recover by itself.'},
        5: {label: 'Not installed',  cls: 'sesext-status-not-installed', bucket: 'empty',
            tip: 'Nothing is present in this position.'},
        6: {label: 'Unknown',        cls: 'sesext-status-unknown',       bucket: 'other',
            tip: 'The enclosure cannot tell the state of this component.'},
        7: {label: 'Not available',  cls: 'sesext-status-not-available', bucket: 'other',
            tip: 'The enclosure cannot report on this component right now.'},
        8: {label: 'No access',      cls: 'sesext-status-not-available', bucket: 'other',
            tip: 'The enclosure does not allow reading this component.'}
    };
    var BUCKET_LABEL = {critical: 'Critical', failed: 'Failed', warn: 'Warning', ok: 'OK', empty: 'Not installed', other: 'Not reported'};
    var BUCKET_CLASS = {critical: 'sesext-status-critical', failed: 'sesext-status-unrecoverable', warn: 'sesext-status-noncritical',
                        ok: 'sesext-status-ok', empty: 'sesext-status-not-installed', other: 'sesext-status-other'};
    var BUCKET_ORDER = ['critical', 'failed', 'warn', 'ok', 'empty', 'other'];

    var FLAGS = [
        ['prdfail',  'Failure predicted', 'The component predicts that it will fail soon.'],
        ['disabled', 'Disabled',          'The component has been disabled by the enclosure or a management client.'],
        ['swap',     'Swapped',           'The enclosure reports that this component was swapped (removed or inserted).']
    ];

    function typeInfo(type, desc) {
        return TYPES[type] || {one: desc || ('Element type ' + type), many: desc || ('Element type ' + type), order: 100 + Number(type || 0)};
    }

    /** The status as words. 'Not installed' reads differently for a drive bay and for a SAS connector. */
    function statusInfo(status, statusDesc, type) {
        var s = Number(status);
        var base = STATUS[s];
        if (!base) { return {label: statusDesc || 'Unknown', cls: '', bucket: 'other', tip: 'SES status ' + status + '.'}; }
        var label = base.label;
        if (s === 5 && Number(type) === 1) { label = 'Empty'; }
        if (s === 5 && Number(type) === 25) { label = 'Not connected'; }
        return {label: label, cls: base.cls, bucket: base.bucket, tip: base.tip + ' (SES status ' + s + ', "' + (statusDesc || base.label) + '")'};
    }

    /**
     * "42 C" -> "42 °C", or "108 °F" when unit is 'F' (whole degrees: SES reports whole degrees Celsius, so a
     * decimal in Fahrenheit would be precision that is not there). Anything that is not "<number> C" is shown
     * as reported.
     */
    function temperature(text, unit) {
        var m = /^\s*(-?\d+(?:\.\d+)?)\s*C\s*$/.exec(String(text));
        if (!m) { return String(text); }
        if (unit === 'F') { return Math.round(parseFloat(m[1]) * 9 / 5 + 32) + ' °F'; }
        return m[1] + ' °C';
    }

    /** "42 C" -> "42 °C" (or °F), 12.18 -> "12.18 V", 3.43 -> "3.43 A"; '' when the element has no reading. */
    function reading(el, unit) {
        if (!el) { return ''; }
        if (el.temperature) { return temperature(el.temperature, unit); }
        if (el.voltage) { return el.voltage + ' V'; }
        if (el.amperage) { return el.amperage + ' A'; }
        return '';
    }

    /** The flags that are set, as [{label, tip}]; empty when none is. */
    function flags(el) {
        var out = [];
        FLAGS.forEach(function (f) { if (el && Number(el[f[0]]) === 1) { out.push({label: f[1], tip: f[2]}); } });
        return out;
    }

    /** "Drive bay 10", "Cooling fan 3"; the overall entry of a type reads "Power supplies (overall)". */
    function componentName(type, number, desc) {
        var t = typeInfo(type, desc);
        return Number(number) === -1 ? t.many + ' (overall)' : t.one + ' ' + number;
    }

    /** The per-type "overall" entries (number -1) are usually reported as unsupported: noise, not information. */
    function isHiddenOverall(el) {
        return !!el && Number(el.element_type_number) === -1 && Number(el.status) === 0;
    }

    function parseId(id) {
        var p = String(id).split('#');
        return [parseInt(p[0], 10), parseInt(p[1], 10)];
    }

    /**
     * Group the elements ({"1#10": {...}}) by type, in reading order.
     * Readings are in the given temperature unit ('C' by default, or 'F').
     * Returns {groups: [{type, title, singular, items: [{id, el, name, status, reading, flags}], counts}], hidden: n, counts}
     * where counts maps a bucket (ok, warn, ...) to how many visible elements are in it.
     */
    function group(raw, unit) {
        var byType = {}, hidden = 0, total = {};
        Object.keys(raw || {}).forEach(function (id) {
            var el = raw[id];
            if (!el || el.status === undefined || el.status === null) { return; }
            if (isHiddenOverall(el)) { hidden++; return; }
            var ids = parseId(id);
            var type = el.element_type !== undefined ? el.element_type : ids[0];
            var number = el.element_type_number !== undefined ? el.element_type_number : ids[1];
            var st = statusInfo(el.status, el.status_desc, type);
            var g = byType[type] || (byType[type] = {type: Number(type), title: typeInfo(type, el.element_type_desc).many, items: [], counts: {}});
            g.items.push({id: id, el: el, number: Number(number), name: componentName(type, number, el.element_type_desc),
                          status: st, reading: reading(el, unit), flags: flags(el)});
            g.counts[st.bucket] = (g.counts[st.bucket] || 0) + 1;
            total[st.bucket] = (total[st.bucket] || 0) + 1;
        });
        var groups = Object.keys(byType).map(function (k) { return byType[k]; });
        groups.forEach(function (g) { g.items.sort(function (a, b) { return a.number - b.number; }); });
        groups.sort(function (a, b) { return typeInfo(a.type).order - typeInfo(b.type).order || a.type - b.type; });
        return {groups: groups, hidden: hidden, counts: total};
    }

    /** "8 Warning | 60 OK | 21 Not installed" as [{bucket, n, label, cls}] in severity order. */
    function summarize(counts) {
        var out = [];
        BUCKET_ORDER.forEach(function (b) { if (counts[b] > 0) { out.push({bucket: b, n: counts[b], label: BUCKET_LABEL[b], cls: BUCKET_CLASS[b]}); } });
        return out;
    }

    /** One change of an alert (before/after), described for people. */
    function describeChange(change, unit) {
        var before = change.before || null, after = change.after || null;
        var type = change.element_type, number = change.element_type_number;
        var b = before ? reading(before, unit) : '', a = after ? reading(after, unit) : '';
        return {
            name: componentName(type, number, change.element_type_desc),
            before: before ? statusInfo(before.status, before.status_desc, type) : null,
            after: after ? statusInfo(after.status, after.status_desc, type) : null,
            reading: b && a && b !== a ? b + ' → ' + a : (a || b),
            flags: after ? flags(after) : [],
            flagsBefore: before ? flags(before) : []
        };
    }

    var api = {typeInfo: typeInfo, statusInfo: statusInfo, temperature: temperature, reading: reading, flags: flags, componentName: componentName,
               isHiddenOverall: isHiddenOverall, group: group, summarize: summarize, describeChange: describeChange};
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; } else { root.SesextFriendly = api; }
})(typeof window !== 'undefined' ? window : this);
