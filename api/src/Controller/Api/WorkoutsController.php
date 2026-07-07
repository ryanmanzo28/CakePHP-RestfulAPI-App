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
        } catch (\Firebase\JWT\ExpiredException $e) {
            return $this->response->withStatus(401)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Token expired']));
        } catch (\Throwable $e) {
            return $this->response->withStatus(401)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Invalid token']));
        }

        try {
            // Return workouts belonging to authenticated user
            $workoutsTable = $this->fetchTable('Workouts');
            $query = $workoutsTable->find()->where(['user_id' => $userId]);
            $results = $query->all()->toArray();
            return $this->response->withType('application/json')
                ->withStringBody(json_encode($results));
        } catch (\Throwable $e) {
            // PDO fallback by user id
            try {
                $data = $this->pdoQueryWorkoutsByUserId($userId);
                return $this->response->withType('application/json')
                    ->withStringBody(json_encode($data));
            } catch (\Throwable $_) {
                return $this->response->withStatus(500)->withType('application/json')
                    ->withStringBody(json_encode(['error' => 'Failed to load workouts']));
            }
        }
    }

    /**
     * Community feed for workouts across users.
     * URL: GET /api/workouts/feed
     */
    public function feed()
    {
        // Authentication middleware validates token and attaches jwt payload.
        $jwt = $this->request->getAttribute('jwt');
        $viewerId = (int)($jwt->sub ?? 0);

        try {
            $workoutsTable = $this->fetchTable('Workouts');
            $results = $workoutsTable->find()
                ->select([
                    'Workouts.id',
                    'Workouts.user_id',
                    'Workouts.title',
                    'Workouts.notes',
                    'Workouts.date',
                    'Workouts.duration',
                    'Workouts.data',
                    'Workouts.created',
                    'Users__username' => 'Users.username',
                    'Users__email' => 'Users.email',
                ])
                ->contain(['Users'])
                ->order(['Workouts.created' => 'DESC'])
                ->limit(100)
                ->all()
                ->toArray();

            $feed = array_map(function ($w) use ($viewerId) {
                $owner = isset($w->user) ? ($w->user->username ?: $w->user->email) : null;
                if (!$owner) {
                    $owner = isset($w->users__username) && $w->users__username ? (string)$w->users__username : (isset($w->users__email) ? (string)$w->users__email : ('User #' . (string)$w->user_id));
                }
                return [
                    'id' => $w->id,
                    'user_id' => $w->user_id,
                    'owner' => $owner,
                    'title' => $w->title,
                    'notes' => $w->notes,
                    'date' => $w->date,
                    'duration' => $w->duration,
                    'data' => $w->data,
                    'created' => $w->created,
                    'isMine' => $viewerId > 0 ? ((int)$w->user_id === $viewerId) : false,
                ];
            }, $results);

            return $this->response->withType('application/json')
                ->withStringBody(json_encode($feed));
        } catch (\Throwable $e) {
            try {
                $data = $this->pdoQueryCommunityFeed($viewerId);
                return $this->response->withType('application/json')
                    ->withStringBody(json_encode($data));
            } catch (\Throwable $_) {
                return $this->response->withStatus(500)->withType('application/json')
                    ->withStringBody(json_encode(['error' => 'Failed to load community feed']));
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
        } catch (\Firebase\JWT\ExpiredException $e) {
            return $this->response->withStatus(401)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Token expired']));
        } catch (\Throwable $e) {
            return $this->response->withStatus(401)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Invalid token']));
        }

        $data = $this->request->getData();
        $title = isset($data['title']) ? trim((string)$data['title']) : '';
        if (!$title) {
            return $this->response->withStatus(400)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Missing title']));
        }

        try {
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
                return $this->response->withStatus(500)->withType('application/json')->withStringBody(json_encode(['error' => 'Failed to create workout']));
            }
        }
    }

    /**
     * Update a workout owned by the authenticated user.
     * URL: PUT /api/workouts/:id
     */
    public function update($id = null)
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
        } catch (\Firebase\JWT\ExpiredException $e) {
            return $this->response->withStatus(401)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Token expired']));
        } catch (\Throwable $e) {
            return $this->response->withStatus(401)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Invalid token']));
        }

        $data = $this->request->getData();

        try {
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

            if (array_key_exists('title', $data)) {
                $title = trim((string)$data['title']);
                if ($title === '') {
                    return $this->response->withStatus(400)->withType('application/json')
                        ->withStringBody(json_encode(['error' => 'Title cannot be empty']));
                }
                $entity->title = $title;
            }
            if (array_key_exists('notes', $data)) {
                $entity->notes = $data['notes'] !== null ? (string)$data['notes'] : null;
            }
            if (array_key_exists('date', $data)) {
                $entity->date = $data['date'] ?: null;
            }
            if (array_key_exists('duration', $data)) {
                $entity->duration = $data['duration'] !== null ? (string)$data['duration'] : null;
            }
            if (array_key_exists('data', $data)) {
                $entity->data = is_string($data['data']) ? $data['data'] : json_encode($data['data']);
            }

            if ($workouts->save($entity)) {
                return $this->response->withType('application/json')
                    ->withStringBody(json_encode($entity));
            }

            throw new \RuntimeException('Failed to save workout update');
        } catch (\Throwable $e) {
            // Fallback to PDO update
            try {
                $host = getenv('DB_HOST') ?: 'localhost';
                $db = getenv('DB_NAME') ?: 'cakephp';
                $user = getenv('DB_USER') ?: 'root';
                $pass = getenv('DB_PASS') ?: '';
                $charset = 'utf8mb4';
                $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
                $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

                // Ensure workout exists and belongs to user
                $check = $pdo->prepare('SELECT id FROM workouts WHERE id = :id AND user_id = :uid');
                $check->execute(['id' => $id, 'uid' => $userId]);
                if (!$check->fetchColumn()) {
                    return $this->response->withStatus(404)->withType('application/json')
                        ->withStringBody(json_encode(['error' => 'Not found']));
                }

                $set = [];
                $params = ['id' => $id, 'uid' => $userId];
                if (array_key_exists('title', $data)) {
                    $title = trim((string)$data['title']);
                    if ($title === '') {
                        return $this->response->withStatus(400)->withType('application/json')
                            ->withStringBody(json_encode(['error' => 'Title cannot be empty']));
                    }
                    $set[] = 'title = :title';
                    $params['title'] = $title;
                }
                if (array_key_exists('notes', $data)) {
                    $set[] = 'notes = :notes';
                    $params['notes'] = $data['notes'] !== null ? (string)$data['notes'] : null;
                }
                if (array_key_exists('date', $data)) {
                    $set[] = '`date` = :date';
                    $params['date'] = $data['date'] ?: null;
                }
                if (array_key_exists('duration', $data)) {
                    $set[] = 'duration = :duration';
                    $params['duration'] = $data['duration'] !== null ? (string)$data['duration'] : null;
                }
                if (array_key_exists('data', $data)) {
                    $set[] = 'data = :data';
                    $params['data'] = is_string($data['data']) ? $data['data'] : json_encode($data['data']);
                }
                if (!$set) {
                    return $this->response->withStatus(400)->withType('application/json')
                        ->withStringBody(json_encode(['error' => 'No fields provided to update']));
                }
                $set[] = 'modified = NOW()';

                $sql = 'UPDATE workouts SET ' . implode(', ', $set) . ' WHERE id = :id AND user_id = :uid';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                $fetch = $pdo->prepare('SELECT * FROM workouts WHERE id = :id AND user_id = :uid');
                $fetch->execute(['id' => $id, 'uid' => $userId]);
                $row = $fetch->fetch(\PDO::FETCH_ASSOC) ?: ['id' => $id];
                return $this->response->withType('application/json')->withStringBody(json_encode($row));
            } catch (\Throwable $_) {
                return $this->response->withStatus(500)->withType('application/json')
                    ->withStringBody(json_encode(['error' => 'Failed to update workout']));
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
        } catch (\Firebase\JWT\ExpiredException $e) {
            return $this->response->withStatus(401)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Token expired']));
        } catch (\Throwable $e) {
            return $this->response->withStatus(401)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Invalid token']));
        }

        try {
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
                return $this->response->withStatus(500)->withType('application/json')->withStringBody(json_encode(['error' => 'Failed to delete workout']));
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
            return [];
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
            return [];
        }
    }

    protected function pdoQueryCommunityFeed(int $viewerId = 0): array
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
            $sql = "SELECT w.id, w.user_id, w.title, w.notes, w.date, w.duration, w.data, w.created,
                           COALESCE(u.username, u.email, CONCAT('User #', w.user_id)) AS owner
                    FROM workouts w
                    LEFT JOIN users u ON u.id = w.user_id
                    ORDER BY w.created DESC
                    LIMIT 100";
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll();
            return array_map(function ($row) use ($viewerId) {
                $row['isMine'] = $viewerId > 0 ? ((int)($row['user_id'] ?? 0) === $viewerId) : false;
                return $row;
            }, $rows);
        } catch (\PDOException $e) {
            return [];
        }
    }
}
