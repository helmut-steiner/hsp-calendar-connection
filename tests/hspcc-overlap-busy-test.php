<?php
if (!function_exists('__')) { function __($text, $domain = '') { return $text; } }
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Offline synthetic projections only. Real bundled VObject; no WP or network.
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    public $code;
    public $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
}
$ameliaPath = getenv('HSPCC_AMELIA_PATH');
if ($ameliaPath === false || $ameliaPath === '') {
    fwrite(STDOUT, "SKIP: overlap fixtures need an installed Amelia plugin; set HSPCC_AMELIA_PATH to its root. No paid dependency is bundled.\n");
    exit(0);
}
$ameliaAutoload = rtrim($ameliaPath, '/\\') . '/vendor/autoload.php';
if (!is_file($ameliaAutoload) || !is_readable($ameliaAutoload)) {
    fwrite(STDERR, "FAIL: HSPCC_AMELIA_PATH does not contain a readable vendor/autoload.php.\n");
    exit(1);
}
if (!class_exists('DOMDocument') || !extension_loaded('mbstring')) {
    fwrite(STDERR, "FAIL: overlap fixtures require DOM and mbstring.\n");
    exit(1);
}
$previousReporting = error_reporting(E_ALL & ~E_DEPRECATED); // Installed vendor autoload files may emit unrelated PHP 8.4 deprecations.
require $ameliaAutoload;
error_reporting($previousReporting);
if (!class_exists('AmeliaVendor\\Sabre\\VObject\\Reader')) {
    fwrite(STDERR, "FAIL: supplied Amelia installation does not provide its prefixed VObject dependency.\n");
    exit(1);
}
require dirname(__DIR__) . '/includes/class-hspcc-overlap-busy.php';
require dirname(__DIR__) . '/includes/class-hspcc-free-busy.php';
$checks = 0;
$prefix = 'hsp-amelia-fbe4328a-4f04-4299-8431-01a18de408e2-';
function check($condition, $label) {
    global $checks;
    ++$checks;
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL); exit(1); }
}
function ts($value) { return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp(); }
function event($uid, $start = 'DTSTART:20261030T090000Z', $end = 'DTEND:20261030T100000Z', $extra = '') {
    return "BEGIN:VEVENT\r\nUID:" . $uid . "\r\nDTSTAMP:20261001T000000Z\r\n" . $start . "\r\n" .
        ($end === '' ? '' : $end . "\r\n") . $extra . "END:VEVENT\r\n";
}
function calendar($events, $zones = '') {
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Synthetic projection//EN\r\n" . $zones . $events . "END:VCALENDAR\r\n";
}
function dav_response($calendar, $status = 'HTTP/1.1 200 OK', $more = '') {
    return '<d:response><d:href>/synthetic/private-event.ics</d:href><d:propstat><d:prop><c:calendar-data>' .
        htmlspecialchars($calendar, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</c:calendar-data></d:prop><d:status>' .
        $status . '</d:status></d:propstat>' . $more . '</d:response>';
}
function multi($responses = '') {
    return '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' . $responses . '</d:multistatus>';
}
function generate($xml, $zone = 'Europe/Vienna', $start = '2026-10-30 00:00:00', $end = '2026-10-31 00:00:00') {
    global $prefix;
    return HSPCC_Overlap_Busy::freebusy($xml, ts($start), ts($end), $prefix, $zone);
}
function periods($xml, $zone = 'Europe/Vienna', $start = '2026-10-30 00:00:00', $end = '2026-10-31 00:00:00') {
    $body = generate($xml, $zone, $start, $end);
    check(is_string($body), 'projection produces serialized freebusy');
    $result = HSPCC_Free_Busy::parse($body, ts($start), ts($end));
    check(is_array($result), 'generated output passes real strict freebusy parser');
    return $result;
}
function bad($xml, $label, $zone = 'Europe/Vienna') {
    ob_start();
    $result = generate($xml, $zone);
    $output = ob_get_clean();
    check($result instanceof WP_Error && $result->code === 'hspcc_overlap_invalid' &&
        $result->message === 'Calendar busy-time response could not be validated.' && $output === '', $label);
}

check(class_exists('AmeliaVendor\\Sabre\\VObject\\FreeBusyGenerator'), 'actual bundled VObject loaded');
check(periods(multi()) === [], 'empty multistatus is empty busy set');
$basic = multi(dav_response(calendar(event('manual-one'))));
check(periods($basic) === [[ts('2026-10-30 09:00'), ts('2026-10-30 10:00')]], 'manual UTC event retained');
check(periods($basic, '') === [[ts('2026-10-30 09:00'), ts('2026-10-30 10:00')]], 'UTC event needs no default timezone');
check(periods(multi(dav_response(calendar(event('floating', 'DTSTART:20261030T100000', 'DTEND:20261030T110000'))))) ===
    [[ts('2026-10-30 09:00'), ts('2026-10-30 10:00')]], 'floating time uses actual collection timezone');
check(periods(multi(dav_response(calendar(event('explicit-zone', 'DTSTART;TZID=Europe/Vienna:20261030T100000', 'DTEND;TZID=Europe/Vienna:20261030T110000')))), '') ===
    [[ts('2026-10-30 09:00'), ts('2026-10-30 10:00')]], 'resolvable explicit TZID works without floating default');
$spring = multi(dav_response(calendar(event('all-day-spring', 'DTSTART;VALUE=DATE:20260329', 'DTEND;VALUE=DATE:20260330'))));
check(periods($spring, 'Europe/Vienna', '2026-03-28 00:00', '2026-03-31 00:00') ===
    [[ts('2026-03-28 23:00'), ts('2026-03-29 22:00')]], 'spring DST all-day spans 23 actual hours');
$autumn = multi(dav_response(calendar(event('all-day-autumn', 'DTSTART;VALUE=DATE:20261025', 'DTEND;VALUE=DATE:20261026'))));
check(periods($autumn, 'Europe/Vienna', '2026-10-24 00:00', '2026-10-27 00:00') ===
    [[ts('2026-10-24 22:00'), ts('2026-10-25 23:00')]], 'autumn DST all-day spans 25 actual hours');
$implicit = multi(dav_response(calendar(event('implicit-day', 'DTSTART;VALUE=DATE:20260329', ''))));
check(periods($implicit, 'Europe/Vienna', '2026-03-28 00:00', '2026-03-31 00:00') ===
    [[ts('2026-03-28 23:00'), ts('2026-03-29 22:00')]], 'implicit all-day duration honors collection DST');
$customZone = "BEGIN:VTIMEZONE\r\nTZID:Synthetic/Vienna\r\nX-LIC-LOCATION:Europe/Vienna\r\n" .
    "BEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n";
$custom = multi(dav_response(calendar(event('custom-tz', 'DTSTART;TZID=Synthetic/Vienna:20261030T100000', 'DTEND;TZID=Synthetic/Vienna:20261030T110000'), $customZone)));
check(periods($custom, '') === [[ts('2026-10-30 09:00'), ts('2026-10-30 10:00')]], 'VTIMEZONE supplies resolvable custom TZID');
$instances = event('same-recurrence', 'DTSTART:20261030T090000Z', 'DTEND:20261030T100000Z', "RECURRENCE-ID:20261030T090000Z\r\n") .
    event('same-recurrence', 'DTSTART:20261030T110000Z', 'DTEND:20261030T120000Z', "RECURRENCE-ID:20261106T090000Z\r\n");
check(periods(multi(dav_response(calendar($instances)))) === [[ts('2026-10-30 09:00'), ts('2026-10-30 10:00')],
    [ts('2026-10-30 11:00'), ts('2026-10-30 12:00')]], 'all masterless expanded siblings retained individually');
check(periods($basic, 'UTC', '2026-10-30 10:00', '2026-10-30 11:00') === [], 'event ending exactly at range start leaves no false busy');
check(periods($basic, 'UTC', '2026-10-30 08:00', '2026-10-30 09:00') === [], 'event starting exactly at range end leaves no false busy');
check(periods($basic, 'UTC', '2026-10-30 09:15', '2026-10-30 09:45') ===
    [[ts('2026-10-30 09:15'), ts('2026-10-30 09:45')]], 'generated busy clipped to exact query window');
check(periods(multi(dav_response(calendar(event('transparent', extra: "TRANSP:TRANSPARENT\r\n"))))) === [], 'transparent event does not block');
check(periods(multi(dav_response(calendar(event('cancelled', extra: "STATUS:CANCELLED\r\n"))))) === [], 'cancelled event does not block');
check(periods(multi(dav_response(calendar(event('tentative', extra: "STATUS:TENTATIVE\r\n"))))) ===
    [[ts('2026-10-30 09:00'), ts('2026-10-30 10:00')]], 'tentative event blocks');
check(periods(multi(dav_response(calendar(event('duration', end: '', extra: "DURATION:PT30M\r\n"))))) ===
    [[ts('2026-10-30 09:00'), ts('2026-10-30 09:30')]], 'positive duration honored');
check(periods(multi(dav_response(calendar(event('instant', end: ''))))) === [], 'timed event without duration is a nonblocking instant');
check(periods(multi(dav_response(calendar(event('zero-duration', end: '', extra: "DURATION:PT0S\r\n"))))) === [], 'zero duration cannot produce an invalid generated busy period');
$own = event($prefix . '42@hsp-calendar-connection.local');
check(periods(multi(dav_response(calendar($own)))) === [], 'this site managed appointment excluded');
$otherSite = event('hsp-amelia-00000000-0000-4000-8000-000000000000-42@hsp-calendar-connection.local');
check(periods(multi(dav_response(calendar($otherSite)))) !== [], 'other site managed event still blocks');
$manualOverlap = event('manual-overlap', 'DTSTART:20261030T093000Z', 'DTEND:20261030T103000Z');
check(periods(multi(dav_response(calendar($own . $manualOverlap)))) ===
    [[ts('2026-10-30 09:30'), ts('2026-10-30 10:30')]], 'excluding owned event never subtracts overlapping manual busy');
check(periods(multi(dav_response(calendar($own)) . dav_response(calendar($manualOverlap)))) ===
    [[ts('2026-10-30 09:30'), ts('2026-10-30 10:30')]], 'manual busy retained across multiple DAV resources');
check(periods(multi(dav_response(calendar(event($prefix . 'manual-note'))))) !== [], 'reserved prefix alone does not exclude non-managed manual UID');
$private = generate(multi(dav_response(calendar(event('private-uid', extra: "SUMMARY:DO NOT EXPOSE\r\nDESCRIPTION:PRIVATE NOTE\r\n")))));
check(!str_contains($private, 'private-uid') && !str_contains($private, 'DO NOT EXPOSE') && !str_contains($private, 'PRIVATE NOTE'), 'freebusy output contains no source identity/title/notes');

// Synology can return unrequested VALARM/metadata with calendar-data. Prove
// parsing never follows attachment URLs and only the event interval survives.
class HSPCC_Reject_Network_Stream {
    public $context;
    public static $attempts = 0;
    public function stream_open($path, $mode, $options, &$openedPath) {
        ++self::$attempts;
        throw new RuntimeException('Offline fixture forbids network access.');
    }
}
$replacedWrappers = [];
foreach (['http', 'https'] as $wrapper) {
    if (in_array($wrapper, stream_get_wrappers(), true)) {
        stream_wrapper_unregister($wrapper);
        stream_wrapper_register($wrapper, HSPCC_Reject_Network_Stream::class);
        $replacedWrappers[] = $wrapper;
    }
}
$alarm = "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT15M\r\nDESCRIPTION:PRIVATE ALARM NOTE\r\nEND:VALARM\r\n";
try {
    $nasExtra = "SUMMARY:PRIVATE NAS SUMMARY\r\nDESCRIPTION:PRIVATE NAS EVENT NOTE\r\n" .
        "ATTACH;VALUE=URI:https://blocked.invalid/PRIVATE-EVENT-ATTACH.bin\r\n" . $alarm .
        "BEGIN:VALARM\r\nACTION:AUDIO\r\nTRIGGER:-PT5M\r\n" .
        "ATTACH;VALUE=URI:https://blocked.invalid/PRIVATE-ALARM-ATTACH.wav\r\nEND:VALARM\r\n";
    $nasBody = generate(multi(dav_response(calendar(event('nas-private-uid', extra: $nasExtra)))));
    check(is_string($nasBody), 'NAS unrequested alarms/metadata accepted only within VEVENT');
    check(HSPCC_Free_Busy::parse($nasBody, ts('2026-10-30 00:00'), ts('2026-10-31 00:00')) ===
        [[ts('2026-10-30 09:00'), ts('2026-10-30 10:00')]], 'NAS alarms do not change parent event busy interval');
    foreach (['VALARM', 'ATTACH', 'DESCRIPTION', 'SUMMARY', 'PRIVATE', 'blocked.invalid', 'nas-private-uid'] as $privateToken) {
        check(!str_contains($nasBody, $privateToken), 'generated output omits source alarm/attachment/private metadata');
    }
    check(HSPCC_Reject_Network_Stream::$attempts === 0, 'real VObject never opens projected attachment URLs');
} finally {
    foreach ($replacedWrappers as $wrapper) {
        stream_wrapper_unregister($wrapper);
        stream_wrapper_restore($wrapper);
    }
}

bad(multi(dav_response(calendar(event('floating', 'DTSTART:20261030T100000', 'DTEND:20261030T110000')))), 'floating without actual default fails closed', '');
bad(multi(dav_response(calendar(event('date', 'DTSTART;VALUE=DATE:20261030', 'DTEND;VALUE=DATE:20261031')))), 'all-day invalid default fails closed', 'Invalid/Zone');
bad(multi(dav_response(calendar(event('unknown-tz', 'DTSTART;TZID=Unresolvable/Zone:20261030T100000', 'DTEND;TZID=Unresolvable/Zone:20261030T110000')))), 'unknown explicit TZID never falls back');
foreach (["RRULE:FREQ=DAILY\r\n", "RDATE:20261101T090000Z\r\n", "EXDATE:20261030T090000Z\r\n"] as $rule) {
    bad(multi(dav_response(calendar(event('not-expanded', extra: $rule)))), 'unexpanded recurrence rejected');
}
foreach ([str_replace('UID:manual-one', 'X-NO-UID:manual-one', calendar(event('manual-one'))),
    str_replace('DTSTART:', 'X-NO-START:', calendar(event('missing-start'))),
    str_replace('END:VCALENDAR', '', calendar(event('truncated'))),
    str_replace('END:VEVENT', '', calendar(event('truncated-event'))),
    str_replace('END:VCALENDAR', "END:VCALENDAR\r\nJUNK:extra", calendar(event('trailing-junk'))),
    calendar(event('invalid-date', 'DTSTART:20260230T090000Z')),
    calendar(event('negative', end: '', extra: "DURATION:-PT1H\r\n")),
    calendar(event('duplicate', extra: "UID:second\r\n")),
    calendar(event('mixed-types', 'DTSTART;VALUE=DATE:20261030', 'DTEND:20261030T100000Z')),
    calendar(event('all-day-hours', 'DTSTART;VALUE=DATE:20261030', '', "DURATION:PT1H\r\n")),
    calendar(event('reverse', end: 'DTEND:20261030T080000Z')),
    calendar(event('both', extra: "DURATION:PT1H\r\n")),
    calendar(event('bad-transp', extra: "TRANSP:UNKNOWN\r\n")),
    calendar(event('truncated-alarm', extra: "BEGIN:VALARM\r\nACTION:DISPLAY\r\n")),
    calendar('', $customZone)] as $data) {
    bad(multi(dav_response($data)), 'invalid projected resource rejected without partial free output');
}
bad(multi(dav_response(calendar(event('root-alarm') . $alarm))), 'VALARM directly under VCALENDAR fails closed');
$timezoneAlarm = str_replace("END:VTIMEZONE\r\n", $alarm . "END:VTIMEZONE\r\n", $customZone);
bad(multi(dav_response(calendar(event('timezone-alarm'), $timezoneAlarm))), 'VALARM under VTIMEZONE fails closed');
bad(multi(dav_response(calendar(event('nested-alarm', extra: "BEGIN:VALARM\r\n" . $alarm . "END:VALARM\r\n")))), 'VALARM nested inside another VALARM fails closed');
bad(multi(dav_response(calendar(event('ok'))) . dav_response(calendar(event('private-error')), 'HTTP/1.1 403 Forbidden')), 'one forbidden resource invalidates entire report');
bad(multi(dav_response(calendar(event('ok')), more: '<d:propstat><d:prop/><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat>')), 'partial propstat failure invalidates response');
bad(multi('<d:response><d:href>/missing</d:href><d:status>HTTP/1.1 404 Not Found</d:status></d:response>'), 'response without 200 calendar-data rejected');
bad(str_replace('HTTP/1.1 200 OK', 'HTTP/1.1 200 OK&#10;HTTP/1.1 403 Forbidden', $basic), 'malformed embedded response status rejected');
bad(str_replace('<c:calendar-data>', '<c:calendar-data><d:href/>', $basic), 'calendar-data XML element injection rejected');
bad(str_replace('<d:multistatus', '<other:multistatus xmlns:other="urn:other"', str_replace('</d:multistatus>', '</other:multistatus>', $basic)), 'wrong root namespace rejected');
bad(multi('<other:response xmlns:other="urn:other"/>'), 'wrong-namespace response cannot appear as empty success');
bad(str_replace('<d:multistatus', '<!DOCTYPE d:multistatus [<!ENTITY xxe SYSTEM "file:///nonexistent-private">]><d:multistatus', $basic), 'DTD/entity rejected without resolution');
bad(str_repeat(' ', 1048577), 'response byte limit');
$many = '';
for ($i = 1; $i <= 5001; ++$i) { $many .= event($prefix . $i . '@hsp-calendar-connection.local'); }
$manyXml = multi(dav_response(calendar($many)));
check(strlen($manyXml) < 1048576, 'event limit fixture separately fits XML byte bound');
bad($manyXml, 'more than 5000 projected events rejected even if managed');
check(HSPCC_Overlap_Busy::freebusy(multi(), 1, 1, $prefix, 'UTC') instanceof WP_Error, 'invalid query range rejected');
check(HSPCC_Overlap_Busy::freebusy(multi(), 1, 2, '', 'UTC') instanceof WP_Error, 'empty exclusion prefix rejected');
echo 'PASS: ' . $checks . " offline overlap checks using real bundled VObject; no network or WordPress mutations.\n";
