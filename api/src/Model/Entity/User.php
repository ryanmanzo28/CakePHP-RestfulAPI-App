<?php
declare(strict_types=1);
namespace App\Model\Entity;

use Cake\ORM\Entity;


class User extends Entity
{
    protected array $_accessible = [
        '*' => true,
        'username' => true,
        'email' => true,
        'password' => true,
        'created' => true,
        'modified' => true,
    ];

    protected array $_hidden = [
        'password',
    ];
}




