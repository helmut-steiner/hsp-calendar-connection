# Offline development tests

Run from the plugin root:

    php tests/run.php

The CLI runner uses PHP_BINARY and isolated subprocesses. It syntax-checks plugin/tests PHP and runs synthetic credentials/discovery, URL/port policy, settings, employee selection and connection isolation, strict free-busy, employee-specific cache/guards, snapshots, conditional storage, durable queue and actual queue/storage recovery fixtures. Multi-employee fixtures include shared destinations, UI-independent routing, old-owner cleanup before transfer and lost-response recovery after native deletion. HTTP, WordPress options, native appointments and advisory locks are stubs. No WordPress installation is bootstrapped and no real calendar, database, email or customer record is used.

The runner limits each subprocess to 20 seconds and 128 KiB of output, with a 120-second overall bound. Use PHP 8.2 or 8.4 with DOM/XML and Sodium or OpenSSL AES-GCM. Load mbstring for the optional overlap suite. Optional extensions already loaded by a parent CLI command are forwarded when their shared module file is available; configuring them in php.ini is preferred. An explicit OpenSSL fallback also runs when available.

The isolated GitHub updater fixture uses synthetic release responses and site transients. It checks native update/details integration, plugin identity, newer/current/older versions, runtime-only asset selection, preview and unsafe asset refusal, failure caching, unrelated plugin preservation and release-note sanitization. It performs no HTTP requests and does not force WordPress's automatic-installation preference.

The current credential fixture checks s2/o2 encryption, independent key/AAD verification, random nonces, tamper and unsupported-format rejection, private options and employee isolation. It also runs with Sodium disabled to check OpenSSL. Employee UI checks exercise the dropdown plus selected-employee section tabs, clean destinations, selected state, escaped names, invalid navigation parameters and form scope. Calendar identities are checked separately by the storage/queue fixtures.

## Optional installed-Amelia suites

The overlap parser uses Amelia's prefixed Sabre VObject classes. Customer-panel rescheduling fixtures also run the actual installed 9.9.1 request creator, Slim body parser, command token extraction, UserApplicationService authorization and JWT signature/expiry verification against synthetic storage and HTTP requests. They exercise the registered pre-reschedule guard with busy/free intervals, routing, ownership, capacity, target-change, merge, failure and version cases, including canonical fixed-price on-site payments and negative online/package/link/payment-relationship cases. The on-site pricing fixture separately runs native reservation tax/price calculations, request-payment validation and payment creation with an in-memory payment repository: a synthetic 120 base plus 20% additive tax remains native 144 owed, with zero collected/pending on-site payment. The guard does not reproduce those calculations or mutate financial data. Native storage is fake; no real native appointment, payment or message is created. This repository does not bundle Amelia, its vendor directory, licenses, credentials or a downloaded paid plugin. To run these suites against your own installed Amelia distribution, set HSPCC_AMELIA_PATH to the Amelia plugin root containing vendor/autoload.php:

    HSPCC_AMELIA_PATH=/path/to/ameliabooking php tests/run.php

PowerShell:

    $env:HSPCC_AMELIA_PATH = 'C:/path/to/ameliabooking'
    php tests/run.php

When the environment variable is absent, these suites print SKIP and exit successfully. A supplied unreadable path, unsupported native version or missing prefixed VObject/DOM/mbstring dependency fails rather than pretending that the suite passed. Only selected native classes/request factory and its dependency autoloader run; WordPress and Amelia's plugin bootstrap are not loaded. A fixture future-version mode verifies fail-closed behavior separately.

CI can run the pure suites on PHP 8.2/8.4 with DOM, mbstring and OpenSSL, leaving this environment variable unset. A skipped optional suite does not establish overlap compatibility with a particular Amelia release; run it separately against each supported release.

## Source and release scope

These files are portable copies of synthetic development fixtures. No site address, private connection configuration, calendar response, database snapshot or customer data belongs here. Include tests in the GitHub development source; exclude tests and development tooling from the installable plugin ZIP. No fixture grants compatibility beyond the explicitly tested versions and supported appointment scope.

## Standards and translation checks

`composer install` installs the reviewed development tools from `composer.lock`; `composer standards` checks all distributed PHP against the WordPress ruleset. Narrow documented exceptions preserve native API names and security-critical protocol/database behavior. Run `composer audit --locked` to check the locked tools for security advisories; CI and releases enforce that check. Keep Composer security blocking enabled when updating dependencies and commit the updated lockfile. Runtime/development dependencies remain excluded from releases.

The rescheduling scope fixture runs independently under synthetic Amelia 9.9.1 and 9.9.2 contexts. It checks native pass-through outside enabled employee scope, configured source/target refusal, and canonical membership failures. This does not claim full native compatibility with 9.9.2.

Run `php tools/extract-messages.php > /tmp/hspcc-messages.json`, then `python tools/compile-catalogs.py --check --messages /tmp/hspcc-messages.json` to verify template coverage, every locale's message/placeholder set and compiled catalog bytes. On Windows, capture PHP stdout as UTF-8 bytes rather than routing it through a legacy-codepage shell.
