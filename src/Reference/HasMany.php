<?php

declare(strict_types=1);

namespace Atk4\Data\Reference;

use Atk4\Data\Exception;
use Atk4\Data\Field;
use Atk4\Data\Model;
use Atk4\Data\Reference;

class HasMany extends Reference
{
    public function getTheirFieldName(): string
    {
        if ($this->their_field) {
            return $this->their_field;
        }

        // this is pure guess, verify if such field exist, otherwise throw
        // TODO probably remove completely in the future
        $ourModel = $this->getOurModel(null);
        $theirFieldName = $ourModel->table . '_' . $ourModel->id_field;
        if (!$this->createTheirModel()->hasField($theirFieldName)) {
            throw (new Exception('Their model does not contain fallback field'))
                ->addMoreInfo('their_fallback_field', $theirFieldName);
        }

        return $theirFieldName;
    }

    /**
     * Returns our field value or id.
     *
     * @return mixed
     */
    protected function getOurFieldValueForRefCondition(Model $ourModel)
    {
        $ourModel = $this->getOurModel($ourModel);

        if ($ourModel->isEntity() && $ourModel->isLoaded()) {
            return $this->our_field
                ? $ourModel->get($this->our_field)
                : $ourModel->getId();
        }

        // create expression based on existing conditions
        return $ourModel->action('field', [
            $this->getOurFieldName(),
        ]);
    }

    /**
     * Returns our field or id field.
     */
    protected function referenceOurValue(): Field
    {
        // TODO horrible hack to render the field with a table prefix,
        // find a solution how to wrap the field inside custom Field (without owner?)
        $ourModelCloned = clone $this->getOurModel(null);
        $ourModelCloned->persistence_data['use_table_prefixes'] = true;

        return $ourModelCloned->getRef($this->link)->getOurField();
    }

    /**
     * Returns referenced model with condition set.
     */
    public function ref(Model $ourModel, array $defaults = []): Model
    {
        $ourModel = $this->getOurModel($ourModel);

        return $this->createTheirModel($defaults)->addCondition(
            $this->getTheirFieldName(),
            $this->getOurFieldValueForRefCondition($ourModel)
        );
    }

    /**
     * Creates model that can be used for generating sub-query actions.
     */
    public function refLink(?Model $ourModel, array $defaults = []): Model
    {
        $ourModel = $this->getOurModel($ourModel);

        $theirModelLinked = $this->createTheirModel($defaults)->addCondition(
            $this->getTheirFieldName(),
            $this->referenceOurValue()
        );

        return $theirModelLinked;
    }

    /**
     * Adds field as expression to our model.
     * Used in aggregate strategy.
     */
    public function addField(string $fieldName, array $defaults = []): Field
    {
        if (!isset($defaults['aggregate']) && !isset($defaults['concat']) && !isset($defaults['expr'])) {
            throw (new Exception('Aggregate field requires "aggregate", "concat" or "expr" specified to hasMany()->addField()'))
                ->addMoreInfo('field', $fieldName)
                ->addMoreInfo('defaults', $defaults);
        }

        $defaults['aggregate_relation'] = $this;

        $alias = $defaults['field'] ?? null;
        $field = $alias ?? $fieldName;

        if (isset($defaults['concat'])) {
            $defaults['aggregate'] = $this->getOurModel(null)->dsql()->groupConcat($field, $defaults['concat']);
            $defaults['read_only'] = false;
            $defaults['never_save'] = true;
        }

        if (isset($defaults['expr'])) {
            $fx = function () use ($defaults, $alias) {
                $theirModelLinked = $this->refLink(null);

                return $theirModelLinked->action('field', [$theirModelLinked->expr(
                    $defaults['expr'],
                    $defaults['args'] ?? []
                ), 'alias' => $alias]);
            };
            unset($defaults['args']);
        } elseif (is_object($defaults['aggregate'])) {
            $fx = function () use ($defaults, $alias) {
                return $this->refLink(null)->action('field', [$defaults['aggregate'], 'alias' => $alias]);
            };
        } elseif ($defaults['aggregate'] === 'count' && !isset($defaults['field'])) {
            $fx = function () use ($alias) {
                return $this->refLink(null)->action('count', ['alias' => $alias]);
            };
        } elseif (in_array($defaults['aggregate'], ['sum', 'avg', 'min', 'max', 'count'], true)) {
            $fx = function () use ($defaults, $field) {
                return $this->refLink(null)->action('fx0', [$defaults['aggregate'], $field]);
            };
        } else {
            $fx = function () use ($defaults, $field) {
                return $this->refLink(null)->action('fx', [$defaults['aggregate'], $field]);
            };
        }

        return $this->getOurModel(null)->addExpression($fieldName, array_merge([$fx], $defaults));
    }

    /**
     * Adds multiple fields.
     *
     * @see addField()
     *
     * @return $this
     */
    public function addFields(array $fields = [])
    {
        foreach ($fields as $name => $seed) {
            if (is_int($name)) {
                $name = $seed;
                $seed = [];
            }

            $this->addField($name, $seed);
        }

        return $this;
    }
}
