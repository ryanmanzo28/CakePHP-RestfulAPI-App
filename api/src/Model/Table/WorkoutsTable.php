<?php
declare(strict_types=1);
namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class WorkoutsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('workouts');
        $this->setDisplayField('title');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
        ]);
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['user_id'], 'Users'));

        return $rules;
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('id')
            ->allowEmptyString('id', null, 'create')
            ->add('id', 'validFormat', [
                'rule' => 'numeric',
                'message' => 'ID must be a numeric value.',
            ])
            ->add('id', 'nonNegative', [
                'rule' => static function ($value, $context): bool {
                    return is_numeric($value) && $value >= 0;
                },
                'message' => 'ID must be a non-negative number.',
            ])
            ->add('id', 'unique', [
                'rule' => 'validateUnique',
                'provider' => 'table',
                'message' => 'ID must be unique.',
            ]);

        $validator
            ->integer('user_id')
            ->requirePresence('user_id', 'create')
            ->notEmptyString('user_id');

        $validator
            ->scalar('title')
            ->maxLength('title', 255)
            ->requirePresence('title', 'create')
            ->notEmptyString('title')
            ->add('title', 'trimmedNotEmpty', [
                'rule' => static function ($value, $context): bool {
                    if (!array_key_exists('title', $context['data'] ?? [])) {
                        return true;
                    }

                    return is_string($value) && trim($value) !== '';
                },
                'message' => 'Title is required.',
            ]);

        $validator
            ->scalar('account_hash')
            ->maxLength('account_hash', 128)
            ->allowEmptyString('account_hash');

        $validator
            ->scalar('duration')
            ->maxLength('duration', 64)
            ->allowEmptyString('duration')
            ->add('duration', 'validDurationFormat', [
                'rule' => static function ($value, $context): bool {
                    if (!array_key_exists('duration', $context['data'] ?? [])) {
                        return true;
                    }

                    if ($value === null || $value === '') {
                        return true;
                    }

                    if (is_int($value)) {
                        return $value >= 0;
                    }

                    if (!is_string($value)) {
                        return false;
                    }

                    return preg_match('/^(\d+|\d+m|\d+h(?:\d+m)?)$/', trim($value)) === 1;
                },
                'message' => 'Duration must be a non-negative minute value like 45, 45m, or 1h15m.',
            ]);

        $validator
            ->scalar('notes')
            ->allowEmptyString('notes')
            ->regex('notes', "/^[A-Z1-10\/!\-_\-{}\]+$/");
                

        $validator
            ->date('date')
            ->allowEmptyDate('date');

        $validator
            ->allowEmptyString('data')
            ->add('data', 'validJsonPayload', [
                'rule' => static function ($value, $context): bool {
                    if (!array_key_exists('data', $context['data'] ?? [])) {
                        return true;
                    }

                    if ($value === null || $value === '') {
                        return true;
                    }

                    if (is_array($value)) {
                        return true;
                    }

                    if (!is_string($value)) {
                        return false;
                    }

                    json_decode($value, true);

                    return json_last_error() === JSON_ERROR_NONE;
                },
                'message' => 'Data must be a valid JSON string or array.',
            ]);

        return $validator;
    }
}
