<?php
if (!function_exists('__')) { function __($text, $domain = '') { return $text; } }
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Synthetic offline tests: no WordPress bootstrap, credentials, database or network.
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct($code, $message, $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function wp_remote_retrieve_response_code($response) { return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_header($response, $name) { return $response['headers'][$name] ?? ''; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
require dirname(__DIR__) . '/includes/class-hspcc-calendar-storage.php';
$checks = 0;
function check($condition, $label) {
    global $checks;
    ++$checks;
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL); exit(1); }
}
function error_is($result, $code, $label, $retryable = null) {
    check($result instanceof WP_Error && $result->get_error_code() === 'hspcc_storage_' . $code &&
        $result->get_error_message() === 'Calendar appointment could not be synchronized.' &&
        ($retryable === null || $result->get_error_data()['retryable'] === $retryable), $label);
}
function response($code, $etag = '', $body = '', $type = 'text/calendar; charset=utf-8') {
    return ['response' => ['code' => $code], 'headers' => ['etag' => $etag, 'content-type' => $type], 'body' => $body];
}
function job($replace = []) {
    return array_replace(['action' => 'upsert', 'siteUuid' => 'fbe4328a-4f04-4299-8431-01a18de408e2',
        'appointmentId' => 42, 'calendarKey' => str_repeat('a', 64), 'startUtc' => 1793354400,
        'endUtc' => 1793356200, 'dtstampUtc' => 1793347200, 'serviceLabel' => 'Free initial consultation',
        'sequence' => 3], $replace);
}
function ready($key = null) {
    HSPCC_Calendar_Storage::set_configuration(static function () use ($key) {
        return ['ready' => true, 'calendarKey' => $key ?? str_repeat('a', 64)];
    });
}
function fixture(array $steps, array $input, $operation = 'sync') {
    $calls = [];
    HSPCC_Calendar_Storage::set_transport(static function ($key, $method, $suffix, $headers, $body) use (&$steps, &$calls) {
        $calls[] = compact('key', 'method', 'suffix', 'headers', 'body');
        check(count($steps) > 0, 'no unexpected HTTP request');
        $step = array_shift($steps);
        check($method === $step[0], 'expected fixture HTTP method');
        return $step[1];
    });
    $result = HSPCC_Calendar_Storage::$operation($input);
    check(count($steps) === 0, 'all expected fixture requests consumed');
    return [$result, $calls];
}

$body = HSPCC_Calendar_Storage::serialize(job());
check(is_string($body), 'pure serializer does not need configuration');
check(str_starts_with($body, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"), 'iCalendar envelope and CRLF');
check(str_contains($body, "DTSTART:20261030T100000Z\r\nDTEND:20261030T103000Z"), 'explicit UTC event interval');
check(substr_count($body, 'BEGIN:VEVENT') === 1 && str_contains($body, 'TRANSP:OPAQUE') && str_contains($body, 'CLASS:PRIVATE'), 'one private opaque event');
check(!str_contains($body, 'METHOD:') && !str_contains($body, 'ATTENDEE') && !str_contains($body, 'VALARM'), 'no scheduling invitations/reminders');
$expectedUid = 'hsp-amelia-fbe4328a-4f04-4299-8431-01a18de408e2-42@hsp-calendar-connection.local';
$unfold = preg_replace('/\r\n /', '', $body);
check(str_contains($unfold, 'UID:' . $expectedUid), 'stable site/appointment UID');
check($body === HSPCC_Calendar_Storage::serialize(job()), 'frozen canonical input is byte deterministic');
check($body === HSPCC_Calendar_Storage::serialize(job(['clientEmail' => 'private@example.test', 'attendees' => ['private']])), 'unrelated client fields never serialized');
$escaped = HSPCC_Calendar_Storage::serialize(job(['serviceLabel' => "Review, strategy; path\\name\nsecond line"]));
check(str_contains($escaped, 'SUMMARY:Review\\, strategy\\; path\\\\name\\nsecond line'), 'RFC text escaping prevents content-line injection');
$long = HSPCC_Calendar_Storage::serialize(job(['serviceLabel' => str_repeat('Österreich ', 30)]));
foreach (explode("\r\n", $long) as $line) {
    check(strlen($line) <= 75 && preg_match('//u', $line) === 1, '75-octet UTF-8-safe physical lines');
}
check(str_contains($long, "\r\n "), 'long text folded');
check(str_contains(HSPCC_Calendar_Storage::serialize(job(['status' => 'tentative'])), 'STATUS:TENTATIVE'), 'tentative state preserved');
foreach ([['appointmentId' => 0], ['appointmentId' => '42'], ['siteUuid' => '../other'], ['calendarKey' => ''],
    ['startUtc' => '1793354400'], ['endUtc' => 1793354400], ['sequence' => -1], ['dtstampUtc' => -1],
    ['serviceLabel' => "bad\0label"], ['serviceLabel' => str_repeat('x', 513)], ['serviceLabel' => "\xc3\x28"],
    ['status' => 'cancelled']] as $invalid) {
    error_is(HSPCC_Calendar_Storage::serialize(job($invalid)), 'invalid_input', 'invalid canonical projection rejected');
}
HSPCC_Calendar_Storage::set_configuration(static fn() => ['ready' => false, 'calendarKey' => str_repeat('a', 64)]);
check(!HSPCC_Calendar_Storage::configured() && HSPCC_Calendar_Storage::target_key() === '', 'unset target remains disabled');
[$result, $calls] = fixture([], job());
error_is($result, 'disabled', 'no write before target selection');
check($calls === [], 'disabled target sends nothing');
[$result, $calls] = fixture([], job(['action' => 'delete', 'calendarKey' => '']));
check($result['result'] === 'absent' && $calls === [], 'unmapped tombstone safely absent without configuration/HTTP');
HSPCC_Calendar_Storage::set_configuration(static function () { throw new RuntimeException('private config'); });
check(HSPCC_Calendar_Storage::target_key() === '', 'configuration failure disabled without detail');
ready();
check(HSPCC_Calendar_Storage::configured(), 'selected target ready');

[$created, $calls] = fixture([['PUT', response(201, '"v1"')]], job());
check($created['result'] === 'created' && $created['etag'] === '"v1"', 'create receipt');
check($calls[0]['headers']['If-None-Match'] === '*' && !isset($calls[0]['headers']['If-Match']), 'create is conditional');
check($calls[0]['suffix'] === 'hsp-amelia-fbe4328a-4f04-4299-8431-01a18de408e2-42.ics', 'managed resource filename contract');
check(!isset($created['body']) && !isset($created['headers']), 'receipt excludes remote payload/headers');
[$result] = fixture([['PUT', response(204, '"v2"')]], job(['mapping' => $created, 'sequence' => 4]));
check($result['result'] === 'updated' && $result['etag'] === '"v2"', 'mapped conditional update');
[$result, $calls] = fixture([['PUT', response(204, '"v2"')]], job(['mapping' => $created]));
check($calls[0]['headers']['If-Match'] === '"v1"' && !isset($calls[0]['headers']['If-None-Match']), 'update uses stored strong ETag');

[$result] = fixture([['PUT', response(412)], ['GET', response(200, '"v1"', $body)]], job());
check($result['result'] === 'unchanged' && $result['etag'] === '"v1"', 'lost create acknowledgement recovered without another PUT');
$spoofed = str_replace('SUMMARY:Free initial consultation', 'SUMMARY:Different remote text', $body);
[$result, $calls] = fixture([['PUT', response(412)], ['GET', response(200, '"v1"', $spoofed)]], job());
error_is($result, 'conflict', 'same UID/source hash cannot conceal changed desired fields', false);
check(count($calls) === 2, 'create conflict never overwrites different event content');
$foreign = str_replace('UID:' . $expectedUid, 'UID:foreign@example.test', $unfold);
[$result] = fixture([['PUT', response(412)], ['GET', response(200, '"v1"', $foreign)]], job());
error_is($result, 'ownership_mismatch', 'foreign UID create collision refused');
[$result] = fixture([['PUT', response(412)], ['GET', response(200, '"new"', $body)]], job(['mapping' => $created]));
check($result['result'] === 'unchanged' && $result['etag'] === '"new"', 'lost mapped-update acknowledgement recovered without overwrite');
[$result, $calls] = fixture([['PUT', response(412)], ['GET', response(200, '"changed"', $spoofed)]], job(['mapping' => $created]));
error_is($result, 'conflict', 'mapped conflict with changed desired projection requires review');
check(count($calls) === 2, 'mapped conflict recovery sends no second PUT');
$nextJob = job(['mapping' => $created, 'sequence' => 4, 'startUtc' => 1793358000, 'endUtc' => 1793359800]);
$nextBody = HSPCC_Calendar_Storage::serialize($nextJob);
[$result] = fixture([['PUT', response(412)], ['GET', response(200, '"v2"', $nextBody)]], $nextJob);
check($result['result'] === 'unchanged' && $result['etag'] === '"v2"', 'lost real reschedule acknowledgement recovers exact desired projection');
[$result] = fixture([['PUT', response(201)], ['GET', response(200, '"post-put"', $body)]], job());
check($result['etag'] === '"post-put"' && $result['result'] === 'created', 'missing PUT ETag recovered through owned GET');
[$result] = fixture([['PUT', response(201, 'W/"weak"')], ['GET', response(200, '"strong"', $body)]], job());
check($result['etag'] === '"strong"', 'weak PUT ETag requires strong GET');
[$result] = fixture([['PUT', response(201)], ['GET', response(404)]], job());
error_is($result, 'write_uncertain', 'post-write missing resource is uncertain', true);
[$result] = fixture([['PUT', response(201)], ['GET', response(200, '"v1"', $spoofed)]], job());
error_is($result, 'write_uncertain', 'post-write body mismatch not acknowledged', true);

$delete = job(['action' => 'delete', 'mapping' => $created]);
[$result, $calls] = fixture([['GET', response(200, '"v1"', $body)], ['DELETE', response(204)]], $delete);
check($result['result'] === 'deleted' && $calls[1]['headers']['If-Match'] === '"v1"', 'owned mapped deletion is conditional');
[$result] = fixture([['GET', response(404)]], $delete);
check($result['result'] === 'absent', 'previous deletion idempotent');
[$result] = fixture([['GET', response(200, '"v1"', $body)], ['DELETE', response(404)]], $delete);
check($result['result'] === 'deleted', 'delete lost-race 404 acknowledged');
[$result] = fixture([['GET', response(200, '"changed"', $body)]], $delete);
error_is($result, 'conflict', 'externally changed ETag blocks deletion');
[$result] = fixture([['GET', response(200, '"v1"', $foreign)]], $delete);
error_is($result, 'ownership_mismatch', 'foreign event never deleted');
[$result] = fixture([['GET', response(200, '"v1"', $body)], ['DELETE', response(412)]], $delete);
error_is($result, 'conflict', 'concurrent change after ownership read blocks deletion');

foreach (['../event.ics', '/collection/event.ics', 'https://other.test/event.ics', 'hsp-amelia-other.ics'] as $path) {
    $map = array_replace($created, ['pathSuffix' => $path]);
    [$result, $calls] = fixture([], job(['mapping' => $map]));
    error_is($result, 'invalid_mapping', 'arbitrary mapping path rejected');
    check($calls === [], 'bad path sends nothing');
}
foreach (['W/"weak"', '"one", "two"', "\"v1\"\r\nX-Evil: yes", '*', ''] as $etag) {
    [$result] = fixture([], job(['mapping' => array_replace($created, ['etag' => $etag])]));
    error_is($result, 'invalid_mapping', 'unsafe mapping ETag rejected');
}
[$result] = fixture([], job(['calendarKey' => str_repeat('b', 64), 'mapping' => $created]));
error_is($result, 'invalid_mapping', 'write-target changes require explicit migration');
foreach ([401, 403, 409, 302] as $status) {
    [$result] = fixture([['PUT', response($status, '', 'PRIVATE REMOTE DETAIL')]], job());
    error_is($result, 'http', 'auth/conflict/redirect errors sanitized and not followed', false);
}
foreach ([408, 429, 500, 503] as $status) {
    [$result] = fixture([['PUT', response($status, '', 'PRIVATE REMOTE DETAIL')]], job());
    error_is($result, 'http', 'transient server failure retryable without payload', true);
}
[$result] = fixture([['PUT', new WP_Error('private_remote_error', 'secret from transport')]], job());
error_is($result, 'transport', 'transport error details sanitized', true);
HSPCC_Calendar_Storage::set_transport(static function () { throw new RuntimeException('secret exception'); });
error_is(HSPCC_Calendar_Storage::sync(job()), 'transport', 'transport exception sanitized', true);

foreach ([['type' => 'text/html'], ['etag' => 'W/"weak"'], ['body' => str_replace('END:VEVENT', '', $body)],
    ['body' => str_replace('X-HSPCC-SITE-UUID:', 'X-FOREIGN-SITE:', $body)],
    ['body' => str_replace('END:VEVENT', "BEGIN:VALARM\r\nEND:VALARM\r\nEND:VEVENT", $body)],
    ['body' => str_replace('END:VCALENDAR', "BEGIN:VEVENT\r\nUID:another\r\nEND:VEVENT\r\nEND:VCALENDAR", $body)],
    ['body' => str_replace('SUMMARY:', 'SUMMARY;LANGUAGE=en:', $body)],
    ['body' => str_replace('DESCRIPTION:', 'DESCRIPTION:bad\\q', $body)],
    ['body' => str_repeat('x', 65537)]] as $invalid) {
    $remote = array_replace(['body' => $body, 'etag' => '"v1"', 'type' => 'text/calendar'], $invalid);
    [$result] = fixture([['GET', response(200, $remote['etag'], $remote['body'], $remote['type'])]], $delete);
    check($result instanceof WP_Error, 'malformed/unsafe owned-resource representation rejected');
}
$selectionRaceCalls = 0;
HSPCC_Calendar_Storage::set_transport(static function ($key, $method) use (&$selectionRaceCalls, $body) {
    ++$selectionRaceCalls;
    check($method === 'GET', 'selection race never reaches DELETE transport');
    ready(str_repeat('b', 64));
    return response(200, '"v1"', $body);
});
error_is(HSPCC_Calendar_Storage::sync($delete), 'disabled', 'target change between GET and DELETE fails closed');
check($selectionRaceCalls === 1, 'target selection rechecked before every request');
ready();
$intent = HSPCC_Calendar_Storage::intent(job());
check(is_array($intent) && array_keys($intent) === ['siteUuid', 'appointmentId', 'calendarKey', 'pathSuffix', 'uid', 'expectedSourceHash'], 'unmapped write intent contains technical identity/hash only');
check($intent['expectedSourceHash'] === $created['sourceHash'] && !isset($intent['startUtc']) && !isset($intent['serviceLabel']), 'intent excludes dates/service/customer content');
[$lost] = fixture([['PUT', new WP_Error('lost_response', 'private detail')]], job());
error_is($lost, 'transport', 'lost create acknowledgement remains uncertain', true);
[$recovered, $calls] = fixture([['GET', response(200, '"created-with-lost-ack"', $body)]], $intent, 'recover');
check($recovered['result'] === 'recovered' && $recovered['sourceHash'] === $created['sourceHash'] && $recovered['etag'] === '"created-with-lost-ack"' && count($calls) === 1, 'read-only recovery maps uncertain successful create');
[$cancelled] = fixture([['GET', response(200, '"created-with-lost-ack"', $body)], ['DELETE', response(204)]], job(['action' => 'delete', 'mapping' => $recovered]));
check($cancelled['result'] === 'deleted', 'lost create acknowledgement followed by cancellation removes only recovered owned entry');
$changedJob = job(['sequence' => 4, 'dtstampUtc' => 1793350800, 'startUtc' => 1793361600, 'endUtc' => 1793363400]);
[$recovered] = fixture([['GET', response(200, '"older-uncertain"', $body)]], $intent, 'recover');
[$changed, $calls] = fixture([['PUT', response(204, '"latest"')]], array_replace($changedJob, ['mapping' => $recovered]));
check($changed['result'] === 'updated' && $calls[0]['headers']['If-Match'] === '"older-uncertain"', 'new native state updates recovered prior uncertain create conditionally');
$mappedIntent = HSPCC_Calendar_Storage::intent($nextJob);
check($mappedIntent['priorSourceHash'] === $created['sourceHash'] && $mappedIntent['priorETag'] === '"v1"' && $mappedIntent['expectedSourceHash'] !== $created['sourceHash'], 'mapped intent carries attempted and prior acknowledged fingerprints');
[$recovered] = fixture([['GET', response(200, '"prior-still-present"', $body)]], $mappedIntent, 'recover');
check($recovered['sourceHash'] === $created['sourceHash'] && $recovered['etag'] === '"prior-still-present"', 'recovery accepts verified previous acknowledged version when attempt never took effect');
[$recovered] = fixture([['GET', response(200, '"attempt-completed"', $nextBody)]], $mappedIntent, 'recover');
check($recovered['sourceHash'] === $mappedIntent['expectedSourceHash'], 'recovery accepts verified attempted update version');
$deleteIntent = HSPCC_Calendar_Storage::intent($delete);
check($deleteIntent['expectedSourceHash'] === $created['sourceHash'] && !isset($deleteIntent['startUtc']), 'delete intent retains only the known owned fingerprint');
[$absent] = fixture([['GET', response(404)]], $deleteIntent, 'recover');
check($absent['result'] === 'absent' && $absent['etag'] === '', 'uncertain successful delete recovered as absent');
error_is(HSPCC_Calendar_Storage::intent(job(['action' => 'delete'])), 'invalid_mapping', 'unmapped tombstone creates no remote intent');
[$result] = fixture([['GET', response(200, '"foreign"', $foreign)]], $intent, 'recover');
error_is($result, 'conflict', 'recovery never adopts foreign UID');
[$result] = fixture([['GET', response(200, '"tampered"', $spoofed)]], $intent, 'recover');
error_is($result, 'conflict', 'remote source-hash property alone is not trusted');
$thirdBody = HSPCC_Calendar_Storage::serialize(job(['sequence' => 9, 'serviceLabel' => 'Unexpected but self-consistent projection']));
[$result] = fixture([['GET', response(200, '"third"', $thirdBody)]], $mappedIntent, 'recover');
error_is($result, 'conflict', 'self-consistent unknown remote version is not an accepted attempted/prior version');
foreach ([str_replace('DTSTART:20261030T100000Z', 'DTSTART:20260230T100000Z', $body),
    str_replace('SEQUENCE:3', 'SEQUENCE:03', $body), str_replace('CLASS:PRIVATE', 'CLASS:PUBLIC', $body),
    str_replace('TRANSP:OPAQUE', 'TRANSP:TRANSPARENT', $body),
    str_replace('DESCRIPTION:Appointment reference: 42', 'DESCRIPTION:Changed reference', $body),
    str_replace('X-HSP-SOURCEHASH:', 'X-MISSING-HASH:', $body)] as $malformed) {
    [$result] = fixture([['GET', response(200, '"bad"', $malformed)]], $intent, 'recover');
    error_is($result, 'conflict', 'malformed/changed owned projection cannot be recovered');
}
foreach ([['expectedSourceHash' => 'invalid'], ['pathSuffix' => '../foreign.ics'], ['startUtc' => 1793354400],
    ['priorSourceHash' => str_repeat('x', 64)], ['priorETag' => "\"v1\"\r\nX: secret"]] as $invalid) {
    [$result, $calls] = fixture([], array_replace($intent, $invalid), 'recover');
    error_is($result, 'invalid_intent', 'corrupt technical intent rejected before HTTP');
    check($calls === [], 'invalid intent sends nothing');
}
ready(str_repeat('b', 64));
[$result, $calls] = fixture([], $intent, 'recover');
error_is($result, 'disabled', 'recovery never reads previous target after selection changed');
check($calls === [], 'target mismatch preserves intent without HTTP');
ready();
echo 'PASS: ' . $checks . " offline storage checks; no network or WordPress mutations.\n";
