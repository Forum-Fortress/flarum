<?php

use Flarum\Extend;
use ForumFortress\Flarum\Api\AdminController;
use ForumFortress\Flarum\Api\PortalController;
use ForumFortress\Flarum\Console\ModerationSyncCommand;
use ForumFortress\Flarum\Console\SyncCommand;
use ForumFortress\Flarum\Listener;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;

$adminJavaScript = str_starts_with(Flarum\Foundation\Application::VERSION, '1.')
    ? __DIR__.'/js/dist/admin.v1.js'
    : __DIR__.'/js/dist/admin.js';

return [
    (new Extend\Frontend('admin'))
        ->js($adminJavaScript)
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Settings())
        ->default('forumfortress.enabled', '1')
        ->default('forumfortress.api_base_url', 'https://api.ffapi.net')
        ->default('forumfortress.control_base_url', 'https://control.ffapi.net')
        ->default('forumfortress.api_key', '')
        ->default('forumfortress.site_id', '')
        ->default('forumfortress.preferred_endpoint', '')
        ->default('forumfortress.endpoint_state', '{}')
        ->default('forumfortress.dashboard_status', '{}')
        ->default('forumfortress.registration_email', '')
        ->default('forumfortress.block_reject_action', 'reject')
        ->default('forumfortress.timeout', '5')
        ->default('forumfortress.fail_open', '1')
        ->default('forumfortress.send_ham', '1')
        ->default('forumfortress.debug_log', '0'),

    (new Extend\Event())
        ->listen(Flarum\User\Event\Saving::class, Listener\CheckRegistration::class)
        ->listen(Flarum\User\Event\Saving::class, Listener\CheckProfile::class)
        ->listen(Flarum\User\Event\LoggedIn::class, Listener\CheckLogin::class)
        ->listen(Flarum\Post\Event\Saving::class, Listener\CheckPost::class)
        ->listen(Flarum\Post\Event\Hidden::class, Listener\ReportPostHidden::class)
        ->listen(Flarum\Post\Event\Restored::class, Listener\ReportPostRestored::class)
        ->listen(Flarum\Approval\Event\PostWasApproved::class, Listener\ReportPostApproved::class),

    (new Extend\Routes('api'))
        ->get('/forumfortress/status', 'forumfortress.status', AdminController::class)
        ->get('/forumfortress/portal-launch', 'forumfortress.portal.launch', PortalController::class)
        ->post('/forumfortress/register', 'forumfortress.register', AdminController::class)
        ->post('/forumfortress/attack-mode', 'forumfortress.attack.start', AdminController::class)
        ->post('/forumfortress/attack-mode/end', 'forumfortress.attack.end', AdminController::class)
        ->post('/forumfortress/portal', 'forumfortress.portal', AdminController::class)
        ->post('/forumfortress/test', 'forumfortress.test', AdminController::class)
        ->post('/forumfortress/sync', 'forumfortress.sync', AdminController::class),

    (new Extend\Console())
        ->command(SyncCommand::class)
        ->command(ModerationSyncCommand::class)
        ->schedule('forumfortress:sync', function (ScheduleEvent $event): void {
            $event->hourly()->withoutOverlapping();
        })
        ->schedule('forumfortress:moderation-sync', function (ScheduleEvent $event): void {
            $event->everyMinute()->withoutOverlapping();
        }),
];
