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
            $this->loadModel('Users');
            $users = $this->Users->find()->all()->toArray();
            return $this->response->withType('application/json')->withStringBody(json_encode($users));
        } catch (\Throwable $e) {
            return $this->response->withStatus(500)->withStringBody(json_encode(['error' => $e->getMessage()]));
        }
    }
}
