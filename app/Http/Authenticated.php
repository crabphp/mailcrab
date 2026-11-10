<?php

declare(strict_types=1);

namespace Mailcrab\Http;

use Crab\Http\HttpException;
use Mailcrab\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class Authenticated implements MiddlewareInterface
{
    public function __construct(
        private Auth $auth,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $this->auth->session($request);
        if (
            !in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)
            && !hash_equals($session['csrf'], $request->getHeaderLine('X-CSRF-Token'))
        ) {
            throw new HttpException(403, 'Invalid CSRF token.');
        }
        return $handler->handle($request->withAttribute('session', $session));
    }
}
