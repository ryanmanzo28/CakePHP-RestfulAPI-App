<?php
namespace App\Controller;

use App\Controller\AppController;

class AuthController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    public function login()
    {
        $email = $this->request->getData('email');
        $password = $this->request->getData('password');

        $emailNorm = strtolower(trim((string)$email));

        if (!$email || !$password) {
            $this->response = $this->response->withStatus(400);
            return $this->response->withStringBody(json_encode(['error' => 'Missing credentials']));
        }

        try {
            $users = $this->fetchTable('Users');
            $user = $users->find()->where(['email' => $email])->first();
            if (!$user) {
                return $this->response->withStatus(401)->withStringBody(json_encode(['error' => 'Invalid credentials']));
            }

            $authenticated = false;
            $isHexSha = (bool)preg_match('/^[0-9a-f]{64}$/i', (string)$password);

            $candidates = [];
            if ($isHexSha) {
                $candidates[] = $password;
            } else {
                $candidates[] = hash('sha256', $password . $emailNorm);
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
}
