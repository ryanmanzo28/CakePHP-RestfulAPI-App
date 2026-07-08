<?php
declare(strict_types=1);
namespace App\Model\Entity;

use Cake\ORM\Entity;

class Workout extends Entity
{
    protected array $_accessible = [
        'id' => false,
        'user_id' => false,
        'title' => true,
        'notes' => true,
        'date' => true,
        'duration' => true,
        'data' => true,
        'created' => true,
        'modified' => true,
    ];
}
