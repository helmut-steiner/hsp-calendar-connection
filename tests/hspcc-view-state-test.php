<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Actual renderer and namespace APIs; synthetic settings and locking only.
require __DIR__ . '/hspcc-employee-settings-test.php';

$viewChecks = 0;
function view_same($expected, $actual, $label) {
    ++$GLOBALS['viewChecks'];
    if ($expected !== $actual) { fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL); exit(1); }
}
function wp_cache_delete($name, $group = '') { return true; }
function wp_generate_uuid4() {
    static $sequence = 0;
    return sprintf('11111111-2222-4333-8444-%012x', ++$sequence);
}
final class HSPCC_View_Wpdb {
    public $options = 'synthetic_options';
    public $last_error = '';
    public $lockQueries = 0;
    private $delegate;
    private $locks = [];
    public function __construct() { $this->delegate = new HSPCC_Settings_Wpdb(); }
    public function esc_like($value) { return $this->delegate->esc_like($value); }
    public function prepare($sql, ...$args) { return $this->delegate->prepare($sql, ...$args); }
    public function get_col($prepared) { return $this->delegate->get_col($prepared); }
    public function suppress_errors($value = true) { return false; }
    public function get_var($prepared) {
        [$sql, $args] = $prepared;
        $name = $args[0];
        if (!preg_match('/^hspcc:[a-f0-9]{56}$/D', $name)) { throw new RuntimeException('Unexpected synthetic lock name.'); }
        ++$this->lockQueries;
        if ($sql === 'SELECT GET_LOCK(%s, 0)') { $this->locks[$name] = true; return 1; }
        if ($sql === 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()') { return isset($this->locks[$name]) ? 1 : 0; }
        if ($sql === 'SELECT RELEASE_LOCK(%s)') { unset($this->locks[$name]); return 1; }
        throw new RuntimeException('Unexpected synthetic lock query.');
    }
}

foreach ([1, 7] as $employee) {
    foreach (['absent', 'valid', 'corrupt'] as $namespaceState) {
        $GLOBALS['hspcc_options'] = [];
        $GLOBALS['hspcc_admin'] = true;
        HSPCC_Employee_Context::select(1);
        (new ReflectionProperty(HSPCC_Employee_Context::class, 'choices'))->setValue(null, [1 => 'Synthetic Ada', 7 => 'Synthetic Lin']);
        foreach ([1, 7] as $owner) {
            employee_seed($owner, 'https://employee-' . $owner . '.example.test/caldav/', 'synthetic-view-password', true, true);
        }
        HSPCC_Employee_Context::select($employee);
        $namespaceName = HSP_Calendar_Connection::option_name('hsp_calendar_site_uuid');
        $discoveryName = HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::DISCOVERY);
        $destination = $GLOBALS['hspcc_options'][$discoveryName]['writeSelected'];
        $GLOBALS['hspcc_options'][$discoveryName]['busySelected'] = [$destination];
        $validNamespace = 'aaaaaaaa-bbbb-4ccc-8ddd-' . sprintf('%012d', $employee);
        $GLOBALS['hspcc_options'][$namespaceName] = $validNamespace;
        $GLOBALS['hspcc_options'][HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::HEALTH)] = [
            'ok' => true, 'checkedAt' => 1800000000, 'periodCount' => 2,
            'identity' => HSP_Calendar_Connection::connection_identity(false),
        ];
        if ($namespaceState === 'absent') { unset($GLOBALS['hspcc_options'][$namespaceName]); }
        elseif ($namespaceState === 'corrupt') { $GLOBALS['hspcc_options'][$namespaceName] = 'invalid-namespace'; }
        $GLOBALS['hspcc_options']['hspcc_queue_worker_health'] = 'existing-health';
        $GLOBALS['wpdb'] = new HSPCC_View_Wpdb();
        $before = $GLOBALS['hspcc_options'];
        $beforeHttp = count($GLOBALS['hspcc_requests']);
        view_same($namespaceState === 'valid' ? $validNamespace : '', HSPCC_Sync_Queue::site_uuid(false), 'view namespace lookup never initializes absent or corrupt state');
        foreach (['connection', 'calendars', 'runtime', 'status'] as $tab) {
            $page = employee_render($employee, $tab);
            $status = HSP_Calendar_Connection::status();
            view_same(true, $status['configured'], 'selected calendars keep a connection configured without a view namespace');
            view_same(1, $status['busyCalendarCount'], 'overlapping busy source remains selected');
            view_same($namespaceState === 'valid' ? true : null, $status['lastCheckOk'], 'health is readable only with its existing valid namespace');
            view_same($namespaceState === 'valid' ? 1800000000 : 0, $status['checkedAt'], 'unknown namespace never presents stale checked time');
            view_same($namespaceState === 'valid' ? 2 : null, $status['periodCount'], 'unknown namespace never presents stale busy-period count');
            view_same($before, $GLOBALS['hspcc_options'], 'every overlap section view preserves both employee options and queue health');
            view_same($beforeHttp, count($GLOBALS['hspcc_requests']), 'overlap section navigation issues no calendar HTTP');
            view_same(0, $GLOBALS['wpdb']->lockQueries, 'read-only overlap views acquire no namespace lock');
            if ($tab === 'status') {
                view_same(true, str_contains($page, 'value="hspcc_check"'), 'Check connection remains available for missing or corrupt view namespace');
            }
        }
        $otherEmployee = $employee === 1 ? 7 : 1;
        $otherBefore = employee_snapshot($otherEmployee);
        unset($otherBefore[HSP_Calendar_Connection::option_name('hspcc_queue_worker_health', $otherEmployee)]);
        if ($namespaceState === 'absent') {
            // Normal availability callers retain lazy creation; the view flag alone suppresses it.
            $identity = HSP_Calendar_Connection::source_identity($destination);
            $createdNamespace = $GLOBALS['hspcc_options'][$namespaceName] ?? '';
            view_same(1, preg_match('/^[a-f0-9-]{36}$/D', $createdNamespace), 'default runtime source identity still creates a namespace');
            view_same(64, strlen($identity), 'default runtime source identity remains usable');
            view_same(true, $GLOBALS['wpdb']->lockQueries > 0, 'runtime namespace creation retains advisory locking');
            view_same($createdNamespace, HSPCC_Sync_Queue::site_uuid(false), 'view reads the namespace created by a runtime operation');
            view_same($createdNamespace, HSPCC_Sync_Queue::site_uuid(), 'direct runtime namespace lookup preserves its stable identity');
            $otherAfter = employee_snapshot($otherEmployee);
            unset($otherAfter[HSP_Calendar_Connection::option_name('hspcc_queue_worker_health', $otherEmployee)]);
            view_same($otherBefore, $otherAfter, 'runtime creation remains isolated to the selected employee');
            view_same($beforeHttp, count($GLOBALS['hspcc_requests']), 'namespace initialization itself sends no calendar request');
        } else {
            view_same($namespaceState === 'valid' ? $validNamespace : '', HSPCC_Sync_Queue::site_uuid(), 'default runtime lookup preserves existing valid or corrupt state behavior');
            view_same($before, $GLOBALS['hspcc_options'], 'existing namespaces are never silently replaced');
        }
    }
}

echo 'PASS: ' . $viewChecks . " read-only overlap views, namespace health and runtime-initialization checks; synthetic state only.\n";
