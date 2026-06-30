<?php
namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;

class AuthenticationMiddleware
{
    protected JwtMiddleware $jwt;

    public function __construct(?JwtMiddleware $jwt = null)
    {
        $this->jwt = $jwt ?? new JwtMiddleware();
    }

    public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
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

    protected function unauthorizedResponse(): ResponseInterface
    {
        $resp = new \Cake\Http\Response();
        return $resp->withStatus(401)
            ->withType('application/json')
            ->withStringBody(json_encode(['error' => 'Unauthorized']));
    }
}
