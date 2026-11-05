<?php
/** Portable, bounded CLI runner. Fixtures use fake WP/native/HTTP state only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!function_exists('proc_open')) {
    fwrite(STDERR, "FAIL: this CLI runner requires proc_open.\n");
    exit(1);
}

const HSPCC_TEST_PROCESS_SECONDS = 20;
const HSPCC_TEST_OUTPUT_LIMIT = 131072;
const HSPCC_TEST_RUN_SECONDS = 120;
$runStarted = microtime(true);
$pluginRoot = dirname(__DIR__);

function test_read_output(array $pipes, string &$stdout, string &$stderr): bool {
    $remaining = HSPCC_TEST_OUTPUT_LIMIT - strlen($stdout) - strlen($stderr);
    $stdout .= stream_get_contents($pipes[1], max(1, $remaining + 1));
    $remaining = HSPCC_TEST_OUTPUT_LIMIT - strlen($stdout) - strlen($stderr);
    $stderr .= stream_get_contents($pipes[2], max(1, $remaining + 1));
    return strlen($stdout) + strlen($stderr) <= HSPCC_TEST_OUTPUT_LIMIT;
}

/** No shell; cap runtime and captured output, preserve stdout/stderr separately. */
function test_process(array $command, string $cwd): array {
    $remainingRun = HSPCC_TEST_RUN_SECONDS - (microtime(true) - $GLOBALS['runStarted']);
    if ($remainingRun <= 0) { return [124, '', "Overall test time bound reached.\n"]; }
    $timeout = min(HSPCC_TEST_PROCESS_SECONDS, $remainingRun);
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($process)) { return [1, '', "Could not start PHP subprocess.\n"]; }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $started = microtime(true);
    $stdout = $stderr = '';
    $exit = -1;
    $forced = null;
    do {
        if (!test_read_output($pipes, $stdout, $stderr)) { $forced = 125; break; }
        $status = proc_get_status($process);
        if (!$status['running']) { $exit = $status['exitcode']; break; }
        if (microtime(true) - $started > $timeout) { $forced = 124; break; }
        usleep(10000);
    } while (true);
    if ($forced !== null) {
        proc_terminate($process);
        usleep(50000);
        if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
    }
    if (!test_read_output($pipes, $stdout, $stderr)) { $forced = 125; }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closed = proc_close($process);
    if ($forced !== null) {
        return [$forced, substr($stdout, 0, HSPCC_TEST_OUTPUT_LIMIT),
            substr($stderr, 0, max(0, HSPCC_TEST_OUTPUT_LIMIT - strlen($stdout))) .
            ($forced === 124 ? "Fixture exceeded its runtime bound.\n" : "Fixture exceeded its output bound.\n")];
    }
    return [$exit >= 0 ? $exit : $closed, $stdout, $stderr];
}

$php = [PHP_BINARY];
$ini = php_ini_loaded_file();
if (is_string($ini) && $ini !== '') { $php = array_merge($php, ['-c', $ini]); }
$disabled = (string) ini_get('disable_functions');
if ($disabled !== '') { $php = array_merge($php, ['-d', 'disable_functions=' . $disabled]); }

// Preserve optional modules loaded by a parent's "-d extension=..." command.
// CI normally loads these via php.ini; avoid loading a module twice there.
[$probeExit, $probeOutput, $probeErrors] = test_process(array_merge($php,
    ['-r', 'echo json_encode(get_loaded_extensions());']), $pluginRoot);
$childModules = json_decode($probeOutput, true);
if ($probeExit !== 0 || !is_array($childModules)) {
    fwrite(STDERR, "FAIL: could not inspect CLI subprocess modules.\n" . $probeErrors);
    exit(1);
}
$childModules = array_map('strtolower', $childModules);
$extensionDir = (string) ini_get('extension_dir');
foreach (['dom', 'xml', 'xmlreader', 'xmlwriter', 'mbstring', 'openssl', 'sodium'] as $name) {
    if (!extension_loaded($name) || in_array($name, $childModules, true)) { continue; }
    $filename = PHP_OS_FAMILY === 'Windows' ? 'php_' . $name . '.dll' : $name . '.' . PHP_SHLIB_SUFFIX;
    $file = rtrim($extensionDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
    if (is_file($file)) { $php = array_merge($php, ['-d', 'extension=' . $file]); }
}
if (!class_exists('DOMDocument')) {
    fwrite(STDERR, "FAIL: DOM/XML must be loaded for the pure parser/discovery suites.\n");
    exit(1);
}
if (!function_exists('sodium_crypto_secretbox') && !function_exists('openssl_encrypt')) {
    fwrite(STDERR, "FAIL: load Sodium or OpenSSL AES-GCM for synthetic credential fixtures.\n");
    exit(1);
}

$lintFiles = array_merge(glob($pluginRoot . '/*.php'), glob($pluginRoot . '/includes/*.php'), glob(__DIR__ . '/*.php'));
sort($lintFiles);
foreach ($lintFiles as $file) {
    if (microtime(true) - $runStarted > HSPCC_TEST_RUN_SECONDS) {
        fwrite(STDERR, "FAIL: test run exceeded its overall time bound.\n"); exit(1);
    }
    [$exit, $output, $errors] = test_process(array_merge($php, ['-l', $file]), $pluginRoot);
    if ($exit !== 0) { fwrite(STDERR, "FAIL: syntax " . basename($file) . "\n" . $output . $errors); exit(1); }
}
fwrite(STDOUT, 'PASS: syntax checked ' . count($lintFiles) . " PHP files.\n");

$jobs = [
    ['GitHub release updates', 'hspcc-updater-test.php', [], []],
    ['current credential security', 'hspcc-current-crypto-test.php', [], []],
    ['credential/transport smoke', 'hspcc-smoke.php', [], []],
    ['discovery smoke', 'hspcc-smoke.php', ['discover'], []],
    ['strict free-busy', 'hspcc-freebusy-test.php', [], []],
    ['busy cache/employee scope', 'hspcc-busy-cache-test.php', [], []],
    ['native appointment guards', 'hspcc-amelia-test.php', [], []],
    ['reschedule employee scope on reviewed Amelia', 'hspcc-reschedule-scope-test.php', ['9.9.1'], []],
    ['reschedule employee scope on newer Amelia', 'hspcc-reschedule-scope-test.php', ['9.9.2'], []],
    ['native customer-panel reschedule', 'hspcc-customer-reschedule-test.php', [], []],
    ['unreviewed Amelia reschedule version', 'hspcc-customer-reschedule-test.php', ['future-version'], []],
    ['native on-site price/tax/payment contract', 'hspcc-native-onsite-price-test.php', [], []],
    ['canonical lifecycle snapshots', 'hspcc-amelia-sync-test.php', [], []],
    ['conditional calendar storage', 'hspcc-calendar-storage-test.php', [], []],
    ['durable queue', 'hspcc-sync-queue-test.php', [], []],
    ['actual queue/storage recovery', 'hspcc-queue-storage-integration.php', [], []],
    ['optional installed-Amelia overlap', 'hspcc-overlap-busy-test.php', [], []],
    ['endpoint/origin policy', 'hspcc-endpoint-config-test.php', [], []],
    ['actual-main settings/runtime', 'hspcc-connection-settings-test.php', [], []],
    ['actual-main employee settings/isolation', 'hspcc-employee-settings-test.php', [], []],
];
if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
    $fallbackDisabled = implode(',', array_unique(array_filter(array_merge(
        explode(',', $disabled), ['sodium_crypto_secretbox', 'sodium_crypto_secretbox_open']))));
    $fallbackFlags = ['-d', 'disable_functions=' . $fallbackDisabled];
    $jobs[] = ['OpenSSL current credential security', 'hspcc-current-crypto-test.php', [], $fallbackFlags];
    $jobs[] = ['OpenSSL credential fallback', 'hspcc-smoke.php', [], $fallbackFlags];
    $jobs[] = ['OpenSSL discovery fallback', 'hspcc-smoke.php', ['discover'], $fallbackFlags];
    $jobs[] = ['OpenSSL settings fallback', 'hspcc-connection-settings-test.php', [], $fallbackFlags];
    $jobs[] = ['OpenSSL employee settings fallback', 'hspcc-employee-settings-test.php', [], $fallbackFlags];
} else {
    fwrite(STDOUT, "SKIP: explicit OpenSSL fallback variants; OpenSSL AES-GCM is not loaded.\n");
}
$passed = $skipped = 0;
foreach ($jobs as [$label, $file, $arguments, $flags]) {
    if (microtime(true) - $runStarted > HSPCC_TEST_RUN_SECONDS) {
        fwrite(STDERR, "FAIL: test run exceeded its overall time bound.\n"); exit(1);
    }
    [$exit, $output, $errors] = test_process(array_merge($php, $flags, [__DIR__ . '/' . $file], $arguments), $pluginRoot);
    fwrite(STDOUT, "\n[" . $label . "]\n" . $output);
    if ($errors !== '') { fwrite(STDERR, $errors); }
    if ($exit !== 0) { fwrite(STDERR, 'FAIL: fixture exited ' . $exit . ".\n"); exit(1); }
    if (str_starts_with(ltrim($output), 'SKIP:')) { ++$skipped; }
    else { ++$passed; }
}
fwrite(STDOUT, "\nPASS: " . $passed . ' fixture subprocesses; ' . $skipped .
    " optional suite skipped. No WordPress bootstrap, real database, mail or calendar HTTP.\n");
