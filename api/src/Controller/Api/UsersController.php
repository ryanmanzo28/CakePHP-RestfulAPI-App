<?php
namespace App\Controller\Api;

use App\Controller\AppController;

class UsersController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    public function index()
    {
        try {
            $usersTable = $this->fetchTable('Users');
            $users = $usersTable->find()->all()->toArray();
            return $this->response->withType('application/json')->withStringBody(json_encode($users));
        } catch (\Throwable $e) {
            return $this->response->withStatus(500)->withType('application/json')->withStringBody(json_encode(['error' => 'Internal server error']));
        }
    }
}
