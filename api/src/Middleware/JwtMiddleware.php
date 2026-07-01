<?php
namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Laminas\Diactoros\Response\JsonResponse;

class JwtMiddleware implements MiddlewareInterface
{
    protected string $secret;

    public function __construct(?string $secret = null)
    {
        $this->secret = $secret ?: (getenv('JWT_SECRET') ?: 'change_me');
    }

    public function getTokenFromRequest(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if (!$header) {
            return null;
        }
        if (preg_match('/Bearer\s+(.*)$/i', $header, $m)) {
            return $m[1];
        }
        return null;
    }

    public function validateToken(string $token)
    {
        return JWT::decode($token, new Key($this->secret, 'HS256'));
    }

    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->getTokenFromRequest($request);
        if ($token) {
            try {
                $payload = $this->validateToken($token);
                $request = $request->withAttribute('jwt', $payload);
            } catch (\Throwable $e) {
                return $this->unauthorizedResponse();
            }
        }

        return $handler->handle($request);
    }

    // PSR-15 compatibility: delegate to __invoke so Cake accepts this middleware
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this($request, $handler);
    }

    protected function unauthorizedResponse(): ResponseInterface
    {
        return new JsonResponse(['error' => 'Unauthorized'], 401);
    }
}
