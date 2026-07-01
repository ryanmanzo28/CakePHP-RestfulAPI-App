<?php
namespace App\Service;

use Cake\ORM\TableRegistry;

class WorkoutService
{
    // Minimal service that uses Cake's TableLocator to fetch workout data.
    public function findByAccountHash(string $hash): array
    {
        $workouts = TableRegistry::getTableLocator()->get('Workouts');
        $results = $workouts->find()->where(['account_hash' => $hash])->all()->toArray();
        return $results;
    }
}
