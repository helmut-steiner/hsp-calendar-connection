<?php
/**
 * Optional Amelia 9.9.1 contract fixture. No WordPress bootstrap or paid code is bundled.
 * Set HSPCC_AMELIA_PATH to an existing, separately installed Amelia plugin directory.
 * Native pricing, request-payment validation, entities and payment creation run;
 * only settings storage, the payment repository and WordPress hooks are synthetic.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$nativePath = getenv('HSPCC_AMELIA_PATH');
if ($nativePath === false || $nativePath === '') {
    echo "SKIP: native onsite pricing needs HSPCC_AMELIA_PATH; no paid dependency is bundled.\n";
    exit;
}
$nativePath = realpath($nativePath);
if ($nativePath === false || !is_dir($nativePath)
    || !is_file($nativePath . '/ameliabooking.php')
    || !is_file($nativePath . '/vendor/autoload.php')) {
    throw new RuntimeException('HSPCC_AMELIA_PATH must identify an installed Amelia plugin with its autoloader.');
}
$versionSource = file_get_contents($nativePath . '/ameliabooking.php');
if (!is_string($versionSource) || !preg_match('/Version:\s*9\.9\.1\s/', $versionSource)) {
    throw new RuntimeException('Native onsite pricing fixture requires reviewed Amelia 9.9.1.');
}
if (defined('ABSPATH') || function_exists('get_option') || function_exists('apply_filters')) {
    throw new RuntimeException('Run this isolated fixture without WordPress or another fixture loaded.');
}
define('ABSPATH', __DIR__ . '/');
define('AMELIA_VERSION', '9.9.1');
// Keep optional vendor auto-instrumentation inert in this isolated CLI process.
putenv('OTEL_SDK_DISABLED=true');
putenv('OTEL_PHP_AUTOLOAD_ENABLED=false');
$previousReporting = error_reporting(E_ALL & ~E_DEPRECATED);
try {
    require $nativePath . '/vendor/autoload.php';
} finally {
    error_reporting($previousReporting);
}

use AmeliaBooking\Application\Services\Payment\PaymentApplicationService;
use AmeliaBooking\Application\Services\Reservation\AppointmentReservationService;
use AmeliaBooking\Application\Services\Tax\TaxApplicationService;
use AmeliaBooking\Domain\Collection\Collection;
use AmeliaBooking\Domain\Entity\Bookable\Service\Service;
use AmeliaBooking\Domain\Factory\Booking\Appointment\CustomerBookingFactory;
use AmeliaBooking\Domain\Services\DateTime\DateTimeService;
use AmeliaBooking\Domain\Services\Settings\SettingsService;
use AmeliaBooking\Domain\Services\Settings\SettingsStorageInterface;
use AmeliaBooking\Domain\ValueObjects\BooleanValueObject;
use AmeliaBooking\Domain\ValueObjects\Number\Float\Price;
use AmeliaBooking\Infrastructure\Common\Container;

/** Hooks are identity operations and reject unexpected native side effects. */
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$arguments) {
        if (!in_array($tag, ['amelia_modify_payment_amount', 'amelia_before_payment'], true)) {
            throw new RuntimeException('Unexpected native filter in isolated pricing fixture: ' . $tag);
        }
        $GLOBALS['hspcc_native_price_hooks'][] = [$tag, $value, $arguments];
        return $value;
    }
}

class HSPCCNativePriceSettings implements SettingsStorageInterface {
    private array $settings = ['payments' => ['currency' => 'EUR']];
    public function getSetting($group, $key) { return $this->settings[$group][$key] ?? null; }
    public function getCategorySettings($group) { return $this->settings[$group] ?? []; }
    public function getAllSettings() { return $this->settings; }
    public function getAllSettingsCategorized() { return $this->settings; }
    public function getFrontendSettings() { return []; }
    public function getBackendSettings() { return []; }
    public function setSetting($group, $key, $value) { throw new RuntimeException('No settings writes.'); }
    public function setCategorySettings($group, $value) { throw new RuntimeException('No settings writes.'); }
    public function setAllSettings($value) { throw new RuntimeException('No settings writes.'); }
}

/** Native payment entities are retained in memory; this is not a DB adapter. */
class HSPCCNativePricePaymentRepository {
    public array $rows = [];
    public int $reads = 0;
    public function add($payment) {
        $id = 700 + count($this->rows);
        $this->rows[$id] = $payment;
        return $id;
    }
    public function getById($id) {
        ++$this->reads;
        if (!isset($this->rows[$id])) { throw new RuntimeException('Unknown synthetic payment.'); }
        return clone $this->rows[$id];
    }
}

$checks = 0;
$check = static function ($condition, $message) use (&$checks) {
    ++$checks;
    if (!$condition) { throw new RuntimeException($message); }
};
$container = new Container();
$container['domain.settings.service'] = new SettingsService(new HSPCCNativePriceSettings());
$container['application.tax.service'] = new TaxApplicationService($container);
// The percentage-tax branch looks up this helper but does not call it.
$container['application.helper.service'] = new stdClass();
$repository = new HSPCCNativePricePaymentRepository();
$container['domain.payment.repository'] = $repository;
DateTimeService::setTimeZone(['timeZoneString' => 'Europe/Vienna', 'gmtOffset' => 0]);

$reservationService = new AppointmentReservationService($container);
$paymentService = new PaymentApplicationService($container);
if (!method_exists($paymentService, 'getValidatedRequestPaymentData')) {
    throw new RuntimeException('Reviewed native request-payment validator is unavailable.');
}

$service = new Service();
$service->setPrice(new Price(120));
$service->setAggregatedPrice(new BooleanValueObject(false));
$service->setExtras(new Collection());
$booking = CustomerBookingFactory::create([
    'id' => 501,
    'persons' => 1,
    'price' => 120,
    'status' => 'approved',
    'duration' => 3600,
    'aggregatedPrice' => false,
    'extras' => [],
    'tax' => [['id' => 91, 'name' => 'Synthetic VAT', 'amount' => 20, 'type' => 'percentage', 'excluded' => true]],
]);

// Assertions consume native results; the fixture does not calculate a price or tax.
$quote = $reservationService->getPaymentAmount($booking, $service);
$check((float)$quote['price'] === 144.0, 'Native payable total must be 144 for base 120 and additive 20% tax.');
$check((float)$quote['unit_price'] === 120.0, 'Native base unit price must remain 120.');
$check((float)$quote['tax'] === 24.0, 'Native tax must be 24.');
$check((float)$quote['total_tax'] === 24.0, 'Native total tax must be 24.');
$check($quote['tax_excluded'] === true, 'Tax must be additive rather than included.');
$check((float)$booking->getPrice()->getValue() === 120.0, 'Quoting must preserve the stored booking base price.');

$validated = $paymentService->getValidatedRequestPaymentData([
    'gateway' => 'onSite',
    'currency' => 'USD',
    'amount' => 0.01,
    'status' => 'paid',
    'orderStatus' => 'completed',
    'wcOrderId' => 999,
    'deposit' => true,
    'isBackendBooking' => true,
    'invoiceNumber' => 999,
    'parentId' => 999,
    'unknownFutureState' => true,
    'data' => ['clientHandle' => 'synthetic-only'],
], ['onSite']);
$check(array_keys($validated) === ['gateway', 'currency', 'data'], 'Native validator must drop forged payment-state keys.');
$check($validated['gateway'] === 'onSite', 'Native validator must retain the allowed onsite gateway.');
$check($validated['currency'] === 'EUR', 'Currency must come from synthetic native settings, not client USD.');
$check($validated['data'] === ['clientHandle' => 'synthetic-only'], 'Native client-handle data must be retained.');
$check($paymentService->getValidatedRequestPaymentData(['gateway' => 'stripe'], ['onSite']) === null, 'Disallowed gateway must be rejected.');
$check($paymentService->getValidatedRequestPaymentData(['gateway' => ['onSite']], ['onSite']) === null, 'Non-scalar gateway must be rejected.');
$check($paymentService->getValidatedRequestPaymentData('onSite', ['onSite']) === null, 'Malformed payment object must be rejected.');

$payment = $reservationService->addPayment(
    501, null, $validated, $quote['price'], new DateTime('2030-06-12 10:00:00', new DateTimeZone('Europe/Vienna')), 'appointment'
);
$row = $payment->toArray();
$check($row['gateway'] === 'onSite', 'Native onsite payment gateway must be retained.');
$check((float)$row['amount'] === 0.0, 'Onsite payment records zero collected money, not the payable total.');
$check($row['status'] === 'pending', 'Positive-price onsite booking must remain payment-pending.');
$check($row['customerBookingId'] === 501, 'Native payment must retain its synthetic booking relation.');
$check($row['entity'] === 'appointment', 'Native payment entity type must be appointment.');
$check($row['packageCustomerId'] === null && $row['parentId'] === null, 'Forged package/parent state must not survive validation.');
$check($row['wcOrderId'] === null && $row['transactionId'] === null, 'No WooCommerce or external transaction must be introduced.');
$check(count($repository->rows) === 1 && $repository->reads === 1, 'Only one in-memory payment write/read is expected.');
$beforePaymentHooks = array_values(array_filter($GLOBALS['hspcc_native_price_hooks'], static fn($hook) => $hook[0] === 'amelia_before_payment'));
$check(count($beforePaymentHooks) === 1 && (float)$beforePaymentHooks[0][2][0] === 144.0, 'Native payment hook must receive the canonical payable total.');

echo "native-onsite-price: {$checks} checks passed (real Amelia 9.9.1 pricing/validation; synthetic settings/repository; no DB, bootstrap, mail, gateway or calendar).\n";
