<?php
/** Offline current-format credential and initialization regression. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/hspcc-smoke.php';

$crypto_checks = 0;
$crypto_http_count = count($GLOBALS['hspcc_requests']);
function hspcc_crypto_check($condition, $label) {
    ++$GLOBALS['crypto_checks'];
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL); exit(1); }
}
function hspcc_crypto_rejected($secret, $label) {
    $rejected = false;
    try { invoke_private('decrypt', $secret); } catch (Throwable $error) { $rejected = true; }
    hspcc_crypto_check($rejected, $label);
}

$plain = " synthetic credential with spaces, colon: and binary \0 ";
$current_key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth') . '|hsp-calendar-connection:v2', true);
$envelopes = [];

// Build independent current-format fixtures for each available crypto backend.
if (function_exists('sodium_crypto_secretbox')) {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $envelopes['s2'] = 's2:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $current_key));
}
if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
    $iv = random_bytes(12);
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $current_key, OPENSSL_RAW_DATA, $iv, $tag, 'hspcc:v2');
    hspcc_crypto_check(is_string($cipher), 'independent current GCM fixture created');
    $envelopes['o2'] = 'o2:' . base64_encode($iv . $tag . $cipher);
    $wrong_aad = openssl_encrypt($plain, 'aes-256-gcm', $current_key, OPENSSL_RAW_DATA, $iv, $wrong_tag, 'unrelated-context');
    hspcc_crypto_rejected('o2:' . base64_encode($iv . $wrong_tag . $wrong_aad), 'GCM authentication binds the expected additional data');
}
hspcc_crypto_check((bool) $envelopes, 'authenticated crypto available');
foreach ($envelopes as $format => $envelope) {
    hspcc_crypto_check(invoke_private('decrypt', $envelope) === $plain, 'independent current envelope preserves exact credential bytes');
    $raw = base64_decode(substr($envelope, 3), true);
    foreach ([0, strlen($raw) - 1] as $index) {
        $tampered = $raw;
        $tampered[$index] = chr(ord($tampered[$index]) ^ 1);
        hspcc_crypto_rejected($format . ':' . base64_encode($tampered), 'nonce or authenticated ciphertext tampering is rejected');
    }
    hspcc_crypto_rejected($format[0] . '1:' . substr($envelope, 3), 'old envelope version is rejected before decoding');
    hspcc_crypto_rejected($format[0] . '3:' . substr($envelope, 3), 'unknown envelope version is rejected');
    $GLOBALS['hspcc_salt'] = 'rotated-synthetic-salt';
    hspcc_crypto_rejected($envelope, 'WordPress salt rotation invalidates the credential');
    $GLOBALS['hspcc_salt'] = 'synthetic-test-salt';
}

$encrypted = invoke_private('encrypt', $plain);
$expected = function_exists('sodium_crypto_secretbox') ? 's2:' : 'o2:';
hspcc_crypto_check(str_starts_with($encrypted, $expected), 'new credentials use only the current format');
hspcc_crypto_check(invoke_private('decrypt', $encrypted) === $plain, 'production current-format round trip preserves exact bytes');
hspcc_crypto_check(invoke_private('encrypt', $plain) !== $encrypted, 'repeated encryption uses an independent random nonce');
hspcc_crypto_check(!str_contains($encrypted, $plain), 'stored envelope contains no plaintext credential');
$raw = base64_decode(substr($encrypted, 3), true);
$independent = $expected === 's2:' ? sodium_crypto_secretbox_open(substr($raw, 24), substr($raw, 0, 24), $current_key)
    : openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $current_key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'hspcc:v2');
hspcc_crypto_check($independent === $plain, 'production encryption uses the current hsp key context and AAD');
foreach (['s1:AAAA', 'o1:AAAA', 's3:AAAA', 'o3:AAAA', 'x2:AAAA', 's2:not-base64!', 'o2:not-base64!',
    's2:' . base64_encode(str_repeat('x', 40)), 'o2:' . base64_encode(str_repeat('x', 28)), '', null, []] as $invalid) {
    hspcc_crypto_rejected($invalid, 'obsolete, unknown, malformed or truncated envelopes are rejected');
}

// Fresh initialization is native and cannot overwrite a saved connection.
$GLOBALS['hspcc_options'] = [];
HSPCC_Employee_Context::select(1);
HSP_Calendar_Connection::activate();
hspcc_crypto_check(get_option(HSP_Calendar_Connection::OPTION) === [], 'fresh credentials start empty');
hspcc_crypto_check(get_option(HSP_Calendar_Connection::DISCOVERY) === [], 'fresh discovery starts empty');
hspcc_crypto_check(HSP_Calendar_Connection::configured_employees() === [], 'fresh install enables no employee connections');
hspcc_crypto_check(!HSP_Calendar_Connection::blocking_enabled(1) && !HSP_Calendar_Connection::writing_enabled(1), 'fresh install enables no calendar actions');
$saved = ['endpoint' => HSPCC_TEST_ENDPOINT, 'providerId' => 1, 'username' => 'synthetic-user', 'secret' => $encrypted];
HSP_Calendar_Connection::set_private_option(HSP_Calendar_Connection::OPTION, $saved);
HSP_Calendar_Connection::set_private_option(HSP_Calendar_Connection::DISCOVERY, ['opaque-synthetic-setting' => true]);
$before = $GLOBALS['hspcc_options'];
HSP_Calendar_Connection::activate();
HSP_Calendar_Connection::boot();
hspcc_crypto_check($GLOBALS['hspcc_options'] === $before, 'reactivation and boot preserve saved current options without migration writes');
hspcc_crypto_check(HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::OPTION, 1) === HSP_Calendar_Connection::OPTION, 'employee one retains the current unsuffixed option namespace');
hspcc_crypto_check(HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::OPTION, 7) === HSP_Calendar_Connection::OPTION . '_employee_7', 'other employee option namespaces remain isolated');
hspcc_crypto_check(count($GLOBALS['hspcc_requests']) === $crypto_http_count, 'crypto and initialization checks make no extra HTTP calls');

fwrite(STDOUT, 'PASS: ' . $crypto_checks . " current credential, tampering, format rejection and initialization checks.\n");
