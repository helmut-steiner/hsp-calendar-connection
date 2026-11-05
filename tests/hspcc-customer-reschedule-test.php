<?php
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    $nativePath = getenv('HSPCC_AMELIA_PATH');
    if (!$nativePath) { echo "SKIP: native customer reschedule needs HSPCC_AMELIA_PATH; no paid dependency is bundled.\n"; exit; }
    $nativeVersionSource = file_get_contents(rtrim($nativePath, '/\\') . '/ameliabooking.php');
    if (!preg_match('/Version:\s*9\.9\.1\s/', $nativeVersionSource)) { throw new \RuntimeException('Fixture requires reviewed Amelia 9.9.1.'); }
    define('ABSPATH', __DIR__ . '/');
    define('AMELIA_VERSION', ($argv[1] ?? '') === 'future-version' ? '9.9.2' : '9.9.1');
    $reporting = error_reporting(E_ALL & ~E_DEPRECATED);
    require rtrim($nativePath, '/\\') . '/vendor/autoload.php';
    class_exists('AmeliaVendor\\Firebase\\JWT\\JWT'); // Reviewed vendor's PHP 8.4 declarations.
    error_reporting($reporting);
}
namespace AmeliaBooking\Infrastructure\WP\SettingsService {
    // Fake storage only. Real native SettingsService, Command token extraction,
    // UserApplicationService authorization/JWT verification and user entities run.
    class SettingsStorage implements \AmeliaBooking\Domain\Services\Settings\SettingsStorageInterface {
        public static $data = [];
        public function getSetting($g, $k) { return self::$data[$g][$k] ?? null; }
        public function getCategorySettings($g) { return self::$data[$g] ?? []; }
        public function getAllSettings() { return self::$data; }
        public function getAllSettingsCategorized() { return self::$data; }
        public function getFrontendSettings() { return []; }
        public function getBackendSettings() { return []; }
        public function setSetting($g, $k, $v) { throw new \RuntimeException('No settings writes in fixture.'); }
        public function setCategorySettings($g, $v) { throw new \RuntimeException('No settings writes in fixture.'); }
        public function setAllSettings($v) { throw new \RuntimeException('No settings writes in fixture.'); }
    }
}
namespace {
    use AmeliaBooking\Infrastructure\Common\Container;
    use AmeliaBooking\Infrastructure\WP\SettingsService\SettingsStorage;
    use AmeliaBooking\Domain\Services\Settings\SettingsService;
    use AmeliaBooking\Domain\Entity\User\AbstractUser;
    use AmeliaBooking\Domain\ValueObjects\Number\Integer\Id;
    use AmeliaBooking\Domain\ValueObjects\String\Name;
    use AmeliaBooking\Domain\ValueObjects\String\Email;
    use AmeliaBooking\Domain\ValueObjects\String\Password;
    use AmeliaBooking\Application\Services\User\UserApplicationService;
    use AmeliaVendor\Firebase\JWT\JWT;

    class NativeFixtureUser extends AbstractUser {
        public $role = 'customer';
        public function getType() { return $this->role; }
    }
    class NativeFixtureRow {
        public function __construct(public array $row) {}
        public function toArray() { return $this->row; }
    }
    class NativeFixtureCollection {
        public function __construct(public array $rows) {}
        public function getItems() { return array_map(static fn($r) => new NativeFixtureRow($r), $this->rows); }
    }
    class NativeFixtureRepo {
        public $rows = [];
        public $targets = [];
        public $user;
        public $fail = false;
        public $queries = [];
        public function getById($id) {
            if ($this->fail) { throw new RuntimeException('Synthetic DB error.'); }
            return isset($this->rows[$id]) ? new NativeFixtureRow($this->rows[$id]) : null;
        }
        public function getByEmail($email, ...$unused) { return $email === 'fixture@example.test' ? $this->user : null; }
        public function getFiltered($criteria) {
            if ($this->fail) { throw new RuntimeException('Synthetic DB error.'); }
            $this->queries[] = $criteria;
            return new NativeFixtureCollection($this->targets);
        }
        public function getByEntityId($id, $column) {
            if ($this->fail) { throw new RuntimeException('Synthetic payment lookup error.'); }
            $this->queries[] = [$id, $column];
            return new NativeFixtureCollection($this->targets);
        }
    }
    class NativeFixtureRequest {
        public $method = 'POST';
        public $source = 'cabinet-customer';
        public $path = '/api/v1/bookings/reassign/51';
        public $token = '';
        public $cookies = [];
        public $body = ['bookingStart' => '2026-11-04 11:00', 'timeZone' => 'Europe/Vienna', 'utcOffset' => null];
        public function getMethod() { return $this->method; }
        public function getQueryParams() { return ['source' => $this->source]; }
        public function getUri() { return $this; }
        public function getPath() { return $this->path; }
        public function getParsedBody() { return $this->body; }
        public function getHeaderLine($name) { return $name === 'Authorization' && $this->token !== '' ? 'Bearer ' . $this->token : ''; }
        public function getCookieParams() { return $this->cookies; }
    }
    final class HSP_Calendar_Connection {
        public static $employee = 1;
        public static function provider_id() { return self::$employee; }
        public static function blocking_enabled($id) { return in_array($id, [1,7], true); }
        public static function with_employee($id, callable $fn) {
            $old = self::$employee; self::$employee = $id;
            try { return $fn(); } finally { self::$employee = $old; }
        }
    }
    class WP_Error {}
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function __($text, $domain = '') { return $text; }
    function get_user_by($field, $value) { return false; }
    $GLOBALS['native_hooks'] = [];
    if (!function_exists('add_action')) {
        function add_filter($name, $callback, $priority = 10, $count = 1) { $GLOBALS['native_hooks'][$name] = $callback; }
        function add_action($name, $callback, $priority = 10, $count = 1) { add_filter($name, $callback, $priority, $count); }
    }
    final class HSPCC_Busy_Time {
        public static $periods = [];
        public static $calls = [];
        public static function intervals($from, $to, $fresh) {
            self::$calls[] = [HSP_Calendar_Connection::$employee, $from, $to, $fresh];
            return self::$periods;
        }
    }
    require dirname(__DIR__) . '/includes/class-hspcc-amelia.php';
    AmeliaBooking\Domain\Services\DateTime\DateTimeService::setTimeZone(['timeZoneString' => 'Europe/Vienna']);
    $checks = 0;
    function native_check($ok, $label) { ++$GLOBALS['checks']; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } }
    function native_refuses(callable $fn, $label) {
        try { $fn(); } catch (AmeliaBooking\Domain\Common\Exceptions\CustomException $e) { native_check(true, $label); return; }
        throw new RuntimeException('FAIL: did not refuse ' . $label);
    }
    function native_reset(): array {
        SettingsStorage::$data = ['roles' => ['enabledHttpAuthorization' => true, 'customerCabinet' => [
            'headerJwtSecret' => 'synthetic-native-header-key-for-fixtures-only', 'loginEnabled' => true,
        ]]];
        $user = new NativeFixtureUser(new Name('Synthetic'), new Name('Customer'), new Email('fixture@example.test'));
        $user->setId(new Id(42));
        $user->setPassword(Password::createFromHashedPassword('synthetic-existing-hash'));
        $request = new NativeFixtureRequest();
        $request->token = JWT::encode(['email' => 'fixture@example.test', 'wp' => 3, 'exp' => time() + 3600], SettingsStorage::$data['roles']['customerCabinet']['headerJwtSecret'], 'HS256');
        $booking = ['id' => 51, 'appointmentId' => 11, 'customerId' => 42, 'status' => 'approved', 'persons' => 1, 'duration' => 1800, 'extras' => []];
        $appointment = ['id' => 11, 'providerId' => 1, 'serviceId' => 1, 'locationId' => 1, 'status' => 'approved',
            'bookingStart' => '2026-11-04 09:00:00', 'bookingEnd' => '2026-11-04 09:30:00', 'bookings' => [$booking]];
        $bookingRepo = new NativeFixtureRepo(); $bookingRepo->rows[51] = $booking;
        $appointmentRepo = new NativeFixtureRepo(); $appointmentRepo->rows[11] = $appointment;
        $userRepo = new NativeFixtureRepo(); $userRepo->user = $user;
        $container = new Container([
            'domain.settings.service' => new SettingsService(new SettingsStorage()),
            'domain.booking.customerBooking.repository' => $bookingRepo,
            'domain.booking.appointment.repository' => $appointmentRepo,
            'domain.users.repository' => $userRepo, 'logged.in.user' => null,
            'domain.permissions.service' => null, 'request' => $request,
            'application.user.service' => static fn($c) => new UserApplicationService($c),
        ]);
        (new ReflectionProperty(HSPCC_Amelia::class, 'container'))->setValue(null, $container);
        (new ReflectionProperty(HSPCC_Amelia::class, 'services'))->setValue(null, ['1:1' => [
            'id' => 1, 'duration' => 1800, 'price' => 0, 'timeBefore' => 0, 'timeAfter' => 900,
            'minCapacity' => 1, 'maxCapacity' => 1, 'extras' => [],
        ]]);
        HSPCC_Busy_Time::$periods = []; HSPCC_Busy_Time::$calls = []; HSP_Calendar_Connection::$employee = 1;
        return [$container, $request, $user, $bookingRepo, $appointmentRepo, $appointment, $booking];
    }
    function native_paid_reset(): array {
        [$container,$request,$user,$bookingRepo,$appointmentRepo,$appointment,$booking] = native_reset();
        $booking = array_replace($booking, ['price'=>120.0,'duration'=>3600,'couponId'=>null,'coupon'=>null,
            'tax'=>[['id'=>3,'name'=>'Synthetic VAT','amount'=>20,'type'=>'percentage','excluded'=>true]]]);
        $appointment['bookings'] = [$booking]; $appointment['bookingEnd'] = '2026-11-04 10:00:00';
        $bookingRepo->rows[51] = $booking; $appointmentRepo->rows[11] = $appointment;
        $paymentRepo = new NativeFixtureRepo();
        $paymentRepo->targets = [['id'=>81,'customerBookingId'=>51,'gateway'=>'onSite','status'=>'pending','amount'=>0,
            'packageCustomerId'=>null,'parentId'=>null,'wcOrderId'=>null,'wcOrderItemId'=>null,'transactionId'=>null,'paymentLinks'=>null]];
        $container['domain.payment.repository'] = $paymentRepo;
        $service = (new ReflectionProperty(HSPCC_Amelia::class,'services'))->getValue();
        $service['1:1']['price'] = 120.0; $service['1:1']['duration'] = 3600;
        (new ReflectionProperty(HSPCC_Amelia::class,'services'))->setValue(null,$service);
        return [$container,$request,$user,$bookingRepo,$appointmentRepo,$appointment,$booking,$paymentRepo];
    }
    HSPCC_Amelia::boot();
    function native_move($appointment, $booking) { ($GLOBALS['native_hooks']['amelia_before_booking_rescheduled'])($appointment, $booking, '2026-11-04 11:00:00'); }
    function native_http_request($token, $body) {
        $savedServer = $_SERVER; $savedGet = $_GET; $savedPost = $_POST;
        try {
            $_SERVER = ['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/wp-admin/admin-ajax.php?action=wpamelia_api&call=/api/v1/bookings/reassign/51&source=cabinet-customer',
                'HTTP_HOST'=>'fixture.example.test','SERVER_NAME'=>'fixture.example.test','SERVER_PORT'=>80,
                'QUERY_STRING'=>'action=wpamelia_api&call=/api/v1/bookings/reassign/51&source=cabinet-customer','CONTENT_TYPE'=>'application/json'];
            $_GET = ['action'=>'wpamelia_api','call'=>'/api/v1/bookings/reassign/51','source'=>'cabinet-customer']; $_POST=[];
            $entries=[];
            require rtrim(getenv('HSPCC_AMELIA_PATH'),'/\\') . '/src/Infrastructure/ContainerConfig/request.php';
            $request = (new Container($entries))->get('request');
            return $request->withBody((new Slim\Psr7\Factory\StreamFactory())->createStream($body))
                ->withHeader('Content-Type','application/json')->withHeader('Authorization','Bearer '.$token)->withParsedBody(null);
        } finally { $_SERVER=$savedServer; $_GET=$savedGet; $_POST=$savedPost; }
    }
    [$container,$request,$user,$bookingRepo,$appointmentRepo,$appointment,$booking] = native_reset();
    if (AMELIA_VERSION !== '9.9.1') {
        native_refuses(static fn() => native_move($appointment,$booking), 'unreviewed version fails closed');
        [$container,$request,$user,$bookingRepo,$appointmentRepo,$appointment,$booking] = native_paid_reset();
        native_refuses(static fn() => native_move($appointment,$booking), 'unreviewed paid version fails closed');
        $appointment['payment'] = ['gateway'=>'onSite'];
        native_refuses(static fn() => HSPCC_Amelia::before_frontend($appointment), 'unreviewed paid creation version fails closed');
        echo "PASS: unreviewed native version refused.\n"; exit;
    }
    native_move($appointment, $booking);
    native_check(HSPCC_Busy_Time::$calls === [[1, strtotime('2026-11-04 10:00:00 UTC'), strtotime('2026-11-04 10:45:00 UTC'), true]], 'real native JWT auth reaches fresh buffered check');
    native_check($appointmentRepo->queries[0]['dates'] === ['2026-11-04 11:00:00','2026-11-04 11:00:00'], 'target merge query uses normalized native target');
    native_check(HSP_Calendar_Connection::$employee === 1, 'employee context restored');
    native_check($appointmentRepo->rows[11]['id'] === 11 && count($appointmentRepo->rows[11]['bookings']) === 1 && !$appointmentRepo->targets,
        'eligible scope is one existing parent and no merge target; native in-place branch retains ID');
    [$container,$request,$user,$bookingRepo,$appointmentRepo,$appointment,$booking] = native_reset();
    $httpRequest = native_http_request($request->token,json_encode($request->body));
    native_check($httpRequest->getUri()->getPath() === '/api/v1/bookings/reassign/51' && $httpRequest->getQueryParams()['source'] === 'cabinet-customer',
        'actual native request factory rewrites admin-ajax call path and source');
    native_check($httpRequest->getParsedBody() === null, 'actual JSON controller-equivalent request begins before middleware');
    $container['request']=$httpRequest;
    native_move($appointment,$booking);
    native_check(count(HSPCC_Busy_Time::$calls) === 1 && HSPCC_Busy_Time::$calls[0][3], 'installed native Slim parser and JWT permit genuine JSON request');
    [$container,$request,$user,$bookingRepo,$appointmentRepo,$appointment,$booking] = native_reset();
    $container['request']=native_http_request($request->token,'{"bookingStart":"2026-11-04 11:00","providerId":7}');
    native_refuses(static fn()=>native_move($appointment,$booking),'native parsed JSON target change');
    native_check(HSPCC_Busy_Time::$calls === [], 'native JSON target change refused before calendar');
    [$container,$request,$user,$bookingRepo,$appointmentRepo,$appointment,$booking] = native_reset();
    $request->cookies = ['ameliaToken' => $request->token]; $request->token = '';
    native_move($appointment,$booking); native_check(count(HSPCC_Busy_Time::$calls) === 1, 'native cookie authentication works');
    foreach ([['UTC','2026-11-04 10:00',null],['America/New_York','2026-11-04 05:00',null],[null,'2026-11-04 10:00',60]] as [$inputZone,$inputWall,$inputOffset]) {
        [$container,$request,$user,$bookingRepo,$appointmentRepo,$appointment,$booking] = native_reset();
        $request->body=['bookingStart'=>$inputWall,'timeZone'=>$inputZone,'utcOffset'=>$inputOffset];
        SettingsStorage::$data['general']['showClientTimeZone']=true;
        native_move($appointment,$booking);
        native_check(HSPCC_Busy_Time::$calls[0][1] === strtotime('2026-11-04 10:00:00 UTC'), 'native timezone normalization binds request to hook time');
    }
    foreach (['missing-token','tampered-token','expired-token','wrong-customer','missing-password','admin','provider','wrong-source','wrong-route','wrong-method','target-provider','target-service','target-location','target-status','custom-fields','unknown-field','malformed-body','time-mismatch','bad-timezone-type','bad-offset-type','wrong-membership','missing-booking','wrong-child','group','waiting','closed','persons','merge','db-failure','paid','custom-pricing','group-service','busy','feed-failure','changed-target','missing-target'] as $case) {
        [$container,$request,$user,$bookingRepo,$appointmentRepo,$appointment,$booking] = native_reset();
        switch ($case) {
            case 'missing-token': $request->token = ''; break;
            case 'tampered-token': $request->token .= 'x'; break;
            case 'expired-token': $request->token = JWT::encode(['email'=>'fixture@example.test','wp'=>3,'exp'=>time()-3600],SettingsStorage::$data['roles']['customerCabinet']['headerJwtSecret'],'HS256'); break;
            case 'wrong-customer': $user->setId(new Id(43)); break;
            case 'missing-password': $user->setPassword(null); break;
            case 'admin': $user->role = 'admin'; $container['logged.in.user'] = $user; break;
            case 'provider': $user->role = 'provider'; $container['logged.in.user'] = $user; break;
            case 'wrong-source': $request->source = 'cabinet-provider'; break;
            case 'wrong-route': $request->path = '/api/v1/bookings/reassign/52'; break;
            case 'wrong-method': $request->method = 'GET'; break;
            case 'target-provider': $request->body['providerId'] = 7; break;
            case 'target-service': $request->body['serviceId'] = 2; break;
            case 'target-location': $request->body['locationId'] = 2; break;
            case 'target-status': $request->body['status'] = 'approved'; break;
            case 'custom-fields': $request->body['customFields'] = []; break;
            case 'unknown-field': $request->body['notNative'] = true; break;
            case 'malformed-body': $request->body = null; break;
            case 'time-mismatch': $request->body['bookingStart'] = '2026-11-04 12:00'; break;
            case 'bad-timezone-type': $request->body['timeZone'] = []; break;
            case 'bad-offset-type': $request->body['utcOffset'] = '60'; break;
            case 'wrong-membership': $bookingRepo->rows[51]['appointmentId'] = 12; break;
            case 'missing-booking': $bookingRepo->rows = []; break;
            case 'wrong-child': $appointmentRepo->rows[11]['bookings'][0]['id'] = 52; break;
            case 'group': $appointmentRepo->rows[11]['bookings'][] = $booking; break;
            case 'waiting': $bookingRepo->rows[51]['status'] = 'waiting'; $appointmentRepo->rows[11]['bookings'][0]['status'] = 'waiting'; break;
            case 'closed': $appointmentRepo->rows[11]['status'] = 'canceled'; break;
            case 'persons': $bookingRepo->rows[51]['persons'] = 2; break;
            case 'merge': $appointmentRepo->targets = [['id'=>12]]; break;
            case 'db-failure': $appointmentRepo->fail = true; break;
            case 'paid': case 'custom-pricing': case 'group-service':
                $service = (new ReflectionProperty(HSPCC_Amelia::class,'services'))->getValue();
                if ($case === 'paid') { $service['1:1']['price'] = 10; }
                if ($case === 'custom-pricing') { $service['1:1']['customPricing'] = ['enabled'=>true]; }
                if ($case === 'group-service') { $service['1:1']['maxCapacity'] = 2; }
                (new ReflectionProperty(HSPCC_Amelia::class,'services'))->setValue(null,$service); break;
            case 'busy': HSPCC_Busy_Time::$periods = [[strtotime('2026-11-04 10:35:00 UTC'),strtotime('2026-11-04 10:40:00 UTC')]]; break;
            case 'feed-failure': HSPCC_Busy_Time::$periods = new WP_Error(); break;
            case 'changed-target': $appointment['providerId'] = 7; break;
            case 'missing-target': $appointmentRepo->rows = []; break;
        }
        native_refuses(static fn() => native_move($appointment,$booking), $case);
        native_check(HSP_Calendar_Connection::$employee === 1, $case . ' scope restored');
        if (!in_array($case,['busy','feed-failure'],true)) { native_check(HSPCC_Busy_Time::$calls === [], $case . ' rejected before calendar access'); }
    }
    // Request actor and active admin selection cannot reroute another employee.
    [$container,$request,$user,$bookingRepo,$appointmentRepo,$appointment,$booking] = native_reset();
    $appointment['providerId'] = 7; $appointmentRepo->rows[11]['providerId'] = 7;
    $service = (new ReflectionProperty(HSPCC_Amelia::class,'services'))->getValue(); $service['7:1'] = $service['1:1'];
    (new ReflectionProperty(HSPCC_Amelia::class,'services'))->setValue(null,$service);
    native_move($appointment,$booking);
    native_check(HSPCC_Busy_Time::$calls[0][0] === 7 && HSP_Calendar_Connection::$employee === 1, 'canonical employee seven isolated from selected one');

    [$container,$request,$user,$bookingRepo,$appointmentRepo,$appointment,$booking,$paymentRepo] = native_paid_reset();
    $beforePaid = [$appointmentRepo->rows, $bookingRepo->rows, $paymentRepo->targets];
    $container['request'] = native_http_request($request->token, json_encode($request->body));
    native_move($appointment,$booking);
    native_check(HSPCC_Busy_Time::$calls === [[1,strtotime('2026-11-04 10:00:00 UTC'),strtotime('2026-11-04 11:15:00 UTC'),true]], 'paid onsite native JSON/JWT move uses fresh full duration plus buffer');
    native_check($paymentRepo->queries === [[51,'customerBookingId']], 'payment relationship read by canonical native booking relation');
    native_check([$appointmentRepo->rows,$bookingRepo->rows,$paymentRepo->targets] === $beforePaid, 'guard preserves native paid base price, tax, payment amount/status and all storage');
    foreach (['online','missing-payment','multiple-payments','payment-owner','package-payment','parent-payment','wc-payment','online-transaction','payment-link','refunded','invalid-amount','price-change','booking-coupon','booking-package','booking-extra','booking-duration','payment-failure','busy','feed-failure'] as $case) {
        [$container,$request,$user,$bookingRepo,$appointmentRepo,$appointment,$booking,$paymentRepo] = native_paid_reset();
        switch ($case) {
            case 'online': $paymentRepo->targets[0]['gateway'] = 'stripe'; break;
            case 'missing-payment': $paymentRepo->targets = []; break;
            case 'multiple-payments': $paymentRepo->targets[] = $paymentRepo->targets[0]; break;
            case 'payment-owner': $paymentRepo->targets[0]['customerBookingId'] = 52; break;
            case 'package-payment': $paymentRepo->targets[0]['packageCustomerId'] = 9; break;
            case 'parent-payment': $paymentRepo->targets[0]['parentId'] = 9; break;
            case 'wc-payment': $paymentRepo->targets[0]['wcOrderId'] = 9; break;
            case 'online-transaction': $paymentRepo->targets[0]['transactionId'] = 'synthetic'; break;
            case 'payment-link': $paymentRepo->targets[0]['paymentLinks'] = ['synthetic']; break;
            case 'refunded': $paymentRepo->targets[0]['status'] = 'refunded'; break;
            case 'invalid-amount': $paymentRepo->targets[0]['amount'] = -1; break;
            case 'price-change': $bookingRepo->rows[51]['price'] = 121; break;
            case 'booking-coupon': $bookingRepo->rows[51]['couponId'] = 9; break;
            case 'booking-package': $bookingRepo->rows[51]['packageCustomerService'] = ['id'=>9]; break;
            case 'booking-extra': $bookingRepo->rows[51]['extras'] = [['extraId'=>9]]; break;
            case 'booking-duration': $bookingRepo->rows[51]['duration'] = 7200; break;
            case 'payment-failure': $paymentRepo->fail = true; break;
            case 'busy': HSPCC_Busy_Time::$periods = [[strtotime('2026-11-04 11:05:00 UTC'),strtotime('2026-11-04 11:10:00 UTC')]]; break;
            case 'feed-failure': HSPCC_Busy_Time::$periods = new WP_Error(); break;
        }
        native_refuses(static fn()=>native_move($appointment,$booking), 'paid ' . $case);
        if (!in_array($case,['busy','feed-failure'],true)) { native_check(HSPCC_Busy_Time::$calls === [], 'paid ' . $case . ' refused before calendar access'); }
    }
    echo 'PASS: ' . $checks . " native customer-reschedule assertions; actual installed Command, UserApplicationService and JWT verification; synthetic storage/calendar only.\n";
}
