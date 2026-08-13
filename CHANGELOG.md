# Changelog

All notable changes to Forum Fortress for Flarum are documented here.

## 1.1.4 - 2026-08-13

- Removed activation/email-verification reporting. Registration is reported
  once after the account is created, matching the other integrations.

## 1.1.3 - 2026-08-13

- Removed activation/email-verification reporting. Registration is reported
  once after the account is created, matching the other integrations.

## 1.1.2 - 2026-08-11

- Verified compatibility with Flarum 1.8.18 using Flarum Core 1.8.18,
  Approval 1.8.2, and Flags 1.8.2.
- No compatibility code changes were required.
- Updated the plugin version reported to Forum Fortress services.

## 1.1.1 - 2026-08-11

- Tighten endpoint selection and fast failover for control-plane and edge
  requests.
- Keep endpoint observations available for portal diagnostics without adding a
  plugin-specific API surface.
- Refresh the administration control plane styling and release metadata.

## 1.1.0 - 2026-08-08

- Added support for Flarum 1.8 while retaining Flarum 2 support in one Composer package.
- Added version-specific Flarum 1 and Flarum 2 admin bundles selected automatically at runtime.
- Restored the translated administration dashboard and shield icon on both supported Flarum series.
- Restored portal login as a direct browser link so popup creation and authentication work on both versions.
- Confirmed clean automatic bootstrap, authenticated control access, and moderation traffic against the production service on both Flarum 1.8 and 2.
- Kept existing Flarum and framework dependency versions untouched during installation.

## 1.0.1 - 2026-08-05

- Made site registration email optional and left it blank by default.
- Kept Register Site behavior aligned with automatic bootstrap and non-empty email-only validation for optional account attachment.

## 1.0.0 - 2026-08-02

- Initial public release for Flarum 2.
- Added automatic site bootstrap and regional endpoint failover.
- Added registration, login, topic, reply, edit, and profile checks.
- Added native approval/moderation synchronization and reporting.
- Added Flarum Admin status, connection, portal, attack-mode, and synchronization controls.
