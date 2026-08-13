<?php

namespace ForumFortress\Flarum\Api;

use Flarum\Foundation\Application;
use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use GuzzleHttp\Client as HttpClient;
use Psr\Log\LoggerInterface;

final class EndpointRequestException extends \RuntimeException
{
    public function __construct(string $message, public int $statusCode = 0, public bool $retryable = true)
    {
        parent::__construct($message, $statusCode);
    }
}

final class ForumFortressClient
{
    public const PLUGIN_VERSION = '1.1.3';
    private const CATALOG_TTL = 3600;
    private const HEALTH_TTL = 3600;
    private const DEGRADED_HEALTH_TTL = 300;
    private const HEALTH_TOTAL_BUDGET_SECONDS = 5;
    private const CHECK_TOTAL_BUDGET_SECONDS = 5;
    private const CHECK_ENDPOINT_TIMEOUT_SECONDS = 1;
    private const FAILED_ENDPOINT_COOLDOWN = 300;

    private HttpClient $http;

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Config $config,
        private LoggerInterface $logger
    ) {
        $this->http = new HttpClient();
    }

    public function isEnabled(): bool
    {
        return $this->settings->get('forumfortress.enabled', '1') === '1';
    }

    public function check(string $eventType, array $payload): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $this->bootstrapIfNeeded();
            $result = $this->requestChecks('POST', '/v1/check/'.$eventType, array_merge($this->commonPayload(), $payload));
            $this->persistIdentity($result);

            return $result;
        } catch (\Throwable $error) {
            $this->logFailure('check/'.$eventType, $error);
            if ($this->failOpen()) {
                return null;
            }

            throw new UnavailableException('Forum Fortress is temporarily unavailable.', 0, $error);
        }
    }

    public function report(string $reportType, array $payload): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            $this->bootstrapIfNeeded();
            $this->requestChecks('POST', '/v1/report/'.$reportType, array_merge($this->commonPayload(), $payload));
        } catch (\Throwable $error) {
            $this->logFailure('report/'.$reportType, $error);
        }
    }

    public function bootstrapIfNeeded(bool $force = false): ?array
    {
        $apiKey = trim((string) $this->settings->get('forumfortress.api_key', ''));
        $state = $this->endpointState();
        $rebootstrapAt = (int) ($state['rebootstrap_at'] ?? 0);

        if (! $force && $apiKey !== '' && ($rebootstrapAt === 0 || time() < $rebootstrapAt)) {
            return null;
        }

        $payload = $this->commonPayload();
        if ($force && str_starts_with($apiKey, 'ff_ob_')) {
            unset($payload['api_key']);
        }
        $lastError = null;
        $bases = array_values(array_unique(array_merge([$this->controlBaseUrl()], $this->bootstrapCandidates())));
        foreach ($bases as $base) {
            try {
                $result = $this->request('POST', $base.'/v1/site/bootstrap', $payload);
                if (trim((string) ($result['api_key'] ?? '')) === '') {
                    throw new \UnexpectedValueException(
                        'Forum Fortress recognizes this site but did not return an API key. Open its plugin re-registration window, then retry synchronization.'
                    );
                }
                $this->persistIdentity($result);
                $this->recordEndpointResult($base, true, 0);
                return $result;
            } catch (\Throwable $error) {
                $lastError = $error;
                $this->recordEndpointResult($base, false, 0);
            }
        }

        throw $lastError ?: new \RuntimeException('No Forum Fortress bootstrap endpoint is available.');
    }

    public function refreshEndpointCatalog(bool $force = false): array
    {
        $state = $this->endpointState();
        if (! $force && time() - (int) ($state['catalog_fetched_at'] ?? 0) < self::CATALOG_TTL) {
            return $state;
        }

        $catalog = null;
        $fetchBases = array_values(array_unique(array_filter(array_merge(
            [$this->controlBaseUrl(), $this->apiBaseUrl()],
            (array) ($state['catalog'] ?? [])
        ))));
        foreach ($fetchBases as $base) {
            try {
                $catalog = $this->request('GET', $this->normalizeBaseUrl($base).'/v1/node-endpoints', [], 2);
                break;
            } catch (\Throwable $error) {
                $this->logFailure('node-endpoints', $error);
            }
        }
        if (is_array($catalog)) {
            [$endpoints, $meta] = $this->extractEndpointCatalog($catalog);
            if ($endpoints !== []) $state['catalog'] = $endpoints;
            if ($meta !== []) $state['endpoint_meta'] = $meta;
            $state['control_check_fallback'] = ! empty($catalog['control_check_fallback']);
            $state['catalog_fetched_at'] = time();
            unset($state['catalog_refresh_failed_at']);
        } else {
            $state['catalog_refresh_failed_at'] = time();
        }
        $this->saveEndpointState($state);

        return $state;
    }

    public function refreshEndpointHealth(bool $force = false): array
    {
        $state = $this->endpointState();
        $last = (int) ($state['last_health_at'] ?? 0);
        $ttl = ! empty($state['slow_health_mode']) ? self::DEGRADED_HEALTH_TTL : self::HEALTH_TTL;
        if (! $force && $last > 0 && time() - $last < $ttl) return $state;

        $meta = (array) ($state['endpoint_meta'] ?? []);
        $candidates = array_values((array) ($state['catalog'] ?? []));
        usort($candidates, fn (string $a, string $b): int =>
            (int) (($state['health'][$a]['latency_ms'] ?? 999999)) <=> (int) (($state['health'][$b]['latency_ms'] ?? 999999))
        );
        $health = [];
        $started = microtime(true);
        foreach ($candidates as $base) {
            $base = $this->normalizeBaseUrl($base);
            $role = strtolower((string) ($meta[$base]['role'] ?? ''));
            if ($base === '' || in_array($role, ['backup', 'control', 'control-fallback'], true)) continue;
            if ((microtime(true) - $started) >= self::HEALTH_TOTAL_BUDGET_SECONDS) break;
            $t0 = microtime(true);
            try {
                $this->request('GET', $base.'/health', [], self::CHECK_ENDPOINT_TIMEOUT_SECONDS);
                $this->request('GET', $base.'/v1/check-ready', [], self::CHECK_ENDPOINT_TIMEOUT_SECONDS);
                $health[$base] = ['latency_ms' => max(1, (int) round((microtime(true) - $t0) * 1000)), 'last_success_at' => time()];
                $meta[$base]['check_ready'] = true;
            } catch (\Throwable $error) {
                $health[$base] = ['latency_ms' => null, 'last_failure_at' => time()];
                $meta[$base]['check_ready'] = false;
            }
        }
        $healthy = array_filter($health, static fn (array $row): bool => is_int($row['latency_ms'] ?? null));
        uasort($healthy, static fn (array $a, array $b): int => $a['latency_ms'] <=> $b['latency_ms']);
        $best = (string) (array_key_first($healthy) ?? '');
        $current = $this->normalizeBaseUrl((string) $this->settings->get('forumfortress.preferred_endpoint', ''));
        if ($best !== '' && $current !== '' && $best !== $current && isset($healthy[$current])) {
            $candidate = (string) ($state['preferred_candidate'] ?? '');
            $streak = $candidate === $best ? (int) ($state['preferred_candidate_streak'] ?? 0) + 1 : 1;
            $state['preferred_candidate'] = $best;
            $state['preferred_candidate_streak'] = $streak;
            if ($streak < 2) $best = $current;
        } else {
            unset($state['preferred_candidate'], $state['preferred_candidate_streak']);
        }
        if ($best !== '') $this->settings->set('forumfortress.preferred_endpoint', $best);
        $bestLatency = (int) ($healthy[$best]['latency_ms'] ?? 0);
        $state['health'] = $health;
        $state['endpoint_meta'] = $meta;
        $state['last_health_at'] = time();
        $state['best_latency_ms'] = $bestLatency;
        $state['slow_health_mode'] = $bestLatency === 0 || $bestLatency > 100;
        $this->saveEndpointState($state);
        return $state;
    }

    public function siteStatus(): array
    {
        $this->bootstrapIfNeeded();
        return $this->request('GET', $this->controlBaseUrl().'/v1/site/status', [
            'api_key' => $this->apiKey(),
            'domain' => $this->domain(),
        ]);
    }

    public function forumStats(): array
    {
        $this->bootstrapIfNeeded();
        return $this->request('GET', $this->controlBaseUrl().'/v1/forum/stats', [
            'api_key' => $this->apiKey(),
            'domain' => $this->domain(),
        ]);
    }

    public function capabilities(): array
    {
        return $this->request('GET', $this->controlBaseUrl().'/v1/capabilities');
    }

    public function health(): array
    {
        return $this->request('GET', $this->controlBaseUrl().'/health');
    }

    public function registerSite(string $email): array
    {
        $this->bootstrapIfNeeded();
        $result = $this->request('POST', $this->controlBaseUrl().'/v1/site/register', array_merge($this->commonPayload(), [
            'email' => trim($email),
        ]));
        $this->persistIdentity($result);
        return $result;
    }

    public function setAttackMode(bool $enabled): array
    {
        $this->bootstrapIfNeeded();
        $path = $enabled ? '/v1/site/attack-mode' : '/v1/site/attack-mode/end';
        $response = $this->request('POST', $this->controlBaseUrl().$path, $this->commonPayload());
        $active = null;
        if (array_key_exists('attack_mode_active', $response)) {
            $active = (bool) $response['attack_mode_active'];
        } elseif (array_key_exists('enabled', $response)) {
            $active = (bool) $response['enabled'];
        } elseif (is_array($response['attack_mode'] ?? null) && array_key_exists('enabled', $response['attack_mode'])) {
            $active = (bool) $response['attack_mode']['enabled'];
        }
        if ($active === null || $active !== $enabled) {
            throw new \RuntimeException(
                $enabled
                    ? 'Forum Fortress did not confirm that attack mode is active.'
                    : 'Forum Fortress did not confirm that attack mode has ended.'
            );
        }

        $response['attack_mode_active'] = $active;
        return $response;
    }

    public function portalLaunch(): array
    {
        $this->bootstrapIfNeeded();
        return $this->request('POST', $this->controlBaseUrl().'/v1/site/portal', $this->commonPayload());
    }

    public function moderationQueueSync(array $items): array
    {
        $this->bootstrapIfNeeded();
        return $this->requestChecks('POST', '/v1/moderation-queue/sync', array_merge($this->commonPayload(), [
            'items' => $items,
            'block_reject_action' => $this->settings->get('forumfortress.block_reject_action', 'reject'),
        ]));
    }

    public function pullModerationActions(int $limit = 50): array
    {
        $this->bootstrapIfNeeded();
        return $this->requestChecks('POST', '/v1/moderation-actions/pull', array_merge($this->commonPayload(), [
            'limit' => max(1, min(100, $limit)),
        ]));
    }

    public function acknowledgeModerationActions(array $results): array
    {
        $this->bootstrapIfNeeded();
        return $this->requestChecks('POST', '/v1/moderation-actions/ack', array_merge($this->commonPayload(), [
            'results' => $results,
        ]));
    }

    public function sync(): array
    {
        if (! $this->isEnabled()) {
            return ['enabled' => false];
        }

        $this->refreshEndpointCatalog();
        $this->refreshEndpointHealth();
        $this->bootstrapIfNeeded();
        $ping = $this->request('POST', $this->controlBaseUrl().'/v1/site/ping', $this->commonPayload());
        $this->persistIdentity($ping);

        return ['enabled' => true, 'ping' => $ping, 'endpoint_state' => $this->endpointStateSummary()];
    }

    public function endpointStateSummary(): array
    {
        $state = $this->endpointState();
        return [
            'preferred' => (string) $this->settings->get('forumfortress.preferred_endpoint', ''),
            'catalog' => array_values((array) ($state['catalog'] ?? [])),
            'catalog_fetched_at' => (int) ($state['catalog_fetched_at'] ?? 0),
            'health' => (array) ($state['health'] ?? []),
            'last_health_at' => (int) ($state['last_health_at'] ?? 0),
            'key_type' => (string) ($state['key_type'] ?? 'normal'),
            'rebootstrap_at' => (int) ($state['rebootstrap_at'] ?? 0),
        ];
    }

    public function userPayload(User $user, array $extra = []): array
    {
        $joinedAt = $user->joined_at;
        return array_merge([
            'username' => (string) $user->username,
            'email' => (string) $user->email,
            'account_age_seconds' => $joinedAt ? max(0, time() - $joinedAt->getTimestamp()) : 0,
            'post_count' => (int) $user->comment_count,
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ], $extra);
    }

    public static function extractExternalLinks(string $content, string $forumDomain): array
    {
        preg_match_all('~https?://[^\\s<>\"\']+~i', $content, $matches);
        $links = [];
        foreach ($matches[0] ?? [] as $rawUrl) {
            $url = rtrim($rawUrl, '.,;:!?)]}');
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== '' && $host !== $forumDomain && ! str_ends_with($host, '.'.$forumDomain)) {
                $links[] = $url;
            }
        }
        return array_values(array_unique($links));
    }

    public function domain(): string
    {
        return strtolower((string) parse_url((string) $this->config['url'], PHP_URL_HOST));
    }

    private function requestChecks(string $method, string $path, array $payload): array
    {
        $lastError = null;
        $started = microtime(true);

        foreach ($this->checkCandidates() as $base) {
            if ((microtime(true) - $started) >= self::CHECK_TOTAL_BUDGET_SECONDS) break;
            try {
                $attemptStarted = microtime(true);
                $result = $this->request($method, $base.$path, $payload, self::CHECK_ENDPOINT_TIMEOUT_SECONDS);
                $this->recordEndpointResult($base, true, (int) ((microtime(true) - $attemptStarted) * 1000));
                $this->settings->set('forumfortress.preferred_endpoint', $base);
                return $result;
            } catch (\Throwable $error) {
                $lastError = $error;
                $this->recordEndpointResult($base, false, 0);

                if (str_contains(strtolower($error->getMessage()), 'node_mismatch')) {
                    $this->settings->set('forumfortress.api_key', '');
                    $this->settings->set('forumfortress.site_id', '');
                    $this->bootstrapIfNeeded(true);
                    $payload = array_merge($payload, $this->commonPayload());
                }
                if ($error instanceof EndpointRequestException && ! $error->retryable) throw $error;
            }
        }

        throw $lastError ?: new \RuntimeException('No healthy Forum Fortress check endpoint is available.');
    }

    private function request(string $method, string $url, array $payload = [], ?int $timeoutOverride = null): array
    {
        $timeout = max(1, min(30, $timeoutOverride ?? $this->timeout()));
        $options = [
            'connect_timeout' => min(2, $timeout),
            'timeout' => $timeout,
            'http_errors' => false,
            'headers' => ['Accept' => 'application/json', 'User-Agent' => 'ForumFortress-Flarum/'.self::PLUGIN_VERSION],
        ];
        if ($payload !== []) {
            $options[strtoupper($method) === 'GET' ? 'query' : 'json'] = $payload;
        }

        $response = $this->http->request($method, $url, $options);
        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        if ($status < 200 || $status >= 300 || ! is_array($decoded)) {
            $detail = is_array($decoded) ? json_encode($decoded['detail'] ?? $decoded) : '';
            $retryable = ($status >= 200 && $status < 300)
                || $status === 0
                || in_array($status, [500, 502, 503, 504], true);
            throw new EndpointRequestException(trim('Forum Fortress returned HTTP '.$status.' '.$detail), $status, $retryable);
        }
        return $decoded;
    }

    private function commonPayload(): array
    {
        return array_filter([
            'api_key' => $this->apiKey(),
            'site_id' => (string) $this->settings->get('forumfortress.site_id', ''),
            'domain' => $this->domain(),
            'platform' => 'flarum',
            'platform_version' => Application::VERSION,
            'plugin_version' => self::PLUGIN_VERSION,
        ], static fn ($value) => $value !== '');
    }

    private function persistIdentity(array $result): void
    {
        foreach (['api_key', 'site_id', 'preferred_endpoint'] as $key) {
            $value = trim((string) ($result[$key] ?? ''));
            if ($value !== '') {
                $this->settings->set('forumfortress.'.$key, $value);
            }
        }

        $state = $this->endpointState();
        $state['key_type'] = (string) ($result['key_type'] ?? $state['key_type'] ?? 'normal');
        if (isset($result['rebootstrap_after_seconds'])) {
            $state['rebootstrap_at'] = time() + max(60, (int) $result['rebootstrap_after_seconds']);
        } elseif (! str_starts_with((string) ($result['api_key'] ?? $this->apiKey()), 'ff_ob_')) {
            $state['rebootstrap_at'] = 0;
        }
        $fallbacks = $result['fallback_bootstrap_endpoints'] ?? [];
        if (is_array($fallbacks) && $fallbacks !== []) {
            $state['fallback_bootstrap_endpoints'] = array_values(array_filter(array_map([$this, 'normalizeBaseUrl'], $fallbacks)));
        }
        $this->saveEndpointState($state);
    }

    private function checkCandidates(): array
    {
        $state = $this->endpointState();
        $preferred = $this->normalizeBaseUrl((string) $this->settings->get('forumfortress.preferred_endpoint', ''));
        if (str_starts_with($this->apiKey(), 'ff_ob_') && $preferred !== '') {
            return [$preferred];
        }

        $meta = (array) ($state['endpoint_meta'] ?? []);
        $catalog = array_values((array) ($state['catalog'] ?? []));
        $health = (array) ($state['health'] ?? []);
        $now = time();
        $edges = array_values(array_filter(array_map([$this, 'normalizeBaseUrl'], $catalog), static function (string $base) use ($meta, $health): bool {
            $role = strtolower((string) ($meta[$base]['role'] ?? ''));
            if (in_array($role, ['backup', 'control', 'control-fallback'], true)) return false;
            return ($meta[$base]['check_ready'] ?? true) !== false && is_int($health[$base]['latency_ms'] ?? null);
        }));

        usort($edges, static function (string $a, string $b) use ($health, $now): int {
            $aFailed = $now - (int) ($health[$a]['last_failure_at'] ?? 0) < self::FAILED_ENDPOINT_COOLDOWN;
            $bFailed = $now - (int) ($health[$b]['last_failure_at'] ?? 0) < self::FAILED_ENDPOINT_COOLDOWN;
            if ($aFailed !== $bFailed) return $aFailed ? 1 : -1;
            return (int) ($health[$a]['latency_ms'] ?? 999999) <=> (int) ($health[$b]['latency_ms'] ?? 999999);
        });
        $healthyEdges = $edges !== [];
        $controlAllowed = ! empty($state['control_check_fallback']) || ! $healthyEdges;
        $preferredFailed = $preferred !== ''
            && $now - (int) ($health[$preferred]['last_failure_at'] ?? 0) < self::FAILED_ENDPOINT_COOLDOWN;
        return array_values(array_unique(array_filter(array_merge(
            $preferred !== '' && ! $preferredFailed ? [$preferred] : [],
            $edges,
            [$this->apiBaseUrl()],
            $controlAllowed ? [$this->controlBaseUrl()] : []
        ))));
    }

    private function bootstrapCandidates(): array
    {
        $state = $this->endpointState();
        return array_merge((array) ($state['fallback_bootstrap_endpoints'] ?? []), (array) ($state['catalog'] ?? []), [$this->apiBaseUrl()]);
    }

    private function extractEndpointCatalog(mixed $value): array
    {
        $urls = [];
        $meta = [];
        $walk = function (mixed $node, ?string $key = null) use (&$walk, &$urls, &$meta): void {
            if (is_array($node)) {
                $rawUrl = $node['url'] ?? $node['base_url'] ?? $node['endpoint'] ?? null;
                if (is_string($rawUrl)) {
                    $url = $this->normalizeBaseUrl($rawUrl);
                    if ($url !== '') {
                        $urls[] = $url;
                        $meta[$url] = [
                            'role' => strtolower((string) ($node['role'] ?? 'edge')),
                            'node_id' => (string) ($node['node_id'] ?? ''),
                            'check_ready' => (bool) ($node['check_ready'] ?? $node['check_capable'] ?? false),
                        ];
                    }
                }
                foreach ($node as $childKey => $child) $walk($child, (string) $childKey);
            } elseif (is_string($node) && in_array($key, ['base_url', 'endpoint', 'url'], true)) {
                $url = $this->normalizeBaseUrl($node);
                if ($url !== '') $urls[] = $url;
            }
        };
        $walk($value);
        return [array_values(array_unique($urls)), $meta];
    }

    private function recordEndpointResult(string $base, bool $success, int $latencyMs): void
    {
        $base = $this->normalizeBaseUrl($base);
        if ($base === '') return;
        $state = $this->endpointState();
        $health = (array) ($state['health'] ?? []);
        $health[$base][$success ? 'last_success_at' : 'last_failure_at'] = time();
        if ($success) {
            unset($health[$base]['last_failure_at']);
            if ($latencyMs > 0) $health[$base]['latency_ms'] = $latencyMs;
        }
        $state['health'] = $health;
        $this->saveEndpointState($state);
    }

    private function endpointState(): array
    {
        $decoded = json_decode((string) $this->settings->get('forumfortress.endpoint_state', '{}'), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function saveEndpointState(array $state): void
    {
        $this->settings->set('forumfortress.endpoint_state', json_encode($state, JSON_UNESCAPED_SLASHES));
    }

    private function normalizeBaseUrl(mixed $url): string
    {
        $url = rtrim(trim((string) $url), '/');
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    private function apiKey(): string
    {
        return trim((string) $this->settings->get('forumfortress.api_key', ''));
    }

    private function apiBaseUrl(): string
    {
        return rtrim((string) $this->settings->get('forumfortress.api_base_url', 'https://api.ffapi.net'), '/');
    }

    private function controlBaseUrl(): string
    {
        return rtrim((string) $this->settings->get('forumfortress.control_base_url', 'https://control.ffapi.net'), '/');
    }

    private function timeout(): int
    {
        return max(1, min(30, (int) $this->settings->get('forumfortress.timeout', '5')));
    }

    private function failOpen(): bool
    {
        return $this->settings->get('forumfortress.fail_open', '1') === '1';
    }

    private function logFailure(string $operation, \Throwable $error): void
    {
        if ($this->settings->get('forumfortress.debug_log', '0') === '1') {
            $this->logger->warning('Forum Fortress {operation} failed: {message}', [
                'operation' => $operation,
                'message' => $error->getMessage(),
                'exception' => $error,
            ]);
        }
    }
}
