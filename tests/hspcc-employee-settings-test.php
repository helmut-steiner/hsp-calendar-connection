<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Actual multi-employee settings/context APIs; no WordPress, DNS or network.
require __DIR__ . '/hspcc-connection-settings-test.php';
$employeeChecks = 0;
$employeeHttpCount = count($GLOBALS['hspcc_requests']);
function employee_same($expected, $actual, $label) {
    ++$GLOBALS['employeeChecks'];
    if ($expected !== $actual) { fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL); exit(1); }
}
function employee_ok($condition, $label) { employee_same(true, (bool) $condition, $label); }
// Minimal native rendering stubs exercise the real settings page without WordPress.
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr__($value, $domain = null) { return esc_attr($value); }
function settings_errors(...$arguments) { echo '<div class="notice notice-error hspcc-synthetic-settings-notice"><p>Synthetic Settings API notice</p></div>'; }
function settings_fields($group) {
    echo '<input type="hidden" name="option_page" value="' . esc_attr($group) . '">';
    echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr($_SERVER['REQUEST_URI'] ?? '') . '">';
}
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="synthetic-nonce">'; }
function submit_button($text, $type = 'primary', $name = 'submit', $wrap = true) {
    if ($wrap) { echo '<p class="submit">'; }
    echo '<input type="submit" class="button button-' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($text) . '">';
    if ($wrap) { echo '</p>'; }
}
function selected($selected, $current = true, $display = true) {
    $result = (string) $selected === (string) $current ? ' selected="selected"' : '';
    if ($display) { echo $result; }
    return $result;
}
function checked($checked, $current = true, $display = true) {
    $result = (string) $checked === (string) $current ? ' checked="checked"' : '';
    if ($display) { echo $result; }
    return $result;
}
function wp_date($format, $timestamp = null) { return gmdate($format, $timestamp ?? time()); }
function plugins_url($path, $plugin) { return 'https://local.test/wp-content/plugins/hsp-calendar-connection/' . $path; }
function wp_enqueue_style(...$arguments) { $GLOBALS['hspcc_employee_styles'][] = $arguments; }
function wp_enqueue_script(...$arguments) { $GLOBALS['hspcc_employee_scripts'][] = $arguments; }
function employee_export_html(string $filename, string $html): void {
    $directory = getenv('HSPCC_TEST_HTML_DIRECTORY');
    if (!is_string($directory) || $directory === '') { return; }
    if (!is_dir($directory)) { throw new RuntimeException('The synthetic HTML export directory must already exist.'); }
    $written = file_put_contents(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $filename, $html);
    if ($written === false) { throw new RuntimeException('Could not export synthetic employee HTML.'); }
}
function employee_fresh_request(): void {
    (new ReflectionProperty(HSP_Calendar_Connection::class, 'sanitized'))->setValue(null, null);
    $GLOBALS['hspcc_settings_error'] = null;
    $_GET = $_POST = [];
}
function employee_document(string $html): DOMXPath {
    $document = new DOMDocument();
    $previousErrors = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);
    return new DOMXPath($document);
}
function employee_render(int $employee, $tab = 'connection', array $query = [], array $post = []): string {
    employee_fresh_request();
    $_GET = array_replace(['page' => HSP_Calendar_Connection::PAGE, 'hspcc_employee' => (string) $employee, 'hspcc_tab' => $tab], $query);
    $_POST = $post;
    $_SERVER['REQUEST_URI'] = '/wp-admin/options-general.php?' . http_build_query($_GET);
    HSP_Calendar_Connection::register();
    ob_start();
    HSP_Calendar_Connection::page();
    return ob_get_clean();
}
function employee_snapshot(int $id): array {
    $snapshot = [];
    foreach ([HSP_Calendar_Connection::OPTION, HSP_Calendar_Connection::DISCOVERY, HSP_Calendar_Connection::HEALTH,
        HSP_Calendar_Connection::BLOCKING, HSP_Calendar_Connection::WRITING, HSP_Calendar_Connection::TIMEZONE,
        HSP_Calendar_Connection::PROBE, HSP_Calendar_Connection::WRITE_HEALTH,
        'hsp_calendar_site_uuid', 'hspcc_queue_worker_health'] as $base) {
        $name = HSP_Calendar_Connection::option_name($base, $id);
        $snapshot[$name] = get_option($name, false);
    }
    return $snapshot;
}
function employee_seed(int $id, string $endpoint, string $password, bool $blocking, bool $writing): array {
    return HSP_Calendar_Connection::with_employee($id, static function () use ($id, $endpoint, $password, $blocking, $writing) {
        employee_fresh_request();
        $credentials = HSP_Calendar_Connection::sanitize([
            'providerId' => $id, 'endpoint' => $endpoint, 'username' => 'synthetic-user-' . $id, 'password' => $password,
        ]);
        HSP_Calendar_Connection::set_private_option(HSP_Calendar_Connection::OPTION, $credentials);
        $source = hash('sha256', $endpoint . 'busy/');
        $destination = hash('sha256', $endpoint . 'booking/');
        $fingerprint = invoke_private('fingerprint', $credentials);
        HSP_Calendar_Connection::set_private_option(HSP_Calendar_Connection::DISCOVERY, [
            'fingerprint' => $fingerprint, 'checkedAt' => 123,
            'busySelected' => [$source], 'writeSelected' => $destination,
            'calendars' => [
                $source => ['url' => $endpoint . 'busy/', 'name' => 'Synthetic busy ' . $id, 'freeBusy' => true],
                $destination => ['url' => $endpoint . 'booking/', 'name' => 'Synthetic destination ' . $id, 'freeBusy' => true],
            ],
        ]);
        HSP_Calendar_Connection::set_private_option(HSP_Calendar_Connection::HEALTH, ['ok' => true, 'checkedAt' => 123, 'identity' => 'synthetic-' . $id]);
        HSP_Calendar_Connection::set_private_option(HSP_Calendar_Connection::WRITE_HEALTH,
            ['ok' => true, 'cleanupComplete' => true, 'fingerprint' => $fingerprint, 'calendarKey' => $destination]);
        HSP_Calendar_Connection::set_private_option(HSP_Calendar_Connection::BLOCKING, $blocking);
        HSP_Calendar_Connection::set_private_option(HSP_Calendar_Connection::WRITING, $writing);
        return [$credentials, $source, $destination];
    });
}

$GLOBALS['hspcc_options'] = [];
$GLOBALS['hspcc_admin'] = true;
$GLOBALS['hspcc_salt'] = 'synthetic-test-salt';
$GLOBALS['wpdb'] = new HSPCC_Settings_Wpdb();
HSPCC_Employee_Context::select(1);
(new ReflectionProperty(HSPCC_Employee_Context::class, 'choices'))->setValue(null, [1 => 'Synthetic Ada', 7 => 'Synthetic Lin']);
[$one, $oneSource, $oneTarget] = employee_seed(1, 'https://one.example.test/caldav/', 'synthetic-password-one', true, false);
$oneBeforeSeven = employee_snapshot(1);
[$seven, $sevenSource, $sevenTarget] = employee_seed(7, 'https://seven.example.test:5006/caldav/', 'synthetic-password-seven', false, true);
employee_same($oneBeforeSeven, employee_snapshot(1), 'creating employee7 connection changes no employee1 private state');
employee_same(1, HSP_Calendar_Connection::employee_id(), 'seeding employee7 restores default employee1 context');
employee_same(HSP_Calendar_Connection::OPTION, HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::OPTION, 1), 'current employee1 option name preserved');
employee_same(HSP_Calendar_Connection::OPTION . '_employee_7', HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::OPTION, 7), 'employee7 private option is separate');
employee_ok($one['secret'] !== $seven['secret'], 'employees have independent encrypted secret records');
employee_same('synthetic-password-one', invoke_private('decrypt', $one['secret']), 'employee1 encrypted secret remains readable');
employee_same('synthetic-password-seven', invoke_private('decrypt', $seven['secret']), 'employee7 encrypted secret remains readable');
employee_same([1, 7], HSP_Calendar_Connection::configured_employees(), 'registry discovers both configured employees');
employee_same([1], HSP_Calendar_Connection::configured_employees('blocking'), 'blocking employee list is independent');
employee_same([7], HSP_Calendar_Connection::configured_employees('writing'), 'writing employee list is independent');
employee_same([], HSP_Calendar_Connection::configured_employees('invalid-feature'), 'unknown feature does not broaden selection');
employee_same(true, HSP_Calendar_Connection::blocking_enabled(1), 'employee1 blocking flag resolved independently');
employee_same(false, HSP_Calendar_Connection::blocking_enabled(7), 'employee7 does not inherit blocking');
employee_same(false, HSP_Calendar_Connection::writing_enabled(1), 'employee1 does not inherit writing');
employee_same(true, HSP_Calendar_Connection::writing_enabled(7), 'employee7 writing flag resolved independently');
employee_same(1, HSP_Calendar_Connection::employee_id(), 'enumerating feature lists restores caller context');
foreach ([[1, $one, $oneSource, $oneTarget], [7, $seven, $sevenSource, $sevenTarget]] as [$id, $credentials, $source, $destination]) {
    HSP_Calendar_Connection::with_employee($id, static function () use ($id, $credentials, $source, $destination) {
        employee_same($id, HSP_Calendar_Connection::employee_id(), 'explicit context selects requested employee');
        employee_same($id, HSP_Calendar_Connection::provider_id(), 'configured provider matches context');
        employee_same($credentials['endpoint'], HSP_Calendar_Connection::endpoint(), 'endpoint comes from selected employee');
        employee_same([$source], HSP_Calendar_Connection::source_keys(), 'busy source comes from selected employee');
        employee_same($destination, HSP_Calendar_Connection::write_configuration()['calendarKey'], 'write target comes from selected employee');
        employee_same(true, invoke_private('write_verified'), 'proof binds only the selected employee connection');
    });
}
try {
    HSP_Calendar_Connection::with_employee(7, static function () {
        HSP_Calendar_Connection::with_employee(1, static function () { employee_same(1, HSP_Calendar_Connection::employee_id(), 'nested context selects employee1'); });
        employee_same(7, HSP_Calendar_Connection::employee_id(), 'nested context restores employee7');
        throw new RuntimeException('Synthetic scoped exception');
    });
} catch (RuntimeException $error) { employee_same('Synthetic scoped exception', $error->getMessage(), 'test exception propagated'); }
employee_same(1, HSP_Calendar_Connection::employee_id(), 'throwing scoped callback restores employee1');
try { HSP_Calendar_Connection::with_employee(0, static function () {}); }
catch (InvalidArgumentException $error) { employee_same(1, HSP_Calendar_Connection::employee_id(), 'invalid context cannot change previous selection'); }

$snapshotOne = employee_snapshot(1);
$snapshotSeven = employee_snapshot(7);
employee_fresh_request();
$_POST = ['hspcc_employee' => '7'];
HSP_Calendar_Connection::register();
employee_same(7, HSP_Calendar_Connection::employee_id(), 'native selector selects named employee7');
employee_same(HSP_Calendar_Connection::OPTION . '_employee_7', $GLOBALS['hspcc_registration'][1], 'Settings API registers selected employee option only');
employee_same(false, $GLOBALS['hspcc_registration'][2]['show_in_rest'], 'employee setting remains private');
employee_same([1 => 'Synthetic Ada', 7 => 'Synthetic Lin'], HSPCC_Employee_Context::choices(), 'selector uses actual native employee-name choices surface');
employee_same($snapshotOne, employee_snapshot(1), 'selecting employee7 changes no employee1 connection data');
employee_fresh_request();
$forged = HSP_Calendar_Connection::sanitize(['endpoint' => $one['endpoint'], 'providerId' => 1,
    'username' => 'forged-account', 'password' => 'forged-synthetic-password']);
employee_same($seven, $forged, 'forged provider1 payload under employee7 returns existing employee7 data');
employee_same('endpoint', $GLOBALS['hspcc_settings_error'], 'cross-profile payload is rejected before secret replacement');
employee_same($snapshotOne, employee_snapshot(1), 'forged cross-profile form cannot alter employee1');
employee_same($snapshotSeven, employee_snapshot(7), 'forged cross-profile form cannot alter employee7');
$_POST = ['hspcc_employee' => '999'];
HSP_Calendar_Connection::register();
employee_same(7, HSP_Calendar_Connection::employee_id(), 'forged unknown selector cannot create an arbitrary profile');
employee_same(HSP_Calendar_Connection::OPTION . '_employee_7', $GLOBALS['hspcc_registration'][1], 'invalid selector leaves only legitimate selected option registered');
$_POST = [];
$_GET = ['hspcc_employee' => '1'];
HSP_Calendar_Connection::register();
employee_same(1, HSP_Calendar_Connection::employee_id(), 'selector can return to employee1');
employee_same(HSP_Calendar_Connection::OPTION, $GLOBALS['hspcc_registration'][1], 'employee1 Settings API key remains unchanged');

employee_fresh_request();
$cachedOne = HSP_Calendar_Connection::sanitize(settings_input());
$cachedCross = HSP_Calendar_Connection::with_employee(7, static fn() => HSP_Calendar_Connection::sanitize($cachedOne));
employee_same($seven, $cachedCross, 'cached sanitized employee1 object cannot bypass employee7 scope validation');
employee_same($snapshotOne, employee_snapshot(1), 'cached cross-context rejection preserves employee1');
employee_same($snapshotSeven, employee_snapshot(7), 'cached cross-context rejection preserves employee7');
employee_same(1, HSP_Calendar_Connection::employee_id(), 'cached cross-context rejection restores caller');

$rotatedSeven = HSP_Calendar_Connection::with_employee(7, static function () {
    employee_fresh_request();
    return settings_save(settings_input(['password' => 'rotated-seven-synthetic-password']));
});
employee_same($snapshotOne, employee_snapshot(1), 'employee7 password rotation cannot change employee1 credentials, flags or proofs');
employee_ok($rotatedSeven['secret'] !== $seven['secret'], 'employee7 stores fresh encrypted password');
employee_same('rotated-seven-synthetic-password', invoke_private('decrypt', $rotatedSeven['secret']), 'rotated employee7 secret reads correctly');
HSP_Calendar_Connection::with_employee(7, static function () use ($sevenSource, $sevenTarget, $one) {
    employee_same([$sevenSource], HSP_Calendar_Connection::source_keys(), 'employee7 rotation preserves busy routing');
    employee_same($sevenTarget, HSP_Calendar_Connection::write_configuration()['calendarKey'], 'employee7 rotation preserves destination');
    employee_same(false, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::WRITING), 'employee7 rotation disables only its writing gate');
    employee_same(false, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::WRITE_HEALTH), 'employee7 rotation invalidates only its proof');
    $proofOne = HSP_Calendar_Connection::with_employee(1, static fn() => HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::WRITE_HEALTH));
    HSP_Calendar_Connection::set_private_option(HSP_Calendar_Connection::WRITE_HEALTH, $proofOne);
    employee_ok(is_wp_error(HSP_Calendar_Connection::configure_runtime(false, true)), 'employee1 proof cannot authorize employee7 writeback');
    HSP_Calendar_Connection::set_private_option(HSP_Calendar_Connection::WRITE_HEALTH,
        ['ok' => true, 'cleanupComplete' => true, 'fingerprint' => invoke_private('fingerprint', HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::OPTION)), 'calendarKey' => $sevenTarget]);
    employee_same(true, HSP_Calendar_Connection::configure_runtime(false, true), 'fresh employee7 proof enables only employee7 writing');
});
employee_same($snapshotOne, employee_snapshot(1), 'employee7 runtime changes leave employee1 proof and flags unchanged');
employee_same([1], HSP_Calendar_Connection::configured_employees('blocking'), 'employee1 remains the only enabled busy guard');
employee_same([7], HSP_Calendar_Connection::configured_employees('writing'), 'employee7 write gate recovered independently');

HSP_Calendar_Connection::set_private_option(HSP_Calendar_Connection::PROBE, ['synthetic' => 'employee1-pending']);
$oneWithProbe = employee_snapshot(1);
HSP_Calendar_Connection::with_employee(7, static function () {
    employee_fresh_request();
    $changed = settings_save(settings_input(['endpoint' => 'https://seven.example.test:5006/new-caldav/']));
    employee_same('https://seven.example.test:5006/new-caldav/', $changed['endpoint'], 'employee1 pending probe cannot block clean employee7 reconfiguration');
    employee_same(false, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::DISCOVERY), 'employee7 reconfiguration clears only its routing');
    employee_same(false, HSP_Calendar_Connection::private_option(HSP_Calendar_Connection::WRITING), 'employee7 reconfiguration disables only its writing');
});
employee_same($oneWithProbe, employee_snapshot(1), 'employee7 reconfiguration retains employee1 pending probe and all private state');
employee_same([1], HSP_Calendar_Connection::configured_employees('blocking'), 'employee1 busy guard survives employee7 routing reset');
employee_same([], HSP_Calendar_Connection::configured_employees('writing'), 'reset employee7 writing does not move to employee1');
employee_same(false, HSP_Calendar_Connection::blocking_enabled(8), 'unconfigured employee has no borrowed guard');
employee_same(false, HSP_Calendar_Connection::writing_enabled(8), 'unconfigured employee has no borrowed writer');
HSP_Calendar_Connection::with_employee(8, static function () {
    employee_same(8, HSP_Calendar_Connection::employee_id(), 'empty profile retains explicit context identity');
    employee_same(0, HSP_Calendar_Connection::provider_id(), 'empty profile has no configured provider');
    employee_same('', HSP_Calendar_Connection::endpoint(), 'empty profile has no borrowed endpoint');
    employee_same([], HSP_Calendar_Connection::source_keys(), 'empty profile has no borrowed source');
});
employee_same(1, HSP_Calendar_Connection::employee_id(), 'empty profile checks restore employee1');

// The employee remains a native selector; only the selected settings subsection renders.
$employeeLabels = [1 => 'Ada <img src=x onerror=alert(1)> & Co', 7 => 'ليلى الموظفة ذات الاسم الطويل', 8 => 'Unconfigured employee'];
(new ReflectionProperty(HSPCC_Employee_Context::class, 'choices'))->setValue(null, $employeeLabels);
employee_seed(1, 'https://one.example.test/caldav/', 'synthetic-ui-password-one', true, false);
employee_seed(7, 'https://seven.example.test:5006/caldav/', 'synthetic-ui-password-seven', false, true);
$sectionLabels = ['connection' => 'Connection settings', 'calendars' => 'Calendar routing',
    'runtime' => 'Runtime settings', 'status' => 'Connection status'];
$uiBefore = $GLOBALS['hspcc_options'];
$uiHttpBefore = count($GLOBALS['hspcc_requests']);
foreach ([1, 7, 8] as $selectedEmployee) {
    foreach ($sectionLabels as $selectedTab => $sectionLabel) {
        $page = employee_render($selectedEmployee, $selectedTab,
            ['hspcc_status' => 'selected', 'settings-updated' => 'true', 'unrelated' => 'discard']);
        employee_export_html('employee-' . $selectedEmployee . '-' . $selectedTab . '.html', $page);
        if ($selectedTab === 'connection') { employee_export_html('employee-' . $selectedEmployee . '.html', $page); }
        $xpath = employee_document($page);
        $selector = $xpath->query('//select[@name="hspcc_employee"]');
        employee_same(1, $selector->length, 'one native employee selector remains available in every subsection');
        employee_same('hspcc-employee', $selector->item(0)->getAttribute('id'), 'employee selector has its associated native label');
        employee_same('Amelia employee', $xpath->query('//label[@for="hspcc-employee"]')->item(0)->textContent, 'employee selector has a translated visible label');
        $selectorForm = $xpath->query('ancestor::form', $selector->item(0))->item(0);
        employee_same('get', strtolower($selectorForm->getAttribute('method')), 'employee navigation uses a read-only GET form');
        employee_same('http://local.test/wp-admin/options-general.php', $selectorForm->getAttribute('action'), 'employee selector has a clean native destination');
        employee_same(1, $xpath->query('.//button[@name="hspcc-show-employee"] | .//input[@type="submit" and @name="hspcc-show-employee"]', $selectorForm)->length, 'employee selector works with its explicit submit control without JavaScript');
        employee_same($selectedTab, $xpath->query('.//input[@name="hspcc_tab"]', $selectorForm)->item(0)->getAttribute('value'), 'switching employees preserves the current settings subsection');
        employee_same(HSP_Calendar_Connection::PAGE, $xpath->query('.//input[@name="page"]', $selectorForm)->item(0)->getAttribute('value'), 'employee switch retains the native settings page');
        employee_same(0, $xpath->query('.//input[@name="hspcc_status" or @name="settings-updated" or @name="unrelated"]', $selectorForm)->length, 'employee switch discards stale notices and unrelated parameters');
        employee_same(0, $xpath->query('.//img | .//script', $selectorForm)->length, 'employee names cannot create markup in dropdown');
        $options = $xpath->query('./option', $selector->item(0));
        employee_same(3, $options->length, 'native employees including an unconfigured employee are selectable');
        employee_same(1, $xpath->query('./option[@selected]', $selector->item(0))->length, 'only the displayed employee is selected');
        foreach ($options as $option) {
            $employee = (int) $option->getAttribute('value');
            employee_same($employeeLabels[$employee] . ' (#' . $employee . ')', $option->textContent, 'employee names and IDs survive contextual escaping');
            employee_same($employee === $selectedEmployee, $option->hasAttribute('selected'), 'dropdown selection follows the current employee');
        }
        $navigation = $xpath->query('//nav[contains(concat(" ", normalize-space(@class), " "), " hspcc-settings-tabs ")]');
        employee_same(1, $navigation->length, 'one settings subsection navigation landmark is rendered');
        $tabs = $xpath->query('.//a', $navigation->item(0));
        employee_same(4, $tabs->length, 'four settings subsections have native navigation links');
        employee_same(0, $xpath->query('.//*[@role="tab" or @role="tablist" or @tabindex]', $navigation->item(0))->length, 'server navigation keeps ordinary link keyboard behavior');
        employee_same(1, $xpath->query('.//a[@aria-current="page"]', $navigation->item(0))->length, 'only the current subsection is marked current');
        foreach ($tabs as $tab) {
            $parts = parse_url($tab->getAttribute('href'));
            parse_str($parts['query'], $query);
            $section = $query['hspcc_tab'] ?? '';
            employee_ok(isset($sectionLabels[$section]), 'section URL uses a known tab identifier');
            employee_same('http://local.test/wp-admin/options-general.php', $parts['scheme'] . '://' . $parts['host'] . $parts['path'], 'tabs navigate to the native settings page');
            employee_same(['page' => HSP_Calendar_Connection::PAGE, 'hspcc_employee' => (string) $selectedEmployee, 'hspcc_tab' => $section], $query, 'tab URLs retain only page, employee and subsection');
            employee_same($sectionLabels[$section], $tab->textContent, 'tab label names the settings subsection');
            employee_same($section === $selectedTab ? 'page' : '', $tab->getAttribute('aria-current'), 'current link follows the requested subsection');
            employee_same($section === $selectedTab, str_contains($tab->getAttribute('class'), 'nav-tab-active'), 'native active-tab appearance follows current subsection');
        }
        employee_same(1, $xpath->query('//h2')->length, 'only the active subsection heading is rendered');
        employee_same($sectionLabel, $xpath->query('//h2')->item(0)->textContent, 'active subsection heading matches navigation');
        employee_same(1, $xpath->query('//div[contains(@class, "hspcc-synthetic-settings-notice")]')->length, 'native Settings API notices remain visible on every subsection');
        employee_ok(str_contains($page, 'Calendar choices saved. Run Check connection to verify availability access.'), 'custom action notices remain visible on every subsection');
        foreach ($xpath->query('//form[translate(@method, "POST", "post")="post"]') as $form) {
            foreach (['hspcc_employee' => (string) $selectedEmployee, 'hspcc_tab' => $selectedTab] as $field => $value) {
                $scope = $xpath->query('.//input[@type="hidden" and @name="' . $field . '"]', $form);
                employee_same(1, $scope->length, 'each settings action includes one scope and subsection field');
                employee_same($value, $scope->item(0)->getAttribute('value'), 'each settings action preserves the displayed scope and subsection');
            }
        }
        $provider = $xpath->query('//input[contains(@name, "[providerId]")]');
        employee_same($selectedTab === 'connection' ? 1 : 0, $provider->length, 'credentials form is rendered only in Connection settings');
        employee_same($selectedTab === 'connection' ? 1 : 0, $xpath->query('//input[@type="password"]')->length, 'other subsections contain no hidden credentials form');
        employee_same($selectedTab === 'runtime' ? 1 : 0, $xpath->query('//input[@name="enable_blocking"]')->length, 'runtime switches appear only in Runtime settings');
        employee_same($selectedTab === 'calendars' && $selectedEmployee !== 8 ? 1 : 0,
            $xpath->query('//select[@name="write_calendar"]')->length, 'calendar routing appears only for the selected employee with discovery data');
        if ($selectedTab === 'connection') {
            employee_same(HSP_Calendar_Connection::option_name(HSP_Calendar_Connection::OPTION, $selectedEmployee) . '[providerId]', $provider->item(0)->getAttribute('name'), 'connection field uses the selected employee option name');
            employee_same((string) $selectedEmployee, $provider->item(0)->getAttribute('value'), 'connection form retains the selected provider');
            $nativeForm = $xpath->query('//form[@action="http://local.test/wp-admin/options.php"]')->item(0);
            $referer = $xpath->query('.//input[@name="_wp_http_referer"]', $nativeForm);
            employee_same(1, $referer->length, 'connection form retains native Settings API return handling');
            parse_str(parse_url($referer->item(0)->getAttribute('value'), PHP_URL_QUERY), $refererQuery);
            employee_same((string) $selectedEmployee, $refererQuery['hspcc_employee'], 'native Settings return retains selected employee');
            employee_same('connection', $refererQuery['hspcc_tab'], 'native Settings return retains connection subsection');
            $endpoint = $xpath->query('//input[@id="hspcc-endpoint"]')->item(0)->getAttribute('value');
            employee_same($selectedEmployee === 8 ? '' : ($selectedEmployee === 1 ? 'https://one.example.test/caldav/' : 'https://seven.example.test:5006/caldav/'), $endpoint, 'displayed connection belongs only to selected employee');
        }
        if ($selectedTab === 'calendars') {
            if ($selectedEmployee === 8) {
                employee_ok(str_contains($page, 'Save a connection and discover calendars first.'), 'unconfigured routing gives a useful empty state');
                employee_same(0, $xpath->query('//input[@name="busy_calendars[]"]')->length, 'empty employee does not borrow another employee calendars');
            } else {
                employee_ok(str_contains($page, 'Synthetic busy ' . $selectedEmployee), 'routing shows selected employee discovered calendar names');
                employee_ok(!str_contains($page, 'Synthetic busy ' . ($selectedEmployee === 1 ? 7 : 1)), 'routing omits the other employee calendars');
            }
        }
        employee_same($uiBefore, $GLOBALS['hspcc_options'], 'page navigation changes no saved settings, flags, proofs or employee registry');
        employee_same($uiHttpBefore, count($GLOBALS['hspcc_requests']), 'page navigation performs no calendar HTTP request');
    }
}
// Treat malformed and poisoned read-only tab input as the safe default.
foreach (['', 'unknown', '../status', '<script>status</script>', 'status&hspcc_employee=1', ['status'], 42] as $invalidTab) {
    $xpath = employee_document(employee_render(7, $invalidTab));
    employee_same('Connection settings', $xpath->query('//h2')->item(0)->textContent, 'invalid tab input falls back to Connection settings');
    foreach ($xpath->query('//input[@name="hspcc_tab"]') as $field) {
        employee_same('connection', $field->getAttribute('value'), 'malformed tab input is never carried into forms');
    }
}
foreach (['runtime', ['status'], '../calendars'] as $postedTab) {
    employee_render(7, 'status', [], ['hspcc_employee' => '7', 'hspcc_tab' => $postedTab]);
    employee_same(is_string($postedTab) && $postedTab === 'runtime' ? 'runtime' : 'connection',
        invoke_private('requested_tab'), 'POST tab has precedence and invalid POST cannot inherit an unrelated GET subsection');
}
HSPCC_Employee_Context::select(7);
foreach (['connection', 'calendars', 'runtime', 'status', '../status'] as $tab) {
    $url = invoke_private('settings_url', $tab, 'runtime_saved');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    employee_same(['page' => HSP_Calendar_Connection::PAGE, 'hspcc_employee' => '7',
        'hspcc_tab' => isset($sectionLabels[$tab]) ? $tab : 'connection', 'hspcc_status' => 'runtime_saved'], $query,
        'custom action return URL preserves only validated employee, subsection and fixed notice');
}
parse_str(parse_url(invoke_private('settings_url', 'connection', 'discovered'), PHP_URL_QUERY), $discoveredQuery);
employee_same('calendars', $discoveredQuery['hspcc_tab'], 'successful discovery opens Calendar routing');
employee_same('7', $discoveredQuery['hspcc_employee'], 'successful discovery keeps its employee scope');
employee_same($uiBefore, $GLOBALS['hspcc_options'], 'invalid navigation and return-URL construction change no private settings');
$GLOBALS['hspcc_employee_styles'] = $GLOBALS['hspcc_employee_scripts'] = [];
HSP_Calendar_Connection::admin_assets('plugins.php');
employee_same([], $GLOBALS['hspcc_employee_styles'], 'settings styles are not loaded on other admin pages');
employee_same([], $GLOBALS['hspcc_employee_scripts'], 'employee selector script is not loaded on other admin pages');
HSP_Calendar_Connection::admin_assets('settings_page_' . HSP_Calendar_Connection::PAGE);
employee_same([['hspcc-admin', 'https://local.test/wp-content/plugins/hsp-calendar-connection/assets/admin.css', [], HSPCC_VERSION]], $GLOBALS['hspcc_employee_styles'], 'settings page loads its versioned stylesheet');
employee_same([], $GLOBALS['hspcc_employee_scripts'], 'native employee navigation requires no autosubmit script');
if (getenv('HSPCC_TEST_HTML_DIRECTORY')) {
    $longLabels = [
        1 => 'Alexandria Maximiliana von Kalenderverbindungsverwaltung',
        7 => 'ليلى عبد الرحمن مسؤولة المواعيد والخدمات والاستشارات',
        8 => 'Maria da Conceição Rodrigues de Albuquerque',
        9 => 'José María Fernández de la Conexión del Calendario',
        10 => str_repeat('Donaudampfschifffahrtsgesellschaft', 3),
        11 => '山田カレンダー接続および予約担当スタッフ',
    ];
    (new ReflectionProperty(HSPCC_Employee_Context::class, 'choices'))->setValue(null, $longLabels);
    foreach (['long-labels-ltr' => 1, 'long-labels-rtl' => 7, 'long-labels-unbroken' => 10] as $scenario => $employee) {
        foreach (array_keys($sectionLabels) as $tab) {
            employee_export_html($scenario . '-' . $tab . '.html', employee_render($employee, $tab));
        }
    }
}
employee_same($employeeHttpCount, count($GLOBALS['hspcc_requests']), 'employee context/settings fixtures make no new HTTP calls');

fwrite(STDOUT, 'PASS: ' . $employeeChecks . " actual-main employee/settings isolation checks; offline stubs only.\n");
