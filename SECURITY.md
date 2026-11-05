# Security policy

Do not include credentials, application passwords, calendar URLs or names, raw events, customer details, database exports or WordPress salts in public issues.

Use a private reporting channel expressly designated by Helmut Steiner in the [Original Project](https://github.com/helmut-steiner/hsp-calendar-connection). If GitHub private vulnerability reporting is enabled for this repository, it is available from its Security tab.

If no private channel is available, open a [non-sensitive contact request](https://github.com/helmut-steiner/hsp-calendar-connection/issues) asking Helmut Steiner for a private delivery method. Do not put vulnerability details, exploit code, credentials or private site/calendar/customer data in public issues. Wait for private delivery instructions before sending sensitive material.

For modifications covered by the [HSP Personal Noncommercial Contribution License 1.0](LICENSE), section 4 provides the safe-delivery procedure. Keep a dated record of your request or attempted delivery and a submission-ready copy while a channel is unavailable. Check documented contact locations at least every 30 days and deliver within 14 days after you learn, or would learn through those checks, that safe delivery is available. Do not disclose sensitive details publicly merely to meet a contribution deadline.

Report the plugin, WordPress, PHP and Amelia versions, a synthetic reproduction, and sanitized error codes. Keep real connection data on the affected site.

The currently tested integration is Amelia 9.9.1. Other versions need compatibility verification. This plugin uses HTTPS, WordPress safe HTTP checks and conditional calendar writes; it does not support insecure HTTP, private network endpoints or bypassing certificate validation.
