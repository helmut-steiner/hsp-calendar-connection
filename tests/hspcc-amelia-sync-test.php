<?php
/** Offline lifecycle/snapshot fixtures: no WordPress, database, email or HTTP. */
namespace AmeliaBooking\Domain\Services\DateTime {
    final class DateTimeService {
        public static $zone;
        public static function getTimeZone() { return self::$zone; }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    define('ABSPATH', __DIR__ . '/');
    final class HSP_Calendar_Connection {
        public static $provider = 1;
        public static $writers = [1, 7];
        public static function provider_id() { return self::$provider; }
        public static function employee_id() { return self::$provider; }
        public static function writing_enabled($id) { return in_array($id, self::$writers, true); }
    }
    final class WP_Error {
        private $code;
        public function __construct($code, $message = '') { $this->code = $code; }
        public function get_error_code() { return $this->code; }
    }
    function __($text, $domain = '') { return $text; }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function wp_strip_all_tags($value) { return strip_tags($value); }
    $hooks = [];
    $collectionErrors = [];
    function add_action($name, $callback, $priority = 10, $accepted = 1) {
        $GLOBALS['hooks'][$name][$priority][] = [$callback, $accepted];
    }
    function do_action($name, ...$args) {
        if ($name === 'hspcc_sync_collection_error') { $GLOBALS['collectionErrors'][] = $args[0]->get_error_code(); }
        $callbacks = $GLOBALS['hooks'][$name] ?? [];
        ksort($callbacks);
        foreach ($callbacks as $group) {
            foreach ($group as [$callback, $accepted]) { call_user_func_array($callback, array_slice($args, 0, $accepted)); }
        }
    }
    final class HSPCC_Sync_Queue {
        public static $enqueued = [];
        public static $fail = false;
        public static function enqueue($id, $deleted = false, $employeeId = 1) {
            if (self::$fail) { return new WP_Error('fake_queue_error'); }
            self::$enqueued[] = [$id, $deleted, $employeeId];
            return true;
        }
    }
    final class FakeEntity {
        private $data;
        public function __construct($data) { $this->data = $data; }
        public function toArray() { return $this->data; }
    }
    final class FakeCollection {
        private $items;
        public function __construct($items) { $this->items = $items; }
        public function getItems() { return $this->items; }
    }
    final class FakeRepo {
        public $appointments = [];
        public $bookings = [];
        public $payments = [];
        public $reads = 0;
        public $fail = false;
        public function getByIdWithoutBookings($id) {
            $this->reads++;
            if ($this->fail) { throw new \RuntimeException('PRIVATE SQL/credentials/customer must not escape'); }
            return array_key_exists($id, $this->appointments) ? new FakeEntity($this->appointments[$id]) : null;
        }
        public function getById($id) {
            $this->reads++;
            if ($this->fail || !isset($this->bookings[$id])) { throw new \RuntimeException('PRIVATE booking lookup'); }
            return new FakeEntity($this->bookings[$id]);
        }
        public function getByEntityId($id, $key) {
            $this->reads++;
            if ($this->fail || $key !== 'wcOrderId') { throw new \RuntimeException('PRIVATE payment lookup'); }
            return new FakeCollection(array_map(static function ($data) { return new FakeEntity($data); }, $this->payments[$id] ?? []));
        }
    }
    final class FakeService {
        public $service = ['id' => 1, 'name' => 'Canonical consultation'];
        public function getAppointmentService($id, $provider) {
            if ($id !== 1 || $provider !== HSP_Calendar_Connection::provider_id()) { throw new \RuntimeException('Unknown service'); }
            return new FakeEntity($this->service);
        }
    }
    final class FakeContainer {
        public $repo;
        public $service;
        public function __construct() { $this->repo = new FakeRepo(); $this->service = new FakeService(); }
        public function get($name) {
            if ($name === 'application.bookable.service') { return $this->service; }
            if (in_array($name, ['domain.booking.appointment.repository', 'domain.booking.customerBooking.repository', 'domain.payment.repository'], true)) { return $this->repo; }
            throw new \RuntimeException('Unknown dependency');
        }
    }
    require dirname(__DIR__) . '/includes/class-hspcc-amelia-sync.php';
    $assertions = 0;
    function check($condition, $message) {
        $GLOBALS['assertions']++;
        if (!$condition) { throw new \RuntimeException('FAILED: ' . $message); }
    }
    function set_private($name, $value) {
        $property = new \ReflectionProperty('HSPCC_Amelia_Sync', $name);
        $property->setValue(null, $value);
    }
    function reset_pending() {
        foreach (['appointments', 'bookings', 'orders'] as $name) { set_private($name, []); }
        HSPCC_Sync_Queue::$enqueued = [];
        HSPCC_Sync_Queue::$fail = false;
        $GLOBALS['collectionErrors'] = [];
    }
    function appointment_fixture($id = 1) {
        return ['id' => $id, 'providerId' => 1, 'serviceId' => 1, 'parentId' => null,
            'status' => 'approved', 'bookingStart' => '2026-11-02 10:00:00', 'bookingEnd' => '2026-11-02 10:30:00',
            'service' => ['id' => 1, 'name' => 'Free initial consultation'],
            'bookings' => [['id' => 51, 'appointmentId' => $id, 'status' => 'approved',
                'customer' => ['firstName' => 'PRIVATE_NAME', 'email' => 'PRIVATE_EMAIL'], 'info' => 'PRIVATE_NOTE']],
            'internalNotes' => 'PRIVATE_INTERNAL_NOTE', 'provider' => ['email' => 'PRIVATE_PROVIDER']];
    }
    $container = new FakeContainer();
    set_private('container', $container);
    \AmeliaBooking\Domain\Services\DateTime\DateTimeService::$zone = new \DateTimeZone('UTC');
    HSPCC_Amelia_Sync::boot();
    HSPCC_Amelia_Sync::boot();
    check(count($hooks['shutdown'][PHP_INT_MAX]) === 1, 'boot is idempotent');
    check(isset($hooks['amelia_after_appointment_updated']), 'native update lifecycle registered');
    check(isset($hooks['amelia_before_booking_approved_link']), 'default remote approval response observed');
    check(isset($hooks['amelia_before_booking_canceled']), 'default remote cancellation response observed');
    check(isset($hooks['woocommerce_order_status_changed']), 'late Woo status observer registered');

    reset_pending();
    foreach (range(11, 24) as $id) { $container->repo->appointments[$id] = appointment_fixture($id); }
    unset($container->repo->appointments[15]); // Native deletion already committed.
    do_action('amelia_after_appointment_added', ['id' => 11, 'customer' => ['id' => 99999]]);
    do_action('amelia_after_appointment_added', ['id' => 11]);
    do_action('amelia_after_appointment_updated', ['id' => 12], ['id' => 13]);
    do_action('amelia_after_booking_rescheduled', ['id' => 13], ['id' => 50, 'appointmentId' => 14]);
    do_action('amelia_after_appointment_deleted', ['id' => 15, 'providerId' => 1]);
    do_action('amelia_after_package_booking_deleted', ['id' => 15], ['id' => 49, 'appointmentId' => 15]);
    do_action('amelia_after_booking_canceled', ['id' => 48, 'appointmentId' => 16]);
    do_action('amelia_after_appointment_status_updated', ['id' => 17], 'approved');
    do_action('amelia_after_appointment_booking_saved', ['id' => 47, 'appointmentId' => 18], ['id' => 1], ['id' => 18]);
    do_action('amelia_after_booking_added', ['type' => 'appointment', 'appointment' => ['id' => 19],
        'booking' => ['id' => 46, 'appointmentId' => 19], 'customer' => ['id' => 99998]]);
    do_action('amelia_after_booking_added', ['type' => 'event', 'event' => ['id' => 99997], 'booking' => ['id' => 45, 'appointmentId' => null]]);
    $container->repo->bookings[51] = ['id' => 51, 'appointmentId' => 20];
    $container->repo->bookings[52] = ['id' => 52, 'appointmentId' => 21];
    do_action('amelia_after_payment_link_callback', ['entity' => 'appointment', 'customerBookingId' => 51, 'data' => 'PRIVATE_PAYMENT']);
    do_action('amelia_after_payment_link_callback', ['entity' => 'event', 'customerBookingId' => 99996]);
    $container->repo->payments[77] = [['entity' => 'appointment', 'customerBookingId' => 52]];
    do_action('woocommerce_order_status_changed', 77, 'pending', 'completed');
    do_action('amelia_before_booking_approved_link', ['id' => 44, 'appointmentId' => 22]);
    do_action('amelia_after_booking_rejected_link', ['id' => 43, 'appointmentId' => 23]);
    do_action('amelia_before_booking_canceled', ['id' => 42, 'appointmentId' => 24]);
    check(HSPCC_Sync_Queue::$enqueued === [], 'no queue/HTTP inside native lifecycle');
    check($container->repo->reads === 0, 'lifecycle callbacks collect identifiers without native reads');
    do_action('shutdown');
    $ids = array_column(HSPCC_Sync_Queue::$enqueued, 0);
    sort($ids);
    check($ids === range(11, 24), 'known appointment IDs deduplicated; old/new reschedule IDs retained; customer IDs ignored');
    $deleted = array_values(array_filter(HSPCC_Sync_Queue::$enqueued, static function ($row) { return $row[0] === 15; }));
    check($deleted === [[15, true, 1]], 'deletion retains prior owner after native row is gone');
    check($collectionErrors === [], 'successful identifier resolution has no errors');
    $count = count(HSPCC_Sync_Queue::$enqueued);
    do_action('shutdown');
    check(count(HSPCC_Sync_Queue::$enqueued) === $count, 'successful shutdown drain is idempotent');
    reset_pending();
    HSPCC_Amelia_Sync::appointment(['id' => 31, 'providerId' => 1]);
    HSPCC_Sync_Queue::$fail = true;
    HSPCC_Amelia_Sync::enqueue_pending();
    check($collectionErrors === ['hspcc_sync_enqueue_failed'], 'queue failure emits sanitized diagnostic');
    HSPCC_Sync_Queue::$fail = false;
    HSPCC_Amelia_Sync::enqueue_pending();
    check(HSPCC_Sync_Queue::$enqueued === [[31, false, 1]], 'queue failure keeps pending identifier for retry');
    reset_pending();
    HSPCC_Amelia_Sync::payment(['entity' => 'appointment', 'customerBookingId' => 99995]);
    HSPCC_Amelia_Sync::enqueue_pending();
    check(HSPCC_Sync_Queue::$enqueued === [], 'failed booking lookup cannot manufacture a tombstone');
    check($collectionErrors === ['hspcc_sync_booking_lookup_failed'], 'lookup failure emits no native exception details');
    reset_pending();

    $container->repo->appointments[1] = appointment_fixture();
    $snapshot = HSPCC_Amelia_Sync::snapshot(1);
    check($snapshot === ['action' => 'upsert', 'appointmentId' => 1, 'providerId' => 1,
        'startUtc' => 1793613600, 'endUtc' => 1793615400, 'serviceLabel' => 'Free initial consultation', 'status' => 'confirmed'],
        'UTC canonical approved appointment has exact allowlisted integer snapshot');
    check(strpos(json_encode($snapshot), 'PRIVATE') === false, 'snapshot excludes customer/provider/notes/payment data');
    $paidSnapshot = appointment_fixture(31);
    $paidSnapshot['service'] = ['id'=>1,'name'=>'Synthetic paid fixed session'];
    $paidSnapshot['bookingEnd'] = '2026-11-02 11:00:00';
    $paidSnapshot['bookings'][0]['price'] = 120.0;
    $paidSnapshot['bookings'][0]['tax'] = [['amount'=>20,'excluded'=>true]];
    $paidSnapshot['bookings'][0]['payments'] = [['gateway'=>'onSite','status'=>'pending','amount'=>0,'data'=>'PRIVATE_FINANCIAL_DATA']];
    $container->repo->appointments[31] = $paidSnapshot;
    $paidProjection = HSPCC_Amelia_Sync::snapshot(31);
    check($paidProjection['action'] === 'upsert' && $paidProjection['endUtc'] - $paidProjection['startUtc'] === 3600,
        'paid onsite pending collection keeps approved appointment calendar projection');
    check(strpos(json_encode($paidProjection), 'PRIVATE') === false && !isset($paidProjection['price'], $paidProjection['tax'], $paidProjection['payments']),
        'paid price/tax/payment information excluded from calendar projection');
    reset_pending();
    do_action('amelia_after_booking_added', ['type'=>'appointment','appointment'=>['id'=>31],'booking'=>['id'=>51,'appointmentId'=>31]]);
    do_action('amelia_after_booking_rescheduled', ['id'=>31,'providerId'=>1], ['id'=>51,'appointmentId'=>31]);
    HSPCC_Amelia_Sync::enqueue_pending();
    check(HSPCC_Sync_Queue::$enqueued === [[31,false,1]], 'paid added and same-parent rescheduled observers enqueue one stable owned appointment');
    $container->repo->appointments[1]['status'] = 'pending';
    check(HSPCC_Amelia_Sync::snapshot(1)['status'] === 'tentative', 'pending canonical appointment is tentative');
    foreach (['canceled', 'rejected', 'no-show', 'waiting'] as $status) {
        $container->repo->appointments[1] = appointment_fixture();
        $container->repo->appointments[1]['status'] = $status;
        check(HSPCC_Amelia_Sync::snapshot(1) === ['action' => 'delete', 'appointmentId' => 1, 'providerId' => 1], 'closed status ' . $status . ' releases owned event');
    }
    $container->repo->appointments[1] = appointment_fixture();
    $container->repo->appointments[1]['bookings'][0]['status'] = 'canceled';
    check(HSPCC_Amelia_Sync::snapshot(1)['action'] === 'delete', 'active parent with no active customers releases owned event');
    $container->repo->appointments[1]['bookings'][] = ['status' => 'pending'];
    check(HSPCC_Amelia_Sync::snapshot(1)['action'] === 'upsert', 'remaining active group child keeps parent event');
    $container->repo->appointments[1] = appointment_fixture();
    $container->repo->appointments[1]['providerId'] = 2;
    check(HSPCC_Amelia_Sync::snapshot(1) === ['action' => 'delete', 'appointmentId' => 1, 'providerId' => 2], 'moved provider cleans only prior owned mapping');
    check(HSPCC_Amelia_Sync::snapshot(99994) === ['action' => 'delete', 'appointmentId' => 99994, 'providerId' => 1], 'actual missing canonical parent returns minimal owned-only tombstone');
    $container->repo->fail = true;
    check(is_wp_error(HSPCC_Amelia_Sync::snapshot(1)), 'database failure is an error, never deletion');
    $container->repo->fail = false;
    check(is_wp_error(HSPCC_Amelia_Sync::snapshot('1 OR 1=1')), 'invalid identifier rejected');
    foreach ([
        ['providerId' => null], ['status' => 'unknown'], ['parentId' => 3],
        ['bookingStart' => '2026-02-30 10:00:00'], ['bookingEnd' => '2026-11-02 09:00:00'],
        ['bookingEnd' => '2027-01-02 10:00:00'], ['bookings' => [['status' => 'unknown']]],
    ] as $changes) {
        $container->repo->appointments[1] = array_replace(appointment_fixture(), $changes);
        check(is_wp_error(HSPCC_Amelia_Sync::snapshot(1)), 'malformed/unsupported canonical data fails without deletion');
    }
    $container->repo->appointments[1] = appointment_fixture();
    $container->repo->appointments[1]['service'] = null;
    check(HSPCC_Amelia_Sync::snapshot(1)['serviceLabel'] === 'Canonical consultation', 'missing joined service resolves canonical native service');
    $container->repo->appointments[1]['service'] = ['id' => 1, 'name' => "<b>Free</b>\nconsultation"];
    check(HSPCC_Amelia_Sync::snapshot(1)['serviceLabel'] === 'Free consultation', 'service label has no HTML or calendar line injection');
    $container->repo->appointments[1]['service']['name'] = "bad\0label";
    check(is_wp_error(HSPCC_Amelia_Sync::snapshot(1)), 'control characters refused');
    \AmeliaBooking\Domain\Services\DateTime\DateTimeService::$zone = new \DateTimeZone('Europe/Vienna');
    $container->repo->appointments[1] = appointment_fixture();
    check(HSPCC_Amelia_Sync::snapshot(1)['startUtc'] === 1793610000, 'native site timezone converted exactly once');
    foreach ([
        ['bookingStart' => '2026-03-29 02:15:00', 'bookingEnd' => '2026-03-29 03:15:00'],
        ['bookingStart' => '2026-10-25 02:15:00', 'bookingEnd' => '2026-10-25 03:15:00'],
    ] as $changes) {
        $container->repo->appointments[1] = array_replace(appointment_fixture(), $changes);
        check(is_wp_error(HSPCC_Amelia_Sync::snapshot(1)), 'DST gap/fold fails without invented timestamp');
    }
    \AmeliaBooking\Domain\Services\DateTime\DateTimeService::$zone = new \DateTimeZone('UTC');
    HSP_Calendar_Connection::$provider = 7;
    $container->repo->appointments[1] = appointment_fixture();
    $container->repo->appointments[1]['providerId'] = 7;
    $container->repo->appointments[1]['service'] = null;
    $snapshot = HSPCC_Amelia_Sync::snapshot(1);
    check($snapshot['action'] === 'upsert' && $snapshot['providerId'] === 7 && $snapshot['serviceLabel'] === 'Canonical consultation', 'configured employee7 canonical appointment and native service lookup supported');
    check(HSPCC_Amelia_Sync::snapshot(99994) === ['action' => 'delete', 'appointmentId' => 99994, 'providerId' => 7], 'missing appointment tombstone uses configured employee7');
    $container->repo->appointments[1]['providerId'] = 1;
    check(HSPCC_Amelia_Sync::snapshot(1) === ['action' => 'delete', 'appointmentId' => 1, 'providerId' => 1], 'unrelated employee1 only releases a previously owned mapping');
    HSP_Calendar_Connection::$provider = 0;
    check(is_wp_error(HSPCC_Amelia_Sync::snapshot(1)) && is_wp_error(HSPCC_Amelia_Sync::snapshot(99994)), 'unset employee is error, never a manufactured deletion');
    HSP_Calendar_Connection::$provider = 1;
    reset_pending();
    // Native payloads, including deleted/old parents, own routing. UI1 does not.
    HSPCC_Amelia_Sync::updated(['id' => 41, 'providerId' => 7], ['id' => 41, 'providerId' => 1]);
    HSPCC_Amelia_Sync::deleted(['id' => 42, 'providerId' => 7]);
    HSPCC_Amelia_Sync::appointment(['id' => 43, 'providerId' => 8]); // Unconfigured employee remains native.
    $container->repo->appointments[44] = appointment_fixture(44);
    $container->repo->appointments[44]['providerId'] = 7;
    HSPCC_Amelia_Sync::booking(['id' => 441, 'appointmentId' => 44]);
    HSPCC_Amelia_Sync::enqueue_pending();
    $routed = HSPCC_Sync_Queue::$enqueued;
    usort($routed, static function ($a, $b) { return [$a[0], $a[2]] <=> [$b[0], $b[2]]; });
    check($routed === [[41, false, 1], [41, false, 7], [42, true, 7], [44, false, 7]], 'owner hints enqueue old/new scopes, deleted owner7 survives missing row, booking-only callback resolves native owner7');
    check(HSP_Calendar_Connection::$provider === 1, 'lifecycle observer never changes administrator selection');
    reset_pending();
    HSP_Calendar_Connection::$provider = 7;
    HSPCC_Amelia_Sync::deleted(['id' => 45, 'providerId' => 1]);
    HSPCC_Amelia_Sync::enqueue_pending();
    check(HSPCC_Sync_Queue::$enqueued === [[45, true, 1]], 'deleted employee1 cannot be rerouted to selected employee7');
    reset_pending();
    HSP_Calendar_Connection::$provider = 1;
    echo 'PASS: ' . $assertions . " offline lifecycle/snapshot assertions; no WP/database/email/HTTP writes.\n";
}
