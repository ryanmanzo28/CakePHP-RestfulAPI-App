<?php
namespace App\Controller\Api;

use App\Controller\AppController;
use Cake\Validation\Validator;

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

                // Legacy compatibility: some earlier registrations stored
                // "<client_sha>$<bcrypt(client_sha)>" in the password column.
                if (is_string($user->password) && str_contains($user->password, '$')) {
                    $parts = explode('$', $user->password, 2);
                    $legacyHash = $parts[1] ?? '';
                    if ($legacyHash && password_verify($cand, $legacyHash)) {
                        $authenticated = true;
                        break;
                    }
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
            return $this->response->withStatus(500)->withType('application/json')->withStringBody(json_encode(['error' => 'Internal server error']));
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
            return $this->response->withStatus(401)->withType('application/json')->withStringBody(json_encode(['error' => 'Invalid token']));
        }
    }

    /**
     * Register a new user. Expects JSON body: { username, email, password }
     * where `password` is the client-side SHA256(password+lowercase(email)) hex string.
     */
    public function register()
    {
        $data = $this->request->getData();

        $errors = $this->validateRegistrationData($data);
        if ($errors) {
            return $this->response->withStatus(400)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Invalid registration data', 'details' => $errors]));
        }

        $email = strtolower(trim((string)($data['email'] ?? '')));
        $username = trim((string)($data['username'] ?? ''));
        $passwordHex = strtolower(trim((string)($data['password'] ?? '')));

        try {
            $users = $this->fetchTable('Users');
            $existing = $users->find()->where(['email' => $email])->first();
            if ($existing) {
                return $this->response->withStatus(409)->withType('application/json')->withStringBody(json_encode(['error' => 'Email already registered']));
            }

            // Server stores bcrypt of the client-provided sha256 hex.
            $hashed = password_hash($passwordHex, PASSWORD_DEFAULT);
            if ($hashed === false) {
                throw new \RuntimeException('Failed to hash password');
            }

            $user = $users->newEntity([
                'username' => $username,
                'email' => $email,
                'password' => $hashed,
            ]);

            if ($users->save($user)) {
                return $this->response->withStatus(201)->withType('application/json')->withStringBody(json_encode(['message' => 'User created']));
            }

            return $this->response->withStatus(500)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Failed to save user', 'details' => $user->getErrors()]));
        } catch (\Throwable $e) {
            return $this->response->withStatus(500)->withType('application/json')->withStringBody(json_encode(['error' => 'Internal server error']));
        }
    }

    protected function validateRegistrationData($data): array
    {
        if (!is_array($data)) {
            return ['request' => 'Invalid request body'];
        }

        $normalized = [
            'username' => trim((string)($data['username'] ?? '')),
            'email' => strtolower(trim((string)($data['email'] ?? ''))),
            'password' => strtolower(trim((string)($data['password'] ?? ''))),
        ];

        $validator = new Validator();
        $validator
            ->requirePresence('username', 'create', 'Username is required')
            ->notEmptyString('username', 'Username is required')
            ->add('username', 'format', [
                'rule' => static function ($value) {
                    return is_string($value) && preg_match('/^[A-Za-z0-9_]{3,20}$/', $value) === 1;
                },
                'message' => 'Username must be 3-20 characters and use only letters, numbers, or underscores',
            ])
            ->requirePresence('email', 'create', 'Email is required')
            ->notEmptyString('email', 'Email is required')
            ->email('email', false, 'Email is invalid')
            ->requirePresence('password', 'create', 'Password hash is required')
            ->notEmptyString('password', 'Password hash is required')
            ->add('password', 'sha256hex', [
                'rule' => static function ($value) {
                    return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
                },
                'message' => 'Password must be a SHA-256 hex string',
            ]);

        return $validator->validate($normalized);
    }
}


