<?php

namespace ForumFortress\Flarum\Api;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use ForumFortress\Flarum\Moderation\Bridge;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AdminController implements RequestHandlerInterface
{
    public function __construct(
        private ForumFortressClient $client,
        private Bridge $bridge,
        private SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();
        $route = (string) $request->getAttribute('routeName');

        try {
            $data = match ($route) {
                'forumfortress.register' => $this->register($request),
                'forumfortress.attack.start' => $this->attackMode(true),
                'forumfortress.attack.end' => $this->attackMode(false),
                'forumfortress.portal' => $this->client->portalLaunch(),
                'forumfortress.test' => $this->connectionTest(),
                'forumfortress.sync' => $this->synchronize(),
                default => $this->dashboardStatus(),
            };

            return new JsonResponse(['data' => $data]);
        } catch (\Throwable $error) {
            return new JsonResponse(['error' => $error->getMessage()], 502);
        }
    }

    private function dashboardStatus(): array
    {
        $status = $this->client->siteStatus();
        $dashboard = [
            'status' => $status,
            'stats' => $this->client->forumStats(),
            'endpoints' => $this->client->endpointStateSummary(),
        ];

        $this->cacheDashboardStatus($dashboard);

        return $dashboard;
    }

    private function connectionTest(): array
    {
        $result = [
            'health' => $this->client->health(),
            'capabilities' => $this->client->capabilities(),
            'status' => $this->client->siteStatus(),
            'stats' => $this->client->forumStats(),
        ];

        $this->cacheDashboardStatus([
            'status' => $result['status'],
            'stats' => $result['stats'],
            'endpoints' => $this->client->endpointStateSummary(),
        ]);

        return $result;
    }

    private function synchronize(): array
    {
        $site = $this->client->sync();
        $moderation = $this->bridge->sync();
        $dashboard = $this->dashboardStatus();

        return [
            'site' => $site,
            'moderation' => $moderation,
            'dashboard' => $dashboard,
        ];
    }

    private function attackMode(bool $enabled): array
    {
        $status = $this->client->setAttackMode($enabled);
        $cached = json_decode((string) $this->settings->get('forumfortress.dashboard_status', '{}'), true);
        $dashboard = is_array($cached) ? $cached : [];
        $dashboard['status'] = array_merge((array) ($dashboard['status'] ?? []), $status, [
            'attack_mode_active' => (bool) $status['attack_mode_active'],
        ]);
        $dashboard['endpoints'] = $this->client->endpointStateSummary();
        $this->cacheDashboardStatus($dashboard);

        return $status;
    }

    private function cacheDashboardStatus(array $dashboard): void
    {
        $encoded = json_encode($dashboard, JSON_UNESCAPED_SLASHES);

        if (is_string($encoded)) {
            $this->settings->set('forumfortress.dashboard_status', $encoded);
        }
    }

    private function register(ServerRequestInterface $request): array
    {
        $body = (array) $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? $this->settings->get('forumfortress.registration_email', '')));

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid registration email is required.');
        }

        return $this->client->registerSite($email);
    }
}
