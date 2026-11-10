<?php

declare(strict_types=1);

namespace Mailcrab\Http;

use Crab\Http\HttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Security implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/api/') && !in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $origin = $request->getHeaderLine('Origin');
            if ($origin !== '' && $origin !== rtrim((string) config('app.url'), '/')) {
                throw new HttpException(403, 'Cross-origin requests are not allowed.');
            }
            if (!str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
                throw new HttpException(415, 'Send application/json.');
            }
        }
        $response = $handler->handle($request);
        if (!$response->hasHeader('Content-Security-Policy')) {
            $response = $response->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-src 'self' about:; frame-ancestors 'self'; form-action 'self'; base-uri 'none'; object-src 'none'",
            );
        }
        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
