# Forum Fortress for Flarum 2

Forum Fortress adds cloud-based spam and abuse protection to Flarum 2. It checks supported forum activity with the Forum Fortress service and returns a simple `ALLOW` or `BLOCK` decision before Flarum completes the action.

## Features

- Automatic site bootstrap with no API key required for first use.
- Registration, login, topic, reply, edit, and supported profile checks.
- Immediate enforcement of `BLOCK` decisions through native Flarum errors.
- Regional endpoint selection, failover, timeout, and fail-open controls.
- Native Flarum Approval and moderation synchronization.
- Attack mode, connection test, usage status, portal access, and manual sync controls in Flarum Admin.
- Scheduled heartbeat and moderation synchronization through Flarum's scheduler.

## Requirements

- Flarum `2.0.0-rc.5` or newer within the Flarum 2 series.
- PHP 8.3 or newer.
- Outbound HTTPS access to Forum Fortress services.

## Install

From the Flarum root:

```bash
composer require forumfortress/flarum:"^1.0"
php flarum extension:enable forumfortress-flarum
php flarum cache:clear
```

Open **Administration > Extensions > Forum Fortress**, then select **Refresh** or **Connection test**. The extension bootstraps automatically when it is first used.

For scheduler setup, configuration, updates, removal, and troubleshooting, use the full installation guide.

## Documentation and support

- [Flarum 2 installation and configuration](https://forumfortress.com/docs/install/flarum/)
- [Forum Fortress documentation](https://forumfortress.com/docs/)
- [Support](https://forumfortress.com/#support)
- [Service status](https://status.forumfortress.com/)

## License

Copyright (c) 2026 Forum Fortress. This extension is proprietary software; see [LICENSE](LICENSE).
