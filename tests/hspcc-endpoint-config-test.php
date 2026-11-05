<?php
if (!function_exists('__')) { function __($text, $domain = '') { return $text; } }
function wp_parse_url($value) { return parse_url($value); }
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Standalone synthetic URL/filter/HTTP fixtures. No DNS, WP, network or options.
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    public $code;
    public $message;
    public function __construct($code, $message = '') { $this->code = $code; $this->message = $message; }
}
$filters = $requests = [];
$httpHook = null;
$checks = 0;
function add_filter($name, $callback, $priority = 10, $args = 1) { $GLOBALS['filters'][$name][] = [$callback, $priority, $args]; }
function remove_filter($name, $callback, $priority = 10) {
    foreach ($GLOBALS['filters'][$name] ?? [] as $key => $entry) {
        if ($entry[0] === $callback && $entry[1] === $priority) { unset($GLOBALS['filters'][$name][$key]); }
    }
    $GLOBALS['filters'][$name] = array_values($GLOBALS['filters'][$name] ?? []);
}
function apply_filters($name, $value, ...$args) {
    foreach ($GLOBALS['filters'][$name] ?? [] as [$callback, $priority, $accepted]) {
        $value = $callback(...array_slice([$value, ...$args], 0, $accepted));
    }
    return $value;
}
function wp_safe_remote_request($url, $args) {
    $GLOBALS['requests'][] = [$url, $args];
    return $GLOBALS['httpHook'] ? ($GLOBALS['httpHook'])($url, $args) : ['response' => ['code' => 207], 'body' => 'synthetic'];
}
require dirname(__DIR__) . '/includes/class-hspcc-endpoint.php';
function same($expected, $actual, $label) {
    ++$GLOBALS['checks'];
    if ($expected !== $actual) { fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL); exit(1); }
}
function bad($value, $label) { same('', HSPCC_Endpoint::normalize($value), $label); }
function allowed($candidate, $endpoint, $expected, $label) { same($expected, HSPCC_Endpoint::allows($candidate, $endpoint), $label); }
function error_is($actual, $code, $label) {
    same(true, $actual instanceof WP_Error && $actual->code === 'hspcc_endpoint_' . $code &&
        $actual->message === 'The calendar server request could not be completed safely.', $label);
}
$endpoint = 'https://calendar.example.test:5006/caldav/';
same('https://calendar.example.test/', HSPCC_Endpoint::normalize('HTTPS://CALENDAR.EXAMPLE.TEST'), 'canonical HTTPS scheme and DNS case');
same('https://calendar.example.test/caldav/', HSPCC_Endpoint::normalize('https://calendar.example.test:443/caldav'), 'default explicit port removed and slash added');
same($endpoint, HSPCC_Endpoint::normalize('https://CALENDAR.EXAMPLE.TEST:05006/caldav/'), 'explicit HTTPS port canonicalized');
same('https://calendar.example.test:1/', HSPCC_Endpoint::normalize('https://calendar.example.test:1'), 'minimum port supported');
same('https://calendar.example.test:65535/', HSPCC_Endpoint::normalize('https://calendar.example.test:65535'), 'maximum port supported');
same('https://xn--bcher-kva.example.test/caldav/', HSPCC_Endpoint::normalize('https://xn--bcher-kva.example.test/caldav'), 'ASCII punycode DNS accepted');
same('https://calendar.example.test/users/a%40example.test/Work%20calendar/', HSPCC_Endpoint::normalize('https://calendar.example.test/users/a%40example.test/Work%20calendar'), 'encoded names are allowed in path only');
same('https://calendar.example.test/Kalender/Grüße/', HSPCC_Endpoint::normalize('https://calendar.example.test/Kalender/Grüße'), 'valid UTF8 path retained');
foreach ([null, false, 10, [], new stdClass(), '', ' https://calendar.example.test/', 'https://calendar.example.test/ ',
    'http://calendar.example.test/', '//calendar.example.test/', '/caldav/', 'ftp://calendar.example.test/',
    'https://calendar.example.test/?', 'https://calendar.example.test/#', 'https://calendar.example.test/?query=1',
    'https://calendar.example.test/#fragment', 'https://user:password@calendar.example.test/', 'https://@calendar.example.test/',
    'https://calendar.example.test:/', 'https://calendar.example.test:0/', 'https://calendar.example.test:65536/',
    'https://calendar.example.test:-1/', 'https://calendar.example.test:abc/', 'https:///calendar.example.test/',
    'https://calendar.example.test./', 'https://calendar..example.test/', 'https://-calendar.example.test/',
    'https://calendar-.example.test/', 'https://calendar_example.test/', 'https://bücher.example.test/',
    'https://calendar%2eexample.test/', 'https://calendar.example.test%40evil.example.test/',
    'https://calendar.example.test%2fevil.example.test/', 'https://user%40calendar.example.test/',
    "https://calendar.example.test/\r\nHeader", "https://calendar.example.test/\0x", "https://calendar.example.test/\\evil",
    'https://calendar.example.test/%00x', 'https://calendar.example.test/%0Dx', 'https://calendar.example.test/%1fx',
    'https://calendar.example.test/%7Fx', 'https://calendar.example.test/%5Cx', 'https://calendar.example.test/%xx',
    'https://calendar.example.test/%', "https://calendar.example.test/\u{200B}x", "https://calendar.example.test/\u{00A0}x",
    "https://calendar.example.test/\xFF", 'https://localhost/', 'https://sub.localhost/', 'https://calendar.local/',
    'https://calendar.internal/', 'https://calendar.invalid/', 'https://calendar.onion/', 'https://calendar/',
    'https://2130706433/', 'https://0x7f000001/', 'https://0177.0.0.1/', 'https://127.1/',
    'https://[::1]/', 'https://[2606:4700:4700::1111]/', 'https://2606:4700:4700::1111/',
    'https://' . str_repeat('a', 64) . '.example.test/', 'https://calendar.example.test/' . str_repeat('x', 2048)] as $index => $value) {
    bad($value, 'unsafe or malformed endpoint ' . $index);
}
foreach (['0.0.0.0', '10.0.0.1', '100.64.0.1', '100.127.255.255', '127.0.0.1', '169.254.169.254',
    '172.16.0.1', '172.31.255.255', '192.0.0.9', '192.0.2.1', '192.88.99.1', '192.168.1.1',
    '198.18.0.1', '198.19.255.255', '198.51.100.1', '203.0.113.1', '224.0.0.1', '239.255.255.255',
    '240.0.0.1', '255.255.255.255'] as $ip) { bad('https://' . $ip . '/', 'non-public literal rejected'); }
// Public numeric values are parsed only; these fixtures never contact them.
same('https://8.8.8.8:5006/', HSPCC_Endpoint::normalize('https://8.8.8.8:5006'), 'public IPv4 literal accepted without DNS or HTTP');
same('https://1.1.1.1/', HSPCC_Endpoint::normalize('https://1.1.1.1/'), 'second public IPv4 literal accepted');

allowed('https://calendar.example.test:5006/principal/user/', $endpoint, true, 'principal may differ from discovery endpoint path');
allowed('https://CALENDAR.EXAMPLE.TEST:5006/calendar-home/', $endpoint, true, 'home uses canonical same origin');
allowed('https://calendar.example.test/principal/', 'https://calendar.example.test:443/caldav/', true, 'explicit and implicit443 equivalent');
allowed('https://other.example.test:5006/caldav/', $endpoint, false, 'different host rejected');
allowed('https://calendar.example.test:5007/caldav/', $endpoint, false, 'different explicit port rejected');
allowed('https://calendar.example.test/caldav/', $endpoint, false, 'implicit443 cannot match configured5006');
allowed('http://calendar.example.test:5006/caldav/', $endpoint, false, 'downgrade rejected');
allowed('https://calendar.example.test.evil.example.test:5006/caldav/', $endpoint, false, 'host suffix trick rejected');
allowed('https://calendar.example.test:5006/caldav/?query=1', $endpoint, false, 'query rejected on discovered href');
allowed('https://calendar.example.test:5006/caldav/#fragment', $endpoint, false, 'fragment rejected on discovered href');
allowed('https://calendar.example.test:5006/caldav/', 'malformed', false, 'invalid configured endpoint fails closed');

$sentinel = static function ($ports) { $ports[] = 1234; return $ports; };
add_filter('http_allowed_safe_ports', $sentinel, 10, 3);
$httpHook = function ($url, $args) use ($endpoint) {
    same('https://calendar.example.test:5006/principal/user/', $url, 'transport receives canonical candidate origin and original path');
    same(true, $args['sslverify'], 'TLS verification cannot be disabled');
    same(0, $args['redirection'], 'redirects cannot be enabled');
    same(true, $args['reject_unsafe_urls'], 'safe URL checking cannot be disabled');
    same(true, $args['blocking'], 'scoped filter lives through synchronous request');
    same(false, $args['stream'], 'response cannot be streamed to a caller filename');
    same(false, isset($args['filename']), 'caller output filename removed');
    same([80, 443, 8080, 1234, 5006], apply_filters('http_allowed_safe_ports', [80, 443, 8080], 'calendar.example.test', $url), 'only chosen origin gains configured port');
    same([443, '5006', 1234, 5006], apply_filters('http_allowed_safe_ports', [443, '5006'], 'calendar.example.test', $url), 'chosen port remains integer for WordPress strict comparison');
    same([80, 443, 8080, 1234], apply_filters('http_allowed_safe_ports', [80, 443, 8080], 'other.example.test', 'https://other.example.test:5006/'), 'unrelated origin gains no new port');
    same([80, 443, 8080, 1234], apply_filters('http_allowed_safe_ports', [80, 443, 8080], 'calendar.example.test', 'https://calendar.example.test:5007/'), 'same host different port gains no new port');
    same([80, 443, 8080, 1234], apply_filters('http_allowed_safe_ports', [80, 443, 8080], 'wrong.example.test', $url), 'URL and supplied host must agree');
    same(false, isset($GLOBALS['filters']['http_request_host_is_external']), 'no private-host or DNS exception installed');
    return ['response' => ['code' => 207], 'body' => 'synthetic'];
};
$response = HSPCC_Endpoint::request('HTTPS://CALENDAR.EXAMPLE.TEST:5006/principal/user/', [
    'method' => 'PROPFIND', 'sslverify' => false, 'redirection' => 5, 'reject_unsafe_urls' => false,
    'blocking' => false, 'stream' => true, 'filename' => 'never-created',
], $endpoint);
same(207, $response['response']['code'] ?? 0, 'successful safe response preserved');
same([[$sentinel, 10, 3]], $filters['http_allowed_safe_ports'], 'only owned temporary filter removed');
same([80, 443, 8080, 1234], apply_filters('http_allowed_safe_ports', [80, 443, 8080], 'calendar.example.test', $endpoint), 'configured port not globally allowed after return');
$httpHook = static function () { throw new RuntimeException('private transport detail'); };
error_is(HSPCC_Endpoint::request($endpoint, [], $endpoint), 'transport', 'exception sanitized');
same([[$sentinel, 10, 3]], $filters['http_allowed_safe_ports'], 'exception still removes owned filter');
$httpHook = static function () { return new WP_Error('http_request_failed', 'private DNS detail'); };
error_is(HSPCC_Endpoint::request('https://private.example.test:5006/', [], 'https://private.example.test:5006/'), 'transport', 'native safe-HTTP DNS/private rejection preserved and sanitized');
same([[$sentinel, 10, 3]], $filters['http_allowed_safe_ports'], 'native HTTP error still cleans filter');
$before = count($requests);
error_is(HSPCC_Endpoint::request('https://other.example.test:5006/', [], $endpoint), 'origin', 'cross-origin request rejected before transport');
error_is(HSPCC_Endpoint::request('https://127.0.0.1/', [], 'https://127.0.0.1/'), 'origin', 'private literal request rejected before transport');
same($before, count($requests), 'rejected origins perform no HTTP');
same([[$sentinel, 10, 3]], $filters['http_allowed_safe_ports'], 'rejected origins do not register temporary filter');

fwrite(STDOUT, 'PASS: ' . $checks . " synthetic endpoint/origin/scoped-port checks; no DNS, WordPress or network.\n");
