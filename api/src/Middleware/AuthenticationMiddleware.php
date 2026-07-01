<?php
namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Laminas\Diactoros\Response\JsonResponse;

class AuthenticationMiddleware implements MiddlewareInterface
{
    protected JwtMiddleware $jwt;

    public function __construct(?JwtMiddleware $jwt = null)
    {
        $this->jwt = $jwt ?? new JwtMiddleware();
    }

    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Allow unauthenticated access to certain auth routes (login, token check)
        $path = $request->getUri()->getPath();
        $allowed = ['/api/auth/login', '/api/auth/check', '/api/auth/register', '/api/auth/logincheck', '/api/auth/token', '/api/auth/validate'];
        if (in_array($path, $allowed, true)) {
            return $handler->handle($request);
        }

        $token = $this->jwt->getTokenFromRequest($request);
        if (!$token) {
            return $this->unauthorizedResponse();
        }

        try {
            $payload = $this->jwt->validateToken($token);
            $request = $request->withAttribute('jwt', $payload);
            // TODO: resolve user identity and attach as `identity` attribute
        } catch (\Throwable $e) {
            return $this->unauthorizedResponse();
        }

        return $handler->handle($request);
    }

    // PSR-15 compatibility
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this($request, $handler);
    }

    protected function unauthorizedResponse(): ResponseInterface
    {
        return new JsonResponse(['error' => 'Unauthorized'], 401);
    }
}
