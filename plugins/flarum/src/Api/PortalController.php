<?php

namespace ForumFortress\Flarum\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class PortalController implements RequestHandlerInterface
{
    public function __construct(private ForumFortressClient $client)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        try {
            $result = $this->client->portalLaunch();
            $url = trim((string) ($result['portal_url'] ?? ''));

            if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new \UnexpectedValueException('Forum Fortress did not return a valid portal URL.');
            }

            return new RedirectResponse($url);
        } catch (\Throwable $error) {
            $message = htmlspecialchars($error->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return new HtmlResponse(
                '<!doctype html><html lang="en"><meta charset="utf-8"><title>Forum Fortress Portal</title>'
                . '<body><h1>Portal launch failed</h1><p>' . $message . '</p></body></html>',
                502
            );
        }
    }
}
