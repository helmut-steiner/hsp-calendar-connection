<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Offline smoke tests only: no WordPress bootstrap, network, credentials or database.
define('ABSPATH', __DIR__ . '/');
define('HSPCC_TEST_ENDPOINT', 'https://calendar.example.test/caldav/');
$GLOBALS['hspcc_options'] = ['hsp_calendar_connection_credentials'=>['endpoint'=>HSPCC_TEST_ENDPOINT,'providerId'=>1]];
$GLOBALS['hspcc_admin'] = true;
$GLOBALS['hspcc_salt'] = 'synthetic-test-salt';
$GLOBALS['hspcc_responses'] = [];
$GLOBALS['hspcc_requests'] = [];
function add_action(...$args) {}
function add_filter(...$args) {}
function plugin_basename($file) { return 'hsp-calendar-connection/' . basename($file); }
function remove_filter(...$args) {}
function delete_option($name) { unset($GLOBALS['hspcc_options'][$name]); }
function add_options_page(...$args) {}
function register_activation_hook(...$args) {}
function register_deactivation_hook(...$args) {}
function register_setting(...$args) { $GLOBALS['hspcc_registration'] = $args; }
function get_option($name, $default = false) { return $GLOBALS['hspcc_options'][$name] ?? $default; }
function add_option($name, $value, $deprecated = '', $autoload = null) {
    if (array_key_exists($name, $GLOBALS['hspcc_options'])) { return false; }
    $GLOBALS['hspcc_options'][$name] = $value;
    return true;
}
function update_option($name, $value, $autoload = null) { $GLOBALS['hspcc_options'][$name] = $value; }
function current_user_can($cap) { return $GLOBALS['hspcc_admin']; }
function wp_salt($scheme) { return $GLOBALS['hspcc_salt'] . ':' . $scheme; }
function add_settings_error(...$args) { $GLOBALS['hspcc_settings_error'] = $args[1]; }
function wp_parse_url($url) { return parse_url($url); }
function trailingslashit($value) { return rtrim($value,'/').'/'; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $value)); }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value); }
function check_admin_referer($action) { $GLOBALS['hspcc_checked_nonce'] = $action; }
function admin_url($path) { return 'http://local.test/wp-admin/' . $path; }
function esc_url($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function __($value, $domain = null) { return $value; }
function esc_html__($value, $domain = null) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_safe_redirect($url) { $GLOBALS['hspcc_redirect'] = $url; }
function wp_die(...$args) { throw new RuntimeException('Forbidden'); }
function wp_safe_remote_request($url, $args) {
    $GLOBALS['hspcc_requests'][] = ['url' => $url, 'method' => $args['method'], 'redirection' => $args['redirection'], 'sslverify' => $args['sslverify'], 'depth' => $args['headers']['Depth'] ?? null];
    return array_shift($GLOBALS['hspcc_responses']);
}
function wp_remote_retrieve_response_code($response) { return $response['status']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_remote_retrieve_header($response, $name) { return $response['headers'][$name] ?? ''; }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error {
    private $code;
    function __construct($code) { $this->code = $code; }
    function get_error_code() { return $this->code; }
}
class WP_Http {
    static function make_absolute_url($href, $base) {
        if (str_starts_with($href, 'https://') || str_starts_with($href, 'http://')) { return $href; }
        $p = parse_url($base);
        if (str_starts_with($href, '//')) {
            $relative = parse_url($href);
            return $p['scheme'] . '://' . $relative['host'] . ($relative['path'] ?? '/');
        }
        return $p['scheme'] . '://' . $p['host'] . (str_starts_with($href, '/') ? $href : dirname($p['path']) . '/' . $href);
    }
}
require dirname(__DIR__) . '/hsp-calendar-connection.php';
function invoke_private($name, ...$args) {
    return (new ReflectionMethod(HSP_Calendar_Connection::class, $name))->invoke(null, ...$args);
}
function check($condition, $label) {
    if (!$condition) { fwrite(STDERR, "FAIL: " . $label . PHP_EOL); exit(1); }
}
$settingsLinks = HSP_Calendar_Connection::settings_link(['deactivate' => '<a>Deactivate</a>']);
check(array_values($settingsLinks) === [
    '<a href="http://local.test/wp-admin/options-general.php?page=hsp-calendar-connection">Settings</a>', '<a>Deactivate</a>',
], 'native plugin-row Settings link opens existing settings page and retains other actions');
$GLOBALS['hspcc_admin'] = false;
check(HSP_Calendar_Connection::settings_link(['existing']) === ['existing'], 'Settings link hidden without manage_options');
$GLOBALS['hspcc_admin'] = true;
function response_xml($properties, $href = '/caldav/') {
    return '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:response><d:href>' . $href . '</d:href><d:propstat><d:prop>' . $properties . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
}
$mode = $argv[1] ?? 'unit';
if ($mode === 'discover') {
    $secret = invoke_private('encrypt', 'synthetic-password');
    $GLOBALS['hspcc_options'][HSP_Calendar_Connection::OPTION] = ['username' => 'synthetic-user', 'secret' => $secret, 'endpoint'=>HSPCC_TEST_ENDPOINT, 'providerId'=>1];
    $GLOBALS['hspcc_responses'] = [
        ['status' => 207, 'body' => response_xml('<d:current-user-principal><d:href>/caldav/principals/synthetic/</d:href></d:current-user-principal>')],
        ['status' => 207, 'body' => response_xml('<c:calendar-home-set><d:href>/caldav/calendars/synthetic/</d:href></c:calendar-home-set>')],
        ['status' => 207, 'body' => response_xml('<d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>Office calendar</d:displayname><d:supported-report-set><d:supported-report><d:report><c:free-busy-query/></d:report></d:supported-report></d:supported-report-set>', '/caldav/calendars/synthetic/office/')],
    ];
    register_shutdown_function(function () {
        $discovery = $GLOBALS['hspcc_options'][HSP_Calendar_Connection::DISCOVERY] ?? [];
        $calendars = $discovery['calendars'] ?? [];
        check(count($GLOBALS['hspcc_requests']) === 3, 'exactly three requests');
        check(array_column($GLOBALS['hspcc_requests'], 'method') === ['PROPFIND', 'PROPFIND', 'PROPFIND'], 'no GET/REPORT/write');
        check(array_column($GLOBALS['hspcc_requests'], 'depth') === ['0', '0', '1'], 'depths');
        check(array_column($GLOBALS['hspcc_requests'], 'redirection') === [0, 0, 0], 'redirects disabled');
        check(count($calendars) === 1 && reset($calendars)['freeBusy'] === true, 'calendar and freebusy detection');
        check(str_contains($GLOBALS['hspcc_redirect'] ?? '', 'hspcc_status=discovered'), 'discovery success');
        echo "PASS: offline discovery, same-origin href resolution, freebusy capability, three PROPFIND-only requests\n";
    });
    HSP_Calendar_Connection::discover();
    exit(1);
}
HSP_Calendar_Connection::register();
check($GLOBALS['hspcc_registration'][2]['show_in_rest'] === false, 'no REST exposure');
check(invoke_private('allowed_url', 'https://calendar.example.test/caldav/') === true, 'allow configured origin');
check(invoke_private('allowed_url', 'https://calendar.example.test:443/principal/') === true, 'allow same HTTPS origin');
foreach (['http://calendar.example.test/caldav/', 'https://evil.test/', 'https://calendar.example.test:8443/', 'https://user:pass@calendar.example.test/', 'https://calendar.example.test/?query=1', 'https://calendar.example.test/#fragment', "//calendar.example.test/caldav/", "https://calendar.example.test/\r\nheader", 'https://calendar.example.test/\\evil'] as $url) {
    check(invoke_private('allowed_url', $url) === false, 'reject unsafe origin/url');
}
foreach (["https://calendar.example.test/\0x", 'https://user%40name@calendar.example.test/', 'https://calendar.example.test%40evil.test/', 'https://calendar.example.test.evil.test/', 'https://calendar%2eexample.test/', 'https://calendar.example.test%2Fevil.test/'] as $url) {
    check(invoke_private('allowed_url', $url) === false, 'reject controls/encoded userinfo/encoded host confusion');
}
$secret = invoke_private('encrypt', 'synthetic-password with spaces ');
check(!str_contains($secret, 'synthetic-password'), 'ciphertext only');
check(invoke_private('decrypt', $secret) === 'synthetic-password with spaces ', 'encryption roundtrip');
$bad = substr($secret, 0, -4) . 'AAAA';
$failed = false;
try { invoke_private('decrypt', $bad); } catch (Throwable $e) { $failed = true; }
check($failed, 'tampering rejected');
$GLOBALS['hspcc_salt'] = 'rotated-test-salt';
$failed = false;
try { invoke_private('decrypt', $secret); } catch (Throwable $e) { $failed = true; }
check($failed, 'salt change fails closed');
$GLOBALS['hspcc_salt'] = 'synthetic-test-salt';
$GLOBALS['hspcc_options'][HSP_Calendar_Connection::OPTION] = ['username' => 'synthetic-user', 'secret' => $secret, 'endpoint'=>HSPCC_TEST_ENDPOINT, 'providerId'=>1];
$saved = HSP_Calendar_Connection::sanitize(['endpoint'=>HSPCC_TEST_ENDPOINT,'providerId'=>1,'username' => 'synthetic-user', 'password' => '']);
check($saved['secret'] === $secret && !array_key_exists('password', $saved), 'blank password preserved and no plaintext field');
check(HSP_Calendar_Connection::sanitize($saved) === $saved, 'repeat sanitizer safe');
$old = $GLOBALS['hspcc_options'][HSP_Calendar_Connection::OPTION];
check(HSP_Calendar_Connection::sanitize(['endpoint'=>HSPCC_TEST_ENDPOINT,'providerId'=>1,'username' => "bad:user", 'password' => 'x']) === $old, 'invalid Basic username rejected');
foreach (["bad\0user", "bad\r\nuser", "bad\x7fuser", "\tbaduser", "baduser\n"] as $username) {
    check(HSP_Calendar_Connection::sanitize(['endpoint'=>HSPCC_TEST_ENDPOINT,'providerId'=>1,'username' => $username, 'password' => 'x']) === $old, 'username control rejected');
}
$GLOBALS['hspcc_admin'] = false;
check(HSP_Calendar_Connection::sanitize(['endpoint'=>HSPCC_TEST_ENDPOINT,'providerId'=>1,'username' => 'other', 'password' => 'x']) === $old, 'capability enforced');
$GLOBALS['hspcc_admin'] = true;
foreach ([['status' => 302, 'body' => ''], ['status' => 401, 'body' => ''], ['status' => 207, 'body' => '<!DOCTYPE x [<!ENTITY y SYSTEM "file:///secret">]><x>&y;</x>']] as $response) {
    $GLOBALS['hspcc_responses'] = [$response];
    check(is_wp_error(invoke_private('propfind', HSPCC_TEST_ENDPOINT, '<d:resourcetype/>', 0, 'synthetic-user', 'synthetic-password')), 'redirect/auth/XXE rejected');
}
$utf16 = "\xff\xfe";
foreach (str_split('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE x [<!ENTITY y "test">]><x>&y;</x>') as $char) { $utf16 .= $char . "\0"; }
$GLOBALS['hspcc_responses'] = [['status' => 207, 'body' => $utf16]];
check(is_wp_error(invoke_private('propfind', HSPCC_TEST_ENDPOINT, '<d:resourcetype/>', 0, 'synthetic-user', 'synthetic-password')), 'UTF-16 doctype rejected after parse');
$GLOBALS['hspcc_responses'] = [['status' => 207, 'body' => str_replace('200 OK', '403 Forbidden', response_xml('<d:current-user-principal><d:href>/caldav/principals/synthetic/</d:href></d:current-user-principal>'))]];
$xpath = invoke_private('propfind', HSPCC_TEST_ENDPOINT, '<d:current-user-principal/>', 0, 'synthetic-user', 'synthetic-password');
check(invoke_private('href', $xpath, '//d:propstat[contains(d:status, " 200 ")]/d:prop/d:current-user-principal/d:href', HSPCC_TEST_ENDPOINT) === false, 'non-success propstat ignored');
$GLOBALS['hspcc_responses'] = [['status' => 207, 'body' => response_xml('<d:current-user-principal><d:href>https://evil.test/principal/</d:href></d:current-user-principal>')]];
$xpath = invoke_private('propfind', HSPCC_TEST_ENDPOINT, '<d:current-user-principal/>', 0, 'synthetic-user', 'synthetic-password');
check(invoke_private('href', $xpath, '//d:propstat[contains(d:status, " 200 ")]/d:prop/d:current-user-principal/d:href', HSPCC_TEST_ENDPOINT) === false, 'cross-origin principal rejected before credential forwarding');
$GLOBALS['hspcc_responses'] = [['status' => 207, 'body' => response_xml('<d:current-user-principal><d:href>//user:pass@calendar.example.test/principal/</d:href></d:current-user-principal>')]];
$xpath = invoke_private('propfind', HSPCC_TEST_ENDPOINT, '<d:current-user-principal/>', 0, 'synthetic-user', 'synthetic-password');
check(invoke_private('href', $xpath, '//d:propstat[contains(d:status, " 200 ")]/d:prop/d:current-user-principal/d:href', HSPCC_TEST_ENDPOINT) === false, 'raw scheme-relative userinfo rejected before WordPress normalization');
echo "PASS: origin allowlist, crypto roundtrip/tamper/salt rotation, blank secret retention, double sanitize, capability, redirect/auth/XML protections\n";

// The REPORT transport never follows discovered redirects or exposes credentials.
$credentials=['username'=>'synthetic-user','secret'=>$secret,'providerId'=>1,'endpoint'=>HSPCC_TEST_ENDPOINT];
$GLOBALS['hspcc_options'][HSP_Calendar_Connection::OPTION]=$credentials;
$GLOBALS['hspcc_options'][HSP_Calendar_Connection::DISCOVERY]=[
    'fingerprint'=>invoke_private('fingerprint',$credentials),'busySelected'=>['synthetic-selected'],
    'calendars'=>['synthetic-selected'=>['url'=>HSPCC_TEST_ENDPOINT.'synthetic/','freeBusy'=>true]],
];
$from=strtotime('2027-01-02 00:00:00 UTC');$until=$from+3600;
$GLOBALS['hspcc_responses']=[['status'=>200,'body'=>'synthetic-calendar','headers'=>['content-type'=>'text/calendar;charset=UTF-8']]];
check(HSP_Calendar_Connection::report($from,$until)==='synthetic-calendar','REPORT MIME and success');
$last=end($GLOBALS['hspcc_requests']);
check($last['method']==='REPORT'&&$last['depth']==='1'&&$last['redirection']===0&&$last['sslverify']===true,'bounded read-only REPORT settings');
foreach ([['status'=>401,'body'=>'secret must not escape'],['status'=>302,'body'=>''],['status'=>200,'body'=>'<html>no calendar</html>','headers'=>['content-type'=>'text/html']],['status'=>200,'body'=>str_repeat('x',1048576),'headers'=>['content-type'=>'text/calendar']]] as $response) {
    $GLOBALS['hspcc_responses']=[$response];
    check(is_wp_error(HSP_Calendar_Connection::report($from,$until)),'REPORT auth/redirect/MIME/size errors fail closed');
}
$count=count($GLOBALS['hspcc_requests']);
check(is_wp_error(HSP_Calendar_Connection::report($from,$from+32*86400))&&count($GLOBALS['hspcc_requests'])===$count,'REPORT oversized window makes no request');
$GLOBALS['hspcc_options'][HSP_Calendar_Connection::DISCOVERY]['calendars']['synthetic-selected']['url']='https://evil.test/';
check(is_wp_error(HSP_Calendar_Connection::report($from,$until))&&count($GLOBALS['hspcc_requests'])===$count,'REPORT never forwards auth across origins');
echo "PASS: REPORT transport, explicit depth, authentication/redirect/MIME/size/window/origin failures\n";

$GLOBALS['hspcc_options'][HSP_Calendar_Connection::DISCOVERY]['calendars']['synthetic-selected']['url']=HSPCC_TEST_ENDPOINT.'synthetic/';
$GLOBALS['hspcc_options'][HSP_Calendar_Connection::DISCOVERY]['writeSelected']='synthetic-selected';
$suffix='hsp-amelia-11111111-1111-4111-8111-111111111111-1.ics';
$count=count($GLOBALS['hspcc_requests']);
$disabled=invoke_private('calendar_request','synthetic-selected','PUT',$suffix,['If-None-Match'=>'*'],'synthetic',true);
check(is_wp_error($disabled)&&$disabled->get_error_code()==='hspcc_writing_disabled'&&count($GLOBALS['hspcc_requests'])===$count,'disabled flag checked immediately before modifying HTTP');
$GLOBALS['hspcc_options'][HSP_Calendar_Connection::WRITING]=true;
$GLOBALS['hspcc_responses']=[['status'=>201,'body'=>'']];
$enabled=invoke_private('calendar_request','synthetic-selected','PUT',$suffix,['If-None-Match'=>'*'],'synthetic',true);
check(!is_wp_error($enabled)&&count($GLOBALS['hspcc_requests'])===$count+1,'enabled owned conditional PUT reaches transport');
$count=count($GLOBALS['hspcc_requests']);
check(is_wp_error(invoke_private('calendar_request','synthetic-selected','DELETE',$suffix,[],'',true))&&count($GLOBALS['hspcc_requests'])===$count,'unconditional DELETE refused');
check(is_wp_error(invoke_private('calendar_request','synthetic-selected','PUT','foreign.ics',['If-None-Match'=>'*'],'synthetic',true))&&count($GLOBALS['hspcc_requests'])===$count,'foreign resource refused');
echo "PASS: runtime write switch, scoped resource paths and conditional mutation guard\n";
