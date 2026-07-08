<?php
namespace App\Controller\Api;

use App\Controller\AppController;
use Cake\Validation\Validator;

class WorkoutsController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    public function index()
    {
        // Safety net: if routing resolves POST /api/workouts to index, run create flow.
        if ($this->request->is('post')) {
            return $this->create();
        }

        // Require Authorization header with Bearer token and validate it
        $header = $this->request->getHeaderLine('Authorization');
        $token = null;
        if ($header && preg_match('/Bearer\s+(.*)$/i', $header, $m)) {
            $token = $m[1];
        }
        $token = $token ?: $this->request->getData('token') ?: $this->request->getQuery('token');
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
                ->withStringBody(json_encode(array_map([$this, 'serializeSessionRecord'], $results)));
        } catch (\Throwable $e) {
            // PDO fallback by user id
            try {
                $data = $this->pdoQueryWorkoutsByUserId($userId);
                return $this->response->withType('application/json')
                    ->withStringBody(json_encode(array_map([$this, 'serializeSessionRecord'], $data)));
            } catch (\Throwable $_) {
                return $this->response->withStatus(500)->withType('application/json')
                    ->withStringBody(json_encode(['error' => 'Failed to load workouts']));
            }
        }
    }

    /**
     * Return a single workout owned by the authenticated user.
     * URL: GET /api/workouts/:id
     */
    public function view($id = null)
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
        $token = $token ?: $this->request->getData('token') ?: $this->request->getQuery('token');
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

            $record = $this->serializeSessionRecord($entity);
            $record['isMine'] = ((int)($entity->user_id ?? 0) === $userId);
            $record['canEdit'] = $record['isMine'];

            return $this->response->withType('application/json')
                ->withStringBody(json_encode($record));
        } catch (\Throwable $e) {
            try {
                $host = getenv('DB_HOST') ?: 'localhost';
                $db = getenv('DB_NAME') ?: 'cakephp';
                $user = getenv('DB_USER') ?: 'root';
                $pass = getenv('DB_PASS') ?: '';
                $charset = 'utf8mb4';
                $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
                $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $stmt = $pdo->prepare('SELECT * FROM workouts WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!$row) {
                    return $this->response->withStatus(404)->withType('application/json')
                        ->withStringBody(json_encode(['error' => 'Not found']));
                }

                $record = $this->serializeSessionRecord($row);
                $record['isMine'] = ((int)($row['user_id'] ?? 0) === $userId);
                $record['canEdit'] = $record['isMine'];
                return $this->response->withType('application/json')
                    ->withStringBody(json_encode($record));
            } catch (\Throwable $_) {
                return $this->response->withStatus(500)->withType('application/json')
                    ->withStringBody(json_encode(['error' => 'Failed to load workout']));
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
        $requestedLimit = (int)$this->request->getQuery('limit', 5000);
        $limit = max(1, min($requestedLimit, 10000));

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
                ->limit($limit)
                ->all()
                ->toArray();

            $feed = array_map(function ($w) use ($viewerId) {
                $owner = isset($w->user) ? ($w->user->username ?: $w->user->email) : null;
                if (!$owner) {
                    $owner = isset($w->users__username) && $w->users__username ? (string)$w->users__username : (isset($w->users__email) ? (string)$w->users__email : ('User #' . (string)$w->user_id));
                }
                return array_merge($this->serializeSessionRecord($w), [
                    'owner' => $owner,
                    'isMine' => $viewerId > 0 ? ((int)$w->user_id === $viewerId) : false,
                ]);
            }, $results);

            return $this->response->withType('application/json')
                ->withStringBody(json_encode($feed));
        } catch (\Throwable $e) {
            try {
                $data = $this->pdoQueryCommunityFeed($viewerId, $limit);
                return $this->response->withType('application/json')
                    ->withStringBody(json_encode($data));
            } catch (\Throwable $_) {
                return $this->response->withStatus(500)->withType('application/json')
                    ->withStringBody(json_encode(['error' => 'Failed to load community feed']));
            }
        }
    }

    /**
     * Mark a workout as completed by its owner.
     * URL: POST /api/workouts/:id/complete
     */
    public function complete($id = null)
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
        $token = $token ?: $this->request->getData('token') ?: $this->request->getQuery('token');
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

        $payloadData = $this->normalizeSessionInput((array)$this->request->getData());
        $completedAt = gmdate('Y-m-d H:i:s');

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

            $currentData = [];
            if (isset($entity->data) && $entity->data !== null && $entity->data !== '') {
                if (is_array($entity->data)) {
                    $currentData = $entity->data;
                } elseif (is_string($entity->data)) {
                    $decoded = json_decode($entity->data, true);
                    $currentData = is_array($decoded) ? $decoded : [];
                }
            }

            $summary = [];
            if (is_array($payloadData)) {
                if (isset($payloadData['summary']) && is_array($payloadData['summary'])) {
                    $summary = $payloadData['summary'];
                } else {
                    $summary = $payloadData;
                }
            }

            $nextData = array_merge($currentData, [
                'kind' => 'session',
                'completed' => true,
                'completedAt' => $completedAt,
                'completed_at' => $completedAt,
            ]);
            if (!empty($summary)) {
                $nextData['completionSummary'] = $summary;
            }
            $entity->data = json_encode($nextData);

            if ($workouts->save($entity)) {
                return $this->response->withType('application/json')
                    ->withStringBody(json_encode($this->serializeSessionRecord($entity)));
            }
            throw new \RuntimeException('Failed to mark workout complete');
        } catch (\Throwable $e) {
            // PDO fallback update
            try {
                $host = getenv('DB_HOST') ?: 'localhost';
                $db = getenv('DB_NAME') ?: 'cakephp';
                $user = getenv('DB_USER') ?: 'root';
                $pass = getenv('DB_PASS') ?: '';
                $charset = 'utf8mb4';
                $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
                $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

                $fetch = $pdo->prepare('SELECT id, user_id, data FROM workouts WHERE id = :id AND user_id = :uid');
                $fetch->execute(['id' => $id, 'uid' => $userId]);
                $row = $fetch->fetch(\PDO::FETCH_ASSOC);
                if (!$row) {
                    return $this->response->withStatus(404)->withType('application/json')
                        ->withStringBody(json_encode(['error' => 'Not found']));
                }

                $currentData = [];
                if (!empty($row['data'])) {
                    $decoded = json_decode((string)$row['data'], true);
                    $currentData = is_array($decoded) ? $decoded : [];
                }
                $summary = [];
                if (is_array($payloadData)) {
                    if (isset($payloadData['summary']) && is_array($payloadData['summary'])) {
                        $summary = $payloadData['summary'];
                    } else {
                        $summary = $payloadData;
                    }
                }

                $nextData = array_merge($currentData, [
                    'kind' => 'session',
                    'completed' => true,
                    'completedAt' => $completedAt,
                    'completed_at' => $completedAt,
                ]);
                if (!empty($summary)) {
                    $nextData['completionSummary'] = $summary;
                }
                $stmt = $pdo->prepare('UPDATE workouts SET data = :data, modified = NOW() WHERE id = :id AND user_id = :uid');
                $stmt->execute([
                    'data' => json_encode($nextData),
                    'id' => $id,
                    'uid' => $userId,
                ]);

                $fetch2 = $pdo->prepare('SELECT * FROM workouts WHERE id = :id AND user_id = :uid');
                $fetch2->execute(['id' => $id, 'uid' => $userId]);
                $updated = $fetch2->fetch(\PDO::FETCH_ASSOC) ?: ['id' => $id];
                return $this->response->withType('application/json')->withStringBody(json_encode($this->serializeSessionRecord($updated)));
            } catch (\Throwable $_) {
                return $this->response->withStatus(500)->withType('application/json')
                    ->withStringBody(json_encode(['error' => 'Failed to complete workout']));
            }
        }
    }

    /**
     * Create a new workout for the authenticated user.
     * Expects JSON body: { title, date?, duration?, notes? }
     */
    public function create()
    {
        $requestData = $this->readJsonRequestData();
        $header = $this->request->getHeaderLine('Authorization');
        $token = null;
        if ($header && preg_match('/Bearer\s+(.*)$/i', $header, $m)) {
            $token = $m[1];
        }
        $token = $token ?: ($requestData['token'] ?? null) ?: $this->request->getQuery('token');
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

        $data = $this->normalizeSessionInput($requestData);
        $title = isset($data['title']) ? trim((string)$data['title']) : '';
        if (!$title) {
            return $this->response->withStatus(400)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Missing title']));
        }

        // Creating a workout should not automatically make it "completed".
        if (isset($data['data'])) {
            $incomingData = null;
            if (is_array($data['data'])) {
                $incomingData = $data['data'];
            } elseif (is_string($data['data'])) {
                $decodedIncoming = json_decode($data['data'], true);
                $incomingData = is_array($decodedIncoming) ? $decodedIncoming : null;
            }
            if (is_array($incomingData)) {
                unset($incomingData['completed'], $incomingData['completedAt'], $incomingData['completed_at'], $incomingData['completionSummary']);
                $incomingData['kind'] = 'session';
                $incomingData['completed'] = false;
                $data['data'] = $incomingData;
            }
        }

        $validationErrors = $this->validateWorkoutPayload($data, true);
        if ($validationErrors) {
            return $this->response->withStatus(400)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Invalid workout data', 'details' => $validationErrors]));
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
                    ->withStringBody(json_encode($this->serializeSessionRecord($entity)));
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
                return $this->response->withStatus(201)->withType('application/json')->withStringBody(json_encode($this->serializeSessionRecord($result)));
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
        $requestData = $this->readJsonRequestData();
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
        $token = $token ?: ($requestData['token'] ?? null) ?: $this->request->getQuery('token');
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

        $data = $this->normalizeSessionInput($requestData);
        if (empty($data)) {
            return $this->response->withStatus(400)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Missing request body']));
        }
        $validationErrors = $this->validateWorkoutPayload($data, false);
        if ($validationErrors) {
            return $this->response->withStatus(400)->withType('application/json')
                ->withStringBody(json_encode(['error' => 'Invalid workout data', 'details' => $validationErrors]));
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
                $existingData = [];
                if (isset($entity->data) && $entity->data !== null && $entity->data !== '') {
                    if (is_array($entity->data)) {
                        $existingData = $entity->data;
                    } elseif (is_string($entity->data)) {
                        $decodedExisting = json_decode($entity->data, true);
                        $existingData = is_array($decodedExisting) ? $decodedExisting : [];
                    }
                }

                if (is_array($data['data'])) {
                    $incomingData = $data['data'];
                } elseif (is_string($data['data'])) {
                    $decodedIncoming = json_decode($data['data'], true);
                    $incomingData = is_array($decodedIncoming) ? $decodedIncoming : [];
                } else {
                    $incomingData = [];
                }

                $mergedData = array_merge($existingData, $incomingData);
                $mergedData['kind'] = 'session';
                $entity->data = json_encode($mergedData);
            }

            if ($workouts->save($entity)) {
                return $this->response->withType('application/json')
                    ->withStringBody(json_encode($this->serializeSessionRecord($entity)));
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
                    $fetchCurrent = $pdo->prepare('SELECT data FROM workouts WHERE id = :id AND user_id = :uid');
                    $fetchCurrent->execute(['id' => $id, 'uid' => $userId]);
                    $currentRow = $fetchCurrent->fetch(\PDO::FETCH_ASSOC) ?: [];

                    $existingData = [];
                    if (!empty($currentRow['data'])) {
                        $decodedExisting = json_decode((string)$currentRow['data'], true);
                        $existingData = is_array($decodedExisting) ? $decodedExisting : [];
                    }

                    if (is_array($data['data'])) {
                        $incomingData = $data['data'];
                    } elseif (is_string($data['data'])) {
                        $decodedIncoming = json_decode($data['data'], true);
                        $incomingData = is_array($decodedIncoming) ? $decodedIncoming : [];
                    } else {
                        $incomingData = [];
                    }

                    $mergedData = array_merge($existingData, $incomingData);
                    $mergedData['kind'] = 'session';
                    $set[] = 'data = :data';
                    $params['data'] = json_encode($mergedData);
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
                return $this->response->withType('application/json')->withStringBody(json_encode($this->serializeSessionRecord($row)));
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

    protected function pdoQueryCommunityFeed(int $viewerId = 0, int $limit = 5000): array
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
            $safeLimit = max(1, min($limit, 10000));
            $sql = "SELECT w.id, w.user_id, w.title, w.notes, w.date, w.duration, w.data, w.created,
                           COALESCE(u.username, u.email, CONCAT('User #', w.user_id)) AS owner
                    FROM workouts w
                    LEFT JOIN users u ON u.id = w.user_id
                    ORDER BY w.created DESC
                    LIMIT :limit";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':limit', $safeLimit, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return array_map(function ($row) use ($viewerId) {
                return array_merge($this->serializeSessionRecord($row), [
                    'owner' => $row['owner'] ?? null,
                    'isMine' => $viewerId > 0 ? ((int)($row['user_id'] ?? 0) === $viewerId) : false,
                ]);
            }, $rows);
        } catch (\PDOException $e) {
            return [];
        }
    }

    protected function normalizeSessionInput(array $data): array
    {
        if (array_key_exists('sessionTitle', $data) && !array_key_exists('title', $data)) {
            $data['title'] = $data['sessionTitle'];
        }
        if (array_key_exists('sessionDate', $data) && !array_key_exists('date', $data)) {
            $data['date'] = $data['sessionDate'];
        }
        if (array_key_exists('sessionDuration', $data) && !array_key_exists('duration', $data)) {
            $data['duration'] = $data['sessionDuration'];
        }
        if (array_key_exists('sessionNotes', $data) && !array_key_exists('notes', $data)) {
            $data['notes'] = $data['sessionNotes'];
        }
        if (array_key_exists('sessionData', $data) && !array_key_exists('data', $data)) {
            $data['data'] = $data['sessionData'];
        }

        $dataBlob = [];
        $hasStructuredData = array_key_exists('data', $data) || isset($data['performedAt']);
        if (array_key_exists('data', $data)) {
            if (is_array($data['data'])) {
                $dataBlob = $data['data'];
            } elseif (is_string($data['data'])) {
                $decoded = json_decode($data['data'], true);
                $dataBlob = is_array($decoded) ? $decoded : [];
            }
        }

        if ($hasStructuredData) {
            $dataBlob['kind'] = 'session';
            if (isset($data['performedAt']) && !isset($dataBlob['completedAt'])) {
                $dataBlob['completedAt'] = $data['performedAt'];
                $dataBlob['completed_at'] = $data['performedAt'];
            }
        }

        if ($hasStructuredData) {
            $data['data'] = $dataBlob;
        }

        return $data;
    }

    protected function readJsonRequestData(): array
    {
        $data = (array)$this->request->getData();
        $parsedBody = $this->request->getParsedBody();
        if (is_array($parsedBody) && !empty($parsedBody)) {
            $data = array_merge($parsedBody, $data);
        }

        try {
            $inputDecoded = $this->request->input('json_decode', true);
            if (is_array($inputDecoded) && !empty($inputDecoded)) {
                $data = array_merge($inputDecoded, $data);
            }
        } catch (\Throwable $_) {
        }

        // Prefer raw input decoding for PUT/PATCH compatibility when framework parsing is inconsistent.
        $raw = @file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_merge($decoded, $data);
            }

            parse_str($raw, $urlEncoded);
            if (is_array($urlEncoded) && !empty($urlEncoded)) {
                return array_merge($urlEncoded, $data);
            }
        }

        if (!empty($data)) {
            return $data;
        }

        // Fallback to PSR body stream if available.
        $streamRaw = (string)$this->request->getBody();
        if ($streamRaw !== '') {
            $decodedStream = json_decode($streamRaw, true);
            if (is_array($decodedStream)) {
                return $decodedStream;
            }
        }

        return [];
    }

    protected function serializeSessionRecord($record): array
    {
        $row = is_object($record) ? $record->toArray() : (array)$record;
        $data = $row['data'] ?? null;
        if (is_string($data) && $data !== '') {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        if (!is_array($data)) {
            $data = [];
        }
        $data['kind'] = 'session';
        $row['data'] = $data;

        $completedAt = $data['completedAt'] ?? $data['completed_at'] ?? null;

        $row['kind'] = 'session';
        $row['sessionId'] = $row['id'] ?? null;
        $row['sessionTitle'] = $row['title'] ?? null;
        $row['sessionDate'] = $row['date'] ?? null;
        $row['sessionDuration'] = $row['duration'] ?? null;
        $row['sessionNotes'] = $row['notes'] ?? null;
        $row['performedAt'] = $completedAt;
        $row['completedAt'] = $completedAt;

        return $row;
    }

    protected function isCompletedWorkoutRecord($workout): bool
    {
        $data = null;
        if (is_array($workout)) {
            $data = $workout['data'] ?? null;
        } elseif (is_object($workout)) {
            $data = $workout->data ?? null;
        }

        if (is_array($data)) {
            if (!empty($data['completed']) || !empty($data['completedAt']) || !empty($data['completed_at'])) {
                return true;
            }
            return false;
        }

        if (is_string($data) && $data !== '') {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                if (!empty($decoded['completed']) || !empty($decoded['completedAt']) || !empty($decoded['completed_at'])) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function validateWorkoutPayload($data, bool $isCreate): array
    {
        if (!is_array($data)) {
            return ['request' => ['Invalid request body']];
        }

        $validator = new Validator();
        $validator
            ->requirePresence('title', $isCreate, 'Title is required')
            ->allowEmptyString('title', !$isCreate)
            ->add('title', 'nonEmptyWhenPresent', [
                'rule' => static function ($value, $context) {
                    if (!array_key_exists('title', $context['data'] ?? [])) {
                        return true;
                    }
                    return is_string($value) && trim($value) !== '';
                },
                'message' => 'Title is required',
            ])
            ->add('date', 'validDateWhenPresent', [
                'rule' => static function ($value, $context) {
                    if (!array_key_exists('date', $context['data'] ?? [])) {
                        return true;
                    }
                    if ($value === null || $value === '') {
                        return true;
                    }
                    return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
                },
                'message' => 'Date must be in YYYY-MM-DD format',
            ])
            ->add('notes', 'validNotesWhenPresent', [
                'rule' => static function ($value, $context) {
                    if (!array_key_exists('notes', $context['data'] ?? [])) {
                        return true;
                    }
                    return $value === null || is_string($value);
                },
                'message' => 'Notes must be a string',
            ])
            ->add('duration', 'validDurationWhenPresent', [
                'rule' => static function ($value, $context) {
                    if (!array_key_exists('duration', $context['data'] ?? [])) {
                        return true;
                    }
                    if ($value === null || $value === '') {
                        return true;
                    }
                    if (is_int($value)) {
                        return $value >= 0;
                    }
                    if (!is_string($value)) {
                        return false;
                    }
                    $trimmed = trim($value);
                    return preg_match('/^(\d+|\d+m|\d+h(?:\d+m)?)$/', $trimmed) === 1;
                },
                'message' => 'Duration must be a non-negative minute value like 45, 45m, or 1h15m',
            ])
            ->add('data', 'validDataWhenPresent', [
                'rule' => static function ($value, $context) {
                    if (!array_key_exists('data', $context['data'] ?? [])) {
                        return true;
                    }
                    if ($value === null || $value === '') {
                        return true;
                    }
                    if (is_array($value)) {
                        return true;
                    }
                    if (!is_string($value)) {
                        return false;
                    }
                    json_decode($value, true);
                    return json_last_error() === JSON_ERROR_NONE;
                },
                'message' => 'Data must be a valid JSON object or array',
            ])
            ->add('reps', 'validRepsWhenPresent', [
                'rule' => static function ($value, $context) {
                    if (!array_key_exists('reps', $context['data'] ?? [])) {
                        return true;
                    }
                    if ($value === null || $value === '') {
                        return true;
                    }
                    if (is_int($value)) {
                        return $value >= 0 && $value <= 10000;
                    }
                    if (!is_string($value)) {
                        return false;
                    }
                    $trimmed = trim($value);
                    return preg_match('/^\d+$/', $trimmed) === 1 && (int)$trimmed <= 10000;
                },
                'message' => 'Reps must be a non-negative integer',
            ]);
            

        return $validator->validate($data);
    }
}
