# Forum Fortress for Flarum 1.8.x and 2.x

Forum Fortress adds cloud-based spam and abuse protection to Flarum 1.8.x and 2.x. It checks supported forum activity with the Forum Fortress service and returns a simple `ALLOW` or `BLOCK` decision before Flarum completes the action.

## Features

- Automatic site bootstrap on extension enable, with no API key required.
- Registration, topic, reply, post-edit, and supported profile/signature checks.
- Immediate enforcement of `BLOCK` decisions through native Flarum errors.
- Regional endpoint selection, failover, timeout, and fail-open controls.
- Native Flarum Approval and moderation synchronization.
- Attack mode, connection test, usage status, portal access, and manual sync controls in Flarum Admin.
- Scheduled heartbeat and moderation synchronization through Flarum's scheduler.

## Requirements

- Flarum `1.8.x` or `2.x`.
- PHP 8.0 or newer (Flarum 2 itself requires the newer PHP version supported by that release).
- Outbound HTTPS access to Forum Fortress services.

Flarum Approval and Flags are optional. When both are enabled, Forum Fortress
also synchronizes their moderation queue; their disabled state never blocks the
main protection extension from activating.

The package includes separate Flarum 1 and Flarum 2 admin bundles generated from
the same TypeScript source. The appropriate bundle is selected automatically.

## Tracked activity

Forum Fortress checks only initial registrations, new topics, replies, post
edits, and supported profile changes including signatures. The extension does
not send login events or moderation hide/restore/approve events. Flarum does
not provide a contact-form event in this extension; contact forms remain
outside the plugin's activity tracking.

## Install

Or search in Extension Manager for Forum Fortress. In **Administration >
Extensions**, choose **Find more extensions** or **Install extension**, search
for the official `forumfortress/flarum` package, select **Install**, then
**Enable** it. Open the Forum Fortress administration page and run **Refresh**
and **Connection test** after enabling.

From the Flarum root:

```bash
composer require forumfortress/flarum:"^1.3"
php flarum extension:enable forumfortress-flarum
php flarum cache:clear
```

The supported Flarum and Guzzle packages are already present in standard Flarum
1.8 and 2 installations. This scoped command installs Forum Fortress without
updating their locked versions. Do not add Composer's `-W` or
`--with-all-dependencies` option.

Enabling the extension immediately performs a short, best-effort bootstrap. A
temporary network problem will not prevent Flarum from enabling the extension;
the next protected request, status refresh, or scheduled synchronization retries
automatically. Open **Administration > Extensions > Forum Fortress** to confirm
the live status. Bootstrap retries use a short-lived, client-held recovery token,
so a response lost after the remote site is created cannot strand the install.

## Removal and reinstall

Flarum's native **Purge** action automatically notifies Forum Fortress and
removes the remote forum. Version 1.3 also listens for Extension Manager's
post-Composer removal event while the extension is loaded. If the extension is
already disabled, it cannot register that listener or display its maintenance
panel. Re-enable it first, then open the Forum Fortress maintenance panel and
choose **Disconnect and remove site** before removing the Composer package.
That action pauses automatic bootstrap until the extension is explicitly
re-enabled or reinstalled.

If remote cleanup fails because Forum Fortress is temporarily unreachable,
removal remains non-blocking and the local identity is retained. A later
reinstall retries the pending cleanup before bootstrapping and shows a direct
support warning if it still cannot finish.

When the account contains other forums, only this forum is removed. When it is
the last forum, a paid non-trial account is retained; free, trial, and overdue
accounts are removed. Local credentials are cleared only after successful
deprovisioning, allowing a failed removal or a later reinstall to recover safely.

For scheduler setup, configuration, updates, removal, and troubleshooting, use the full installation guide.

## Documentation and support

- [Flarum 1.8.x and 2.x installation and configuration](https://forumfortress.com/docs/install/flarum/)
- [Forum Fortress documentation](https://forumfortress.com/docs/)
- [Support](https://forumfortress.com/#support)
- [Contact support](https://forumfortress.com/#contact)
- [Service status](https://status.forumfortress.com/)

## License

Copyright (c) 2026 Forum Fortress. This extension is proprietary software; see [LICENSE](LICENSE).
