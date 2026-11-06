# Contributing

## Development

Read the licensing and submission requirements below before changing the software. Fork [helmut-steiner/hsp-calendar-connection](https://github.com/helmut-steiner/hsp-calendar-connection), create a feature branch and keep changes focused. Follow WordPress coding standards. The installed identity is `hsp-calendar-connection/hsp-calendar-connection.php`; preserve existing saved option names and managed-event identifiers.

Run `php tests/run.php` with PHP 8.4 and 8.5 and `python tools/build-release.py` from the repository root. See [tests/README.md](tests/README.md) for isolated synthetic fixtures and optional tests against your own installed Amelia distribution. Do not add paid plugin code, vendor dependencies, credentials, calendar responses or site/customer data to the repository or releases. Do not create real bookings, calendar events, payments or notifications during tests without a separately authorized test flow and cleanup.

## License and required contributions

HSP Calendar Connection uses the [HSP Personal Noncommercial Contribution License 1.0](LICENSE). The Original Project is [helmut-steiner/hsp-calendar-connection](https://github.com/helmut-steiner/hsp-calendar-connection), maintained by Helmut Steiner. Commercial, business or organizational activity requires separate written permission; these contribution instructions do not grant an exception.

Submit substantive modifications by [pull request](https://github.com/helmut-steiner/hsp-calendar-connection/pulls) within **30 calendar days** of first saving each substantive change, or before distributing a copy containing it, whichever comes first. This includes private, experimental, unfinished and later abandoned modifications. You may group changes, but ongoing work does not restart their deadlines. Ordinary settings, user data and processed output are excluded as described in section 3 of the license.

Provide a usable patch against an identified version or the modified source with sufficient context, a description of changes and any necessary build instructions. Identify authors and third-party material accurately. Obtain any required permission from an employer, co-author or other rights holder before submission.

Include this acknowledgment in your pull request or other submission:

> I have authority to grant the rights described in section 5 of the HSP Personal Noncommercial Contribution License 1.0 for this contribution, and I grant those rights to Helmut Steiner, including commercial and proprietary relicensing rights. I retain any copyright I hold in my original contribution and have identified third-party material and its terms.

Submission does not require acceptance or merging by the maintainer and does not entitle you to payment or support. Retaining copyright in your own contribution does not give you commercial rights to the combined plugin.

For commercial permission or another delivery method, open a [non-sensitive contact request](https://github.com/helmut-steiner/hsp-calendar-connection/issues) for Helmut Steiner. For security-sensitive changes, follow [SECURITY.md](SECURITY.md) and request a private delivery channel without disclosing vulnerability details. Never submit credentials, signing keys, private site data or unnecessary personal information.

If a submission channel is unavailable or a private channel has not been supplied, follow section 4 of the license: keep a dated record and a submission-ready copy, check documented contact locations at least every 30 days and deliver within 14 days after you learn, or would learn through those checks, that safe delivery is available. The license governs its exceptions and all other terms.
