<?php
namespace App\Controller\Api;

use App\Controller\AppController;

class WorkoutsController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    public function index()
    {
        // Require Authorization header with Bearer token and validate it
        $header = $this->request->getHeaderLine('Authorization');
        $token = null;
        if ($header && preg_match('/Bearer\s+(.*)$/i', $header, $m)) {
            $token = $m[1];
        }
        if (!$token) {
            return $this->response->withStatus(401)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Missing or invalid Authorization header']));
        }

        try {
            $secret = getenv('JWT_SECRET') ?: 'change_me';
            $payload = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($secret, 'HS256'));
            $userId = (int)($payload->sub ?? 0);
            if ($userId <= 0) {
                return $this->response->withStatus(401)->withType('application/json')
                    ->withStringBody(json_encode(['error' => 'Invalid token payload']));
            }

            // Return workouts belonging to authenticated user
            $workoutsTable = $this->fetchTable('Workouts');
            $query = $workoutsTable->find()->where(['user_id' => $userId]);
            $results = $query->all()->toArray();
            return $this->response->withType('application/json')
                ->withStringBody(json_encode($results));
        } catch (\Firebase\JWT\ExpiredException $e) {
            return $this->response->withStatus(401)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Token expired']));
        } catch (\Throwable $e) {
            // PDO fallback by user id
            try {
                $userId = isset($userId) ? (int)$userId : 0;
                $data = $this->pdoQueryWorkoutsByUserId($userId);
                return $this->response->withType('application/json')
                    ->withStringBody(json_encode($data));
            } catch (\Throwable $_) {
                return $this->response->withStatus(500)->withType('application/json')
                    ->withStringBody(json_encode(['error' => $e->getMessage()]));
            }
        }
    }

    protected function pdoQueryWorkoutsByHash(string $hash): array
    {
        $host = (function_exists('env') ? env('DB_HOST') : getenv('DB_HOST')) ?: 'localhost';
        $db = (function_exists('env') ? env('DB_NAME') : getenv('DB_NAME')) ?: 'cakephp';
        $user = (function_exists('env') ? env('DB_USER') : getenv('DB_USER')) ?: 'root';
        $pass = (function_exists('env') ? env('DB_PASS') : getenv('DB_PASS')) ?: '';
        $charset = 'utf8mb4';
        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
        try {
            $pdo = new \PDO($dsn, $user, $pass, $options);
            $stmt = $pdo->prepare('SELECT * FROM workouts WHERE account_hash = :hash');
            $stmt->execute(['hash' => $hash]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    protected function pdoQueryWorkoutsByUserId(int $userId): array
    {
        $host = (function_exists('env') ? env('DB_HOST') : getenv('DB_HOST')) ?: 'localhost';
        $db = (function_exists('env') ? env('DB_NAME') : getenv('DB_NAME')) ?: 'cakephp';
        $user = (function_exists('env') ? env('DB_USER') : getenv('DB_USER')) ?: 'root';
        $pass = (function_exists('env') ? env('DB_PASS') : getenv('DB_PASS')) ?: '';
        $charset = 'utf8mb4';
        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
        try {
            $pdo = new \PDO($dsn, $user, $pass, $options);
            $stmt = $pdo->prepare('SELECT * FROM workouts WHERE user_id = :uid');
            $stmt->execute(['uid' => $userId]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
