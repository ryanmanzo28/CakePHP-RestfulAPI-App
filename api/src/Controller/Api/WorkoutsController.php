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

    /**
     * Create a new workout for the authenticated user.
     * Expects JSON body: { title, date?, duration?, notes? }
     */
    public function create()
    {
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

            $data = $this->request->getData();
            $title = isset($data['title']) ? trim((string)$data['title']) : '';
            if (!$title) {
                return $this->response->withStatus(400)->withType('application/json')
                    ->withStringBody(json_encode(['error' => 'Missing title']));
            }

            // Use Cake ORM when available
            $workouts = $this->fetchTable('Workouts');
            $entity = $workouts->newEntity([]);
            $entity->user_id = $userId;
            $entity->title = $title;
            $entity->notes = isset($data['notes']) ? (string)$data['notes'] : null;
            $entity->date = isset($data['date']) && $data['date'] ? $data['date'] : null;
            $entity->duration = isset($data['duration']) ? (string)$data['duration'] : null;
            // persist structured payload if provided (store as JSON)
            if (isset($data['data'])) {
                // ensure JSON encoding
                try {
                    $entity->data = is_string($data['data']) ? $data['data'] : json_encode($data['data']);
                } catch (\Throwable $_) {
                    $entity->data = null;
                }
            }

            if ($workouts->save($entity)) {
                return $this->response->withStatus(201)->withType('application/json')
                    ->withStringBody(json_encode($entity));
            }

            // Fallback to PDO insert if save failed
            throw new \RuntimeException('Failed to save workout');
        } catch (\Throwable $e) {
            // Try PDO fallback insert
            try {
                $host = getenv('DB_HOST') ?: 'localhost';
                $db = getenv('DB_NAME') ?: 'cakephp';
                $user = getenv('DB_USER') ?: 'root';
                $pass = getenv('DB_PASS') ?: '';
                $charset = 'utf8mb4';
                $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
                $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];
                $pdo = new \PDO($dsn, $user, $pass, $options);
                // adaptive insert: detect which columns exist and insert only those
                $colsStmt = $pdo->query('SHOW COLUMNS FROM workouts');
                $cols = $colsStmt->fetchAll(\PDO::FETCH_COLUMN, 0);
                $available = array_flip($cols ?: []);
                $fields = [];
                $placeholders = [];
                $params = [];
                // helper to add field if available
                $add = function($field, $paramVal) use (&$available, &$fields, &$placeholders, &$params) {
                    if (isset($available[$field])) {
                        $fields[] = "`$field`";
                        $placeholders[] = ":$field";
                        $params[$field] = $paramVal;
                    }
                };
                $add('user_id', $userId ?? 0);
                $add('title', $title ?? '');
                $add('notes', $data['notes'] ?? null);
                $add('date', $data['date'] ?? null);
                $add('duration', $data['duration'] ?? null);
                if (isset($data['data'])) $add('data', is_string($data['data']) ? $data['data'] : json_encode($data['data']));
                // always set created/modified if present
                if (isset($available['created'])) { $fields[]='created'; $placeholders[]='NOW()'; }
                if (isset($available['modified'])) { $fields[]='modified'; $placeholders[]='NOW()'; }
                $sql = 'INSERT INTO workouts ('.implode(',', $fields).') VALUES ('.implode(',', $placeholders).')';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $id = (int)$pdo->lastInsertId();
                $result = ['id' => $id, 'user_id' => $userId, 'title' => $title, 'notes' => $data['notes'] ?? null, 'date' => $data['date'] ?? null, 'duration' => $data['duration'] ?? null];
                if (isset($data['data'])) {
                    $result['data'] = is_string($data['data']) ? $data['data'] : json_encode($data['data']);
                }
                return $this->response->withStatus(201)->withType('application/json')->withStringBody(json_encode($result));
            } catch (\Throwable $_) {
                return $this->response->withStatus(500)->withType('application/json')->withStringBody(json_encode(['error' => $e->getMessage()]));
            }
        }
    }

    /**
     * Delete a workout owned by the authenticated user.
     * URL: DELETE /api/workouts/:id
     */
    public function delete($id = null)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return $this->response->withStatus(400)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Invalid id']));
        }

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

            $workouts = $this->fetchTable('Workouts');
            try {
                $entity = $workouts->get($id);
            } catch (\Throwable $e) {
                return $this->response->withStatus(404)->withType('application/json')
                    ->withStringBody(json_encode(['error' => 'Not found']));
            }

            if ((int)($entity->user_id ?? 0) !== $userId) {
                return $this->response->withStatus(403)->withType('application/json')
                    ->withStringBody(json_encode(['error' => 'Forbidden']));
            }

            if ($workouts->delete($entity)) {
                return $this->response->withType('application/json')->withStringBody(json_encode(['deleted' => true]));
            }

            throw new \RuntimeException('Failed to delete');
        } catch (\Throwable $e) {
            // Fallback to PDO delete
            try {
                $host = getenv('DB_HOST') ?: 'localhost';
                $db = getenv('DB_NAME') ?: 'cakephp';
                $user = getenv('DB_USER') ?: 'root';
                $pass = getenv('DB_PASS') ?: '';
                $charset = 'utf8mb4';
                $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
                $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $stmt = $pdo->prepare('DELETE FROM workouts WHERE id = :id AND user_id = :uid');
                $stmt->execute(['id' => $id, 'uid' => $userId ?? 0]);
                $count = $stmt->rowCount();
                if ($count) {
                    return $this->response->withType('application/json')->withStringBody(json_encode(['deleted' => true]));
                }
                return $this->response->withStatus(404)->withType('application/json')->withStringBody(json_encode(['error' => 'Not found']));
            } catch (\Throwable $_) {
                return $this->response->withStatus(500)->withType('application/json')->withStringBody(json_encode(['error' => $e->getMessage()]));
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
