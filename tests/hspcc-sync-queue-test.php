<?php
if (!function_exists('__')) { function __($text, $domain = '') { return $text; } }
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Synthetic standalone fixtures. No WP bootstrap, real appointments or HTTP.
define('ABSPATH', __DIR__ . '/');
class HSP_Calendar_Connection {
    public static $provider = 1;
    public static $writers = [1];
    public static function provider_id() { return self::$provider; }
    public static function employee_id() { return self::$provider; }
    public static function option_name($base, $id = null) {
        $id = $id ?? self::employee_id();
        if (!is_int($id) || $id < 1) { throw new InvalidArgumentException('Invalid employee'); }
        return $id === 1 ? $base : $base . '_employee_' . $id;
    }
    public static function private_option($base, $default = false) { return get_option(self::option_name($base), $default); }
    public static function set_private_option($base, $value) { return update_option(self::option_name($base), $value, false); }
    public static function delete_private_option($base) { return delete_option(self::option_name($base)); }
    public static function configured_employees($feature = '') { return self::$writers; }
    public static function writing_enabled($id) { return in_array($id, self::$writers, true); }
    public static function with_employee($id, $callback) {
        if (!is_int($id) || $id < 1) { throw new InvalidArgumentException('Invalid employee'); }
        $previous = self::$provider;
        self::$provider = $id;
        try { return $callback(); } finally { self::$provider = $previous; }
    }
}
class WP_Error {
    private $code;
    public function __construct($code, $message = '', $data = null) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
$options = $cron = $hooks = $optionWrites = [];
$updateHook = $addHook = null;
$checks = 0;
function get_option($name, $default = false) { global $options; return $options[$name] ?? $default; }
function update_option($name, $value, $autoload = null) {
    global $options, $optionWrites, $updateHook;
    $optionWrites[] = [$name, $autoload];
    if ($updateHook && $updateHook($name, $value) === false) { return false; }
    $changed = !isset($options[$name]) || $options[$name] !== $value;
    $options[$name] = $value;
    return $changed;
}
function add_option($name, $value, $deprecated = '', $autoload = null) {
    global $options, $optionWrites, $addHook;
    $optionWrites[] = [$name, $autoload];
    if ($addHook && $addHook($name, $value) === false) { return false; }
    if (array_key_exists($name, $options)) { return false; }
    $options[$name] = $value;
    return true;
}
function delete_option($name) { global $options; unset($options[$name]); return true; }
function wp_cache_delete($name, $group = '') { return true; }
function wp_generate_uuid4() { return sprintf('11111111-2222-4333-8444-%012x', random_int(1, 0x7fffffff)); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function add_filter($hook, $callback, $priority = 10, $args = 1) { global $hooks; $hooks[$hook][] = $callback; }
function add_action($hook, $callback, $priority = 10, $args = 1) { add_filter($hook, $callback, $priority, $args); }
function wp_next_scheduled($hook) { global $cron; return $cron[$hook]['time'] ?? false; }
function wp_schedule_event($time, $interval, $hook) { global $cron; $cron[$hook] = compact('time', 'interval'); return true; }
function wp_schedule_single_event($time, $hook) { global $cron; $cron[$hook] = ['time' => $time, 'interval' => false]; return true; }
function wp_clear_scheduled_hook($hook) { global $cron; unset($cron[$hook]); return true; }
class SyntheticWpdb {
    public $options = 'synthetic_options';
    public $queries = [];
    public $connection = 1;
    public $locks = [];
    public $lockUnavailable = false;
    public $scanFailurePrefix = '';
    private $suppressed = false;
    public function esc_like($text) { return addcslashes($text, '_%\\'); }
    public function prepare($sql, ...$args) { return [$sql, $args]; }
    public function suppress_errors($value = true) { $old = $this->suppressed; $this->suppressed = $value; return $old; }
    public function get_var($query) {
        $this->queries[] = $query;
        [$sql, $args] = $query;
        $name = $args[0];
        if (!preg_match('/^hspcc:[a-f0-9]{56}$/D', $name) || !in_array($sql, [
            'SELECT GET_LOCK(%s, 0)', 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', 'SELECT RELEASE_LOCK(%s)'], true)) {
            throw new RuntimeException('Unexpected advisory SQL.');
        }
        if ($this->lockUnavailable) { return null; }
        $held = $this->locks[$name] ?? null;
        if ($sql === 'SELECT GET_LOCK(%s, 0)') {
            if ($held !== null && $held !== $this->connection) { return '0'; }
            $this->locks[$name] = $this->connection;
            return '1';
        }
        if ($sql === 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()') { return $held === $this->connection ? '1' : '0'; }
        if ($held !== $this->connection) { return '0'; }
        unset($this->locks[$name]);
        return '1';
    }
    public function get_col($query) {
        global $options;
        $this->queries[] = $query;
        [$sql, $args] = $query;
        $prefix = substr(str_replace(['\\_', '\\%'], ['_', '%'], $args[0]), 0, -1);
        if (!str_starts_with($sql, 'SELECT option_name FROM synthetic_options WHERE option_name LIKE %s') ||
            !preg_match('/^hspcc_queue_(?:dirty_|state_(?:[1-9][0-9]*_employee_)?)$/D', $prefix) || $args[2] > 1001) {
            throw new RuntimeException('Non-scoped option read.');
        }
        if ($prefix === $this->scanFailurePrefix) { return null; }
        $names = array_filter(array_keys($options), fn($name) => str_starts_with($name, $prefix) && strcmp($name, $args[1]) > 0);
        sort($names, SORT_STRING);
        return array_slice($names, 0, $args[2]);
    }
}
class HSPCC_Amelia_Sync {
    public static $native = [];
    public static $reads = 0;
    public static $hook = null;
    public static function snapshot($id) {
        ++self::$reads;
        if (self::$hook) { return (self::$hook)($id); }
        return self::$native[$id] ?? ['action' => 'delete', 'appointmentId' => $id, 'providerId' => HSP_Calendar_Connection::employee_id()];
    }
}
if (!defined('HSPCC_QUEUE_REAL_STORAGE')) {
class HSPCC_Calendar_Storage {
    public static $enabled = true;
    public static $key;
    public static $jobs = [];
    public static $hook = null;
    public static $recoveries = [];
    public static $remote = [];
    public static $recoverHook = null;
    public static function configured() { return self::$enabled; }
    public static function target_key() { return self::$enabled ? self::$key : ''; }
    public static function sync($job) {
        self::$jobs[] = $job;
        $result = self::$hook ? (self::$hook)($job) : self::receipt($job);
        if (is_array($result)) {
            if ($job['action'] === 'delete') { unset(self::$remote[$job['appointmentId']]); }
            else { self::$remote[$job['appointmentId']] = self::receipt($job); }
        }
        return $result;
    }
    public static function intent($job) {
        $receipt = self::receipt($job);
        $intent = ['siteUuid' => $job['siteUuid'], 'appointmentId' => $job['appointmentId'],
            'calendarKey' => $job['calendarKey'], 'pathSuffix' => $receipt['pathSuffix'], 'uid' => $receipt['uid'],
            'expectedSourceHash' => $receipt['sourceHash']];
        if ($job['mapping'] !== null) {
            $intent['priorSourceHash'] = $job['mapping']['sourceHash'];
            $intent['priorETag'] = $job['mapping']['etag'];
        }
        return $intent;
    }
    public static function recover($intent) {
        self::$recoveries[] = $intent;
        if (self::$recoverHook) { return (self::$recoverHook)($intent); }
        $remote = self::$remote[$intent['appointmentId']] ?? null;
        if ($remote === null) {
            return ['action' => 'recover', 'result' => 'absent', 'calendarKey' => $intent['calendarKey'],
                'pathSuffix' => $intent['pathSuffix'], 'uid' => $intent['uid'], 'etag' => '',
                'sourceHash' => $intent['expectedSourceHash']];
        }
        if (!in_array($remote['sourceHash'], [$intent['expectedSourceHash'], $intent['priorSourceHash'] ?? ''], true)) {
            return new WP_Error('hspcc_storage_conflict');
        }
        $remote['action'] = 'recover'; $remote['result'] = 'recovered';
        return $remote;
    }
    public static function receipt($job) {
        return ['action' => $job['action'], 'result' => $job['action'] === 'delete' ? 'deleted' : 'updated',
            'calendarKey' => $job['calendarKey'], 'pathSuffix' => 'hsp-amelia-' . $job['siteUuid'] . '-' . $job['appointmentId'] . '.ics',
            'uid' => 'hsp-amelia-' . $job['siteUuid'] . '-' . $job['appointmentId'] . '@hsp-calendar-connection.local',
            'etag' => $job['action'] === 'delete' ? '' : '"synthetic-etag-' . $job['sequence'] . '"',
            'sourceHash' => $job['action'] === 'delete' ? $job['mapping']['sourceHash'] : hash('sha256', json_encode([
                $job['siteUuid'], $job['appointmentId'], $job['startUtc'], $job['endUtc'], $job['dtstampUtc'],
                $job['sequence'], $job['serviceLabel'], strtoupper($job['status']),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))];
    }
}
}
require dirname(__DIR__) . '/includes/class-hspcc-sync-queue.php';
function same($expected, $actual, $label) {
    global $checks;
    ++$checks;
    if ($expected !== $actual) { fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL); exit(1); }
}
function ok($actual, $label) { same(true, (bool) $actual, $label); }
function reset_fixture() {
    global $options, $cron, $optionWrites, $updateHook, $addHook, $wpdb;
    $options = $cron = $optionWrites = [];
    $updateHook = $addHook = null;
    $wpdb = new SyntheticWpdb();
    HSP_Calendar_Connection::$provider = 1;
    HSP_Calendar_Connection::$writers = [1];
    HSPCC_Amelia_Sync::$native = [];
    HSPCC_Amelia_Sync::$reads = 0;
    HSPCC_Amelia_Sync::$hook = null;
    HSPCC_Calendar_Storage::$enabled = true;
    HSPCC_Calendar_Storage::$key = str_repeat('a', 64);
    HSPCC_Calendar_Storage::$jobs = [];
    HSPCC_Calendar_Storage::$hook = null;
    HSPCC_Calendar_Storage::$recoveries = [];
    HSPCC_Calendar_Storage::$remote = [];
    HSPCC_Calendar_Storage::$recoverHook = null;
}
function native($id = 11, $shift = 0) {
    return ['action' => 'upsert', 'appointmentId' => $id, 'providerId' => 1,
        'startUtc' => 1800000000 + $shift, 'endUtc' => 1800001800 + $shift,
        'serviceLabel' => 'Synthetic consultation', 'status' => 'confirmed'];
}
function state($id = 11, $employeeId = 1) { return get_option(HSP_Calendar_Connection::option_name('hspcc_queue_state_' . $id, $employeeId)); }
function marker($id = 11, $employeeId = 1) { return get_option(HSP_Calendar_Connection::option_name('hspcc_queue_dirty_' . $id, $employeeId)); }
function expire_retry($id = 11) { global $options; $options['hspcc_queue_state_' . $id]['nextRetryUtc'] = 0; }
function lock_name($scope) { global $wpdb; return 'hspcc:' . substr(hash('sha256', '|' . $wpdb->options . '|' . $scope), 0, 56); }
function run_worker() { return HSPCC_Sync_Queue::process_pending(); }
function successful($id = 11) {
    HSPCC_Amelia_Sync::$native[$id] = native($id);
    HSPCC_Sync_Queue::enqueue($id);
    run_worker();
}

if (defined('HSPCC_QUEUE_LIBRARY_ONLY')) { return; }

reset_fixture();
HSPCC_Sync_Queue::boot();
HSPCC_Sync_Queue::boot();
same(1, count($hooks['hspcc_queue_retry']), 'boot is idempotent');
same(1, count($hooks['shutdown']), 'shutdown scheduling hook only once');
same(300, HSPCC_Sync_Queue::schedules([])['hspcc_five_minutes']['interval'], 'five-minute retry interval');
same(false, HSPCC_Sync_Queue::enqueue(0), 'invalid ID refused');
same([], $options, 'invalid ID creates no option');
ok(HSPCC_Sync_Queue::enqueue(11), 'enqueue durable marker');
$first = marker();
same(0, HSPCC_Amelia_Sync::$reads, 'enqueue does not read native transaction');
same([], HSPCC_Calendar_Storage::$jobs, 'enqueue does no HTTP');
HSPCC_Sync_Queue::shutdown();
same([], HSPCC_Calendar_Storage::$jobs, 'shutdown does no HTTP');
same(2, count($cron), 'one retry plus one delayed kick');
same('hspcc_five_minutes', $cron['hspcc_queue_retry']['interval'], 'recurring native cron scheduled');
ok($cron['hspcc_queue_kick']['time'] >= time() + 9, 'worker is delayed into later request');
HSPCC_Sync_Queue::enqueue(11, true);
ok($first['token'] !== marker()['token'], 'enqueue rotates immutable marker token');
same(['token', 'queuedUtc', 'employeeId'], array_keys(marker()), 'marker has only technical owner metadata and no native payload');
same(false, state(), 'enqueue never edits worker state');

reset_fixture();
HSPCC_Calendar_Storage::$enabled = false;
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
run_worker();
same([], HSPCC_Calendar_Storage::$jobs, 'unconfigured writer refuses all writes');
same(0, HSPCC_Amelia_Sync::$reads, 'unconfigured worker does not inspect appointments');
same(1, HSPCC_Sync_Queue::summary()['pending'], 'unconfigured marker stays pending');
$site = HSPCC_Sync_Queue::site_uuid();
same($site, HSPCC_Sync_Queue::site_uuid(), 'site identity stable while writes disabled');
same($site, get_option('hsp_calendar_site_uuid'), 'shared site UUID option name');
HSPCC_Calendar_Storage::$enabled = true;
run_worker();
same(1, count(HSPCC_Calendar_Storage::$jobs), 'configuration resumes queued job');
same(0, HSPCC_Sync_Queue::summary()['pending'], 'success acknowledged');
same(1, HSPCC_Sync_Queue::summary()['managed'], 'managed count includes acknowledged remote mapping');
same($site, HSPCC_Calendar_Storage::$jobs[0]['siteUuid'], 'storage uses shared stable site UUID');
same(0, state()['sequence'], 'first projection sequence zero');
ok(is_int(state()['dtstampUtc']), 'DTSTAMP persisted as integer UTC seconds');
same(marker()['token'], state()['ackToken'], 'worker acknowledges observed token');
same(['calendarKey', 'pathSuffix', 'uid', 'etag', 'sourceHash'], array_keys(state()['mapping']), 'only mapping receipt allowlist persisted');
same([], array_values(array_filter($optionWrites, fn($write) => $write[1] !== false)), 'all owned options explicitly non-autoload');
$version = state();
run_worker();
same(1, count(HSPCC_Calendar_Storage::$jobs), 'clean unchanged tracked ID avoids duplicate HTTP');
HSPCC_Sync_Queue::enqueue(11);
run_worker();
same(1, count(HSPCC_Calendar_Storage::$jobs), 'duplicate hook avoids duplicate HTTP');
same($version['sequence'], state()['sequence'], 'duplicate native hash keeps sequence');
same($version['dtstampUtc'], state()['dtstampUtc'], 'duplicate native hash keeps DTSTAMP');
same(marker()['token'], state()['ackToken'], 'duplicate hook token is acknowledged');
same(false, str_contains(json_encode($options), 'Synthetic consultation'), 'canonical service label never persisted');
same(false, str_contains(json_encode($options), '1800001800'), 'appointment start/end never persisted');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11, true);
run_worker();
same('upsert', HSPCC_Calendar_Storage::$jobs[0]['action'], 'current native state wins stale deletion hint');
HSPCC_Amelia_Sync::$native[11] = native(11, 3600);
run_worker();
same(2, count(HSPCC_Calendar_Storage::$jobs), 'tracked reconciliation catches missed hook');
same(1, state()['sequence'], 'new canonical hash increments sequence');
ok(HSPCC_Calendar_Storage::$jobs[1]['dtstampUtc'] > HSPCC_Calendar_Storage::$jobs[0]['dtstampUtc'], 'new version DTSTAMP increases even same second');
same(HSPCC_Calendar_Storage::$jobs[0]['appointmentId'], HSPCC_Calendar_Storage::$jobs[1]['appointmentId'], 'appointment identity stable');
same('"synthetic-etag-0"', HSPCC_Calendar_Storage::$jobs[1]['mapping']['etag'], 'prior ETag injected into update');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
$observed = marker()['token'];
HSPCC_Calendar_Storage::$hook = function ($job) {
    HSPCC_Amelia_Sync::$native[11] = native(11, 7200);
    HSPCC_Sync_Queue::enqueue(11);
    return HSPCC_Calendar_Storage::receipt($job);
};
run_worker();
same($observed, state()['ackToken'], 'HTTP completion only acknowledges token it observed');
ok(marker()['token'] !== state()['ackToken'], 'concurrent enqueue survives worker acknowledgement');
same(1, HSPCC_Sync_Queue::summary()['pending'], 'concurrent enqueue stays visible pending');
HSPCC_Calendar_Storage::$hook = null;
run_worker();
same(1800007200, HSPCC_Calendar_Storage::$jobs[1]['startUtc'], 'next worker projects latest native time');
same(0, HSPCC_Sync_Queue::summary()['pending'], 'latest state eventually acknowledged');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
HSPCC_Calendar_Storage::$hook = fn($job) => new WP_Error('remote-private-credential-like-code', 'private body', ['raw' => 'private']);
run_worker();
$failed = state();
same('sync_failed', $failed['errorCode'], 'untrusted error code/message reduced to fixed local category');
same(1, $failed['attempts'], 'first failure counted');
ok($failed['nextRetryUtc'] >= time() + 299, 'first retry delayed five minutes');
same(1, HSPCC_Sync_Queue::summary()['retrying'], 'retry counter exposed');
same(false, str_contains(json_encode($options), 'private'), 'error body/code/data not persisted');
run_worker();
same(1, count(HSPCC_Calendar_Storage::$jobs), 'unchanged failed job respects backoff');
same(2, HSPCC_Amelia_Sync::$reads, 'canonical reconciliation still reads during backoff');
expire_retry();
run_worker();
same(2, state()['attempts'], 'retry failure increments attempts');
same($failed['sequence'], HSPCC_Calendar_Storage::$jobs[1]['sequence'], 'retry reuses exact sequence');
same($failed['dtstampUtc'], HSPCC_Calendar_Storage::$jobs[1]['dtstampUtc'], 'retry reuses exact DTSTAMP');
ok(state()['nextRetryUtc'] >= time() + 599, 'retry backs off exponentially');
HSPCC_Amelia_Sync::$native[11] = native(11, 1);
HSPCC_Calendar_Storage::$hook = null;
run_worker();
same(3, count(HSPCC_Calendar_Storage::$jobs), 'new native hash bypasses stale backoff without hook');
same(1, state()['sequence'], 'corrected desired version increments once');
same(0, state()['attempts'], 'successful recovery resets attempts');
same('', state()['errorCode'], 'successful recovery clears sanitized error');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
HSPCC_Calendar_Storage::$hook = fn($job) => new WP_Error('hspcc_storage_transport');
for ($attempt = 0; $attempt < 10; ++$attempt) { if (state()) { expire_retry(); } run_worker(); }
same(10, state()['attempts'], 'durable retry attempts survive repeated worker requests');
ok(state()['nextRetryUtc'] <= time() + 21600, 'retry delay capped at six hours');
same(0, state()['sequence'], 'outage retries never advance sequence');
same(1, count(array_unique(array_column(HSPCC_Calendar_Storage::$jobs, 'dtstampUtc'))), 'outage retries retain one DTSTAMP');

reset_fixture();
successful();
$before = state();
HSPCC_Amelia_Sync::$native[11] = native(11, 3600);
HSPCC_Calendar_Storage::$hook = fn($job) => new WP_Error('hspcc_storage_transport', 'secret response');
run_worker();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Calendar_Storage::$hook = null;
run_worker();
same(3, count(HSPCC_Calendar_Storage::$jobs), 'return to old acknowledged hash still repairs uncertain intermediate write');
same(2, state()['sequence'], 'rollback to earlier native state creates a new projection version');
same($before['successHash'], state()['successHash'], 'repaired native canonical success hash matches current state');

reset_fixture();
successful();
unset(HSPCC_Amelia_Sync::$native[11]);
HSPCC_Sync_Queue::enqueue(11, true);
run_worker();
same('delete', HSPCC_Calendar_Storage::$jobs[1]['action'], 'native tombstone deletes previously mapped resource');
ok(is_array(HSPCC_Calendar_Storage::$jobs[1]['mapping']), 'delete receives previous ownership mapping');
same(null, state()['mapping'], 'successful delete clears mapping');
same(0, HSPCC_Sync_Queue::summary()['managed'], 'deleted remote mapping excluded from managed count');
same(1, state()['sequence'], 'delete advances stable desired version');
run_worker();
same(2, count(HSPCC_Calendar_Storage::$jobs), 'unchanged tombstone does not repeat DELETE');
HSPCC_Amelia_Sync::$native[11] = native();
run_worker();
same(3, count(HSPCC_Calendar_Storage::$jobs), 'restored native appointment is recreated by reconciliation');
same(null, HSPCC_Calendar_Storage::$jobs[2]['mapping'], 'restoration has no stale deleted ETag');
same(2, state()['sequence'], 'restoration advances sequence');
HSPCC_Amelia_Sync::$native[11] = ['action' => 'delete', 'appointmentId' => 11, 'providerId' => 2];
HSPCC_Sync_Queue::enqueue(11);
run_worker();
same('delete', HSPCC_Calendar_Storage::$jobs[3]['action'], 'moving away from provider1 removes only prior managed event');
reset_fixture();
HSPCC_Sync_Queue::enqueue(11, true);
run_worker();
same([], HSPCC_Calendar_Storage::$jobs, 'unmapped tombstone performs no remote request');
same(0, HSPCC_Sync_Queue::summary()['pending'], 'unmapped tombstone acknowledged');

reset_fixture();
successful();
$oldMap = state()['mapping'];
HSPCC_Calendar_Storage::$key = str_repeat('b', 64);
HSPCC_Sync_Queue::enqueue(11);
run_worker();
same(1, count(HSPCC_Calendar_Storage::$jobs), 'target change never migrates or deletes old resource automatically');
same($oldMap, state()['mapping'], 'target change retains owned mapping');
same('target_changed', state()['errorCode'], 'target change requires manual migration');
same(1, HSPCC_Sync_Queue::summary()['conflicts'], 'target change counted as conflict');
same(1, HSPCC_Sync_Queue::summary()['pending'], 'target change remains pending');
run_worker();
same(1, state()['attempts'], 'unchanged manual-migration conflict respects backoff');

reset_fixture();
successful();
$oldMap = state()['mapping'];
HSPCC_Amelia_Sync::$native[11] = native(11, 10);
HSPCC_Calendar_Storage::$hook = fn($job) => new WP_Error('hspcc_storage_ownership_mismatch', 'remote PII');
run_worker();
same('conflict', state()['errorCode'], 'ownership mismatch categorized as conflict');
same($oldMap, state()['mapping'], 'conflict keeps acknowledged mapping');
same(1, HSPCC_Sync_Queue::summary()['errors'], 'conflict also counted as error');
same(false, str_contains(json_encode($options), 'remote PII'), 'conflict details not stored');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
HSPCC_Calendar_Storage::$hook = function ($job) { $receipt = HSPCC_Calendar_Storage::receipt($job); $receipt['pathSuffix'] = '../foreign.ics'; return $receipt; };
run_worker();
same('invalid_receipt', state()['errorCode'], 'foreign receipt path rejected');
same(null, state()['mapping'], 'invalid receipt not persisted');
HSPCC_Calendar_Storage::$hook = function ($job) { $receipt = HSPCC_Calendar_Storage::receipt($job); $receipt['extraCustomerPayload'] = 'never store'; return $receipt; };
expire_retry();
run_worker();
same(false, str_contains(json_encode($options), 'never store'), 'extra receipt payload stripped');
same(0, HSPCC_Sync_Queue::summary()['pending'], 'valid allowlisted receipt accepted');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
$updateHook = fn($name, $value) => !str_starts_with($name, 'hspcc_queue_state_');
run_worker();
same([], HSPCC_Calendar_Storage::$jobs, 'failed durable pre-write version prevents HTTP');
same(1, HSPCC_Sync_Queue::summary()['pending'], 'failed option write leaves job pending');
$updateHook = null;
run_worker();
same(1, count(HSPCC_Calendar_Storage::$jobs), 'durable write recovery resumes job');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
$updateHook = function ($name, $value) {
    if (str_starts_with($name, 'hspcc_queue_lock_')) { throw new RuntimeException('private option-hook detail'); }
    return true;
};
run_worker();
same([], HSPCC_Calendar_Storage::$jobs, 'lease metadata exception prevents HTTP');
same([], $wpdb->locks, 'failed lease setup releases its connection advisory lock in finally');
same(false, str_contains(json_encode($options), 'private option-hook'), 'lease setup exception details never persisted');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
$options['hspcc_queue_lock_11'] = ['owner' => wp_generate_uuid4(), 'expiresUtc' => time() + 200];
$wpdb->locks[lock_name('appointment:11')] = 2;
same(1, run_worker()['locked'], 'active worker lease excludes another worker');
same([], HSPCC_Calendar_Storage::$jobs, 'active lease blocks HTTP');
$options['hspcc_queue_lock_11']['expiresUtc'] = time() - 1;
same(1, run_worker()['locked'], 'expired metadata cannot steal a live connection advisory lock');
unset($wpdb->locks[lock_name('appointment:11')]);
run_worker();
same(1, count(HSPCC_Calendar_Storage::$jobs), 'orphaned expired metadata can recover after actual connection releases');
same(false, get_option('hspcc_queue_lock_11'), 'owned lease released');
same([], $wpdb->locks, 'worker releases its advisory locks');
reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
$otherOwner = wp_generate_uuid4();
HSPCC_Calendar_Storage::$hook = function ($job) use ($otherOwner) {
    global $options;
    $options['hspcc_queue_lock_11'] = ['owner' => $otherOwner, 'expiresUtc' => time() + 300];
    return HSPCC_Calendar_Storage::receipt($job);
};
run_worker();
same('', state()['successHash'], 'worker that loses lease cannot acknowledge HTTP result');
same(null, state()['mapping'], 'worker that loses lease cannot overwrite mapping');
same($otherOwner, get_option('hspcc_queue_lock_11')['owner'], 'losing worker cannot release new owner lease');
same(1, HSPCC_Sync_Queue::summary()['pending'], 'uncertain result stays pending');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
HSPCC_Calendar_Storage::$hook = function ($job) {
    global $wpdb;
    $wpdb->connection = 2;
    same('0', $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', lock_name('appointment:11'))), 'another DB connection cannot acquire active worker lock');
    same('0', $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', lock_name('appointment:11'))), 'another DB connection cannot release worker lock');
    $wpdb->connection = 1;
    return HSPCC_Calendar_Storage::receipt($job);
};
run_worker();
same(0, HSPCC_Sync_Queue::summary()['pending'], 'serialized worker completes normally');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
HSPCC_Calendar_Storage::$hook = function ($job) {
    global $wpdb;
    $wpdb->locks = []; // Database connection was lost while HTTP was in flight.
    $wpdb->connection = 2;
    return HSPCC_Calendar_Storage::receipt($job);
};
run_worker();
same('', state()['successHash'], 'reconnected DB session cannot acknowledge without original advisory lock');
same(1, HSPCC_Sync_Queue::summary()['pending'], 'DB reconnection leaves durable desired version pending');
HSPCC_Calendar_Storage::$hook = null;
run_worker();
same(0, HSPCC_Sync_Queue::summary()['pending'], 'later owner retries after lost DB connection');
same(0, state()['sequence'], 'lost-connection retry reuses persisted version');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
$wpdb->lockUnavailable = true;
run_worker();
same([], HSPCC_Calendar_Storage::$jobs, 'unsupported advisory locks fail closed before HTTP');
same(false, state(), 'unsupported advisory locks never mutate worker state');
same('lock_unavailable', HSPCC_Sync_Queue::summary()['workerError'], 'unsupported locking surfaces fixed sanitized health code');
same(1, HSPCC_Sync_Queue::summary()['pending'], 'unsupported locking retains pending durable marker');
$wpdb->lockUnavailable = false;
run_worker();
same('', HSPCC_Sync_Queue::summary()['workerError'], 'locking recovery clears fixed health code');
same(0, HSPCC_Sync_Queue::summary()['pending'], 'locking recovery resumes queued work');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
HSPCC_Calendar_Storage::$hook = function ($job) { throw new RuntimeException('secret response text'); };
run_worker();
same('sync_failed', state()['errorCode'], 'storage exception is sanitized');
same(false, str_contains(json_encode($options), 'secret response'), 'exception text never persisted');
same(false, get_option('hspcc_queue_lock_11'), 'throwing storage still releases worker lease');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
$options['hspcc_queue_state_11'] = ['mapping' => ['private' => 'do not destroy']];
run_worker();
same([], HSPCC_Calendar_Storage::$jobs, 'corrupted state fails closed');
same(['mapping' => ['private' => 'do not destroy']], state(), 'corrupted state is not reset or overwritten');
same(1, HSPCC_Sync_Queue::summary()['errors'], 'corrupted state visible in aggregate health');
reset_fixture();
HSPCC_Sync_Queue::enqueue(11);
HSPCC_Amelia_Sync::$hook = fn($id) => new WP_Error('database', 'raw native data');
run_worker();
same('snapshot_failed', state()['errorCode'], 'snapshot errors never imply delete');
same([], HSPCC_Calendar_Storage::$jobs, 'snapshot failure sends no remote request');
same(false, str_contains(json_encode($options), 'raw native'), 'native error details not persisted');
reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Amelia_Sync::$native[11]['providerId'] = 2;
HSPCC_Sync_Queue::enqueue(11);
run_worker();
same([], HSPCC_Calendar_Storage::$jobs, 'non-provider-1 upsert refused');
same('snapshot_failed', state()['errorCode'], 'unsupported canonical shape fails closed');
run_worker();
same(1, state()['attempts'], 'unsupported snapshot respects existing backoff');

reset_fixture();
for ($id = 1; $id <= 7; ++$id) { HSPCC_Amelia_Sync::$native[$id] = native($id); HSPCC_Sync_Queue::enqueue($id); }
same(5, run_worker()['synced'], 'worker attempts at most five remote jobs');
same(2, HSPCC_Sync_Queue::summary()['pending'], 'bounded batch preserves unprocessed markers');
same(2, run_worker()['synced'], 'scan cursor eventually reaches remaining jobs');
same(0, HSPCC_Sync_Queue::summary()['pending'], 'bounded batches eventually reconcile all markers');
reset_fixture();
for ($id = 1; $id <= 105; ++$id) {
    HSPCC_Sync_Queue::enqueue($id);
    $options['hspcc_queue_lock_' . $id] = ['owner' => wp_generate_uuid4(), 'expiresUtc' => time() + 300];
    $wpdb->locks[lock_name('appointment:' . $id)] = 2;
}
same(100, run_worker()['checked'], 'one worker scans at most one hundred tracked IDs');
same(5, run_worker()['checked'], 'next scan continues beyond first page');
same([], HSPCC_Calendar_Storage::$jobs, 'held leases cause no HTTP even with many markers');
ok(count($wpdb->queries) > 0, 'only plugin-owned option names enumerated by scoped SELECT');

reset_fixture();
for ($id = 1; $id <= 100; ++$id) { $options['hspcc_queue_dirty_' . str_pad((string) $id, 4, '0', STR_PAD_LEFT)] = []; }
HSPCC_Amelia_Sync::$native[9999] = native(9999);
HSPCC_Sync_Queue::enqueue(9999);
same(0, run_worker()['checked'], 'malformed option names are not native IDs');
same(1, run_worker()['synced'], 'malformed option names cannot starve valid later jobs');

reset_fixture();
for ($id = 1; $id <= 1001; ++$id) {
    $options['hspcc_queue_dirty_' . $id] = ['token' => wp_generate_uuid4(), 'queuedUtc' => time()];
}
same(true, HSPCC_Sync_Queue::summary()['limited'], 'admin summary declares its thousand-ID bound');
same(1000, HSPCC_Sync_Queue::summary()['tracked'], 'admin summary bounded count is explicit');
HSPCC_Sync_Queue::enqueue(11);
HSPCC_Sync_Queue::deactivate();
same([], $cron, 'deactivation clears retry and kick hooks');
ok(is_array(marker()), 'deactivation retains durable queued markers');

reset_fixture();
$winner = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
$addHook = function ($name, $value) use ($winner) {
    global $options;
    if ($name === 'hsp_calendar_site_uuid') { $options[$name] = $winner; return false; }
    return true;
};
same($winner, HSPCC_Sync_Queue::site_uuid(), 'identity initialization reads persisted winner rather than candidate');
$addHook = null;
$options['hsp_calendar_site_uuid'] = 'invalid-identity';
same('', HSPCC_Sync_Queue::site_uuid(), 'corrupt site identity is not silently regenerated');
same('invalid-identity', get_option('hsp_calendar_site_uuid'), 'identity corruption leaves existing remote ownership intact');

// A response can be lost after the NAS has committed the write. These fixtures
// preserve only technical intent and prove newer native states cannot orphan it.
reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
HSPCC_Calendar_Storage::$hook = function ($job) {
    same(HSPCC_Calendar_Storage::intent($job), state()['intent'], 'technical write intent durable before HTTP');
    HSPCC_Calendar_Storage::$remote[11] = HSPCC_Calendar_Storage::receipt($job);
    return new WP_Error('hspcc_storage_transport', 'lost create response');
};
run_worker();
$uncertain = state()['intent'];
same(null, state()['mapping'], 'lost create response has no acknowledged ETag');
same(1, HSPCC_Sync_Queue::summary()['unresolved'], 'uncertain create intent visible in summary');
same(['siteUuid', 'appointmentId', 'calendarKey', 'pathSuffix', 'uid', 'expectedSourceHash'], array_keys($uncertain), 'unmapped create intent contains only technical identity/hash');
same(false, str_contains(json_encode($options), 'Synthetic consultation'), 'uncertain intent stores no service text');
same(false, str_contains(json_encode($options), '1800000000'), 'uncertain intent stores no event time');
unset(HSPCC_Amelia_Sync::$native[11]);
HSPCC_Sync_Queue::enqueue(11, true);
HSPCC_Calendar_Storage::$hook = null;
run_worker();
same($uncertain, HSPCC_Calendar_Storage::$recoveries[0], 'cancellation first recovers the precise earlier attempted fingerprint');
same('delete', HSPCC_Calendar_Storage::$jobs[1]['action'], 'cancel after uncertain create deletes recovered owned resource');
same('"synthetic-etag-0"', HSPCC_Calendar_Storage::$jobs[1]['mapping']['etag'], 'cancel uses actual recovered ETag');
same([], HSPCC_Calendar_Storage::$remote, 'cancelled uncertain create leaves no orphan remote resource');
same(null, state()['intent'], 'successful cancellation clears unresolved intent');
same(null, state()['mapping'], 'successful cancellation clears recovered mapping');
same(0, HSPCC_Sync_Queue::summary()['pending'], 'uncertain create cancellation fully acknowledged');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
HSPCC_Calendar_Storage::$hook = function ($job) {
    HSPCC_Calendar_Storage::$remote[11] = HSPCC_Calendar_Storage::receipt($job);
    return new WP_Error('hspcc_storage_transport');
};
run_worker();
$uncertain = state()['intent'];
HSPCC_Amelia_Sync::$native[11] = native(11, 3600);
HSPCC_Calendar_Storage::$hook = null;
run_worker();
same($uncertain, HSPCC_Calendar_Storage::$recoveries[0], 'changed native hash does not overwrite earlier unresolved fingerprint');
same(1, HSPCC_Calendar_Storage::$jobs[1]['sequence'], 'new native state follows recovered projection with next sequence');
same('"synthetic-etag-0"', HSPCC_Calendar_Storage::$jobs[1]['mapping']['etag'], 'reschedule uses recovered prior version ETag');
same(1800003600, HSPCC_Calendar_Storage::$jobs[1]['startUtc'], 'latest rescheduled time is eventually written');
same(0, HSPCC_Sync_Queue::summary()['unresolved'], 'reschedule resolves earlier ambiguous write');

reset_fixture();
successful();
$oldMap = state()['mapping'];
HSPCC_Amelia_Sync::$native[11] = native(11, 3600);
HSPCC_Calendar_Storage::$hook = function ($job) {
    HSPCC_Calendar_Storage::$remote[11] = HSPCC_Calendar_Storage::receipt($job);
    return new WP_Error('hspcc_storage_transport');
};
run_worker();
$uncertain = state()['intent'];
same($oldMap['sourceHash'], $uncertain['priorSourceHash'], 'uncertain update remembers prior acknowledged source hash');
same($oldMap['etag'], $uncertain['priorETag'], 'uncertain update remembers prior acknowledged ETag');
same($oldMap, state()['mapping'], 'uncertain update preserves old acknowledged receipt until recovery');
unset(HSPCC_Amelia_Sync::$native[11]);
HSPCC_Sync_Queue::enqueue(11, true);
HSPCC_Calendar_Storage::$hook = null;
run_worker();
same('"synthetic-etag-1"', HSPCC_Calendar_Storage::$jobs[2]['mapping']['etag'], 'cancellation uses recovered attempted update ETag rather than stale prior ETag');
same(2, state()['sequence'], 'cancel follows ambiguous updated version monotonically');
same([], HSPCC_Calendar_Storage::$remote, 'uncertain update cancellation leaves no resource');

reset_fixture();
successful();
unset(HSPCC_Amelia_Sync::$native[11]);
HSPCC_Sync_Queue::enqueue(11, true);
HSPCC_Calendar_Storage::$hook = function ($job) {
    unset(HSPCC_Calendar_Storage::$remote[11]);
    return new WP_Error('hspcc_storage_transport');
};
run_worker();
ok(is_array(state()['intent']), 'uncertain delete retains recovery intent');
ok(is_array(state()['mapping']), 'uncertain delete retains acknowledged mapping until recovery');
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
HSPCC_Calendar_Storage::$hook = null;
run_worker();
same(null, HSPCC_Calendar_Storage::$jobs[2]['mapping'], '404 after uncertain delete clears stale mapping before restoration');
same(2, state()['sequence'], 'restoration follows uncertain deletion with next version');
ok(isset(HSPCC_Calendar_Storage::$remote[11]), 'restored appointment recreated after confirmed remote absence');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
HSPCC_Calendar_Storage::$hook = fn($job) => new WP_Error('hspcc_storage_transport');
run_worker();
$oldVersion = state();
HSPCC_Amelia_Sync::$native[11] = native(11, 7200);
HSPCC_Calendar_Storage::$recoverHook = fn($intent) => new WP_Error('hspcc_storage_transport', 'private recovery body');
run_worker();
same($oldVersion['intent'], state()['intent'], 'failed recovery keeps original unresolved technical intent');
same($oldVersion['desiredHash'], state()['desiredHash'], 'failed recovery does not replace earlier desired hash');
same($oldVersion['sequence'], state()['sequence'], 'failed recovery does not consume newer projection sequence');
same(1, count(HSPCC_Calendar_Storage::$jobs), 'failed recovery prevents sending newer native projection');
same(false, str_contains(json_encode($options), 'private recovery'), 'recovery error details never persisted');
run_worker();
same(1, count(HSPCC_Calendar_Storage::$recoveries), 'unchanged newer native hash respects failed-recovery backoff');
same(1, HSPCC_Sync_Queue::summary()['unresolved'], 'failed recovery remains visible unresolved');
expire_retry();
HSPCC_Calendar_Storage::$recoverHook = null;
HSPCC_Calendar_Storage::$hook = null;
run_worker();
same(1, state()['sequence'], 'successful later recovery then projects latest desired state once');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
HSPCC_Calendar_Storage::$hook = fn($job) => new WP_Error('hspcc_storage_transport');
run_worker();
$uncertain = state()['intent'];
HSPCC_Calendar_Storage::$recoverHook = fn($intent) => new WP_Error('hspcc_storage_conflict');
unset(HSPCC_Amelia_Sync::$native[11]);
HSPCC_Sync_Queue::enqueue(11, true);
run_worker();
same('conflict', state()['errorCode'], 'foreign recovery fingerprint requires manual conflict resolution');
same($uncertain, state()['intent'], 'conflicting recovery keeps unresolved fingerprint intact');
same(1, count(HSPCC_Calendar_Storage::$jobs), 'conflicting recovery never proceeds to delete');
same(1, HSPCC_Sync_Queue::summary()['conflicts'], 'unresolved recovery conflict appears in admin aggregate');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
$updateHook = fn($name, $value) => !(str_starts_with($name, 'hspcc_queue_state_') && ($value['intent'] ?? null) !== null);
run_worker();
same([], HSPCC_Calendar_Storage::$jobs, 'failed intent persistence prevents any remote write');
same(null, state()['intent'], 'failed intent save cannot claim durable recovery metadata');
$updateHook = null;
run_worker();
same(0, HSPCC_Sync_Queue::summary()['pending'], 'intent persistence recovery resumes same stable version');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
$updateHook = fn($name, $value) => !(str_starts_with($name, 'hspcc_queue_state_') && ($value['successHash'] ?? '') !== '');
run_worker();
ok(isset(HSPCC_Calendar_Storage::$remote[11]), 'remote write may succeed before acknowledgement save fails');
same('', state()['successHash'], 'failed acknowledgement does not pretend to be durable');
ok(is_array(state()['intent']), 'failed acknowledgement preserves previously durable recovery intent');
$updateHook = null;
unset(HSPCC_Amelia_Sync::$native[11]);
HSPCC_Sync_Queue::enqueue(11, true);
run_worker();
same([], HSPCC_Calendar_Storage::$remote, 'cancellation recovers and removes write whose acknowledgement failed');
same(0, HSPCC_Sync_Queue::summary()['pending'], 'failed-ack recovery eventually converges');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
HSPCC_Calendar_Storage::$hook = function ($job) {
    HSPCC_Calendar_Storage::$remote[11] = HSPCC_Calendar_Storage::receipt($job);
    return new WP_Error('hspcc_storage_transport');
};
run_worker();
$uncertain = state()['intent'];
unset(HSPCC_Amelia_Sync::$native[11]);
HSPCC_Sync_Queue::enqueue(11, true);
HSPCC_Calendar_Storage::$hook = null;
$updateHook = fn($name, $value) => !(str_starts_with($name, 'hspcc_queue_state_') && ($value['intent'] ?? null) === null && ($value['mapping'] ?? null) !== null);
run_worker();
same(1, count(HSPCC_Calendar_Storage::$jobs), 'failed recovered-mapping save prevents subsequent DELETE');
same($uncertain, state()['intent'], 'failed recovered-mapping save leaves original intent recoverable');
$updateHook = null;
run_worker();
same([], HSPCC_Calendar_Storage::$remote, 'later recovered-mapping durability permits safe cleanup');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
HSPCC_Calendar_Storage::$hook = fn($job) => new WP_Error('hspcc_storage_transport');
run_worker();
$uncertain = state()['intent'];
HSPCC_Calendar_Storage::$key = str_repeat('b', 64);
HSPCC_Sync_Queue::enqueue(11);
run_worker();
same([], HSPCC_Calendar_Storage::$recoveries, 'target change refuses even recovery GET against a different collection');
same($uncertain, state()['intent'], 'target change retains unresolved old-target intent');
same('target_changed', state()['errorCode'], 'unmapped uncertain target migration is blocked explicitly');

reset_fixture();
HSP_Calendar_Connection::$provider = 7;
HSP_Calendar_Connection::$writers = [7];
$employee7 = native(); $employee7['providerId'] = 7;
HSPCC_Amelia_Sync::$native[11] = $employee7;
HSPCC_Sync_Queue::enqueue(11, false, 7);
same(1, run_worker()['synced'], 'configured employee7 projection is accepted');
same(1, count(HSPCC_Calendar_Storage::$jobs), 'configured employee7 causes one remote projection');
HSPCC_Amelia_Sync::$native[11] = native(); // Explicit unrelated employee1 upsert must not be accepted.
HSPCC_Sync_Queue::enqueue(11, false, 7);
same(1, run_worker()['failed'], 'unrelated employee1 upsert cannot pass queue contract');
same(1, count(HSPCC_Calendar_Storage::$jobs), 'invalid employee1 upsert makes no remote request');
ok(state(11, 7)['mapping'] !== null, 'invalid employee1 upsert preserves prior employee7 mapping');
HSPCC_Amelia_Sync::$native[11] = ['action' => 'delete', 'appointmentId' => 11, 'providerId' => 1];
HSPCC_Sync_Queue::enqueue(11, true, 7);
same(1, run_worker()['synced'], 'canonical moved-away employee1 tombstone can clean owned mapping');
same([], HSPCC_Calendar_Storage::$remote, 'moved-away employee cleanup removes only tracked owned projection');
reset_fixture();
HSP_Calendar_Connection::$writers = [7];
HSPCC_Amelia_Sync::$native[11] = $employee7;
HSPCC_Sync_Queue::enqueue(11, false, 7);
run_worker();
HSP_Calendar_Connection::$provider = 0;
same(false, HSPCC_Sync_Queue::enqueue(11, false, 0), 'unassigned owner cannot enqueue a job');
same(0, run_worker()['failed'], 'worker uses persisted owner rather than invalid UI selection');
same(1, count(HSPCC_Calendar_Storage::$jobs), 'unset employee adds no remote request');
ok(state(11, 7)['mapping'] !== null, 'unassigned enqueue retains recoverable employee7 mapping');
HSP_Calendar_Connection::$provider = 1;

foreach (['state', 'marker', 'both'] as $unsupported) {
    reset_fixture();
    successful();
    same(2, state()['version'], 'new state uses the current version');
    same(1, marker()['employeeId'], 'new marker explicitly records employee one');
    if ($unsupported !== 'marker') {
        $options['hspcc_queue_state_11']['version'] = 1;
        unset($options['hspcc_queue_state_11']['employeeId']);
    }
    if ($unsupported !== 'state') { unset($options['hspcc_queue_dirty_11']['employeeId']); }
    $retainedState = state();
    $retainedMarker = marker();
    $retainedRemote = HSPCC_Calendar_Storage::$remote;
    $beforeReads = HSPCC_Amelia_Sync::$reads;
    unset(HSPCC_Amelia_Sync::$native[11]);
    same(1, run_worker()['failed'], 'unsupported ' . $unsupported . ' is refused before reconciliation');
    same($beforeReads, HSPCC_Amelia_Sync::$reads, 'unsupported records prevent native reads');
    same($retainedState, state(), 'unsupported records preserve the complete acknowledged state');
    same($retainedMarker, marker(), 'unsupported records retain the original dirty marker');
    same($retainedRemote, HSPCC_Calendar_Storage::$remote, 'unsupported records never erase the owned projection');
    same(1, count(HSPCC_Calendar_Storage::$jobs), 'unsupported records send no new storage request');
    same(1, HSPCC_Sync_Queue::summary(1)['errors'], 'unsupported records remain visible as an employee error');
    same(1, HSPCC_Sync_Queue::summary()['pending'], 'unsupported records remain pending for manual recovery');
    same(1, HSPCC_Sync_Queue::summary(7)['ambiguous'], 'unsupported ownership cannot bypass another employee guard');
    same(1, run_worker()['failed'], 'retry still refuses unsupported records');
    same($retainedState, state(), 'retry cannot silently upgrade or overwrite unsupported state');
    same($retainedMarker, marker(), 'retry cannot silently assign an owner to an unsupported marker');
}

reset_fixture();
HSP_Calendar_Connection::$writers = [1, 7];
HSP_Calendar_Connection::$provider = 7; // UI choice must not route queued jobs.
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Amelia_Sync::$native[12] = native(12); HSPCC_Amelia_Sync::$native[12]['providerId'] = 7;
HSPCC_Sync_Queue::enqueue(11); // The default owner is employee1.
HSPCC_Sync_Queue::enqueue(12, false, 7);
same(2, run_worker()['synced'], 'global worker drains each configured writing owner');
same(7, HSP_Calendar_Connection::employee_id(), 'worker restores caller/UI employee context');
same(1, state(11, 1)['employeeId'], 'employee1 state isolated in original option names');
same(7, state(12, 7)['employeeId'], 'employee7 state isolated in suffixed option names');
ok(get_option('hsp_calendar_site_uuid') !== get_option('hsp_calendar_site_uuid_employee_7'), 'employees receive separate stable UID namespaces');
same(HSPCC_Calendar_Storage::$jobs[0]['calendarKey'], HSPCC_Calendar_Storage::$jobs[1]['calendarKey'], 'shared destination is tested explicitly');
ok(HSPCC_Calendar_Storage::$jobs[0]['siteUuid'] !== HSPCC_Calendar_Storage::$jobs[1]['siteUuid'], 'shared destination cannot collide across employees');
same(1, HSPCC_Sync_Queue::summary(1)['managed'], 'employee1 health is filtered');
same(1, HSPCC_Sync_Queue::summary(7)['managed'], 'employee7 health is filtered');
same(2, HSPCC_Sync_Queue::summary()['managed'], 'null health is aggregate');
same(7, HSP_Calendar_Connection::employee_id(), 'summary restores UI context');
$options['hspcc_queue_dirty_11']['employeeId'] = 7;
same(1, HSPCC_Sync_Queue::summary(7)['ambiguous'], 'conflicting owner metadata blocks every connection migration safely');
same(1, HSPCC_Sync_Queue::summary(1)['ambiguous'], 'ambiguous metadata is not silently reassigned');
$options['hspcc_queue_dirty_11']['employeeId'] = 1;
$options['hspcc_queue_state_12_employee_7']['version'] = 1;
unset($options['hspcc_queue_state_12_employee_7']['employeeId']);
same(1, HSPCC_Sync_Queue::summary(7)['errors'], 'v1 state cannot be assigned to employee7');
HSP_Calendar_Connection::$provider = 1;

reset_fixture();
HSP_Calendar_Connection::$writers = [1, 7];
$acquireOwnerLock = new ReflectionMethod(HSPCC_Sync_Queue::class, 'acquire');
$releaseOwnerLock = new ReflectionMethod(HSPCC_Sync_Queue::class, 'release');
$ownerOneLease = $acquireOwnerLock->invoke(null, 11);
ok($ownerOneLease !== '', 'employee1 holds appointment advisory lock');
$wpdb->connection = 2;
same('', HSP_Calendar_Connection::with_employee(7, fn() => $acquireOwnerLock->invoke(null, 11)), 'same appointment lock serializes employee7 transfer');
same(false, get_option('hspcc_queue_lock_11_employee_7'), 'contending employee7 does not overwrite employee1 lease');
$wpdb->connection = 1;
$releaseOwnerLock->invoke(null, 11, $ownerOneLease);
$wpdb->connection = 2;
$ownerSevenLease = HSP_Calendar_Connection::with_employee(7, fn() => $acquireOwnerLock->invoke(null, 11));
ok($ownerSevenLease !== '', 'employee7 can acquire after employee1 released');
HSP_Calendar_Connection::with_employee(7, fn() => $releaseOwnerLock->invoke(null, 11, $ownerSevenLease));
same([], $wpdb->locks, 'global appointment lock released without cross-owner lease deletion');
same(1, HSP_Calendar_Connection::employee_id(), 'nested lock work restores employee context');

reset_fixture();
HSP_Calendar_Connection::$writers = [1, 7];
successful();
unset($options['hspcc_queue_dirty_11']);
ok(HSPCC_Sync_Queue::summary(7)['ambiguous'] > 0, 'orphaned employee1 state cannot evade another owner migration guard');
$employee7 = native(); $employee7['providerId'] = 7;
HSPCC_Amelia_Sync::$native[11] = $employee7;
HSPCC_Sync_Queue::enqueue(11, false, 7);
run_worker();
same(1, count(HSPCC_Calendar_Storage::$jobs), 'orphaned old ownership blocks a new employee projection');
same('owner_transfer_pending', state(11, 7)['errorCode'], 'orphaned ownership requires explicit reconciliation');

reset_fixture();
HSP_Calendar_Connection::$writers = [7];
HSPCC_Amelia_Sync::$native[11] = $employee7;
HSPCC_Sync_Queue::enqueue(11, false, 7);
HSPCC_Calendar_Storage::$hook = fn($job) => throw new RuntimeException('private employee7 transport error');
same(1, run_worker()['failed'], 'employee7 exception recorded in its own state');
same(1, HSP_Calendar_Connection::employee_id(), 'employee7 worker exception restores caller scope');
same('sync_failed', state(11, 7)['errorCode'], 'employee7 failure cannot be written into employee1 state');
same(false, state(11, 1), 'employee1 state untouched by employee7 error');

reset_fixture();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
$wpdb->scanFailurePrefix = 'hspcc_queue_state_11_employee_';
same(1, run_worker()['failed'], 'owner metadata lookup failure refuses new resource creation');
same([], HSPCC_Calendar_Storage::$jobs, 'metadata failure is not evidence that no old owner exists');
$wpdb->scanFailurePrefix = '';
same(1, run_worker()['synced'], 'restored owner lookup resumes without stale transfer backoff');

reset_fixture();
for ($id = 1000; $id < 2002; ++$id) { $options['hspcc_queue_state_' . $id] = []; }
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
same(1, run_worker()['synced'], 'unrelated large history does not disable appointment-specific ownership check');

fwrite(STDOUT, 'PASS: ' . $checks . " synthetic queue checks; no WordPress bootstrap, native appointments or network.\n");
