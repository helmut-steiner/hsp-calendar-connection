# HSP Calendar Connection

Version 0.5.5. Private CalDAV busy-time checks and Amelia appointment writeback, with one connection per employee.

HSP Calendar Connection is a separate WordPress plugin. Amelia remains responsible for services, appointments, working hours and notifications. The plugin does not provide appointment availability or replace Amelia's booking interface.

## Requirements

- WordPress 5.8 or later with PHP 8.0 or later and normal WordPress HTTP/cron support.
- PHP DOM/XML and mbstring, plus Sodium or OpenSSL with AES-256-GCM for authenticated credential encryption.
- Amelia 9.9.1, whose hooks, native repositories and prefixed Sabre VObject classes are the currently validated integration surface.
- An HTTPS CalDAV server/account that permits discovery and reading all selected busy calendars, plus reading/creating/updating/deleting resources in the selected write calendar.
- A functioning WordPress database with MySQL/MariaDB advisory-lock support for durable write processing.

Amelia and its vendor dependencies are not included or redistributed in this package. Other Amelia versions require compatibility testing before use.

## Installation and setup

1. Install the plugin folder or ZIP through WordPress and activate it.
2. Open **Settings → HSP Calendar Connection** as an administrator.
3. Open the named Amelia employee tab. The connection settings, calendar choices, feature controls and status below follow that selection. Create employees in Amelia first.
4. Enter that employee's HTTPS CalDAV address and credentials. Enter passwords only in this private interface; the password field stays blank after saving. Discover calendars, then select up to ten busy sources and, optionally, one write destination on that connection.
5. Set the fallback timezone if needed. It initially follows the WordPress site timezone, with UTC as fallback; an already saved selection is preserved. A published calendar timezone takes priority.
6. Check the read connection. For writeback, click **Test write access** to create/update a temporary event containing no customer data, then **Remove test event** to complete cleanup. Finish both steps before enabling writing.
7. Under **Runtime settings**, choose the two feature checkboxes and click **Save runtime settings**. Each new employee connection starts with both features disabled; disabling remains available even when the connection is incomplete. Repeat setup for each employee who needs a connection.
8. Configure real Amelia working hours, services, policies and notifications, then test the full booking lifecycle before offering appointments.

The destination may also be a busy source. The plugin excludes that employee's managed appointment events from the source while retaining overlapping manual events and events owned by other employees. Employees may use separate CalDAV accounts or share a calendar; each employee has a distinct managed-event namespace.

Selecting an employee in the administrator screen does not change runtime routing. Availability requests and appointment hooks use the native appointment employee; workers use the durable job owner, including after a native appointment has been deleted. An unconfigured employee retains Amelia's normal behavior.

## Connection safety

Requests use TLS verification, stay on the configured HTTPS origin and follow no redirects. Explicit HTTPS ports are restricted to that same origin through a scoped port filter. Standard WordPress safe-HTTP protection still blocks private/reserved DNS destinations; the plugin does not bypass it.

Use a DNS hostname. IPv6 literal addresses are not supported.

Changing an employee's CalDAV address or account is blocked while that connection has managed resources, unresolved queue state or a probe requiring reconciliation. A safe connection change clears that employee's discovery/cache health and disables both features, requiring fresh setup. Destination changes similarly require reconciliation; no automatic connection or destination migration tool is provided. Switching the employee tabs only changes the settings being displayed.

Credentials use authenticated encryption tied to WordPress salts. A blank password preserves an existing secret only on the same origin; a different origin requires explicit password entry. Password rotation keeps calendar routing but disables writing and invalidates its proof: repeat the write test and cleanup before enabling it again. Salt rotation or moving options to different salts requires password re-entry. Keep connection options and credentials out of Git, exports and support logs.

The write proof must match the current employee connection and destination. An absent or unbound proof requires testing before writing can be enabled. Employee-one storage and owned-resource identifiers remain unchanged; never copy its settings into another employee's connection or discard tracked state.

## Busy-time checks

Each employee's selected calendars contribute a union of busy intervals. Display requests may reuse validated data for at most 60 seconds; appointment-save guards fetch fresh data. If a required source cannot be verified, that employee's affected availability is refused rather than treating an error as free time. Other configured employees use their own connection and health state.

Queries are bounded. An overlapping write calendar uses an expanded minimal-property event query so owned events can be excluded without subtracting manual conflicts. Unrequested alarms, attachments, summaries and notes are removed from the busy projection; attachment URLs are not opened. Floating/all-day entries require a resolvable published or configured timezone.

## Appointment writeback

Hooks collect native identifiers. A later worker reads current committed appointment state and performs asynchronous writeback; a calendar failure does not roll back an appointment already saved by Amelia.

Events contain service/reference, UTC times and status. They contain no customer names/contact details, attendees or reminders. Stable employee-specific site/appointment identifiers and conditional ETag requests prevent duplicate creation and unguarded overwrites. Deletion requires a known owned resource.

A durable technical intent is saved before each write. Read-only recovery verifies ownership and recomputes the event projection hash before accepting an attempted or previously acknowledged version. This preserves cancellation/rescheduling recovery when a successful response was lost. Other conflicts remain pending for review.

WordPress cron retries pending work for all writing-enabled employees under a shared appointment advisory lock and owner-specific state. An appointment moved to another connected employee is created in the new destination only after any old owned event or uncertain write has been reconciled. A disabled or unavailable old connection can leave this transfer pending for review. This queue protection does not expand the native booking flows listed below.

Review the selected employee's connection/queue health when a calendar is unavailable. Ambiguous ownership or unreadable global queue state can block connection changes until reviewed. Deactivation removes scheduled hooks but preserves credentials, jobs and mappings for recovery; do not remove state to hide unresolved writes.

## Supported scope

The validated busy-time guard scope includes free fixed appointments and free selected extras for configured employees whose busy checks are enabled. Amelia 9.9.1 also permits a fixed positive-price frontend appointment with exactly one booking/person, capacity one and native on-site payment. Paid selected extras, coupons, deposits and custom durations/pricing are refused. Adding a paid customer booking to an existing parent appointment remains refused, including native reuse of a parent with closed historical children. Amelia alone calculates and stores prices, taxes and payment totals; on-site payment records normally show zero collected and pending status even when money is owed.

Customer-panel rescheduling is supported for an authenticated customer's own single approved/pending booking in a capacity-one service, without target changes or merging into another appointment. A paid booking also requires an unchanged fixed base price and exactly one canonical native on-site payment for that booking, with no package/parent/WooCommerce/online-transaction or payment-link association. No price, tax, amount collected or payment status is changed by the guard. The guard reconstructs native command authentication from the native request, checks canonical database membership and employee ownership, and fetches fresh busy times before persistence. Other actors, group/waiting/closed bookings, custom-field changes and target merges remain refused. Both paid support and customer-panel moves fail closed on other Amelia versions until their native contracts are reviewed.

Online payments/callbacks, recurring/package/cart and other single-booking reassignment remain outside this scope. Other paid backend creation, updates and status activation are refused; do not enable such a path assuming that a native callback will receive the frontend guard. Ordinary free whole-appointment time changes/status activation are guarded. Cancellation remains possible during a calendar outage, including for existing paid appointments. Queue cleanup for whole-appointment employee changes does not expand the supported paid/customer-panel scope. Existing online-payment attempts from before an upgrade need separate reconciliation; no callback support is added.

No online-meeting integration is added. A read-only availability check cannot atomically prevent another calendar client from inserting a conflict between checking and Amelia's commit. Full booking, notification, timezone and language tests remain part of site acceptance.

## Languages

The plugin includes English source text and complete catalogs for German (`de_DE`), Spanish (`es_ES`), Brazilian Portuguese (`pt_BR`), European Portuguese (`pt_PT`), French (`fr_FR`), Italian (`it_IT`), Dutch (`nl_NL`), Polish (`pl_PL`), Japanese (`ja`), Simplified Chinese (`zh_CN`), Arabic (`ar`) and Hindi (`hi_IN`). This covers plugin settings, validation, status messages and visitor-facing calendar errors. WordPress uses the administrator's profile language for the admin area and the current site/request language for visitors. Customer records, service names and calendar names remain owned by their original systems.

Edit the relevant `languages/hsp-calendar-connection-<locale>.po`, then run `python tools/compile-catalogs.py`. The English message template is `languages/hsp-calendar-connection.pot`. Format placeholders such as `%1$d` and `%1$s` must be preserved. The compiler checks complete message coverage, placeholders and compiled `.mo` readback; `--check` validates without writing. CI also compares the template against `php tools/extract-messages.php` output so new user-facing messages cannot silently miss the catalog update. No runtime translation service or credentials are needed.

## Development and releases

This package contains plugin-owned integration code only. It does not bundle WordPress, Amelia or Amelia's Sabre dependencies. Site-specific credentials, calendar names/addresses, raw events and local verification artifacts must remain outside the distributable plugin.

The source of truth is [helmut-steiner/hsp-calendar-connection](https://github.com/helmut-steiner/hsp-calendar-connection). The WordPress folder and main file are **`hsp-calendar-connection/hsp-calendar-connection.php`**. Active classes, files, hooks, controls and settings use the `HSP`/`HSPCC` or `hsp`/`hspcc` prefixes. Only current HSP option names and credential formats are supported; managed-event identifiers remain unchanged.

Run the portable tests from the repository root with PHP 8.2 or 8.4 using `php tests/run.php`; see [tests/README.md](tests/README.md). CI runs the synthetic suites on PHP 8.2 and 8.4 with DOM/XML, mbstring and authenticated-encryption extensions. The fixture runner uses newer reflection behavior; these CI runtimes do not change the plugin's declared PHP 8.0 minimum or establish that minimum's runtime compatibility. Optional installed-Amelia suites are skipped unless `HSPCC_AMELIA_PATH` points to a separately obtained installation. CI and releases do not download or redistribute Amelia, its vendor dependencies or license material. Passing synthetic tests does not establish live booking or calendar compatibility.

Build an installable package offline:

```text
python tools/build-release.py --tag v0.5.5
```

The builder checks the tag against the plugin header and readme stable tag. It writes `dist/hsp-calendar-connection-0.5.5.zip` plus a SHA-256 file manifest using an explicit runtime-file allowlist. The archive includes the license, contribution instructions and security policy; it excludes tests, tools, CI files, vendor dependencies and private site data. The `.distignore` file documents exclusions for other packaging tools; the Python allowlist is authoritative.

For each release, update the main plugin `Version` header and `HSPCC_VERSION` constant, `readme.txt` stable tag, this version notice and both changelogs; run tests and build the ZIP before publishing. The builder checks that the runtime constant matches the header. Create a matching `v<version>` tag and GitHub Release in this repository. The Release workflow checks out that tag, reruns the portable tests, verifies metadata and uploads the exact `hsp-calendar-connection-<version>.zip` asset plus its manifest. Manual workflow dispatch also requires an existing matching tag and release. Publishing a release or pushing a tag is a separate maintainer action; repository setup alone does not publish one.

## Migration and WordPress updates

Version 0.5.5 supports fresh installations and existing connections already using current HSP settings, `busySelected` routing, version-2 queue records and `s2:`/`o2:` encrypted credentials. Historical option-name imports, configuration upgrades and old encrypted-password readers have been removed. Installing 0.5.4 alone did not rewrite old encrypted passwords; convert any remaining old credentials with that version before replacing it. Keep a private options backup and the previous ZIP for recovery.

Install the new ZIP manually, then verify the version, employee tabs, calendar choices and feature state. Keep only one active plugin copy. Deactivation preserves private settings, jobs and mappings. Code rollback does not undo later calendar writes; review unresolved jobs before rollback.

The new plugin integrates **public GitHub Releases** with WordPress's normal update checks and plugin details. It accepts the matching `hsp-calendar-connection-<version>.zip` release asset with its `hsp-calendar-connection/` root. GitHub source archives and arbitrary ZIP assets are not update packages. The updater does not authenticate private repositories or release downloads. A repository without a suitable published release has no update to offer.

After installing this version, use **Plugins → Installed Plugins → Enable auto-updates** for this plugin if unattended installation is wanted. WordPress retains control of that setting and its normal update scheduler. The updater does not enable automatic installation on the administrator's behalf.

## License and contributions

Copyright (c) 2026 Helmut Steiner. Licensed under the **HSP Personal Noncommercial Contribution License 1.0**; see [LICENSE](LICENSE) for the complete terms. Personal noncommercial use is permitted. Commercial, business and organizational use requires separate written permission from Helmut Steiner.

Substantive modifications, including private changes, must be contributed within **30 calendar days** of first saving each substantive change, or before redistribution, whichever is earlier, subject to the license's delivery exceptions. Contributors retain their copyright and grant the section 5 rights, including commercial and proprietary relicensing rights. Earlier copies retain their previously granted permissions; third-party components retain their own terms.

The Original Project is [helmut-steiner/hsp-calendar-connection](https://github.com/helmut-steiner/hsp-calendar-connection). Submit modifications by [pull request](https://github.com/helmut-steiner/hsp-calendar-connection/pulls) following [CONTRIBUTING.md](CONTRIBUTING.md). For commercial permission or a private security delivery channel, open a [non-sensitive contact request](https://github.com/helmut-steiner/hsp-calendar-connection/issues); do not include vulnerability details, credentials or personal data. Follow [SECURITY.md](SECURITY.md) and section 4 of the license for safe delivery.
