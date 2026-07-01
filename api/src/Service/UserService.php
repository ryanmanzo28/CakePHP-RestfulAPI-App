<?php
namespace App\Service;

use Cake\ORM\TableRegistry;

class UserService
{
    // Minimal service that uses Cake's TableLocator to fetch user data.
    public function getUserById($id)
    {
        $users = TableRegistry::getTableLocator()->get('Users');
        $user = $users->find()->where(['id' => $id])->first();
        return $user ?: null;
    }

    public function getUser(string $username)
    {
        $users = TableRegistry::getTableLocator()->get('Users');
        return $users->find()->where(['username' => $username])->first();
    }
    public function getUserByUsername(string $username)
    {
        $users = TableRegistry::getTableLocator()->get('Users');
        return $users->find()->where(['username' => $username])->first();
    }
    public function index()
    {
        $users = TableRegistry::getTableLocator()->get('');
        $users = TableRegistry::getTableLocator()->get('Users');
        return $users->find()->all()->toArray();
    }
}
