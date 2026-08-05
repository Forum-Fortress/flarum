# Changelog

All notable changes to Forum Fortress for Flarum are documented here.

## 1.0.3 - 2026-08-05

- Updated portal verification warning copy to avoid assuming SMTP is missing when delivery is otherwise configured.
- Documented recommended scheduler setup for production cron execution in the extension README.

## 1.0.2 - 2026-08-05

- Required valid registration email for plugin Register Site flow, with admin email fallback when configuration is blank.
- Fixed portal anonymous registration banner contrast in dark mode.
- Hardened SMTP verification sender fallback when `from_email` is left blank.

## 1.0.1 - 2026-08-05

- Made site registration email optional and left it blank by default.
- Kept Register Site behavior aligned with automatic bootstrap and non-empty email-only validation for optional account attachment.

## 1.0.0 - 2026-08-02

- Initial public release for Flarum 2.
- Added automatic site bootstrap and regional endpoint failover.
- Added registration, login, topic, reply, edit, and profile checks.
- Added native approval/moderation synchronization and reporting.
- Added Flarum Admin status, connection, portal, attack-mode, and synchronization controls.
