=== HSP Calendar Connection ===
Tags: caldav, calendar, appointments, amelia
Requires at least: 5.8
Requires PHP: 8.0
Stable tag: 0.5.6
License: HSP Personal Noncommercial Contribution License 1.0
License URI: https://github.com/helmut-steiner/hsp-calendar-connection/blob/main/LICENSE

Private CalDAV busy-time checks and appointment writeback with one connection per Amelia employee.

== Description ==

Choose an employee by name from the dropdown, connect their HTTPS CalDAV account, select multiple busy calendars and optionally one write destination on that connection. Settings and status follow the selected employee. Native appointments and background workers route by their actual employee, independently of the administrator's selection.

Each employee has separate credentials, routing, health, feature controls and managed-event identifiers. Employees may share a destination without sharing event ownership. A write calendar may also be a busy source; that employee's owned entries are excluded while manual conflicts and other employees' entries remain busy.

Amelia retains services, working hours, bookings and notifications. This is a separate integration plugin, not a native Amelia calendar connector. Amelia 9.9.1 and its prefixed Sabre VObject classes are required; neither Amelia nor vendor dependencies are bundled.

PHP 8.0+, DOM/XML, mbstring and Sodium or OpenSSL AES-256-GCM are required. Use an HTTPS DNS hostname; IPv6 literal addresses are not supported.

The validated guard scope includes free fixed appointments/free selected extras and Amelia 9.9.1 fixed paid frontend appointments with one person, capacity one and native on-site payment. Native Amelia retains price/tax/payment calculations. Customer-panel time changes require the authenticated owner's own single approved/pending capacity-one booking, unchanged targets and no merge. Paid moves additionally require an unchanged fixed base price and one canonical on-site payment with no package/parent/WooCommerce/online association. Native authentication, canonical membership and fresh CalDAV checks are required. Paid extras, coupons, deposits, custom durations/pricing, online payments/callbacks, recurring/package/cart and other single-booking reassignment remain outside scope. Other paid backend creation/update/status paths are refused; cancellation remains possible during calendar failures. These paths fail closed on unreviewed Amelia versions. Each new employee connection starts disabled.

Adding a paid booking to an existing parent appointment remains refused, including reuse of a parent with closed historical children. New on-site appointments and the proven same-parent customer time-change path are covered; other paid rebooking paths need separate review.

Public GitHub Releases integrate with native WordPress update checks and plugin details. The required release asset is hsp-calendar-connection-<version>.zip with the hsp-calendar-connection/ root; GitHub source archives are not accepted. Private repositories and authenticated release downloads are unsupported. Administrators may enable normal WordPress auto-updates from the Plugins screen; the plugin does not change that choice.

== Installation ==

Install version 0.5.6 manually. It requires current HSP option names, busySelected routing, version-2 queue records and s2:/o2: encrypted credentials, or a fresh installation. Old option/configuration/password imports have been removed. Installing 0.5.4 alone did not convert old encrypted passwords; finish conversion before replacing it. Retain a private options backup and the previous ZIP for recovery. Keep only one active copy.

1. Install and activate HSP Calendar Connection alongside Amelia 9.9.1.
2. Open Settings > HSP Calendar Connection as an administrator.
3. Choose the named Amelia employee from the dropdown, then privately save their HTTPS CalDAV address and account credentials. Create employees in Amelia first.
4. In Connection settings, save the connection and discover calendars. Then use Calendar routing to select busy sources, optionally one write destination, and the fallback timezone.
5. Use Connection status to check reading. In Calendar routing, click Test write access, then Remove test event. The temporary event has no customer data; cleanup completes the current connection-bound proof.
6. Choose the two checkboxes under Runtime settings and click Save runtime settings. New employee connections start disabled; disabling remains available even if setup is incomplete. Repeat setup for each employee who needs a connection.
7. Configure Amelia's real hours/services/policies and test bookings, changes, cancellation and notifications before public use.

Requests remain on the configured HTTPS origin, verify TLS and follow no redirects. Standard WordPress safe-HTTP restrictions remain active. Passwords are encrypted against WordPress salts and are never filled back into the form.

Blank password entry preserves a secret only on the same origin. A different origin requires explicit password entry. Password rotation keeps routing but disables writing and requires a fresh write test/cleanup. An absent or unbound proof requires testing before writing can be enabled. Current employee-one options and owned-resource identifiers remain unchanged.

== Frequently Asked Questions ==

= Does this create a booking system? =

No. Amelia owns appointments and availability. This plugin adds external conflict checks and asynchronous calendar writeback.

= Are customer details written to the calendar? =

Events use service/reference, UTC times and status. No customer names/contact details, attendees or reminders are added.

= Can I change the connection or destination? =

Managed resources, unresolved jobs or probe state must be reconciled first. Safe connection changes reset discovery/health and disable the features until setup is repeated. There is no automatic destination migration.

= What happens when an appointment changes employee? =

For a supported whole-appointment change, tracked old-owner writes must be reconciled before a new-owner event is created. A disabled or unavailable old connection leaves the transfer pending for review. The limited customer-panel time-change path keeps the same native appointment and employee; other single-booking reassignment is not supported.

= What happens during failures? =

Required busy-source failures refuse affected availability. Writeback failures remain in the durable retry queue and do not roll back an already saved Amelia appointment. Conflicts require review. A functioning WordPress cron scheduler and database advisory locks are required.

= Is state deleted on deactivation? =

Scheduled hooks are cleared, while private credentials/jobs/mappings are retained for reviewed recovery. Salt changes require password re-entry.

== License and Contributions ==

Copyright (c) 2026 Helmut Steiner. This copy uses the HSP Personal Noncommercial Contribution License 1.0; complete terms are included in LICENSE. Personal noncommercial use is permitted. Commercial, business and organizational activity requires separate written permission. Earlier copies retain permissions already granted with them, and third-party terms remain applicable.

The Original Project is https://github.com/helmut-steiner/hsp-calendar-connection. Submit substantive modifications, including private, experimental, unfinished and later abandoned changes, by pull request at https://github.com/helmut-steiner/hsp-calendar-connection/pulls within 30 calendar days of first saving each substantive change or before redistribution, whichever is earlier. Follow the included CONTRIBUTING.md and its section 5 rights acknowledgment. Contributors retain their copyright while granting Helmut Steiner rights including commercial and proprietary relicensing rights.

For commercial permission or a private delivery method, open a non-sensitive contact request for Helmut Steiner at https://github.com/helmut-steiner/hsp-calendar-connection/issues. Do not disclose vulnerability details, credentials or private calendar/customer data there. Follow the included SECURITY.md and section 4 of LICENSE. When safe delivery is unavailable, retain a dated record and submission-ready copy, check documented contact locations at least every 30 days and deliver within 14 days after safe delivery becomes known or would be learned through those checks.

== Changelog ==

= 0.5.6 =
* Restore the employee dropdown and organize the selected employee's Connection settings, Calendar routing, Runtime settings and Connection status into separate tabs.
* Preserve employee and section context during navigation and form actions; open Calendar routing after successful discovery.
* Include the patched, locked development tools and security audits required by GitHub CI.

= 0.5.5 =
* Use employee tabs for independent connection settings and status, with mobile/RTL wrapping and keyboard focus.
* Remove historical option, configuration, password and queue-format compatibility after conversion to current formats.
* Refresh all 12 language catalogs and current-credential security checks.

= 0.5.4 =
* Standardize HSP/HSPCC names with safe per-employee option and encrypted-password compatibility.
* Preserve native rescheduling for employees outside configured calendar scope while protecting configured source and target employees.
* Add complete German, Spanish, Brazilian/European Portuguese, French, Italian, Dutch, Polish, Japanese, Simplified Chinese, Arabic and Hindi translations.
* Fix the supported-scope notice; apply WordPress coding standards and enforce standards/catalog verification in CI.

= 0.5.3 =
* Establish the standalone helmut-steiner/hsp-calendar-connection source repository and new hsp-calendar-connection folder/main file; retain saved private settings and managed-event identity.
* Integrate public GitHub Release assets with native WordPress update checks and plugin details; retain the administrator's auto-update choice.
* Add a permission-aware Settings link on the Plugins page leading to the existing plugin settings screen.
* Add portable PHP CI and a tag-checked runtime-only release build with a SHA-256 manifest.
* Adopt HSP Personal Noncommercial Contribution License 1.0 with project-specific contribution and safe security-delivery instructions; preserve earlier grants and third-party terms.

= 0.5.2 =
* Support fixed paid capacity-one frontend appointments with native on-site payment and unchanged native price/tax calculations.
* Admit same-target customer time changes only after canonical single on-site payment validation.
* Keep other paid gateways, extras, coupons, deposits, custom pricing/duration and backend/status paths closed; preserve cancellation.
* Extend synthetic native pricing/payment, authentication and fail-closed fixtures.

= 0.5.1 =
* Support a native authenticated customer's own capacity-one free appointment time change in Amelia 9.9.1.
* Reuse native request parsing and command authentication, bind canonical booking/employee ownership and fetch fresh busy times.
* Refuse target changes, other actors, groups, waiting/closed records, merges and unreviewed Amelia versions.
* Add installed-native request, authentication, JWT and negative fixtures without real bookings, mail or calendar writes.

= 0.5.0 =
* Named employee selector with a separate CalDAV connection, routing, health and feature state per employee.
* Canonical employee routing for availability, guards, observers and background jobs.
* Separate managed-event namespaces for shared calendars; reconcile old ownership before appointment transfer.
* Preserve existing employee-1 options and identifiers; start new employee connections disabled.
* Add portable multi-employee isolation and recovery fixtures plus the admin selector script.

= 0.4.0 =
* Configurable HTTPS CalDAV address and employee ID for reusable installation.
* Safe connection-change reset and explicit feature enablement controls.
* Scoped same-origin support for explicit HTTPS ports.
* Connection-bound write testing/cleanup, safe password rotation and legacy-proof retesting.

= 0.3.0 =
* Multiple busy sources, one write destination and overlap-safe owned-event filtering.
* Conditional appointment storage, durable outbox and uncertain-response recovery.

= 0.2.0 =
* Bounded busy-time reads and Amelia appointment guards.

= 0.1.0 =
* Private encrypted credential setup and calendar discovery.

== Upgrade Notice ==

= 0.5.6 =
The employee dropdown is retained. Tabs now select sections of that employee's settings; Connection settings is the default.

= 0.5.5 =
Historical data imports and s1/o1 password readers have been removed. Existing installations must already use the current formats; retain a private settings backup before replacing the previous version.

= 0.5.4 =
Fixes employee scope during rescheduling and adds translated settings/messages. Existing connection state and the Amelia 9.9.1 compatibility restrictions are retained. When moving from the old hs-calendar-connection identity, deactivate the old copy before activating this package.

= 0.5.3 =
Manual installation is required for the new hsp-calendar-connection folder/main file. Deactivate and remove the old hs-calendar-connection plugin code before activating the new copy; never run both. Saved private state remains in place. This copy uses the included HSP Personal Noncommercial Contribution License 1.0; earlier copies retain their previously granted permissions.

= 0.5.2 =
Private state and managed-event identifiers stay in place. Paid support is limited to fixed capacity-one native on-site frontend appointments and the authenticated owner's proven same-target time change. Online callbacks and other paid flows remain unsupported; reconcile existing payment attempts separately. Re-audit the native contract before upgrading Amelia.

= 0.5.1 =
Private state and managed-event identifiers stay in place. Customer-panel rescheduling is narrowly supported on reviewed Amelia 9.9.1 only; re-audit that native hook and request contract before upgrading Amelia. No paid/group/target-change support is added.

= 0.5.0 =
Existing employee-1 options and managed-event identifiers remain in place. Each additional employee needs private setup, calendar checks and explicit feature enablement. Former single-connection installations using another employee require reviewed migration before upgrade. Reconcile tracked writes before changing an employee's connection or destination.

= 0.4.0 =
Review connection/employee settings and feature state. Reconcile managed or unresolved writes before changing connection identity or destination. Retest legacy unbound write proofs; password rotation also requires a new test and cleanup before writing resumes.
