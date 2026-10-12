<?php

namespace ForumFortress\Flarum\Middleware;

use ForumFortress\Flarum\Moderation\RepeatOffender;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RepeatOffenderEvidence implements MiddlewareInterface
{
    public function __construct(private RepeatOffender $enforcement) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } finally {
            // Runs after rejected validation and rolled-back native transactions.
            // Persistence errors cannot replace the ordinary content response.
            $this->enforcement->flush();
        }
    }
}
