<?php
declare(strict_types=1);
namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class UsersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('users');
        $this->setDisplayField('email');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('Workouts', [
            'foreignKey' => 'user_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('id')
            ->allowEmptyString('id', null, 'create')
            ->add('id', 'unique', [
                'rule' => 'validateUnique',
                'provider' => 'table',
                'message' => 'ID must be unique.'
            ]);

        $validator
            ->email('email')
            ->requirePresence('email', 'create')
            ->notEmptyString('email')
            ->add('email', 'unique', [
                'rule' => 'validateUnique',
                'provider' => 'table',
                'message' => 'Email must be unique.'
            ])
            ->add('email', 'format', [
                'rule' => 'email',
                'message' => 'Please provide a valid email address.'
            ]);

        $validator
            ->scalar('password')
            ->requirePresence('password', 'create')
            ->notEmptyString('password')
            ->add(
                'password',
                'minLength',
                [
                    'rule' => ['minLength', 8],
                    'message' => 'Password must be at least 8 characters long.',
                ]
            )
            ->add('password', 'custom', [
                'rule' => function ($value, $context) {
                    return preg_match('/[A-Z]/', $value) && preg_match('/[a-z]/', $value) && preg_match('/[0-9]/', $value);
                },
                'message' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number.',
            ]);

        return $validator;
    }
}
