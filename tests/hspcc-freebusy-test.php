<?php
if (!function_exists('__')) { function __($text, $domain = '') { return $text; } }
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Synthetic offline fixtures only; no WordPress bootstrap, network, storage or real events.
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    public $code;
    public $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
}
require dirname(__DIR__) . '/includes/class-hspcc-free-busy.php';
$checks = 0;
function ts($value) { return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp(); }
function calendar($body = '', $extra = '') {
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Synthetic tests//EN\r\n" . $extra .
        "BEGIN:VFREEBUSY\r\nDTSTAMP:20261030T000000Z\r\n" . $body . "END:VFREEBUSY\r\nEND:VCALENDAR\r\n";
}
function parse_busy($body, $start = null, $end = null) {
    return HSPCC_Free_Busy::parse($body, $start ?? ts('2026-10-30 00:00:00'), $end ?? ts('2026-12-31 00:00:00'));
}
function same($expected, $actual, $label) {
    global $checks;
    ++$checks;
    if ($expected !== $actual) { fwrite(STDERR, "FAIL: " . $label . PHP_EOL); exit(1); }
}
function bad($body, $label, $start = null, $end = null) {
    global $checks;
    ++$checks;
    $actual = parse_busy($body, $start, $end);
    if (!$actual instanceof WP_Error || $actual->code !== 'hspcc_free_busy_invalid' ||
        $actual->message !== 'Calendar busy-time response could not be validated.') {
        fwrite(STDERR, "FAIL: " . $label . PHP_EOL); exit(1);
    }
}
$nine = ts('2026-10-30 09:00:00');
$ten = ts('2026-10-30 10:00:00');
$eleven = ts('2026-10-30 11:00:00');
$twelve = ts('2026-10-30 12:00:00');
same([], parse_busy(calendar()), 'well-formed empty report, no UID or range required');
same([[$nine, $ten]], parse_busy(calendar("FREEBUSY:20261030T090000Z/20261030T100000Z\r\n")), 'explicit UTC period');
same([[$nine, $ten]], parse_busy(calendar("FREEBUSY:20261030T090000Z/PT1H\r\n")), 'duration period');
same([[$nine, $ten], [$eleven, $twelve]], parse_busy(calendar("FREEBUSY:20261030T090000Z/PT1H,20261030T110000Z/PT1H\r\n")), 'comma list');
same([[$nine, $ten], [$eleven, $twelve]], parse_busy(calendar("FREEBUSY:20261030T090000Z/PT1H,\r\n 20261030T110000Z/PT1H\r\n")), 'RFC line unfolding');
same([[$nine, $ten]], parse_busy(calendar("FREEBUSY:20261030T09\r\n\t0000Z/PT1H\r\n")), 'fold within UTC value');
same([[$nine, $ten]], parse_busy(str_replace("\r\n", "\n", calendar("FREEBUSY:20261030T090000Z/PT1H\r\n"))), 'LF transport tolerated');
same([[$nine, $ten]], parse_busy(calendar("ATTENDEE;CN=\"Office: A; B\":mailto:synthetic@example.test\r\nFREEBUSY:20261030T090000Z/PT1H\r\n")), 'quoted metadata colon and semicolon');
same([], parse_busy(calendar("COMMENT:Caf\xc3\r\n \xa9\r\n")), 'UTF8 folded between octets');
same([[$nine, $ten]], parse_busy(calendar("freebusy;fbtype=\"BUSY\";value=\"PERIOD\":20261030T090000Z/PT1H\r\n")), 'case-insensitive property/parameters');
foreach (['BUSY', 'BUSY-TENTATIVE', 'BUSY-UNAVAILABLE', 'X-UNKNOWN', 'SOME-FUTURE-TYPE'] as $type) {
    same([[$nine, $ten]], parse_busy(calendar("FREEBUSY;FBTYPE=" . $type . ":20261030T090000Z/PT1H\r\n")), 'busy and unknown FBTYPE block');
}
same([], parse_busy(calendar("FREEBUSY;FBTYPE=FREE:20261030T090000Z/PT1H\r\n")), 'FREE ignored');
same([[$nine, $twelve]], parse_busy(calendar("FREEBUSY:20261030T090000Z/PT3H\r\nFREEBUSY;FBTYPE=FREE:20261030T100000Z/PT1H\r\n")), 'FREE cannot subtract busy');
same([[$nine, $twelve]], parse_busy(calendar("FREEBUSY:20261030T110000Z/PT1H,20261030T090000Z/PT1H,20261030T100000Z/PT1H,20261030T093000Z/PT1H\r\n")), 'sort merge overlap adjacency duplicates');
same([[$nine + 900, $ten - 900]], parse_busy(calendar("FREEBUSY:20261030T090000Z/PT1H\r\n"), $nine + 900, $ten - 900), 'clip both edges');
same([], parse_busy(calendar("FREEBUSY:20261030T090000Z/PT1H,20261030T110000Z/PT1H\r\n"), $ten, $eleven), 'half-open touching window boundaries');
same([], parse_busy(calendar("FREEBUSY:20261029T090000Z/PT1H\r\n")), 'validated periods outside window ignored');
same([], parse_busy(calendar("DTSTART:20261030T000000Z\r\nDTEND:20261231T000000Z\r\n")), 'optional valid UTC range');
same([], parse_busy(calendar("DTSTART:20261030T000000Z\r\n")), 'independently optional DTSTART');
same([], parse_busy(calendar("DTEND:20261231T000000Z\r\n")), 'independently optional DTEND');
same([], parse_busy(calendar("DTSTART:20261029T000000Z\r\nDTEND:20270101T000000Z\r\n")), 'wider report bounds cover request');
bad(calendar("DTSTART:20261030T000001Z\r\n"), 'partial start coverage cannot yield free');
bad(calendar("DTEND:20261230T235959Z\r\n"), 'partial end coverage cannot yield free');
bad(calendar("DTSTART:20261030T000001Z\r\nDTEND:20261231T000000Z\r\n"), 'partial start with end cannot yield free');
bad(calendar("DTSTART:20261030T000000Z\r\nDTEND:20261230T235959Z\r\n"), 'partial end with start cannot yield free');
bad(calendar("DTSTART:20261030T000001Z\r\nFREEBUSY:20261030T090000Z/PT1H\r\n"), 'partial coverage fails even with busy periods');
same([], parse_busy(calendar("UID:synthetic@example.test\r\nREQUEST-STATUS:2.0;Success\r\n")), 'optional UID success status');
$durations = ['P1W' => 604800, 'P2D' => 172800, '+P1DT2H3M4S' => 93784, 'PT90M' => 5400,
    'PT1H30M' => 5400, 'PT0H30M' => 1800, 'PT1H0M1S' => 3601, 'PT30S' => 30, 'P0001D' => 86400, 'P1DT0S' => 86400];
foreach ($durations as $duration => $seconds) {
    same([[$nine, $nine + $seconds]], parse_busy(calendar("FREEBUSY:20261030T090000Z/" . $duration . "\r\n")), 'positive RFC duration ' . $duration);
}
date_default_timezone_set('Europe/Vienna');
same([[ts('2026-10-25 00:30:00 UTC'), ts('2026-10-25 02:30:00 UTC')]], parse_busy(
    calendar("FREEBUSY:20261025T003000Z/PT2H\r\n"), ts('2026-10-25 00:00:00 UTC'), ts('2026-10-25 04:00:00 UTC')
), 'UTC independent of Vienna DST overlap and server default timezone');
date_default_timezone_set('UTC');
foreach (['P', 'PT', 'P0W', 'P0D', 'PT0S', 'P1DT', 'P1WT1H', 'P1Y', 'P1M', 'P1D2H',
    '-PT1H', '+P-1D', 'PT-1H', 'PT1.5H', 'PT1S2M', 'PT1H1H', 'PT1H1S', 'P999999999999999W'] as $duration) {
    bad(calendar("FREEBUSY:20261030T090000Z/" . $duration . "\r\n"), 'malformed/zero/negative/overflow duration');
}
foreach (['20260229T090000Z', '20261301T090000Z', '20261030T240000Z', '20261030T096000Z',
    '20261030T090060Z', '20261030T090000', '20261030T090000+0200', '20261030', '00001030T090000Z'] as $date) {
    bad(calendar("FREEBUSY:" . $date . "/PT1H\r\n"), 'strict UTC dates reject normalization/floating/offset/leap second');
}
foreach (["FREEBUSY:20261030T090000Z/20261030T090000Z\r\n", "FREEBUSY:20261030T100000Z/20261030T090000Z\r\n",
    "FREEBUSY:20261030T090000Z/PT1H,\r\n", "FREEBUSY:\r\n", "FREEBUSY:20261030T090000Z/PT1H/extra\r\n",
    "FREEBUSY;FBTYPE=:20261030T090000Z/PT1H\r\n", "FREEBUSY;FBTYPE=BUSY,FREE:20261030T090000Z/PT1H\r\n",
    "FREEBUSY;FBTYPE=BUSY;FBTYPE=FREE:20261030T090000Z/PT1H\r\n", "FREEBUSY;TZID=Europe/Vienna:20261030T090000Z/PT1H\r\n",
    "FREEBUSY;VALUE=DATE-TIME:20261030T090000Z/PT1H\r\n", "FREEBUSY;FBTYPE=FREE:invalid\r\n",
    "RRULE:FREQ=DAILY\r\n", "RDATE:20261030T090000Z\r\n", "DURATION:PT1H\r\n",
    "REQUEST-STATUS:3.0;Failure\r\n", "DTSTART:20261030T090000\r\n",
    "DTSTART:20261030T100000Z\r\nDTEND:20261030T090000Z\r\n", "DTSTAMP:20261030T000000Z\r\n",
    "UID:one\r\nUID:two\r\n", "ATTENDEE;CN=\"unterminated:mailto:synthetic@example.test\r\n"] as $content) {
    bad(calendar($content), 'malformed/unsupported semantic content');
}
bad(calendar("FREEBUSY:99991231T235959Z/PT1S\r\n"), 'end beyond supported UTC year');
foreach (['VEVENT', 'VTODO', 'VTIMEZONE', 'VALARM', 'VJOURNAL'] as $component) {
    bad(calendar("BEGIN:" . $component . "\r\nEND:" . $component . "\r\n"), 'extra nested component rejected');
}
bad(str_replace('END:VFREEBUSY', "END:VFREEBUSY\r\nBEGIN:VFREEBUSY\r\nDTSTAMP:20261030T000000Z\r\nEND:VFREEBUSY", calendar()), 'multiple VFREEBUSY rejected');
bad(calendar() . calendar(), 'multiple VCALENDAR rejected');
bad("BEGIN:VFREEBUSY\r\nDTSTAMP:20261030T000000Z\r\nEND:VFREEBUSY\r\n", 'unwrapped VFREEBUSY rejected');
bad(str_replace("DTSTAMP:20261030T000000Z\r\n", '', calendar()), 'DTSTAMP required');
bad(str_replace("VERSION:2.0\r\n", '', calendar()), 'VERSION required');
bad(str_replace("PRODID:-//Synthetic tests//EN\r\n", '', calendar()), 'PRODID required');
bad(str_replace('VERSION:2.0', 'VERSION:1.0', calendar()), 'unsupported version');
bad(calendar('', "METHOD:REQUEST\r\n"), 'request must not be accepted as empty response');
bad(str_replace('END:VCALENDAR', 'END:VTODO', calendar()), 'mismatched components');
bad(calendar() . 'trailing garbage', 'trailing noncalendar body');
bad("<html>server failure</html>", 'HTML never interpreted as free');
bad('', 'empty transport never interpreted as free');
bad(calendar("COMMENT:bad\xff\r\n"), 'invalid UTF8 rejected after unfolding');
bad(calendar("COMMENT:bad\0\r\n"), 'NUL rejected');
bad(str_replace("\r\n", "\r", calendar()), 'bare CR rejected');
bad("\t" . calendar(), 'orphan folded line rejected');
bad(calendar("\r\n"), 'interior blank line rejected');
bad(calendar(), 'empty lookup window rejected', $nine, $nine);
bad(str_repeat('x', HSPCC_Free_Busy::MAX_BYTES + 1), 'oversize body rejected');
bad(calendar(str_repeat("COMMENT:x\r\n", HSPCC_Free_Busy::MAX_LINES)), 'line count bound');
bad(calendar(str_repeat("FREEBUSY:20261030T090000Z/PT1S\r\n", HSPCC_Free_Busy::MAX_PERIODS + 1)), 'period count bound');
echo "PASS: " . $checks . " synthetic VFREEBUSY parser checks\n";
