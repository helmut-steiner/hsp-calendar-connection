<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Actual queue + storage classes; synthetic WP/native/conditional HTTP only.
// No WordPress bootstrap, database connection, credentials or network calls.
define('HSPCC_QUEUE_REAL_STORAGE', true);
define('HSPCC_QUEUE_LIBRARY_ONLY', true);
require __DIR__ . '/hspcc-sync-queue-test.php';
require dirname(__DIR__) . '/includes/class-hspcc-calendar-storage.php';
function wp_remote_retrieve_response_code($response) { return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_header($response, $name) { return $response['headers'][strtolower($name)] ?? ''; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function http_response($code, $body = '', $etag = '') {
    return ['response' => ['code' => $code], 'headers' => ['content-type' => 'text/calendar; charset=utf-8', 'etag' => $etag], 'body' => $body];
}
function integration_reset() {
    global $options, $cron, $optionWrites, $updateHook, $addHook, $wpdb, $remote, $requests, $lostMethod, $etagCounter, $ready, $target, $targetsByOwner;
    $options = $cron = $optionWrites = $remote = $requests = [];
    $updateHook = $addHook = null;
    $wpdb = new SyntheticWpdb();
    $lostMethod = '';
    $etagCounter = 0;
    $ready = true;
    $target = str_repeat('a', 64);
    $targetsByOwner = [];
    HSP_Calendar_Connection::$provider = 1;
    HSP_Calendar_Connection::$writers = [1];
    HSPCC_Amelia_Sync::$native = [];
    HSPCC_Amelia_Sync::$hook = null;
    HSPCC_Amelia_Sync::$reads = 0;
    HSPCC_Calendar_Storage::set_configuration(function () use (&$ready, &$target, &$targetsByOwner) {
        return ['ready' => $ready, 'calendarKey' => $targetsByOwner[HSP_Calendar_Connection::employee_id()] ?? $target];
    });
    HSPCC_Calendar_Storage::set_transport(function ($key, $method, $path, $headers, $body) use (&$remote, &$requests, &$lostMethod, &$etagCounter, &$target, &$targetsByOwner) {
        $employee = HSP_Calendar_Connection::employee_id();
        same($targetsByOwner[$employee] ?? $target, $key, 'transport receives only explicit owner calendar key');
        ok((bool) preg_match('/^hsp-amelia-[a-f0-9-]+-[1-9][0-9]*\.ics$/D', $path), 'transport receives only owned deterministic filename');
        $requests[] = ['method' => $method, 'path' => $path, 'headers' => $headers, 'employeeId' => $employee, 'calendarKey' => $key];
        $existing = $remote[$path] ?? null;
        if ($method === 'GET') { return $existing ? http_response(200, $existing['body'], $existing['etag']) : http_response(404); }
        if ($method === 'PUT') {
            if ((($headers['If-None-Match'] ?? '') === '*' && $existing !== null) ||
                (isset($headers['If-Match']) && ($existing['etag'] ?? null) !== $headers['If-Match'])) { return http_response(412); }
            $etag = '"integration-' . ++$etagCounter . '"';
            $remote[$path] = ['body' => $body, 'etag' => $etag];
            if ($lostMethod === 'PUT') { $lostMethod = ''; return new WP_Error('synthetic_lost_response'); }
            return http_response($existing ? 204 : 201, '', $etag);
        }
        if ($method === 'DELETE') {
            if ($existing === null) { return http_response(404); }
            if (($headers['If-Match'] ?? '') !== $existing['etag']) { return http_response(412); }
            unset($remote[$path]);
            if ($lostMethod === 'DELETE') { $lostMethod = ''; return new WP_Error('synthetic_lost_response'); }
            return http_response(204);
        }
        throw new RuntimeException('Unsupported synthetic method.');
    });
}
function methods() { global $requests; return array_column($requests, 'method'); }

integration_reset();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
$lostMethod = 'PUT';
run_worker();
same(1, count($remote), 'actual serializer created remote projection despite lost response');
same(null, state()['mapping'], 'lost create response has no acknowledged mapping');
ok(is_array(state()['intent']), 'lost create response retains actual storage technical intent');
$intent = state()['intent'];
unset(HSPCC_Amelia_Sync::$native[11]);
HSPCC_Sync_Queue::enqueue(11, true);
run_worker();
same(['PUT', 'GET', 'GET', 'DELETE'], methods(), 'actual recovery GET precedes ownership-checked cancellation DELETE');
same([], $remote, 'actual cancelled uncertain create leaves no orphan');
same(null, state()['intent'], 'actual cleanup clears technical intent');
same(null, state()['mapping'], 'actual cleanup clears recovered ETag mapping');
same(0, HSPCC_Sync_Queue::summary()['pending'], 'actual cleanup fully acknowledged');
same(false, str_contains(json_encode($options), 'Synthetic consultation'), 'actual intent/options never contain service label');
same(false, str_contains(json_encode($options), '1800000000'), 'actual intent/options never contain appointment time');

integration_reset();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
$lostMethod = 'PUT';
run_worker();
$intent = state()['intent'];
HSPCC_Amelia_Sync::$native[11] = native(11, 3600);
run_worker(); // No new hook: native-hash reconciliation must bypass old backoff.
same(['PUT', 'GET', 'PUT'], methods(), 'changed native state recovers old owned write before new conditional PUT');
same('"integration-1"', $requests[2]['headers']['If-Match'] ?? null, 'new projection uses recovered actual ETag');
same(1, state()['sequence'], 'actual reschedule follows recovered old version');
ok(str_contains(array_values($remote)[0]['body'], 'DTSTART:' . gmdate('Ymd\THis\Z', 1800003600)), 'actual serializer writes latest native time');
same(null, state()['intent'], 'actual reschedule resolves uncertain intent');
same(0, HSPCC_Sync_Queue::summary()['pending'], 'actual reschedule converges');

integration_reset();
successful();
unset(HSPCC_Amelia_Sync::$native[11]);
HSPCC_Sync_Queue::enqueue(11, true);
$lostMethod = 'DELETE';
run_worker();
same([], $remote, 'actual DELETE can commit before response is lost');
ok(is_array(state()['mapping']), 'uncertain actual delete retains prior acknowledged mapping');
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
run_worker();
same(['PUT', 'GET', 'DELETE', 'GET', 'PUT'], methods(), 'restore recovers DELETE absence then creates one current projection');
same('*', $requests[4]['headers']['If-None-Match'] ?? null, 'restore after absent recovery uses create precondition');
same(1, count($remote), 'actual restoration creates exactly one resource');
same(2, state()['sequence'], 'actual restore follows uncertain deletion version');
same(0, HSPCC_Sync_Queue::summary()['pending'], 'actual restore fully acknowledged');

integration_reset();
successful();
HSPCC_Amelia_Sync::$native[11] = native(11, 3600);
HSPCC_Sync_Queue::enqueue(11);
$lostMethod = 'PUT';
run_worker();
same('"integration-1"', state()['mapping']['etag'], 'uncertain actual update keeps old acknowledged ETag');
unset(HSPCC_Amelia_Sync::$native[11]);
HSPCC_Sync_Queue::enqueue(11, true);
run_worker();
same(['PUT', 'PUT', 'GET', 'GET', 'DELETE'], methods(), 'cancel recovers uncertain updated version before DELETE');
same('"integration-2"', $requests[4]['headers']['If-Match'] ?? null, 'cancel uses ETag of actual uncertain updated version');
same([], $remote, 'actual uncertain updated projection safely removed');

integration_reset();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11);
$lostMethod = 'PUT';
run_worker();
$path = array_key_first($remote);
$remote[$path]['body'] = str_replace('SUMMARY:Synthetic consultation', 'SUMMARY:Externally edited', $remote[$path]['body']);
unset(HSPCC_Amelia_Sync::$native[11]);
HSPCC_Sync_Queue::enqueue(11, true);
run_worker();
same(['PUT', 'GET'], methods(), 'externally modified actual fields never proceed to DELETE');
same(1, count($remote), 'conflicting remote item preserved');
same('conflict', state()['errorCode'], 'recomputed actual projection detects stale claimed source hash');
ok(is_array(state()['intent']), 'external conflict retains unresolved intent for manual review');
same(1, HSPCC_Sync_Queue::summary()['conflicts'], 'actual recovery conflict visible in aggregate');

function scoped_native_snapshot() {
    HSPCC_Amelia_Sync::$hook = static function ($id) {
        $native = HSPCC_Amelia_Sync::$native[$id] ?? null;
        $owner = HSP_Calendar_Connection::employee_id();
        if ($native === null || ($native['providerId'] ?? null) !== $owner) {
            return ['action' => 'delete', 'appointmentId' => $id, 'providerId' => $native['providerId'] ?? $owner];
        }
        return $native;
    };
}

integration_reset();
HSP_Calendar_Connection::$writers = [1, 7];
HSP_Calendar_Connection::$provider = 7;
$targetsByOwner = [1 => str_repeat('a', 64), 7 => str_repeat('b', 64)];
scoped_native_snapshot();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Amelia_Sync::$native[12] = native(12); HSPCC_Amelia_Sync::$native[12]['providerId'] = 7;
HSPCC_Sync_Queue::enqueue(11, false, 1);
HSPCC_Sync_Queue::enqueue(12, false, 7);
same(2, run_worker()['synced'], 'actual storage writes each explicit employee connection');
same([1, 7], array_column($requests, 'employeeId'), 'selected UI7 cannot redirect employee1 job');
same([$targetsByOwner[1], $targetsByOwner[7]], array_column($requests, 'calendarKey'), 'different employee destinations stay isolated');
same(2, count($remote), 'two employee resources retained independently');
same(1, HSPCC_Sync_Queue::summary(1)['managed'], 'actual employee1 filtered health');
same(1, HSPCC_Sync_Queue::summary(7)['managed'], 'actual employee7 filtered health');
same(2, HSPCC_Sync_Queue::summary()['managed'], 'actual aggregate health includes both');
same(7, HSP_Calendar_Connection::employee_id(), 'actual worker and summary restore selected employee');

integration_reset();
HSP_Calendar_Connection::$writers = [1, 7];
scoped_native_snapshot();
HSPCC_Amelia_Sync::$native[11] = native();
HSPCC_Sync_Queue::enqueue(11, false, 1);
run_worker();
$oldPath = array_key_first($remote);
HSPCC_Amelia_Sync::$native[11]['providerId'] = 7;
HSPCC_Sync_Queue::enqueue(11, false, 1);
HSPCC_Sync_Queue::enqueue(11, false, 7);
same(0, run_worker()['synced'], 'new owner waits while previous owner mapping exists');
same('owner_transfer_pending', state(11, 7)['errorCode'], 'owner transfer is explicitly pending rather than duplicating resources');
same([$oldPath], array_keys($remote), 'waiting new owner cannot create alongside old resource');
same(2, run_worker()['synced'], 'reassignment reconciles old owner and new owner separately');
$newPath = array_key_first($remote);
same(1, count($remote), 'shared destination retains only reassigned employee resource');
ok($newPath !== $oldPath && !isset($remote[$oldPath]), 'same appointment uses a different employee UID/path namespace');
same(null, state(11, 1)['mapping'], 'reassignment clears only prior owner mapping');
ok(state(11, 7)['mapping'] !== null, 'reassignment records new owner mapping');
same(['PUT', 'GET', 'DELETE', 'PUT'], methods(), 'old conditional cleanup precedes new scoped create');
same([1, 1, 1, 7], array_column($requests, 'employeeId'), 'cleanup and create use their own private transports');
$beforeDelete = count($requests);
unset(HSPCC_Amelia_Sync::$native[11]);
HSP_Calendar_Connection::$provider = 1;
HSPCC_Sync_Queue::enqueue(11, true, 7);
run_worker();
same([], $remote, 'missing native row is deleted using persisted previous employee7 owner');
same([7, 7], array_column(array_slice($requests, $beforeDelete), 'employeeId'), 'deleted owner7 never falls back to selected employee1');

integration_reset();
HSP_Calendar_Connection::$writers = [1, 7];
scoped_native_snapshot();
HSPCC_Amelia_Sync::$native[11] = native(); HSPCC_Amelia_Sync::$native[11]['providerId'] = 7;
HSPCC_Sync_Queue::enqueue(11, false, 7);
$lostMethod = 'PUT';
run_worker();
ok(state(11, 7)['intent'] !== null && state(11, 7)['mapping'] === null, 'employee7 uncertain create keeps scoped durable intent');
unset(HSPCC_Amelia_Sync::$native[11]);
HSP_Calendar_Connection::$provider = 1;
HSPCC_Sync_Queue::enqueue(11, true, 7);
run_worker();
same([], $remote, 'employee7 lost create then native deletion is recovered and removed');
same(['PUT', 'GET', 'GET', 'DELETE'], methods(), 'employee7 uncertain ownership recovered before conditional cleanup');
same([7, 7, 7, 7], array_column($requests, 'employeeId'), 'uncertain recovery never uses UI-selected employee1 credentials');
same(0, HSPCC_Sync_Queue::summary(7)['unresolved'], 'employee7 recovery converges without affecting employee1');
same(1, HSP_Calendar_Connection::employee_id(), 'uncertain recovery restores caller scope');

foreach (['state', 'marker'] as $unsupported) {
    integration_reset();
    HSPCC_Amelia_Sync::$native[11] = native();
    HSPCC_Sync_Queue::enqueue(11);
    $lostMethod = 'PUT';
    run_worker();
    ok(is_array(state()['intent']), 'uncertain write has a recoverable current-format intent');
    if ($unsupported === 'state') {
        $options['hspcc_queue_state_11']['version'] = 1;
        unset($options['hspcc_queue_state_11']['employeeId']);
    } else { unset($options['hspcc_queue_dirty_11']['employeeId']); }
    $retainedState = state();
    $retainedMarker = marker();
    $retainedRemote = $remote;
    $retainedRequests = $requests;
    unset(HSPCC_Amelia_Sync::$native[11]);
    same(1, run_worker()['failed'], 'unsupported ' . $unsupported . ' blocks actual uncertain-write recovery');
    same($retainedState, state(), 'unsupported record retains actual storage intent without conversion');
    same($retainedMarker, marker(), 'unsupported record retains original marker for inspection');
    same($retainedRemote, $remote, 'unsupported record preserves actual remote projection');
    same($retainedRequests, $requests, 'unsupported record sends no recovery or deletion HTTP');
    same(1, HSPCC_Sync_Queue::summary(1)['errors'], 'unsupported record exposes a scoped error');
    same(1, HSPCC_Sync_Queue::summary()['pending'], 'unsupported record remains pending for manual recovery');
}

fwrite(STDOUT, 'PASS: ' . $checks . " actual queue/storage integration checks; synthetic native/WP/HTTP only.\n");
