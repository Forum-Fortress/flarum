<?php

namespace ForumFortress\Flarum\Listener;

use Flarum\Foundation\ValidationException;
use Flarum\Settings\Event\Deserializing;
use Flarum\Settings\Event\Saving;
use ForumFortress\Flarum\ClientIp;
use InvalidArgumentException;

final class ProxySettings
{
    public function handle(Deserializing|Saving $event): void
    {
        if ($event instanceof Deserializing) {
            $hint = ClientIp::suggestProxy(
                $_SERVER,
                (string) ($event->settings['forumfortress.forwarded_ip_header'] ?? ClientIp::DEFAULT_HEADER)
            );
            $event->settings['forumfortress.proxy_address_hint'] = $hint['address'] ?? '';
            $event->settings['forumfortress.proxy_header_hint'] = $hint['header'] ?? '';
            return;
        }

        unset($event->settings['forumfortress.proxy_address_hint'], $event->settings['forumfortress.proxy_header_hint']);
        $errors = [];
        if (array_key_exists('forumfortress.trusted_proxies', $event->settings)) {
            $proxies = $event->settings['forumfortress.trusted_proxies'];
            try {
                if (! is_string($proxies)) {
                    throw new InvalidArgumentException('Enter comma-separated proxy IP addresses or CIDR ranges.');
                }
                ClientIp::parseTrustedProxies($proxies);
            } catch (InvalidArgumentException $error) {
                $errors['forumfortress.trusted_proxies'] = $error->getMessage();
            }
        }

        if (array_key_exists('forumfortress.forwarded_ip_header', $event->settings)) {
            $header = $event->settings['forumfortress.forwarded_ip_header'];
            if (! is_string($header) || ! ClientIp::supportsHeader($header)) {
                $errors['forumfortress.forwarded_ip_header'] = 'Choose a supported client IP header.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }
}
