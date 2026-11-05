<?php
/** Isolated release fixtures. No WordPress bootstrap or network requests. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__ . '/');
define('HSPCC_VERSION', '0.5.3');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
class WP_Error {
    public $code;
    public function __construct($code) { $this->code = $code; }
}
$checks = 0;
$filters = $requests = $transients = $expirations = [];
$http = null;
function add_filter($name, $callback, $priority = 10, $accepted = 1) {
    $GLOBALS['filters'][$name][] = [$callback, $priority, $accepted];
}
function plugin_basename($file) { return 'hsp-calendar-connection/' . basename($file); }
function get_site_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_site_transient($key, $value, $expiration = 0) {
    $GLOBALS['transients'][$key] = $value;
    $GLOBALS['expirations'][$key] = $expiration;
    return true;
}
function wp_remote_get($url, $args = []) {
    $GLOBALS['requests'][] = [$url, $args];
    return $GLOBALS['http'];
}
function wp_remote_retrieve_response_code($response) { return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_html__($value, $domain = null) { return esc_html($value); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function wp_kses_post($value) {
    return preg_replace('#<script\b[^>]*>.*?</script>#is', '', strip_tags((string) $value, '<p><strong><em><a><ul><ol><li><br>'));
}
function wpautop($value) { return '<p>' . str_replace("\n\n", '</p><p>', (string) $value) . '</p>'; }
function __($value, $domain = null) { return $value; }
require dirname(__DIR__) . '/includes/class-hspcc-updater.php';
const PLUGIN_FILE = 'hsp-calendar-connection/hsp-calendar-connection.php';
const OTHER_PLUGIN = 'another-plugin/another-plugin.php';
function same($expected, $actual, $label) {
    ++$GLOBALS['checks'];
    if ($expected !== $actual) { fwrite(STDERR, 'FAIL: ' . $label . "\n"); exit(1); }
}
function release_data($version = '0.5.4', $tag = null) {
    $tag = $tag ?? 'v' . $version;
    $name = 'hsp-calendar-connection-' . $version . '.zip';
    return [
        'tag_name' => $tag, 'draft' => false, 'prerelease' => false,
        'body' => "Reviewed changes.\n\n<script>alert('unsafe')</script><strong>Details</strong>",
        'published_at' => '2026-11-05T10:00:00Z',
        'html_url' => 'https://github.com/helmut-steiner/hsp-calendar-connection/releases/tag/' . $tag,
        'zipball_url' => 'https://api.github.com/repos/helmut-steiner/hsp-calendar-connection/zipball/' . $tag,
        'assets' => [[
            'name' => $name, 'state' => 'uploaded',
            'browser_download_url' => 'https://github.com/helmut-steiner/hsp-calendar-connection/releases/download/' . $tag . '/' . $name,
        ]],
    ];
}
function reset_http($release = null, $code = 200) {
    $GLOBALS['requests'] = $GLOBALS['transients'] = $GLOBALS['expirations'] = [];
    $GLOBALS['http'] = ['response' => ['code' => $code], 'body' => json_encode($release ?? release_data())];
}
function update_state($installed = HSPCC_VERSION) {
    return (object) [
        'checked' => [PLUGIN_FILE => $installed, OTHER_PLUGIN => '1.0.0'],
        'response' => [OTHER_PLUGIN => (object) ['new_version' => '2.0.0']],
        'no_update' => ['other-current/current.php' => (object) ['new_version' => '1.0.0']],
    ];
}
function own_update($state) { return $state->response[PLUGIN_FILE] ?? null; }
HSPCC_Updater::init('/synthetic/wp-content/plugins/' . PLUGIN_FILE);
same(true, isset($filters['pre_set_site_transient_update_plugins']), 'native update hook registered');
same(true, isset($filters['plugins_api']), 'native details hook registered');
same(false, isset($filters['auto_update_plugin']), 'automatic installation choice remains native');

reset_http();
$state = HSPCC_Updater::filter_update_transient(update_state());
$update = own_update($state);
same(true, is_object($update), 'newer stable release offered');
same('0.5.4', $update->new_version ?? '', 'version normalized');
same(PLUGIN_FILE, $update->plugin ?? '', 'new plugin folder identity retained');
same('hsp-calendar-connection', $update->slug ?? '', 'plugin slug matches repository');
same(release_data()['assets'][0]['browser_download_url'], $update->package ?? '', 'built runtime asset offered');
same('8.0', $update->requires_php ?? '', 'PHP requirement retained');
same('5.8', $update->requires ?? '', 'native update URI WordPress floor');
same('2.0.0', $state->response[OTHER_PLUGIN]->new_version, 'other plugin update retained');
same('1.0.0', $state->no_update['other-current/current.php']->new_version, 'other current plugin retained');
same(1, count($requests), 'release fetched once');
same('https://api.github.com/repos/helmut-steiner/hsp-calendar-connection/releases/latest', $requests[0][0], 'original upstream queried');
$headers = array_change_key_case($requests[0][1]['headers'] ?? [], CASE_LOWER);
same(false, isset($headers['authorization']), 'no site or CalDAV credentials sent');
same(0, $requests[0][1]['redirection'] ?? null, 'metadata requests do not follow redirects');
same(true, $requests[0][1]['sslverify'] ?? null, 'metadata TLS is verified');
same(262144, $requests[0][1]['limit_response_size'] ?? null, 'metadata response size bounded');
same(HOUR_IN_SECONDS, $expirations[HSPCC_Updater::RELEASE_TRANSIENT] ?? null, 'positive cache lasts one hour');
HSPCC_Updater::filter_update_transient(update_state());
same(1, count($requests), 'valid cache reused');
foreach (['0.5.3', '0.5.2'] as $version) {
    reset_http(release_data($version));
    $input = update_state();
    $input->response[PLUGIN_FILE] = (object) ['new_version' => '99.0.0'];
    $state = HSPCC_Updater::filter_update_transient($input);
    same(null, own_update($state), 'current or older release cannot cause downgrade');
    same(HSPCC_VERSION, $state->no_update[PLUGIN_FILE]->new_version ?? '', 'no-update uses installed version');
    same('', $state->no_update[PLUGIN_FILE]->package ?? null, 'no-update has no package');
}
reset_http(release_data('0.5.4', '0.5.4'));
same('0.5.4', own_update(HSPCC_Updater::filter_update_transient(update_state()))->new_version ?? '', 'plain numeric tag accepted');
reset_http();
$unrelated = (object) ['checked' => [OTHER_PLUGIN => '1.0.0']];
same($unrelated, HSPCC_Updater::filter_update_transient($unrelated), 'unrelated check unchanged');
same(null, HSPCC_Updater::filter_update_transient(null), 'invalid transient unchanged');
same(0, count($requests), 'unrelated checks make no HTTP request');

$invalid = [];
$item = release_data(); $item['draft'] = true; $invalid['draft'] = $item;
$item = release_data(); $item['prerelease'] = true; $invalid['prerelease'] = $item;
foreach (['v0.5.4-beta.1', 'vlatest', 'v0.5.4/../evil', '0.5', 'v00.5.4', 'v0.05.4', ''] as $tag) {
    $item = release_data(); $item['tag_name'] = $tag; $invalid['tag ' . $tag] = $item;
}
$item = release_data(); $item['assets'] = []; $invalid['zipball without runtime asset'] = $item;
$item = release_data(); $item['assets'][0]['state'] = 'new'; $invalid['unfinished upload'] = $item;
$item = release_data(); $item['assets'][0]['name'] = 'source.zip'; $invalid['unrelated ZIP'] = $item;
$item = release_data(); $item['assets'][0]['name'] = 'hsp-calendar-connection-0.5.3.zip'; $invalid['wrong asset version'] = $item;
foreach ([
    'http://github.com/helmut-steiner/hsp-calendar-connection/releases/download/v0.5.4/hsp-calendar-connection-0.5.4.zip',
    'https://github.com.evil.example/helmut-steiner/hsp-calendar-connection/releases/download/v0.5.4/hsp-calendar-connection-0.5.4.zip',
    'https://github.com/other-owner/hsp-calendar-connection/releases/download/v0.5.4/hsp-calendar-connection-0.5.4.zip',
    'https://github.com/helmut-steiner/hsp-calendar-connection/releases/download/v0.5.3/hsp-calendar-connection-0.5.4.zip',
    'https://github.com/helmut-steiner/hsp-calendar-connection/releases/download/v0.5.4/hsp-calendar-connection-0.5.4.zip?token=unexpected',
] as $url) {
    $item = release_data(); $item['assets'][0]['browser_download_url'] = $url; $invalid['unsafe asset ' . $url] = $item;
}
foreach ($invalid as $label => $release) {
    reset_http($release);
    $state = update_state();
    $state->response[PLUGIN_FILE] = (object) ['new_version' => '99.0.0'];
    $state = HSPCC_Updater::filter_update_transient($state);
    same(null, own_update($state), $label . ' rejected and stale offer removed');
    same('2.0.0', $state->response[OTHER_PLUGIN]->new_version, $label . ' preserves unrelated plugin');
}
foreach (['transport', 'not-found', 'rate-limit', 'invalid-json', 'invalid-shape'] as $kind) {
    reset_http();
    if ($kind === 'transport') { $http = new WP_Error('http_request_failed'); }
    elseif ($kind === 'not-found') { $http['response']['code'] = 404; }
    elseif ($kind === 'rate-limit') { $http['response']['code'] = 403; }
    elseif ($kind === 'invalid-json') { $http['body'] = '{'; }
    else { $http['body'] = json_encode('unexpected'); }
    $state = update_state();
    $state->response[PLUGIN_FILE] = (object) ['new_version' => '99.0.0'];
    $state = HSPCC_Updater::filter_update_transient($state);
    same(null, own_update($state), $kind . ' removes stale offer');
    same(true, isset($state->no_update[PLUGIN_FILE]), $kind . ' keeps native auto-update toggle available');
    HSPCC_Updater::filter_update_transient(update_state());
    same(1, count($requests), $kind . ' negative cache prevents repeated HTTP');
    same(10 * MINUTE_IN_SECONDS, $expirations[HSPCC_Updater::RELEASE_TRANSIENT] ?? null, $kind . ' cached for ten minutes');
}
reset_http();
$transients[HSPCC_Updater::RELEASE_TRANSIENT] = ['release' => ['tag_name' => 'v99.0.0']];
same('0.5.4', own_update(HSPCC_Updater::filter_update_transient(update_state()))->new_version ?? '', 'malformed cached metadata is replaced');
same(1, count($requests), 'malformed cache triggers one fresh request');
reset_http();
$transients[HSPCC_Updater::RELEASE_TRANSIENT] = ['release' => []];
$state = HSPCC_Updater::filter_update_transient(update_state());
same(null, own_update($state), 'cached failure remains no-update');
same(0, count($requests), 'cached empty release is a real negative cache');
reset_http();
$sentinel = (object) ['untouched' => true];
same($sentinel, HSPCC_Updater::filter_plugins_api($sentinel, 'query_plugins', (object) ['slug' => 'hsp-calendar-connection']), 'other details action retained');
same($sentinel, HSPCC_Updater::filter_plugins_api($sentinel, 'plugin_information', (object) ['slug' => 'hsp-smart-cache']), 'other plugin details retained');
same(0, count($requests), 'unrelated details make no HTTP');
$info = HSPCC_Updater::filter_plugins_api(false, 'plugin_information', (object) ['slug' => 'hsp-calendar-connection']);
same('HSP Calendar Connection', $info->name ?? '', 'details identify calendar plugin');
same('0.5.4', $info->version ?? '', 'details show release version');
same(release_data()['assets'][0]['browser_download_url'], $info->download_link ?? '', 'details use runtime asset');
same(false, strpos($info->sections['changelog'] ?? '', '<script') !== false, 'release notes cannot inject scripts');
same(true, strpos($info->sections['changelog'] ?? '', '<p>') !== false, 'release notes use paragraph formatting');
same(1, count($requests), 'details only request release metadata');
fwrite(STDOUT, 'PASS: ' . $checks . " GitHub updater assertions; synthetic HTTP/cache state only.\n");
