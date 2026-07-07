<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class CreateUsers extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('users')) {
            return;
        }

        $table = $this->table('users');
        $table
            ->addColumn('email', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('username', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('password', 'string', ['limit' => 255, 'null' => false])
            ->addTimestamps('created', 'modified')
            ->addIndex(['email'], ['unique' => true, 'name' => 'users_email'])
            ->create();
    }
}
