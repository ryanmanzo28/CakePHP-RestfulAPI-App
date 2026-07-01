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
        $hash = $this->request->getQuery('hash');
        if (!$hash) {
            $this->response = $this->response->withStatus(400);
            return $this->response->withStringBody(json_encode(['error' => 'Missing hash parameter']));
        }

        try {
            $workoutsTable = $this->fetchTable('Workouts');
            $query = $workoutsTable->find()->where(['account_hash' => $hash]);
            $results = $query->all()->toArray();
            return $this->response->withType('application/json')
                ->withStringBody(json_encode($results));
        } catch (\Throwable $e) {
            // PDO fallback
            $data = $this->pdoQueryWorkoutsByHash($hash);
            return $this->response->withType('application/json')
                ->withStringBody(json_encode($data));
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
}
