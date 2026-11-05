<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Actual plugin settings/runtime API; only the existing offline WP
// stubs are reused. No additional HTTP calls, WP bootstrap or real options.
require __DIR__ . '/hspcc-smoke.php';
final class HSPCC_Settings_Wpdb {
    public $options = 'synthetic_options';
    public $last_error = '';
    public function esc_like($value) { return addcslashes($value, '_%\\'); }
    public function prepare($sql, ...$args) { return [$sql, $args]; }
    public function get_col($prepared) {
        [$sql, $args] = $prepared;
        if (!str_starts_with($sql, 'SELECT option_name FROM synthetic_options WHERE option_name LIKE %s')) {
            throw new RuntimeException('Unexpected synthetic option scan.');
        }
        $prefix = stripslashes(substr($args[0], 0, -1));
        $after = $args[1] ?? '';
        $names = array_filter(array_keys($GLOBALS['hspcc_options']), static fn($name) =>
            str_starts_with($name, $prefix) && strcmp($name, $after) > 0);
        sort($names, SORT_STRING);
        return array_slice($names, 0, $args[2] ?? 1001);
    }
}
$settingsChecks = 0;
$settingsBaselineHttp = count($GLOBALS['hspcc_requests']);
function settings_same($expected, $actual, $label) {
    ++$GLOBALS['settingsChecks'];
    if ($expected !== $actual) { fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL); exit(1); }
}
function settings_ok($condition, $label) { settings_same(true, (bool) $condition, $label); }
function settings_error($result, $code, $label) {
    settings_ok(is_wp_error($result) && $result->get_error_code() === $code, $label);
}
function settings_reset($provider = 1, $endpoint = 'https://calendar.example.test/caldav/') {
    $GLOBALS['hspcc_options'] = [];
    HSPCC_Employee_Context::select($provider);
    HSPCC_Employee_Context::remember($provider);
    $GLOBALS['hspcc_admin'] = true;
    $GLOBALS['hspcc_salt'] = 'synthetic-test-salt';
    $GLOBALS['hspcc_settings_error'] = null;
    $GLOBALS['hspcc_responses'] = [];
    $GLOBALS['wpdb'] = new HSPCC_Settings_Wpdb(); // Read-only in-memory option-name scan, never a database.
    (new ReflectionProperty(HSP_Calendar_Connection::class, 'sanitized'))->setValue(null, null);
    (new ReflectionProperty(HSP_Calendar_Connection::class, 'probe_resource'))->setValue(null, '');
    $credentials = ['username' => 'settings-synthetic-user', 'secret' => invoke_private('encrypt', 'synthetic-settings-password'),
        'providerId' => $provider, 'endpoint' => $endpoint];
    $source = hash('sha256', $endpoint . 'source/');
    $destination = hash('sha256', $endpoint . 'destination/');
    $other = hash('sha256', $endpoint . 'other/');
    $GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::OPTION)] = $credentials;
    $GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::DISCOVERY)] = [
        'fingerprint' => invoke_private('fingerprint', $credentials), 'checkedAt' => 123,
        'busySelected' => [$source], 'writeSelected' => $destination,
        'calendars' => [
            $source => ['url' => $endpoint . 'source/', 'name' => 'Synthetic source', 'freeBusy' => true],
            $destination => ['url' => $endpoint . 'destination/', 'name' => 'Synthetic destination', 'freeBusy' => true],
            $other => ['url' => $endpoint . 'other/', 'name' => 'Other synthetic destination', 'freeBusy' => true],
        ],
    ];
    $GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::HEALTH)] = ['ok' => true, 'checkedAt' => 123, 'identity' => 'synthetic-previous-health'];
    $GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::WRITE_HEALTH)] = [
        'ok' => true, 'checkedAt' => 123, 'cleanupComplete' => true,
        'fingerprint' => invoke_private('fingerprint', $credentials), 'calendarKey' => $destination,
    ];
    $GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::BLOCKING)] = true;
    $GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::WRITING)] = true;
    return [$credentials, $source, $destination, $other];
}
function settings_input(array $overrides = []) {
    $old = HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::OPTION, []);
    return array_replace(['endpoint' => $old['endpoint'] ?? '', 'providerId' => $old['providerId'] ?? 0,
        'username' => $old['username'] ?? '', 'password' => ''], $overrides);
}
function settings_save(array $input) {
    $saved = HSP_Calendar_Connection::sanitize($input);
    // The native Settings API persists the callback return after sanitation.
    $GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::OPTION)] = $saved;
    return $saved;
}

[$credentials] = settings_reset();
$baseFingerprint = invoke_private('fingerprint', $credentials);
foreach ([['endpoint' => 'https://other.example.test/caldav/'], ['endpoint' => 'https://calendar.example.test:5006/caldav/'],
    ['endpoint' => 'https://calendar.example.test/different/'], ['providerId' => 7], ['username' => 'different-user'],
    ['secret' => invoke_private('encrypt', 'different-synthetic-password')]] as $change) {
    settings_ok($baseFingerprint !== invoke_private('fingerprint', array_replace($credentials, $change)), 'fingerprint binds each connection identity field');
}
settings_same($baseFingerprint, invoke_private('fingerprint', array_replace($credentials,
    ['endpoint' => 'HTTPS://CALENDAR.EXAMPLE.TEST:443/caldav', 'providerId' => '1'])), 'fingerprint canonicalizes equivalent endpoint/provider representation');
$before = $GLOBALS['hspcc_options'];
$saved = settings_save(settings_input());
settings_same($credentials, $saved, 'unchanged blank-password save preserves encrypted secret');
settings_same($before, $GLOBALS['hspcc_options'], 'unchanged connection preserves choices, health and both runtime gates');
settings_same(false, array_key_exists('password', $saved), 'no plaintext password field in saved options');
settings_same($saved, HSP_Calendar_Connection::sanitize($saved), 'native double sanitation remains idempotent');

foreach ([['endpoint' => 'http://calendar.example.test/caldav/'], ['endpoint' => 'https://user:pass@calendar.example.test/'],
    ['endpoint' => 'https://127.0.0.1/'], ['endpoint' => 'not-a-url'], ['providerId' => 0], ['providerId' => '0'],
    ['providerId' => -1], ['providerId' => 7], ['providerId' => 7.2], ['providerId' => 'not-an-id']] as $invalid) {
    [$credentials] = settings_reset();
    $before = $GLOBALS['hspcc_options'];
    settings_same($credentials, HSP_Calendar_Connection::sanitize(settings_input($invalid)), 'invalid endpoint/employee returns existing connection');
    settings_same($before, $GLOBALS['hspcc_options'], 'invalid endpoint/employee makes no option changes');
    settings_same('endpoint', $GLOBALS['hspcc_settings_error'], 'invalid connection reports fixed settings error');
}
[$credentials] = settings_reset();
$GLOBALS['hspcc_admin'] = false;
$before = $GLOBALS['hspcc_options'];
settings_same($credentials, HSP_Calendar_Connection::sanitize(settings_input(['providerId' => 7])), 'non-administrator cannot change connection');
settings_same($before, $GLOBALS['hspcc_options'], 'unauthorized settings action makes no option changes');

foreach (['https://other.example.test/caldav/', 'https://calendar.example.test:5006/caldav/'] as $newOrigin) {
    [$credentials] = settings_reset();
    $before = $GLOBALS['hspcc_options'];
    settings_same($credentials, HSP_Calendar_Connection::sanitize(settings_input(['endpoint' => $newOrigin])), 'new host/port requires explicit password entry');
    settings_same('new_origin', $GLOBALS['hspcc_settings_error'], 'new-origin error identifies password requirement');
    settings_same($before, $GLOBALS['hspcc_options'], 'new origin never silently forwards retained secret');
}
[$credentials] = settings_reset();
$saved = settings_save(settings_input(['endpoint' => 'https://other.example.test/caldav/', 'password' => 'new-origin-synthetic-password']));
settings_same('https://other.example.test/caldav/', $saved['endpoint'], 'explicit new-origin password permits deliberate connection change');
settings_same('new-origin-synthetic-password', invoke_private('decrypt', $saved['secret']), 'new origin stores newly entered encrypted password');
settings_same(false, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::WRITING), 'new origin disables writing');
settings_same(false, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::BLOCKING), 'new origin disables busy blocking');

foreach (['probe', 'queue'] as $blocker) {
    foreach ([['endpoint' => 'https://calendar.example.test/new-path/'], ['username' => 'new-account']] as $change) {
        [$credentials] = settings_reset();
        if ($blocker === 'probe') { $GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::PROBE)] = ['synthetic' => true]; }
        else { $GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name('hspcc_queue_worker_health')] = 'lock_unavailable'; }
        $before = $GLOBALS['hspcc_options'];
        settings_same($credentials, HSP_Calendar_Connection::sanitize(settings_input($change)), 'probe or queue uncertainty blocks connection migration');
        settings_same('migration', $GLOBALS['hspcc_settings_error'], 'blocked migration reports fixed category');
        settings_same($before, $GLOBALS['hspcc_options'], 'blocked migration retains complete existing state');
    }
}
[$credentials] = settings_reset();
$GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::PROBE)] = ['synthetic' => true];
$before = $GLOBALS['hspcc_options'];
settings_same($credentials, HSP_Calendar_Connection::sanitize(settings_input(['password' => 'replacement-synthetic-password'])), 'probe residue blocks password replacement');
settings_same('probe', $GLOBALS['hspcc_settings_error'], 'password replacement reports pending probe');
settings_same($before, $GLOBALS['hspcc_options'], 'pending probe retains original credentials needed for cleanup');

[$credentials] = settings_reset();
$saved = settings_save(settings_input(['endpoint' => 'https://calendar.example.test/other-caldav/']));
settings_same('https://calendar.example.test/other-caldav/', $saved['endpoint'], 'clean same-origin path change accepted');
settings_same($credentials['secret'], $saved['secret'], 'same-origin path change retains encrypted password');
foreach ([HSP_Calendar_Connection::DISCOVERY, HSP_Calendar_Connection::HEALTH, HSP_Calendar_Connection::WRITE_HEALTH] as $option) {
    settings_same(false, get_option($option), 'changed connection clears stale routing and proof');
}
settings_same(false, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::BLOCKING), 'path change disables busy gate');
settings_same(false, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::WRITING), 'path change disables write gate');
[$credentials] = settings_reset();
$before = $GLOBALS['hspcc_options'];
$saved = settings_save(settings_input(['providerId' => 7]));
settings_same($credentials, $saved, 'employee cannot be moved by a forged provider field');
settings_same('endpoint', $GLOBALS['hspcc_settings_error'], 'employee field must match selected profile');
settings_same($before, $GLOBALS['hspcc_options'], 'cross-profile provider field changes no connection state');

[$credentials, $source, $destination] = settings_reset();
$oldDiscovery = HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::DISCOVERY);
$saved = settings_save(settings_input(['password' => 'rotated-synthetic-password']));
settings_same($oldDiscovery['busySelected'], HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::DISCOVERY)['busySelected'], 'password rotation preserves busy routing');
settings_same($destination, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::DISCOVERY)['writeSelected'], 'password rotation preserves owned destination');
settings_same(invoke_private('fingerprint', $saved), HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::DISCOVERY)['fingerprint'], 'password rotation rebinds discovery to new ciphertext');
settings_same(false, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::WRITE_HEALTH), 'password rotation requires fresh bound write proof');
settings_same(false, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::WRITING), 'password rotation disables writeback until verified again');
settings_same(true, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::BLOCKING), 'password rotation retains failclosed busy guard');

[$credentials] = settings_reset();
$before = $GLOBALS['hspcc_options'];
settings_same(HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::DISCOVERY), invoke_private('discovery'), 'current discovery remains usable without migration');
settings_same(true, invoke_private('write_verified'), 'current bound write proof remains usable');
HSP_Calendar_Connection::boot();
settings_same($before, $GLOBALS['hspcc_options'], 'boot and current configuration reads do not mutate saved settings');

// Obsolete or incorrectly scoped fingerprints are refused, never rebound implicitly.
foreach (['old_fingerprint', 'wrong_employee'] as $invalidConfiguration) {
    [$credentials] = settings_reset();
    if ($invalidConfiguration === 'old_fingerprint') {
        $GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::DISCOVERY)]['fingerprint'] =
            hash('sha256', $credentials['username'] . '|' . $credentials['secret']);
    } else {
        $GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::OPTION)]['providerId'] = 7;
    }
    $before = $GLOBALS['hspcc_options'];
    settings_same([], invoke_private('discovery'), 'obsolete or cross-employee discovery is unavailable');
    settings_same(false, invoke_private('write_verified'), 'obsolete or cross-employee discovery cannot inherit write verification');
    settings_same(false, HSP_Calendar_Connection::write_configuration()['ready'], 'invalid discovery cannot enable calendar writes');
    settings_same($before, $GLOBALS['hspcc_options'], 'invalid configuration is not silently migrated or rewritten');
}

settings_reset();
$GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::OPTION)] = [];
$GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::PROBE)] = ['synthetic' => true];
$GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name('hspcc_queue_worker_health')] = 'lock_unavailable';
settings_same(true, HSP_Calendar_Connection::configure_runtime(false, false), 'administrator can always disable gates on broken connection');
settings_same(false, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::BLOCKING), 'emergency disable clears blocking');
settings_same(false, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::WRITING), 'emergency disable clears writing');
settings_error(HSP_Calendar_Connection::configure_runtime(false, true), 'selection', 'missing employee/sources cannot enable writing');

settings_reset();
unset($GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::WRITE_HEALTH)]);
settings_error(HSP_Calendar_Connection::configure_runtime(false, true), 'write_test', 'unverified destination cannot enable writing');
settings_same(false, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::WRITING), 'unverified gate remains off');
settings_same(false, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::BLOCKING), 'failed runtime change cannot leave old gate active');

[$credentials, $source, $destination] = settings_reset(7);
settings_same(true, HSP_Calendar_Connection::configure_runtime(false, true), 'matching destination, fingerprint and completed probe permit write gate');
settings_same(true, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::WRITING), 'verified write gate saved');
settings_same(false, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::BLOCKING), 'write-only configuration does not enable busy gate');
settings_same(7, HSP_Calendar_Connection::provider_id(), 'runtime proof belongs to configured employee');

foreach (['missing_fingerprint', 'wrong_fingerprint', 'missing_destination', 'wrong_destination', 'missing_cleanup', 'not_ok', 'pending_probe'] as $invalidProof) {
    [$credentials, $source, $destination, $other] = settings_reset();
    $proof = &$GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::WRITE_HEALTH)];
    if ($invalidProof === 'missing_fingerprint') { unset($proof['fingerprint']); }
    if ($invalidProof === 'missing_destination') { unset($proof['calendarKey']); }
    if ($invalidProof === 'wrong_fingerprint') { $proof['fingerprint'] = str_repeat('0', 64); }
    if ($invalidProof === 'wrong_destination') { $proof['calendarKey'] = $other; }
    if ($invalidProof === 'missing_cleanup') { $proof['cleanupComplete'] = false; }
    if ($invalidProof === 'not_ok') { $proof['ok'] = false; }
    if ($invalidProof === 'pending_probe') { $GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::PROBE)] = ['synthetic' => true]; }
    unset($proof);
    settings_error(HSP_Calendar_Connection::configure_runtime(false, true), 'write_test', 'wrong or incomplete proof cannot enable writing');
    settings_same(false, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::WRITING), 'rejected proof leaves writing disabled');
}
settings_reset();
$GLOBALS['hspcc_admin'] = false;
settings_error(HSP_Calendar_Connection::configure_runtime(false, false), 'runtime', 'runtime administration requires capability');
settings_same(true, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::WRITING), 'unauthorized runtime action cannot alter flags');
$GLOBALS['hspcc_admin'] = true;
settings_error(HSP_Calendar_Connection::configure_runtime('false', false), 'runtime', 'runtime API rejects truthy strings');
settings_same(true, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::WRITING), 'invalid runtime arguments make no changes');
settings_same($settingsBaselineHttp, count($GLOBALS['hspcc_requests']), 'settings/runtime proof fixtures make no HTTP calls beyond baseline smoke stubs');

fwrite(STDOUT, 'PASS: ' . $settingsChecks . " actual-main settings/runtime checks; offline stubs only.\n");
