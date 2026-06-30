<?php
namespace Api\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * AuthenticationMiddleware
 *
 * Small coordinator that uses JwtMiddleware to validate tokens and then
 * (optionally) resolves a user identity and attaches it to the request.
 * Keep this file minimal — move application-specific user lookups into services.
 */
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
            // No token present — proceed as guest or block. Here we block.
            return $this->unauthorizedResponse();
        }

        try {
            $payload = $this->jwt->validateToken($token);
            // Attach decoded JWT payload to the request for downstream handlers
            $request = $request->withAttribute('jwt', $payload);

            // TODO: Optionally resolve a user record using a service e.g. UserService
            // $user = $this->userService->findById($payload->sub);
            // $request = $request->withAttribute('identity', $user);

        } catch (\Throwable $e) {
            return $this->unauthorizedResponse();
        }

        return $handler->handle($request);
    }

    protected function unauthorizedResponse(): ResponseInterface
    {
        if (class_exists('Cake\\Http\\Response')) {
            $resp = new \Cake\Http\Response();
            return $resp->withStatus(401)
                ->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Unauthorized']));
        }

        throw new \RuntimeException('Unauthorized', 401);
    }
}



