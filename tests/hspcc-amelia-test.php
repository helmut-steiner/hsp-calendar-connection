<?php
// Offline fixtures only. No WordPress bootstrap, HTTP, real credentials or writes.
namespace AmeliaBooking\Domain\Common\Exceptions {
    class CustomException extends \Exception {}
}
namespace AmeliaBooking\Domain\Services\DateTime {
    class DateTimeService {
        public static function getTimeZone() { return new \DateTimeZone('Europe/Vienna'); }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    define('ABSPATH', __DIR__ . '/');
    define('AMELIA_VERSION', '9.9.1');
    final class HSP_Calendar_Connection {
        public static $provider = 1;
        public static $enabled = null;
        public static function provider_id() { return self::$provider; }
        public static function blocking_enabled($id) {
            return self::$enabled === null ? $id === self::$provider : !empty(self::$enabled[$id]);
        }
        public static function with_employee($id, callable $callback) {
            $old = self::$provider;
            self::$provider = $id;
            try { return $callback(); }
            finally { self::$provider = $old; }
        }
    }
    $GLOBALS['hspcc_hooks'] = [];
    function add_filter($name, $callback, $priority, $args) { $GLOBALS['hspcc_hooks'][$name] = [$callback, $priority, $args]; }
    function add_action($name, $callback, $priority, $args) { add_filter($name, $callback, $priority, $args); }
    class WP_Error {}
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function __($text, $domain = '') { return $text; }
    final class HSPCC_Busy_Time {
        public static $periods = [];
        public static $calls = [];
        public static $employeeCalls = [];
        public static $perEmployee = null;
        public static function intervals($start, $end, $fresh = false) {
            self::$calls[] = [$start, $end, $fresh];
            $employee = HSP_Calendar_Connection::provider_id();
            self::$employeeCalls[] = [$employee, $start, $end, $fresh];
            return self::$perEmployee === null ? self::$periods : (self::$perEmployee[$employee] ?? new WP_Error());
        }
    }
    class HSPCC_Test_Settings {
        public $showClient = false;
        public function getSetting($group, $key) { return $group === 'payments' && $key === 'currency' ? 'EUR' : $this->showClient; }
    }
    class HSPCC_Test_Container {
        public $settings;
        public $bookings;
        public $appointments;
        public function __construct() {
            $this->settings = new HSPCC_Test_Settings();
            $this->bookings = new HSPCC_Test_Repository();
            $this->appointments = new HSPCC_Test_Repository();
        }
        public function get($key) {
            if ($key === 'domain.settings.service') { return $this->settings; }
            if ($key === 'domain.booking.customerBooking.repository') { return $this->bookings; }
            if ($key === 'domain.booking.appointment.repository') { return $this->appointments; }
            throw new \RuntimeException('Unexpected native lookup in fixture.');
        }
    }
    class HSPCC_Test_Repository {
        public $rows = [];
        public function getById($id) { return isset($this->rows[$id]) ? new HSPCC_Test_Model($this->rows[$id]) : null; }
    }
    class HSPCC_Test_Model {
        private $data;
        public function __construct($data) { $this->data = $data; }
        public function toArray() { return $this->data; }
    }
    require dirname(__DIR__) . '/includes/class-hspcc-amelia.php';
    $service = ['id' => 1, 'duration' => 1800, 'timeBefore' => 600, 'timeAfter' => 300, 'price' => 0.0,
        'customPricing' => null, 'extras' => [['id' => 9, 'duration' => 900, 'price' => 0.0]]];
    $container = new HSPCC_Test_Container();
    (new \ReflectionProperty(HSPCC_Amelia::class, 'container'))->setValue(null, $container);
    (new \ReflectionProperty(HSPCC_Amelia::class, 'services'))->setValue(null, ['1:1' => $service]);
    $GLOBALS['hspcc_assertions'] = 0;
    function check($condition, $label) {
        ++$GLOBALS['hspcc_assertions'];
        if (!$condition) { throw new \RuntimeException('FAIL: ' . $label); }
    }
    function utc($date) { return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->getTimestamp(); }
    function reset_busy($periods = []) {
        HSPCC_Busy_Time::$periods = $periods; HSPCC_Busy_Time::$calls = [];
        HSPCC_Busy_Time::$employeeCalls = []; HSPCC_Busy_Time::$perEmployee = null;
    }
    function refuses($callback, $label, $contact = false) {
        try { $callback(); }
        catch (\AmeliaBooking\Domain\Common\Exceptions\CustomException $e) {
            check(!$contact || stripos($e->getMessage(), 'contact us') !== false, $label . ' contact alternative');
            return;
        }
        throw new \RuntimeException('FAIL: ' . $label . ' did not refuse');
    }
    function payload() {
        return ['providerId' => 1, 'serviceId' => 1, 'bookingStart' => '2026-11-02 09:00:00',
            'bookings' => [['status' => 'approved', 'duration' => 1800, 'extras' => []]]];
    }
    function slots($rows, $time = '09:00', $day = '2026-11-02') {
        return ['slots' => [$day => [$time => $rows]], 'occupied' => [], 'duration' => 45, 'busyness' => [$day => 0]];
    }
    $props = ['serviceId' => 1, 'extras' => [], 'isFrontEndBooking' => true, 'timeZone' => null];

    HSPCC_Amelia::boot();
    HSPCC_Amelia::boot();
    check(count($GLOBALS['hspcc_hooks']) === 10, 'documented hooks registered once');
    foreach (['amelia_before_booking_rescheduled', 'amelia_before_booking_approved',
        'amelia_before_booking_pending', 'amelia_before_booking_approved_link',
        'amelia_before_appointment_status_updated'] as $action) {
        check($GLOBALS['hspcc_hooks'][$action][1] === 1, 'actions guard before ordinary callbacks');
    }
    reset_busy([[utc('2026-11-02 07:55:00'), utc('2026-11-02 08:05:00')]]);
    $filtered = HSPCC_Amelia::filter_slots(slots([[1, 1], [2, 1], ['invalid']]), $props);
    check($filtered['slots']['2026-11-02']['09:00'] === [[2, 1]], 'normal rows/provider scoping/unknown provider refusal');
    check(HSPCC_Busy_Time::$calls[0] === [utc('2026-11-02 07:50:00'), utc('2026-11-02 08:35:00'), false], 'native site timezone and buffers');
    check($filtered['busyness'] === [], 'stale optional busyness removed');
    $otherSlots = slots([[2, 1]]);
    check(HSPCC_Amelia::filter_slots($otherSlots, $props) === $otherSlots, 'other-provider-only hints remain unchanged');
    reset_busy();
    $empty = HSPCC_Amelia::filter_slots(['slots' => [], 'duration' => 45], $props);
    check($empty['slots'] === [] && HSPCC_Busy_Time::$calls === [], 'empty hours never generate slots or requests');

    reset_busy([[utc('2026-11-02 09:20:00'), utc('2026-11-02 09:25:00')]]);
    $filtered = HSPCC_Amelia::filter_slots(slots([
        ['e' => 1, 'l' => 1, 'p' => null],
        ['e' => 1, 'l' => 1, 's' => 1, 'c' => 1, 'd' => 90],
        [1, 1, 1, 1, 90],
    ]), $props);
    check(count($filtered['slots']['2026-11-02']['09:00']) === 1, 'structured normal plus both native joinable variants');

    $container->settings->showClient = true;
    reset_busy([[utc('2026-11-02 08:15:00'), utc('2026-11-02 08:20:00')]]);
    $filtered = HSPCC_Amelia::filter_slots(slots([[1, 1]], '08:00'), $props);
    check($filtered['slots'] === [], 'frontend showClientTimezone uses UTC response keys');
    check(HSPCC_Busy_Time::$calls[0][0] === utc('2026-11-02 07:50:00'), 'UTC response not converted twice');
    $container->settings->showClient = false;
    reset_busy([[utc('2026-11-02 08:15:00'), utc('2026-11-02 08:20:00')]]);
    $filtered = HSPCC_Amelia::filter_slots(slots([[1, 1]], '03:00'), array_merge($props, ['timeZone' => 'America/New_York']));
    check($filtered['slots'] === [], 'explicit response timezone respected');

    $appointment = payload();
    $appointment['utc'] = true;
    $appointment['timeZone'] = 'America/New_York';
    reset_busy();
    check(HSPCC_Amelia::before_frontend($appointment) === $appointment, 'frontend preserves normalized payload');
    check(HSPCC_Busy_Time::$calls[0] === [utc('2026-11-02 07:50:00'), utc('2026-11-02 08:35:00'), true], 'frontend normalized site time and fresh pre-save read');
    reset_busy();
    check(HSPCC_Amelia::before_update($appointment, [], $service) === $appointment, 'update retains payload');
    check(HSPCC_Busy_Time::$calls[0][0] === utc('2026-11-02 07:50:00'), 'update does not reapply retained utc flag');
    $raw = payload(); $raw['bookingStart'] = '2026-11-02 08:00:00'; $raw['utc'] = true;
    reset_busy(); HSPCC_Amelia::before_create($raw, $service);
    check(HSPCC_Busy_Time::$calls[0][0] === utc('2026-11-02 07:50:00'), 'backend raw UTC conversion once');
    $raw = payload(); $raw['bookingStart'] = '2026-11-02 03:00:00'; $raw['timeZone'] = 'America/New_York';
    reset_busy(); HSPCC_Amelia::before_create($raw, $service);
    check(HSPCC_Busy_Time::$calls[0][0] === utc('2026-11-02 07:50:00'), 'backend explicit timezone conversion once');
    reset_busy(); HSPCC_Amelia::before_reschedule(payload(), null, '2026-11-02 09:00:00');
    check(HSPCC_Busy_Time::$calls[0][0] === utc('2026-11-02 07:50:00') && HSPCC_Busy_Time::$calls[0][2], 'whole-appointment reschedule fresh check');
    refuses(function () { HSPCC_Amelia::before_reschedule(payload(), ['id' => 10], '2026-11-02 09:00:00'); }, 'ambiguous reassignment', true);

    $extras = payload(); $extras['bookings'][0]['extras'] = [['extraId' => 9, 'quantity' => 1]];
    reset_busy([[utc('2026-11-02 08:45:00'), utc('2026-11-02 08:55:00')]]);
    refuses(function () use ($extras) { HSPCC_Amelia::before_frontend($extras); }, 'extras extend busy check');
    check(HSPCC_Busy_Time::$calls[0][1] === utc('2026-11-02 08:50:00'), 'extras duration and after-buffer');
    $nativeExtraDuration = $extras;
    $nativeExtraDuration['bookings'][0]['duration'] = 2700; // Native duration already includes the 15-minute extra.
    reset_busy(); HSPCC_Amelia::before_frontend($nativeExtraDuration);
    check(HSPCC_Busy_Time::$calls[0][1] === utc('2026-11-02 08:50:00'), 'native combined booking duration does not double-count extras');
    $zeroExtra = payload();
    $zeroExtra['bookings'][0]['extras'] = [['extraId' => 9, 'quantity' => 0]];
    reset_busy(); HSPCC_Amelia::before_frontend($zeroExtra);
    check(HSPCC_Busy_Time::$calls[0][1] === utc('2026-11-02 08:35:00'), 'zero selected-extra quantity is valid and adds no duration');
    $selectedProps = array_merge($props, ['extras' => [['id' => 9, 'quantity' => 1]]]);
    $extraSlots = slots([[1, 1]]); $extraSlots['duration'] = 60;
    reset_busy([[utc('2026-11-02 08:45:00'), utc('2026-11-02 08:55:00')]]);
    $filtered = HSPCC_Amelia::filter_slots($extraSlots, $selectedProps);
    check($filtered['slots'] === [], 'slot extra quantities included');
    reset_busy();
    $zeroExtraSlots = slots([[1, 1]]);
    $filtered = HSPCC_Amelia::filter_slots($zeroExtraSlots, array_merge($props, ['extras' => [['id' => 9, 'quantity' => '0']]]));
    check($filtered['slots'] === $zeroExtraSlots['slots'], 'zero quantity native slot extras remain available');

    reset_busy([[utc('2026-11-02 08:35:00'), utc('2026-11-02 08:45:00')],
        [utc('2026-11-02 07:40:00'), utc('2026-11-02 07:50:00')]]);
    HSPCC_Amelia::before_frontend(payload());
    check(count(HSPCC_Busy_Time::$calls) === 1, 'half-open touching boundaries stay available');
    $bad = payload(); $bad['providerId'] = null;
    reset_busy(); refuses(function () use ($bad) { HSPCC_Amelia::before_frontend($bad); }, 'unknown provider');
    check(!HSPCC_Busy_Time::$calls, 'unknown provider never guessed');
    $other = payload(); $other['providerId'] = 2;
    check(HSPCC_Amelia::before_frontend($other) === $other && !HSPCC_Busy_Time::$calls, 'explicit other provider untouched');
    $cancel = payload(); $cancel['bookings'][0]['status'] = 'canceled';
    check(HSPCC_Amelia::before_update($cancel, [], $service) === $cancel && !HSPCC_Busy_Time::$calls, 'cancellation does not require feed');
    foreach (['canceled', 'cancelled', 'rejected'] as $status) {
        $cancel = payload(); $cancel['status'] = $status;
        reset_busy(new WP_Error());
        refuses(function () use ($cancel, $service) { HSPCC_Amelia::before_update($cancel, [], $service); }, 'top cancellation with active children cannot bypass update');
        refuses(function () use ($cancel) { HSPCC_Amelia::before_frontend($cancel); }, 'client top-status cannot bypass create validation');
        $cancel['bookings'][0]['status'] = $status;
        reset_busy(new WP_Error());
        check(HSPCC_Amelia::before_update($cancel, [], $service) === $cancel && !HSPCC_Busy_Time::$calls, 'native closed children release capacity when feed unavailable');
        refuses(function () use ($cancel) { HSPCC_Amelia::before_frontend($cancel); }, 'client child-status cannot bypass create validation');
    }

    foreach (['recurring' => [['bookingStart' => '2026-11-09 09:00']], 'package' => [['id' => 1]], 'isCart' => true] as $key => $value) {
        $unsupported = payload(); $unsupported[$key] = $value;
        refuses(function () use ($unsupported) { HSPCC_Amelia::before_frontend($unsupported); }, $key . ' refused', true);
    }
    foreach (['2026-03-29 02:30:00', '2026-10-25 02:30:00', '2026-02-30 10:00:00', ''] as $invalid) {
        $bad = payload(); $bad['bookingStart'] = $invalid;
        refuses(function () use ($bad) { HSPCC_Amelia::before_frontend($bad); }, 'invalid or ambiguous local time');
    }
    $foldUtc = payload(); $foldUtc['bookingStart'] = '2026-10-25 00:30:00'; $foldUtc['utc'] = true;
    reset_busy(); HSPCC_Amelia::before_create($foldUtc, $service);
    check(HSPCC_Busy_Time::$calls[0][0] === utc('2026-10-25 00:20:00'), 'explicit UTC fold instant unambiguous');
    reset_busy();
    HSPCC_Amelia::filter_slots(slots([[1, 1]], '00:30', '2026-10-25'), array_merge($props, ['timeZone' => 'UTC']));
    check(HSPCC_Busy_Time::$calls[0][0] === utc('2026-10-25 00:20:00'), 'UTC slot during DST fold');

    $nativeParent = payload();
    $nativeParent['id'] = 8;
    $nativeParent['status'] = 'canceled';
    $nativeParent['bookings'][0]['id'] = 4;
    $nativeParent['bookings'][0]['appointmentId'] = 8;
    $nativeParent['bookings'][0]['status'] = 'canceled';
    $container->appointments->rows[8] = $nativeParent;
    $container->bookings->rows[4] = $nativeParent['bookings'][0];
    $container->bookings->rows[5] = ['id' => 5, 'appointmentId' => null, 'status' => 'canceled'];
    $guards = [
        function () { HSPCC_Amelia::before_frontend(payload()); },
        function () use ($service) { HSPCC_Amelia::before_create(payload(), $service); },
        function () use ($service) { HSPCC_Amelia::before_update(payload(), [], $service); },
        function () use ($service) { HSPCC_Amelia::before_booking_saved(payload()['bookings'][0], $service, payload()); },
        function () { HSPCC_Amelia::before_reschedule(payload(), null, '2026-11-02 09:00:00'); },
        function () { HSPCC_Amelia::before_approved(['id' => 4]); },
        function () { HSPCC_Amelia::before_pending(['id' => 4]); },
        function () use ($nativeParent) { HSPCC_Amelia::before_appointment_status($nativeParent, 'approved'); },
        function () use ($nativeParent) { HSPCC_Amelia::before_appointment_status($nativeParent, 'pending'); },
    ];
    foreach ($guards as $index => $guard) {
        reset_busy(new WP_Error());
        refuses($guard, 'unavailable feed guard ' . $index, true);
        check(HSPCC_Busy_Time::$calls[0][2] === true, 'every write guard forces fresh read ' . $index);
        reset_busy([[utc('2026-11-02 08:00:00'), utc('2026-11-02 08:05:00')]]);
        refuses($guard, 'busy feed guard ' . $index);
    }
    reset_busy(new WP_Error());
    HSPCC_Amelia::before_approved(['id' => 5]);
    HSPCC_Amelia::before_pending(['id' => 5]);
    HSPCC_Amelia::before_appointment_status($nativeParent, 'canceled');
    HSPCC_Amelia::before_appointment_status($nativeParent, 'rejected');
    check(!HSPCC_Busy_Time::$calls, 'event status and appointment cancellation unchanged with feed down');
    refuses(function () { HSPCC_Amelia::before_approved(['id' => 999]); }, 'unknown persisted booking cannot bypass status guard', true);
    $otherParent = $nativeParent; $otherParent['providerId'] = 2;
    $container->appointments->rows[8] = $otherParent;
    reset_busy(new WP_Error()); HSPCC_Amelia::before_approved(['id' => 4]);
    check(!HSPCC_Busy_Time::$calls, 'canonical other-provider status untouched');
    $container->appointments->rows[8] = $nativeParent;
    $spoofed = payload(); $spoofed['type'] = 'event';
    reset_busy([[utc('2026-11-02 08:00:00'), utc('2026-11-02 08:05:00')]]);
    refuses(function () use ($spoofed, $service) { HSPCC_Amelia::before_create($spoofed, $service); }, 'backend create type metadata cannot bypass appointment guard');
    refuses(function () use ($spoofed, $service) { HSPCC_Amelia::before_update($spoofed, [], $service); }, 'backend update type metadata cannot bypass appointment guard');
    reset_busy(new WP_Error());
    $nativeEvent = ['type' => 'event', 'eventId' => 7, 'bookings' => []];
    check(HSPCC_Amelia::before_frontend($nativeEvent) === $nativeEvent && !HSPCC_Busy_Time::$calls, 'shared frontend event route remains outside appointment scope');
    reset_busy(new WP_Error());
    $filtered = HSPCC_Amelia::filter_slots(slots([[1, 1], [2, 1]]), $props);
    check($filtered['slots']['2026-11-02']['09:00'] === [[2, 1]], 'feed failure closes only affected provider rows');
    reset_busy([['bad', 'interval']]);
    refuses(function () { HSPCC_Amelia::before_frontend(payload()); }, 'malformed busy data', true);

    $paidService = $service; $paidService['id'] = 2; $paidService['price'] = 120.0;
    $paidExtraService = $service; $paidExtraService['id'] = 3; $paidExtraService['extras'][0]['price'] = 15.0;
    $customService = $service; $customService['id'] = 4; $customService['customPricing'] = '{"enabled":"duration"}';
    (new \ReflectionProperty(HSPCC_Amelia::class, 'services'))->setValue(null, [
        '1:1' => $service, '1:2' => $paidService, '1:3' => $paidExtraService, '1:4' => $customService,
    ]);
    foreach ([2, 4] as $unsupportedServiceId) {
        $unsupported = payload(); $unsupported['serviceId'] = $unsupportedServiceId;
        reset_busy();
        refuses(function () use ($unsupported) { HSPCC_Amelia::before_frontend($unsupported); }, 'paid or variable service refused', true);
        check(!HSPCC_Busy_Time::$calls, 'unsupported service does not query NAS');
        $filtered = HSPCC_Amelia::filter_slots(slots([[1, 1]]), array_merge($props, ['serviceId' => $unsupportedServiceId]));
        check($filtered['slots'] === [], 'paid or variable service has no online choices');
    }
    $paidExtra = payload(); $paidExtra['serviceId'] = 3;
    $paidExtra['bookings'][0]['extras'] = [['extraId' => 9, 'quantity' => 1]];
    reset_busy();
    refuses(function () use ($paidExtra) { HSPCC_Amelia::before_frontend($paidExtra); }, 'paid selected extra refused', true);
    check(!HSPCC_Busy_Time::$calls, 'paid extra does not query NAS');
    $filtered = HSPCC_Amelia::filter_slots(slots([[1, 1]]), array_merge($props, ['serviceId' => 3, 'extras' => [['id' => 9, 'quantity' => 1]]]));
    check($filtered['slots'] === [], 'paid selected extra has no online choices');
    $paidExtra['bookings'][0]['extras'][0]['quantity'] = 0;
    reset_busy();
    HSPCC_Amelia::before_frontend($paidExtra);
    check(count(HSPCC_Busy_Time::$calls) === 1, 'unselected zero-quantity paid extra is not charged/blocked');
    $paidCancel = payload(); $paidCancel['serviceId'] = 2; $paidCancel['bookings'][0]['status'] = 'canceled';
    reset_busy(new WP_Error());
    check(HSPCC_Amelia::before_update($paidCancel, [], $paidService) === $paidCancel && !HSPCC_Busy_Time::$calls, 'existing paid appointment can cancel with NAS down');

    $onsiteService = $service;
    $onsiteService = array_replace($onsiteService, ['id' => 5, 'duration' => 3600, 'price' => 120.0,
        'minCapacity' => 1, 'maxCapacity' => 1, 'timeBefore' => 0, 'timeAfter' => 900, 'extras' => []]);
    $cached = (new \ReflectionProperty(HSPCC_Amelia::class, 'services'))->getValue();
    $cached['1:5'] = $onsiteService;
    (new \ReflectionProperty(HSPCC_Amelia::class, 'services'))->setValue(null, $cached);
    $onsite = payload(); $onsite['serviceId'] = 5;
    $onsite['payment'] = ['gateway' => 'onSite', 'currency' => 'EUR'];
    $onsite['bookings'][0] = ['persons' => 1, 'duration' => 3600, 'extras' => [], 'deposit' => false];
    reset_busy();
    check(HSPCC_Amelia::before_frontend($onsite) === $onsite, 'fixed paid onsite frontend payload preserved');
    check(HSPCC_Busy_Time::$calls === [[utc('2026-11-02 08:00:00'), utc('2026-11-02 09:15:00'), true]], 'paid onsite uses fresh full duration plus after buffer');
    reset_busy();
    check(HSPCC_Amelia::filter_slots(slots([[1, 1]]), array_merge($props, ['serviceId' => 5]))['slots'] !== [], 'fixed capacity-one paid service has display choices');
    reset_busy([[utc('2026-11-02 09:05:00'), utc('2026-11-02 09:10:00')]]);
    refuses(static function () use ($onsite) { HSPCC_Amelia::before_frontend($onsite); }, 'paid after-buffer collision');
    check(HSPCC_Amelia::filter_slots(slots([[1, 1]]), array_merge($props, ['serviceId' => 5]))['slots'] === [], 'paid display choice removes buffered conflict');
    foreach (['stripe', 'payPal', 'mollie', 'wc', null, ['onSite']] as $gateway) {
        $badPaid = $onsite; $badPaid['payment']['gateway'] = $gateway;
        reset_busy();
        refuses(static function () use ($badPaid) { HSPCC_Amelia::before_frontend($badPaid); }, 'other or malformed payment refused', true);
        check(!HSPCC_Busy_Time::$calls, 'unsupported paid payment refused before calendar');
    }
    foreach (['amount', 'status', 'isBackendBooking', 'orderStatus', 'parentId', 'wcOrderId', 'transactionId'] as $field) {
        $badPaid = $onsite; $badPaid['payment'][$field] = 1;
        reset_busy();
        refuses(static function () use ($badPaid) { HSPCC_Amelia::before_frontend($badPaid); }, 'API financial state ' . $field, true);
        check(!HSPCC_Busy_Time::$calls, 'API state rejected before calendar');
    }
    foreach ([['gateway'=>'onSite'], ['gateway'=>'onSite','currency'=>'USD'], ['gateway'=>'onSite','currency'=>[]],
        ['gateway'=>'onSite','currency'=>'EUR','data'=>'malformed']] as $payment) {
        $badPaid = $onsite; $badPaid['payment'] = $payment;
        reset_busy();
        refuses(static function () use ($badPaid) { HSPCC_Amelia::before_frontend($badPaid); }, 'unnormalized API onsite state', true);
        check(!HSPCC_Busy_Time::$calls, 'unnormalized payment rejected before calendar');
    }
    foreach (['missing-payment', 'persons', 'group', 'waiting', 'custom-duration', 'extras', 'deposit', 'coupon', 'package-child', 'package', 'cart', 'recurring'] as $case) {
        $badPaid = $onsite;
        switch ($case) {
            case 'missing-payment': unset($badPaid['payment']); break;
            case 'persons': $badPaid['bookings'][0]['persons'] = 2; break;
            case 'group': $badPaid['bookings'][] = $badPaid['bookings'][0]; break;
            case 'waiting': $badPaid['bookings'][0]['status'] = 'waiting'; break;
            case 'custom-duration': $badPaid['bookings'][0]['duration'] = 7200; break;
            case 'extras': $badPaid['bookings'][0]['extras'] = [['extraId' => 9]]; break;
            case 'deposit': $badPaid['bookings'][0]['deposit'] = true; break;
            case 'coupon': $badPaid['couponCode'] = 'synthetic-discount'; break;
            case 'package-child': $badPaid['bookings'][0]['packageCustomerService'] = ['id' => 1]; break;
            case 'package': $badPaid['package'] = ['id' => 1]; break;
            case 'cart': $badPaid['isCart'] = true; break;
            case 'recurring': $badPaid['recurring'] = ['synthetic']; break;
        }
        reset_busy();
        refuses(static function () use ($badPaid) { HSPCC_Amelia::before_frontend($badPaid); }, 'paid scope ' . $case, true);
        check(!HSPCC_Busy_Time::$calls, $case . ' rejected before calendar');
    }
    foreach (['before_create', 'before_update'] as $hook) {
        reset_busy();
        refuses(static function () use ($onsite, $hook) { HSPCC_Amelia::$hook($onsite); }, 'unreviewed paid backend path', true);
        check(!HSPCC_Busy_Time::$calls, 'backend paid path not admitted by frontend flag');
    }
    reset_busy();
    $existingPaid = $onsite; $existingPaid['bookings'] = [];
    refuses(static function () use ($onsite, $onsiteService, $existingPaid) {
        HSPCC_Amelia::before_booking_saved($onsite['bookings'][0], $onsiteService, $existingPaid);
    }, 'existing paid parent append is outside reviewed create path', true);
    check(!HSPCC_Busy_Time::$calls, 'existing-parent paid append refused before calendar');
    HSP_Calendar_Connection::$provider = 7;
    $service7 = $service;
    $service7['duration'] = 3600;
    (new \ReflectionProperty(HSPCC_Amelia::class, 'services'))->setValue(null, ['1:1' => $service, '7:1' => $service7]);
    reset_busy([[utc('2026-11-02 08:00:00'), utc('2026-11-02 08:10:00')]]);
    $filtered = HSPCC_Amelia::filter_slots(slots([[7, 1], [1, 1]]), $props);
    check($filtered['slots']['2026-11-02']['09:00'] === [[1, 1]], 'configured employee7 blocked, unrelated employee1 retained');
    check(HSPCC_Busy_Time::$calls[0][1] - HSPCC_Busy_Time::$calls[0][0] === 4500, 'service cache is scoped by configured employee');
    $employee7 = payload(); $employee7['providerId'] = 7;
    refuses(function () use ($employee7) { HSPCC_Amelia::before_frontend($employee7); }, 'configured employee7 native save refused on overlap');
    reset_busy(new WP_Error());
    check(HSPCC_Amelia::before_frontend(payload()) === payload() && HSPCC_Busy_Time::$calls === [], 'unrelated employee1 native save bypasses employee7 NAS dependency');
    reset_busy();
    check(HSPCC_Amelia::before_frontend($employee7) === $employee7 && HSPCC_Busy_Time::$calls[0][2] === true, 'configured employee7 free save checks fresh availability');
    $container->bookings->rows[701] = ['id' => 701, 'appointmentId' => 700, 'status' => 'canceled', 'duration' => 3600, 'extras' => []];
    $container->appointments->rows[700] = ['id' => 700, 'providerId' => 7, 'serviceId' => 1,
        'bookingStart' => '2026-11-02 09:00:00', 'bookings' => [$container->bookings->rows[701]]];
    reset_busy([[utc('2026-11-02 08:00:00'), utc('2026-11-02 08:10:00')]]);
    refuses(function () { HSPCC_Amelia::before_approved(['id' => 701]); }, 'configured employee7 canonical approval checked');
    $container->appointments->rows[700]['providerId'] = 1;
    reset_busy(new WP_Error());
    HSPCC_Amelia::before_approved(['id' => 701]);
    check(HSPCC_Busy_Time::$calls === [], 'unrelated employee1 canonical approval unchanged');
    HSP_Calendar_Connection::$provider = 0;
    HSP_Calendar_Connection::$enabled = [7 => true];
    check(HSPCC_Amelia::filter_slots(slots([[7, 1], [1, 1]]), $props)['slots']['2026-11-02']['09:00'] === [[1, 1]], 'unset UI context cannot bypass enabled employee7 guard or affect unconfigured employee1');
    refuses(function () use ($employee7) { HSPCC_Amelia::before_frontend($employee7); }, 'unset UI context cannot silently bypass configured native save');
    HSP_Calendar_Connection::$provider = 1;
    HSP_Calendar_Connection::$enabled = [1 => true, 7 => true];
    reset_busy();
    HSPCC_Busy_Time::$perEmployee = [1 => [[utc('2026-11-02 08:00:00'), utc('2026-11-02 08:10:00')]], 7 => []];
    $mixed = slots([[1, 1], [7, 1], [2, 1]]);
    $filtered = HSPCC_Amelia::filter_slots($mixed, $props);
    check($filtered['slots']['2026-11-02']['09:00'] === [[7, 1], [2, 1]], 'two employees use different feeds within one native slot response');
    check(array_column(HSPCC_Busy_Time::$employeeCalls, 0) === [1, 7], 'native rows are grouped by employee before busy checks');
    check(HSPCC_Busy_Time::$employeeCalls[0][2] - HSPCC_Busy_Time::$employeeCalls[0][1] === 2700 &&
        HSPCC_Busy_Time::$employeeCalls[1][2] - HSPCC_Busy_Time::$employeeCalls[1][1] === 4500, 'each employee uses its own service cache duration');
    check(HSP_Calendar_Connection::$provider === 1, 'slot display restores initial connection context');
    reset_busy();
    HSPCC_Busy_Time::$perEmployee = [1 => [], 7 => new WP_Error()];
    $filtered = HSPCC_Amelia::filter_slots($mixed, $props);
    check($filtered['slots']['2026-11-02']['09:00'] === [[1, 1], [2, 1]], 'employee7 feed outage removes only employee7 choices');
    check(HSP_Calendar_Connection::$provider === 1, 'failed second feed restores initial connection context');
    check(HSPCC_Amelia::before_frontend(payload()) === payload(), 'employee1 native save remains available while employee7 feed fails');
    refuses(function () use ($employee7) { HSPCC_Amelia::before_frontend($employee7); }, 'employee7 native save fails closed on its own feed');
    check(end(HSPCC_Busy_Time::$employeeCalls)[0] === 7 && end(HSPCC_Busy_Time::$employeeCalls)[3] === true,
        'employee7 save uses its own uncached availability');
    check(HSP_Calendar_Connection::$provider === 1, 'failed employee7 save restores context');
    HSP_Calendar_Connection::$provider = 7;
    HSPCC_Busy_Time::$perEmployee = [1 => new WP_Error(), 7 => []];
    check(HSPCC_Amelia::before_frontend($employee7) === $employee7, 'employee7 remains free while employee1 fails');
    refuses(function () { HSPCC_Amelia::before_frontend(payload()); }, 'employee1 native save is routed from canonical provider rather than selected employee7');
    check(HSP_Calendar_Connection::$provider === 7, 'failed employee1 save restores selected context7');
    $container->appointments->rows[700]['providerId'] = 7;
    HSPCC_Busy_Time::$perEmployee = [1 => [], 7 => new WP_Error()];
    refuses(function () { HSPCC_Amelia::before_approved(['id' => 701]); }, 'canonical booking approval routes to employee7 connection');
    check(HSP_Calendar_Connection::$provider === 7, 'status failure restores selected context');
    HSP_Calendar_Connection::$enabled = [1 => true, 7 => false];
    HSPCC_Busy_Time::$calls = [];
    check(HSPCC_Amelia::before_frontend($employee7) === $employee7 && !HSPCC_Busy_Time::$calls,
        'disabled employee7 guard leaves native save untouched');
    HSP_Calendar_Connection::$enabled = [];
    check(HSPCC_Amelia::filter_slots($mixed, $props) === $mixed, 'all unconfigured employees keep native slot result and hints');
    HSP_Calendar_Connection::$provider = 1;
    HSP_Calendar_Connection::$enabled = null;
    echo 'PASS: ' . $GLOBALS['hspcc_assertions'] . " native guard checks, including independent employee feeds, failures and restored context; offline stubs only.\n";
}
