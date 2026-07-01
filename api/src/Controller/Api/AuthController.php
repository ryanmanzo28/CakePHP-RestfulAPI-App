<?php
namespace App\Controller\Api;

use App\Controller\AppController;

class AuthController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    public function login()
    {
        // Handle login request

        $email = $this->request->getData('email');
        $password = $this->request->getData('password');

        // normalize email for hashing consistency
        $emailNorm = strtolower(trim((string)$email));

        if (!$email || !$password) {
            $this->response = $this->response->withStatus(400);
            return $this->response->withStringBody(json_encode(['error' => 'Missing credentials']));
        }

        try {
                $users = $this->fetchTable('Users');
                // lookup by normalized (lowercased, trimmed) email to match registration normalization
                $user = $users->find()->where(['email' => $emailNorm])->first();
            if (!$user) {
                return $this->response->withStatus(401)->withStringBody(json_encode(['error' => 'Invalid credentials']));
            }

            // Support multiple login modes:
            // - client sends precomputed SHA256(password+email) (hex) and DB stores bcrypt of that value
            // - client sends raw password and DB stores bcrypt of sha256(password+email)
            // - fallback: DB stores bcrypt(raw password)
            $authenticated = false;
            $isHexSha = (bool)preg_match('/^[0-9a-f]{64}$/i', (string)$password);

            $candidates = [];
            if ($isHexSha) {
                // client already sent the sha256 hex
                $candidates[] = $password;
            } else {
                // compute sha256(password + normalized email)
                $candidates[] = hash('sha256', $password . $emailNorm);
                // also try raw password as fallback
                $candidates[] = $password;
            }

            foreach ($candidates as $cand) {
                if (password_verify($cand, $user->password)) {
                    $authenticated = true;
                    break;
                }
            }

            if (!$authenticated) {
                return $this->response->withStatus(401)->withStringBody(json_encode(['error' => 'Invalid credentials']));
            }

            $payload = [
                'sub' => $user->id,
                'exp' => time() + 3600,
            ];
            $token = \Firebase\JWT\JWT::encode($payload, getenv('JWT_SECRET') ?: 'change_me', 'HS256');

            return $this->response
                ->withType('application/json')
                ->withStringBody(json_encode(['token' => $token]));
        } catch (\Throwable $e) {
            return $this->response->withStatus(500)->withStringBody(json_encode(['error' => $e->getMessage()]));
        }
    }

    /**
     * Check a JWT token and return the decoded payload if valid.
     * Accepts Authorization: Bearer <token> or JSON body { token: '...' } or ?token=...
     */
    public function check()
    {
        $header = $this->request->getHeaderLine('Authorization');
        $token = null;
        if ($header && preg_match('/Bearer\s+(.*)$/i', $header, $m)) {
            $token = $m[1];
        }
        $token = $token ?: $this->request->getData('token') ?: $this->request->getQuery('token');

        if (!$token) {
            return $this->response->withStatus(400)->withType('application/json')->withStringBody(json_encode(['error' => 'Missing token']));
        }

        try {
            $secret = getenv('JWT_SECRET') ?: 'change_me';
            $payload = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
            return $this->response->withType('application/json')->withStringBody(json_encode(['valid' => true, 'payload' => $payload]));
        } catch (\Throwable $e) {
            return $this->response->withStatus(401)->withType('application/json')->withStringBody(json_encode(['error' => $e->getMessage()]));
        }
    }

    /**
     * Register a new user. Expects JSON body: { username, email, password }
     * where `password` is the client-side SHA256(password+lowercase(email)) hex string.
     */
    public function register()
    {
        $data = $this->request->getData();
        $username = isset($data['username']) ? trim((string)$data['username']) : null;
        $email = isset($data['email']) ? trim((string)$data['email']) : null;
        $password = isset($data['password']) ? (string)$data['password'] : null;

        if (!$username || !$email || !$password) {
            return $this->response->withStatus(400)->withType('application/json')->withStringBody(json_encode(['error' => 'Missing fields']));
        }

        try {
            $users = $this->fetchTable('Users');
            $existing = $users->find()->where(['email' => $email])->first();
            if ($existing) {
                return $this->response->withStatus(409)->withType('application/json')->withStringBody(json_encode(['error' => 'Email already registered']));
            }

            // Server stores bcrypt of the client-provided sha hex
            $hashed = password_hash($password, PASSWORD_DEFAULT);

            $user = $users->newEntity([]);
            $user->username = $username;
            $user->email = strtolower($email);
            $user->password = $hashed;

            if ($users->save($user)) {
                return $this->response->withStatus(201)->withType('application/json')->withStringBody(json_encode(['message' => 'User created']));
            }

            return $this->response->withStatus(500)->withType('application/json')->withStringBody(json_encode(['error' => 'Failed to save user']));
        } catch (\Throwable $e) {
            return $this->response->withStatus(500)->withType('application/json')->withStringBody(json_encode(['error' => $e->getMessage()]));
        }
    }
}
