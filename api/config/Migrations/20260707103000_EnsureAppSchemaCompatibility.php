<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class EnsureAppSchemaCompatibility extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('users')) {
            $users = $this->table('users');

            if (!$users->hasColumn('username')) {
                $users->addColumn('username', 'string', ['limit' => 100, 'null' => true]);
            }
            if (!$users->hasColumn('email')) {
                $users->addColumn('email', 'string', ['limit' => 255, 'null' => false]);
            }
            if (!$users->hasColumn('password')) {
                $users->addColumn('password', 'string', ['limit' => 255, 'null' => false]);
            }
            if (!$users->hasColumn('created')) {
                $users->addColumn('created', 'datetime', ['null' => true]);
            }
            if (!$users->hasColumn('modified')) {
                $users->addColumn('modified', 'datetime', ['null' => true]);
            }
            if (!$users->hasIndex(['email'])) {
                $users->addIndex(['email'], ['unique' => true, 'name' => 'users_email']);
            }

            $users->update();
        }

        if ($this->hasTable('workouts')) {
            $workouts = $this->table('workouts');

            if (!$workouts->hasColumn('user_id')) {
                $workouts->addColumn('user_id', 'integer', ['null' => false]);
            }
            if (!$workouts->hasColumn('title')) {
                $workouts->addColumn('title', 'string', ['limit' => 255, 'null' => false, 'default' => 'Workout']);
            }
            if (!$workouts->hasColumn('account_hash')) {
                $workouts->addColumn('account_hash', 'string', ['limit' => 128, 'null' => true]);
            }
            if (!$workouts->hasColumn('data')) {
                $workouts->addColumn('data', 'json', ['null' => true]);
            }
            if (!$workouts->hasColumn('duration')) {
                $workouts->addColumn('duration', 'string', ['limit' => 64, 'null' => true]);
            }
            if (!$workouts->hasColumn('notes')) {
                $workouts->addColumn('notes', 'text', ['null' => true]);
            }
            if (!$workouts->hasColumn('date')) {
                $workouts->addColumn('date', 'date', ['null' => true]);
            }
            if (!$workouts->hasColumn('created')) {
                $workouts->addColumn('created', 'datetime', ['null' => true]);
            }
            if (!$workouts->hasColumn('modified')) {
                $workouts->addColumn('modified', 'datetime', ['null' => true]);
            }
            if (!$workouts->hasIndex(['user_id'])) {
                $workouts->addIndex(['user_id'], ['name' => 'workouts_user_id']);
            }
            if (!$workouts->hasIndex(['account_hash'])) {
                $workouts->addIndex(['account_hash'], ['name' => 'workouts_account_hash']);
            }

            $workouts->update();
        }
    }
}
