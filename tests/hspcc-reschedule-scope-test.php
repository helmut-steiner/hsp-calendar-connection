<?php
/** Synthetic employee-scope regressions; no native bootstrap, HTTP or storage. */
namespace AmeliaBooking\Domain\Common\Exceptions {
    class CustomException extends \Exception {}
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    define('ABSPATH', __DIR__ . '/');
    define('AMELIA_VERSION', $argv[1] ?? '9.9.1');
    final class HSP_Calendar_Connection {
        public static $enabled = [1 => true];
        public static function blocking_enabled($id) { return !empty(self::$enabled[$id]); }
    }
    function __($text, $domain = '') { return $text; }
    final class Scope_Model {
        private $row;
        public function __construct($row) { $this->row = $row; }
        public function toArray() { return $this->row; }
    }
    final class Scope_Repository {
        public $rows = [];
        public function getById($id) { return isset($this->rows[$id]) ? new Scope_Model($this->rows[$id]) : null; }
    }
    final class Scope_Request {
        public $body = ['bookingStart' => '2026-12-01 10:00'];
        public $source = 'cabinet-provider';
        public function getParsedBody() { return $this->body; }
        public function getMethod() { return 'POST'; }
        public function getQueryParams() { return ['source' => $this->source]; }
    }
    final class Scope_Container {
        public $bookings;
        public $appointments;
        public $request;
        public function __construct() {
            $this->bookings = new Scope_Repository();
            $this->appointments = new Scope_Repository();
            $this->request = new Scope_Request();
        }
        public function get($key) {
            switch ($key) {
                case 'domain.booking.customerBooking.repository': return $this->bookings;
                case 'domain.booking.appointment.repository': return $this->appointments;
                case 'request': return $this->request;
            }
            throw new \RuntimeException('Unexpected native lookup in scope fixture.');
        }
    }
    require dirname(__DIR__) . '/includes/class-hspcc-amelia.php';
    $checks = 0;
    function verify($condition, $label) {
        ++$GLOBALS['checks'];
        if (!$condition) { throw new \RuntimeException('FAIL: ' . $label); }
    }
    function fixture($provider = 2) {
        HSP_Calendar_Connection::$enabled = [1 => true];
        $booking = ['id' => 202, 'appointmentId' => 101, 'customerId' => 303, 'persons' => 1, 'status' => 'approved'];
        $appointment = ['id' => 101, 'providerId' => $provider, 'serviceId' => 7, 'locationId' => null,
            'bookingStart' => '2026-12-01 09:00:00', 'bookings' => [$booking]];
        $container = new Scope_Container();
        $container->bookings->rows[202] = $booking;
        $container->appointments->rows[101] = $appointment;
        (new \ReflectionProperty(HSPCC_Amelia::class, 'container'))->setValue(null, $container);
        return [$appointment, $booking, $container];
    }
    function move($appointment, $booking, $expected, $label) {
        try { HSPCC_Amelia::before_reschedule($appointment, $booking, '2026-12-01 10:00:00'); $actual = 'allow'; }
        catch (\AmeliaBooking\Domain\Common\Exceptions\CustomException $error) { $actual = 'refuse'; }
        verify($actual === $expected, $label);
    }
    [$a, $b, $c] = fixture();
    move($a, $b, 'allow', 'unconfigured employee staff move remains native');
    $c->request->source = 'cabinet-customer';
    move($a, $b, 'allow', 'unconfigured employee customer move remains native');
    $c->request->source = 'wp-admin';
    move($a, $b, 'allow', 'unconfigured employee backend move remains native');
    $c->appointments->rows[101]['bookings'][] = ['id' => 203, 'appointmentId' => 101];
    $a = $c->appointments->rows[101];
    move($a, $b, 'allow', 'unconfigured native group is not subject to capacity-one restriction');
    [$a, $b, $c] = fixture();
    $c->request->body['providerId'] = 3;
    move($a, $b, 'allow', 'unconfigured source and target remain native');
    $c->request->body['providerId'] = 1;
    move($a, $b, 'refuse', 'unconfigured source cannot bypass a configured target');
    $c->request->body['providerId'] = '1';
    move($a, $b, 'refuse', 'numeric-string configured target stays protected');
    $c->request->body['providerId'] = ['1'];
    move($a, $b, 'refuse', 'malformed target scope is refused');
    [$a, $b, $c] = fixture(1);
    move($a, $b, 'refuse', 'configured employee keeps customer-only/version restrictions');
    $c->request->body['providerId'] = 2;
    move($a, $b, 'refuse', 'configured source cannot bypass guard via an unconfigured target');
    HSP_Calendar_Connection::$enabled[1] = false;
    move($a, $b, 'allow', 'explicitly disabled source and unconfigured target remain native');
    [$a, $b, $c] = fixture();
    $a['providerId'] = 3;
    move($a, $b, 'refuse', 'payload owner mismatch cannot bypass canonical membership');
    [$a, $b, $c] = fixture();
    $c->appointments->rows[101]['providerId'] = 1;
    move($a, $b, 'refuse', 'canonical configured owner wins over supplied unconfigured owner');
    [$a, $b, $c] = fixture();
    $c->bookings->rows[202]['appointmentId'] = 102;
    move($a, $b, 'refuse', 'canonical booking parent mismatch is refused');
    [$a, $b, $c] = fixture();
    unset($c->bookings->rows[202]);
    move($a, $b, 'refuse', 'missing canonical booking is refused');
    [$a, $b, $c] = fixture();
    unset($c->appointments->rows[101]);
    move($a, $b, 'refuse', 'missing canonical appointment is refused');
    [$a, $b, $c] = fixture();
    unset($a['id']);
    move($a, $b, 'refuse', 'missing appointment identity is refused');
    move(null, $b, 'refuse', 'missing native scope is refused');
    echo 'PASS: ' . $checks . ' rescheduling employee-scope checks for Amelia ' . AMELIA_VERSION . '; synthetic native state only.', PHP_EOL;
}
