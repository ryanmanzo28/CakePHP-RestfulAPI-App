<?php
// JwtMiddleware moved from api/old-api/middleware

<?php
namespace Api\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Firebase\JWT\JWT;

/**
 * JwtMiddleware
 *
 * Responsibilities:
 * - extract a Bearer token from the `Authorization` header
 * - validate/decode the token using the configured secret
 * - attach the decoded payload to the request as the `jwt` attribute
 *
 * This is intentionally a minimal, framework-agnostic skeleton. Adjust
 * response construction to match your app (CakePHP Response, Diactoros, etc.).
 */
class JwtMiddleware
{
	protected string $secret;

	public function __construct(?string $secret = null)
	{
		$this->secret = $secret ?: (getenv('JWT_SECRET') ?: 'change_me');
	}

	/**
	 * Extracts a Bearer token from the Authorization header.
	 */
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

	/**
	 * Decode and return the token payload. Throws on invalid/expired token.
	 *
	 * @return object Decoded JWT payload
	 */
	public function validateToken(string $token)
	{
		return JWT::decode($token, $this->secret, ['HS256']);
	}

	/**
	 * PSR-15 compatible middleware entrypoint.
	 */
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
		// Prefer CakePHP Response if available
		if (class_exists('Cake\\Http\\Response')) {
			$resp = new \Cake\Http\Response();
			return $resp->withStatus(401)
				->withType('application/json')
				->withStringBody(json_encode(['error' => 'Unauthorized']));
		}

		// Fallback to throwing — calling code should map exceptions to responses.
		throw new \RuntimeException('Unauthorized', 401);
	}
}

namespace app\controller\api;
use firebase\JWT\JWT;