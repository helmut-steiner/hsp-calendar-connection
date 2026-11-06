# Changelog

## 0.5.7

- Require WordPress 7.1.4 or later and PHP 8.4 or later. Report these requirements in the native WordPress updater and plugin details.
- Run the portable test suite in CI on PHP 8.4 and 8.5.

## 0.5.6

- Restore employee selection through the dropdown and use tabs for the chosen employee's Connection settings, Calendar routing, Runtime settings and Connection status.
- Keep employee and section context through native settings saves and action redirects; successful discovery opens Calendar routing.
- Keep navigation read-only, accessible without JavaScript, and usable with long labels, mobile and RTL layouts.
- Use patched PHPCS/WordPressCS libraries, commit the development lockfile and enforce manifest validation and dependency audits in CI and releases.

## 0.5.5

- Replace the employee selector with native WordPress navigation tabs that preserve independent connection settings and status.
- Support long names, keyboard focus, mobile wrapping and right-to-left layouts without selector JavaScript.
- Remove completed legacy option, configuration and credential compatibility; require current HSP settings and authenticated s2/o2 credentials.
- Remove obsolete queue-record upgrades while preserving current employee-one storage and managed-event identity.
- Refresh all 12 translation catalogs and retain standards, credential-security and employee-isolation checks.

## 0.5.4

- Standardize active names on HSP/HSPCC, including classes, files, hooks, controls and settings; migrate legacy per-employee options and retain old encrypted-password readers without changing managed-event identity.

- Scope rescheduling restrictions to canonical configured source/target employees; preserve native rescheduling for employees outside the calendar connection scope.
- Correct the settings screen's supported-flow notice while retaining the existing Amelia 9.9.1 compatibility gate.
- Internationalize all 97 user-facing messages and bundle 12 locale catalogs, including both Portuguese variants.
- Apply WordPress coding standards, document native/security exceptions and enforce standards plus translation checks in CI and releases.
- Add isolated regression coverage for unconfigured employees, configured targets and canonical ownership on reviewed and newer Amelia version contexts.

## 0.5.3

- Establish the standalone `helmut-steiner/hsp-calendar-connection` source repository and new `hsp-calendar-connection` folder/main file, preserving private settings and managed-event identity. Manual installation replaces the old plugin code; never activate both copies.
- Integrate matching public GitHub Release ZIP assets with native WordPress update checks and plugin details, preserving the administrator's auto-update choice.
- Add a capability-scoped Settings link on the WordPress Plugins page, with the existing native settings screen as its destination.
- Add portable PHP CI and a tag-checked runtime-only release workflow with a SHA-256 file manifest, excluding private data, tests and third-party vendor dependencies.
- Adopt the HSP Personal Noncommercial Contribution License 1.0 with project-specific contribution and safe security-delivery instructions; preserve earlier grants and third-party terms.

## 0.5.2

- Support a reviewed Amelia 9.9.1 fixed-price paid frontend appointment with one person, capacity one and native on-site payment; preserve native tax, price and payment processing.
- Support its authenticated customer's same-target date/time move after reading the canonical single on-site payment relationship, while retaining fresh CalDAV conflict checks, buffers, ownership and no-merge restrictions.
- Refuse other paid gateways, paid extras, coupons, deposits, custom duration/pricing, packages, carts, groups and unreviewed paid backend/status changes. Cancellation remains possible during calendar outages.
- Extend synthetic guard/native authentication fixtures and verify installed native on-site pricing/payment behavior without real bookings, payments, mail or calendar writes.

## 0.5.1

- Support Amelia 9.9.1 customer-panel date/time moves for the authenticated owner's single approved/pending booking in a free capacity-one service.
- Bind the native customer request, native command authentication, canonical booking/appointment membership and employee ownership; refuse target changes, other actors, groups, waiting/closed records and target-appointment merges.
- Keep a fresh CalDAV check including extras and buffers before native persistence. Fail closed for unreviewed Amelia versions.
- Add synthetic fixtures running installed Amelia command authentication and JWT verification; no customer, database, mail or remote calendar writes occur in these fixtures.

## 0.5.0

- Add a named Amelia employee selector and one private CalDAV connection per employee.
- Isolate credentials, discovery, routing, timezone, health, feature gates, cache and write proof by employee; new employee connections start disabled.
- Preserve legacy employee-1 options and managed-event identifiers without rerouting existing records.
- Route native availability, appointment guards and lifecycle observers by the canonical employee, independently of the administrator's selected employee.
- Process all writing-enabled employees with owner-specific durable jobs and shared appointment advisory locks.
- Isolate managed-event UUID namespaces when employees share a destination; retain other employees' entries as external busy times.
- Reconcile an old employee's tracked event or uncertain write before creating a reassigned appointment in the new destination.
- Add synthetic multi-employee settings, cache, guards, ownership, reassignment and lost-response recovery fixtures; ship the employee selector script in runtime releases.

## 0.4.0

- Make the HTTPS CalDAV address and Amelia employee ID configurable.
- Restrict requests and explicit ports to the configured origin while retaining WordPress safe-HTTP checks.
- Reset discovery/health and disable features after safe connection changes; block changes requiring managed-resource/queue/probe reconciliation.
- Provide explicit setup and feature-enable controls for new installations.
- Add connection-bound Test write access / Remove test event controls; disabling features remains available during incomplete setup.
- Require explicit password entry for a new origin; keep routing during password rotation while disabling writing and invalidating its proof.
- Retain legacy employee-1 choices and require older unbound write proofs to be retested.
- Document PHP DOM/XML, mbstring and authenticated-encryption requirements, plus DNS-hostname-only endpoint configuration.
- Add generic installation and licensing documentation without site-specific connection data.

## 0.3.0

- Select multiple busy calendars and one write destination.
- Preserve manual conflicts when the destination is also a busy source; process expanded events and ignore unrequested alarm/attachment metadata.
- Add minimal appointment projection, conditional ETag storage and owned-resource deletion.
- Add durable intent/outbox recovery for lost write acknowledgements, cancellations and changed native state.

## 0.2.0

- Add bounded free/busy reads, short display caching and fresh save guards.
- Integrate the validated free-fixed appointment scope with native Amelia hooks.

## 0.1.0

- Add private encrypted credential setup, discovery and calendar selection.
