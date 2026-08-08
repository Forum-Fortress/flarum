# Forum Fortress for Flarum 1.8 and 2

Forum Fortress adds cloud-based spam and abuse protection to Flarum 1.8 and 2. It checks supported forum activity with the Forum Fortress service and returns a simple `ALLOW` or `BLOCK` decision before Flarum completes the action.

## Features

- Automatic site bootstrap with no API key required for first use.
- Registration, login, topic, reply, edit, and supported profile checks.
- Immediate enforcement of `BLOCK` decisions through native Flarum errors.
- Regional endpoint selection, failover, timeout, and fail-open controls.
- Native Flarum Approval and moderation synchronization.
- Attack mode, connection test, usage status, portal access, and manual sync controls in Flarum Admin.
- Scheduled heartbeat and moderation synchronization through Flarum's scheduler.

## Requirements

- Flarum `1.8.x` or `2.x`.
- PHP 8.0 or newer (Flarum 2 itself requires the newer PHP version supported by that release).
- Outbound HTTPS access to Forum Fortress services.

The package includes separate Flarum 1 and Flarum 2 admin bundles generated from
the same TypeScript source. The appropriate bundle is selected automatically.

## Install

From the Flarum root:

```bash
composer require forumfortress/flarum:"^1.1"
php flarum extension:enable forumfortress-flarum
php flarum cache:clear
```

The supported Flarum and Guzzle packages are already present in standard Flarum
1.8 and 2 installations. This scoped command installs Forum Fortress without
updating their locked versions. Do not add Composer's `-W` or
`--with-all-dependencies` option.

Open **Administration > Extensions > Forum Fortress**, then select **Refresh** or **Connection test**. The extension bootstraps automatically when it is first used.

For scheduler setup, configuration, updates, removal, and troubleshooting, use the full installation guide.

## Documentation and support

- [Flarum 1.8 and 2 installation and configuration](https://forumfortress.com/docs/install/flarum/)
- [Forum Fortress documentation](https://forumfortress.com/docs/)
- [Support](https://forumfortress.com/#support)
- [Service status](https://status.forumfortress.com/)

## License

Copyright (c) 2026 Forum Fortress. This extension is proprietary software; see [LICENSE](LICENSE).
