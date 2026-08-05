# Flarum 2 installation

Forum Fortress provides a Composer extension for Flarum 2. Once enabled, it automatically registers the forum with Forum Fortress and begins checking supported activity.

Forum Fortress returns either `ALLOW` or `BLOCK`. An allowed action continues normally; a blocked action is rejected before Flarum completes it.

## Before you start

Make sure you have:

- Flarum `2.0.0-rc.5` or newer within the Flarum 2 series
- PHP 8.3 or newer
- Composer access in the Flarum installation directory
- Flarum administrator access
- outbound HTTPS access from the forum server

If your host manages Composer for you, ask it to install the package `forumfortress/flarum`.

## Privacy and outbound access

Forum Fortress sends supported forum activity to its analysis service. Checks can include submitted text, links, IP address, email address, username, account details, and basic request metadata.

If your server uses an outbound firewall, allow HTTPS traffic on port **443** to hosts ending in:

`*.ffapi.net`

Read [Privacy and network access](../privacy-network.md) before enabling the extension on a live community.

## Install with Composer

Run these commands from the Flarum root, where `composer.json` and the `flarum` executable are located:

```bash
composer require forumfortress/flarum
php flarum extension:enable forumfortress-flarum
php flarum cache:clear
```

Composer installs Forum Fortress and its required PHP/Flarum dependencies automatically.

You can also enable the extension from **Administration > Extensions** after Composer finishes.

## First connection

1. Open **Administration > Extensions > Forum Fortress**.
2. Leave **Site API key** blank for a new installation. Forum Fortress bootstraps the site automatically.
3. Keep **Enable Forum Fortress protection** enabled.
4. Select **Refresh** to load the current site, plan, endpoint, and usage details.
5. Select **Connection test** and wait for the success message.
6. Optionally select **Portal Login** to open Forum Fortress and connect the forum to your account.

If details initially say **Refresh to view**, select **Refresh**, **Connection test**, or **Synchronize now**. These actions retrieve the latest status from the service.

You do not need a portal account before installation. See [Your account and forums](../registration.md) when you are ready to attach the forum to an account.

## Recommended settings

| Setting | Recommendation |
| --- | --- |
| Enable Forum Fortress protection | Enabled |
| Allow requests when Forum Fortress is unavailable | Enabled for most forums, so a service or network interruption does not lock users out |
| Site registration email | Leave blank unless you want to use **Register Site** from the extension |
| Request timeout | Keep the default unless support asks you to change it |
| Check API, control API, preferred endpoint | Leave automatic values unchanged unless diagnosing a connection issue |
| Site API key | Leave blank on first use; automatic bootstrap stores the issued key |

## Scheduler

Forum Fortress uses Flarum's scheduler for heartbeat, endpoint maintenance, and moderation synchronization. It is strongly recommended (and required for production reliability) to keep Flarum's scheduler running every minute:

```cron
* * * * * cd /path/to/flarum && php flarum schedule:run >> /dev/null 2>&1
```

Replace `/path/to/flarum` with the directory containing the `flarum` executable.

Without this cron, synchronization and moderation queue updates can fall behind.

Manual synchronization is also available:

```bash
php flarum forumfortress:sync
php flarum forumfortress:moderation-sync
```

## What is protected

The Flarum 2 extension checks:

- new registrations
- logins
- new and edited discussions
- new and edited replies
- supported profile changes

Blocked registrations are rejected before the account is stored. Blocked discussions and replies are rejected before the content is published. Supported moderation actions synchronize with Flarum's normal Approval flow and the Forum Fortress portal.

## Updating

From the Flarum root:

```bash
composer update forumfortress/flarum --with-dependencies
php flarum migrate
php flarum cache:clear
```

Open Forum Fortress in Administration and run **Connection test** after an update.

## Disabling or removing

To disable the extension but keep it installed:

```bash
php flarum extension:disable forumfortress-flarum
php flarum cache:clear
```

To remove it:

```bash
composer remove forumfortress/flarum
php flarum cache:clear
```

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| Composer cannot find the package | Confirm the package name is `forumfortress/flarum`, then run `composer clear-cache` and retry. |
| Extension will not enable | Confirm Flarum is 2.0 RC5 or newer and PHP is 8.3 or newer. |
| Connection test fails | Check outbound HTTPS, DNS, firewall, WAF, proxy, and host-level egress rules. |
| Status details do not appear | Select **Refresh** or **Synchronize now**. |
| Portal Login does not open | Allow popups for the Flarum administration page and try again. |
| Legitimate activity is blocked | Review the decision in the portal, then include its decision reference when contacting support. |
| Synchronization is delayed | Confirm Flarum's scheduler runs every minute and try **Synchronize now**. |

For more help, check the [general troubleshooting guide](../troubleshooting.md) or use [Forum Fortress support](https://forumfortress.com/#support). Include your forum URL, Flarum version, extension version, approximate time, and any decision reference or error message.
