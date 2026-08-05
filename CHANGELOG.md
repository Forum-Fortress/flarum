# Changelog

All notable changes to Forum Fortress for Flarum are documented here.

## 1.0.2 - 2026-08-05

- Made Register Site button flows require a valid email and automatically use the admin account email if no configured registration email is present.
- Fixed dark-mode contrast for the portal email registration banner in anonymous sessions.
- Improved verification email sender fallback logic so verification messages work when no explicit SMTP from_email is configured.

## 1.0.1 - 2026-08-05

- Made site registration email optional and left it blank by default.
- Kept Register Site behavior aligned with automatic bootstrap and non-empty email-only validation for optional account attachment.

## 1.0.0 - 2026-08-02

- Initial public release for Flarum 2.
- Added automatic site bootstrap and regional endpoint failover.
- Added registration, login, topic, reply, edit, and profile checks.
- Added native approval/moderation synchronization and reporting.
- Added Flarum Admin status, connection, portal, attack-mode, and synchronization controls.
