<?php
namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Firebase\JWT\JWT;

class JwtMiddleware
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
        return JWT::decode($token, $this->secret, ['HS256']);
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

    protected function unauthorizedResponse(): ResponseInterface
    {
        $resp = new \Cake\Http\Response();
        return $resp->withStatus(401)
            ->withType('application/json')
            ->withStringBody(json_encode(['error' => 'Unauthorized']));
    }
}
