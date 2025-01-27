<?php

declare(strict_types=1);

namespace Atk4\Data;

use Atk4\Core\DiContainerTrait;
use Atk4\Core\Factory;
use Atk4\Core\InitializerTrait;
use Atk4\Core\TrackableTrait;

/**
 * Reference implements a link between one model and another. The basic components for
 * a reference is ability to generate the destination model, which is returned through
 * getModel() and that's pretty much it.
 *
 * It's possible to extend the basic reference with more meaningful references.
 *
 * @method Model getOwner() our model
 */
class Reference
{
    use DiContainerTrait;
    use InitializerTrait {
        init as private _init;
    }
    use TrackableTrait {
        setOwner as private _setOwner;
    }

    /**
     * Use this alias for related entity by default. This can help you
     * if you create sub-queries or joins to separate this from main
     * table. The table_alias will be uniquely generated.
     *
     * @var string
     */
    protected $table_alias;

    /**
     * What should we pass into owner->ref() to get through to this reference.
     * Each reference has a unique identifier, although it's stored
     * in Model's elements as '#ref-xx'.
     *
     * @var string
     */
    public $link;

    /**
     * Definition of the destination their model, that can be either an object, a
     * callback or a string. This can be defined during initialization and
     * then used inside getModel() to fully populate and associate with
     * persistence.
     *
     * @var Model|\Closure|array
     */
    public $model;

    /**
     * This is an optional property which can be used by your implementation
     * to store field-level relationship based on a common field matching.
     *
     * @var string
     */
    protected $our_field;

    /**
     * This is an optional property which can be used by your implementation
     * to store field-level relationship based on a common field matching.
     *
     * @var string|null
     */
    protected $their_field;

    /**
     * Caption of the referenced model. Can be used in UI components, for example.
     * Should be in plain English and ready for proper localization.
     *
     * @var string|null
     */
    public $caption;

    public function __construct(string $link)
    {
        $this->link = $link;
    }

    /**
     * @param Model $owner
     *
     * @return $this
     */
    public function setOwner(object $owner)
    {
        $owner->assertIsModel();

        return $this->_setOwner($owner);
    }

    protected function getOurFieldName(): string
    {
        return $this->our_field ?: $this->getOurModel(null)->id_field;
    }

    final protected function getOurField(): Field
    {
        return $this->getOurModel(null)->getField($this->getOurFieldName());
    }

    /**
     * @return mixed
     */
    final protected function getOurFieldValue(Model $ourEntity)
    {
        return $this->getOurModel($ourEntity)->get($this->getOurFieldName());
    }

    public function getTheirFieldName(): string
    {
        return $this->their_field ?? $this->model->id_field;
    }

    protected function onHookToOurModel(Model $model, string $spot, \Closure $fx, array $args = [], int $priority = 5): int
    {
        $name = $this->short_name; // use static function to allow this object to be GCed

        return $model->onHookDynamic(
            $spot,
            static function (Model $model) use ($name): self {
                return $model->getModel(true)->getElement($name);
            },
            $fx,
            $args,
            $priority
        );
    }

    protected function onHookToTheirModel(Model $model, string $spot, \Closure $fx, array $args = [], int $priority = 5): int
    {
        if ($model->ownerReference !== null && $model->ownerReference !== $this) {
            throw new Exception('Model owner reference unexpectedly already set');
        }
        $model->ownerReference = $this;
        $getThisFx = static function (Model $model) {
            return $model->ownerReference;
        };

        return $model->onHookDynamic(
            $spot,
            $getThisFx,
            $fx,
            $args,
            $priority
        );
    }

    /**
     * Initialization.
     */
    protected function init(): void
    {
        $this->_init();

        $this->initTableAlias();
    }

    /**
     * Will use #ref_<link>.
     */
    public function getDesiredName(): string
    {
        return '#ref_' . $this->link;
    }

    public function getOurModel(?Model $ourModel): Model
    {
        if ($ourModel === null) {
            $ourModel = $this->getOwner();
        }

        $this->getOwner()->assertIsModel($ourModel->getModel(true));

        return $ourModel;
    }

    /**
     * Create destination model that is linked through this reference. Will apply
     * necessary conditions.
     *
     * IMPORTANT: the returned model must be a fresh clone or freshly built from a seed
     */
    public function createTheirModel(array $defaults = []): Model
    {
        // set table_alias
        $defaults['table_alias'] ??= $this->table_alias;

        // if model is Closure, then call the closure and it should return a model
        if ($this->model instanceof \Closure) {
            $m = ($this->model)($this->getOurModel(null), $this, $defaults);
        } else {
            $m = $this->model;
        }

        if (is_object($m)) {
            $theirModel = Factory::factory(clone $m, $defaults);
        } else {
            // add model from seed
            $modelDefaults = $m;
            $theirModelSeed = [$modelDefaults[0]];
            unset($modelDefaults[0]);
            $defaults = array_merge($modelDefaults, $defaults);

            $theirModel = Factory::factory($theirModelSeed, $defaults);
        }

        $this->addToPersistence($theirModel, $defaults);

        return $theirModel;
    }

    protected function initTableAlias(): void
    {
        if (!$this->table_alias) {
            $ourModel = $this->getOurModel(null);

            $aliasFull = $this->link;
            $alias = preg_replace('~_(' . preg_quote($ourModel->id_field, '~') . '|id)$~', '', $aliasFull);
            $alias = preg_replace('~([0-9a-z]?)[0-9a-z]*[^0-9a-z]*~i', '$1', $alias);
            if ($ourModel->table_alias !== null) {
                $aliasFull = $ourModel->table_alias . '_' . $aliasFull;
                $alias = preg_replace('~^_(.+)_[0-9a-f]{12}$~', '$1', $ourModel->table_alias) . '_' . $alias;
            }
            $this->table_alias = '_' . $alias . '_' . substr(md5($aliasFull), 0, 12);
        }
    }

    protected function addToPersistence(Model $theirModel, array $defaults = []): void
    {
        if (!$theirModel->persistence && $persistence = $this->getDefaultPersistence($theirModel)) {
            $persistence->add($theirModel, $defaults);
        }

        // set model caption
        if ($this->caption !== null) {
            $theirModel->caption = $this->caption;
        }
    }

    /**
     * Returns default persistence for theirModel.
     *
     * @return Persistence|false
     */
    protected function getDefaultPersistence(Model $theirModel)
    {
        $ourModel = $this->getOurModel(null);

        // this will be useful for containsOne/Many implementation in case when you have
        // SQL_Model->containsOne()->hasOne() structure to get back to SQL persistence
        // from Array persistence used in containsOne model
        if ($ourModel->contained_in_root_model && $ourModel->contained_in_root_model->persistence) {
            return $ourModel->contained_in_root_model->persistence;
        }

        return $ourModel->persistence ?: false;
    }

    /**
     * Returns referenced model without any extra conditions. However other
     * relationship types may override this to imply conditions.
     */
    public function ref(Model $ourModel, array $defaults = []): Model
    {
        return $this->createTheirModel($defaults);
    }

    /**
     * Returns referenced model without any extra conditions. Ever when extended
     * must always respond with Model that does not look into current record
     * or scope.
     */
    public function refModel(Model $ourModel, array $defaults = []): Model
    {
        return $this->createTheirModel($defaults);
    }

    /**
     * List of properties to show in var_dump.
     *
     * @var array<int|string, string>
     */
    protected $__debug_fields = ['link', 'model', 'our_field', 'their_field'];

    /**
     * Returns array with useful debug info for var_dump.
     */
    public function __debugInfo(): array
    {
        $arr = [];
        foreach ($this->__debug_fields as $k => $v) {
            $k = is_int($k) ? $v : $k;
            if ($this->{$v} !== null) {
                $arr[$k] = $this->{$v};
            }
        }

        return $arr;
    }
}
