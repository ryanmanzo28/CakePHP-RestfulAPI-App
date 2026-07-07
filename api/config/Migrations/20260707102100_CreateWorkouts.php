<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class CreateWorkouts extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('workouts')) {
            return;
        }

        $table = $this->table('workouts');
        $table
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('title', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('account_hash', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('data', 'json', ['null' => true])
            ->addColumn('duration', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('date', 'date', ['null' => true])
            ->addTimestamps('created', 'modified')
            ->addIndex(['user_id'], ['name' => 'workouts_user_id'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'NO_ACTION',
                'constraint' => 'workouts_user_fk',
            ])
            ->create();
    }
}
