<?php

declare(strict_types=1);

namespace Atk4\Data;

use Atk4\Core\CollectionTrait;
use Atk4\Core\ContainerTrait;
use Atk4\Core\DiContainerTrait;
use Atk4\Core\DynamicMethodTrait;
use Atk4\Core\Factory;
use Atk4\Core\HookTrait;
use Atk4\Core\InitializerTrait;
use Atk4\Core\ReadableCaptionTrait;
use Atk4\Data\Field\CallbackField;
use Atk4\Data\Field\SqlExpressionField;
use Mvorisek\Atk4\Hintable\Data\HintableModelTrait;

/**
 * Data model class.
 *
 * @property int                 $id       @Atk4\Field(visibility="protected_set") Contains ID of the current record.
 *                                         If the value is null then the record is considered to be new.
 * @property Field[]|Reference[] $elements
 *
 * @phpstan-implements \IteratorAggregate<static>
 */
class Model implements \IteratorAggregate
{
    use CollectionTrait {
        _addIntoCollection as private __addIntoCollection;
    }
    use ContainerTrait {
        add as private _add;
    }
    use DiContainerTrait {
        DiContainerTrait::__isset as private __di_isset;
        DiContainerTrait::__get as private __di_get;
        DiContainerTrait::__set as private __di_set;
        DiContainerTrait::__unset as private __di_unset;
    }
    use DynamicMethodTrait;
    use HintableModelTrait {
        HintableModelTrait::__isset as private __hintable_isset;
        HintableModelTrait::__get as private __hintable_get;
        HintableModelTrait::__set as private __hintable_set;
        HintableModelTrait::__unset as private __hintable_unset;
    }
    use HookTrait;
    use InitializerTrait {
        init as private _init;
    }
    use Model\JoinsTrait;
    use Model\ReferencesTrait;
    use Model\UserActionsTrait;
    use ReadableCaptionTrait;

    /** @const string */
    public const HOOK_BEFORE_LOAD = self::class . '@beforeLoad';
    /** @const string */
    public const HOOK_AFTER_LOAD = self::class . '@afterLoad';
    /** @const string */
    public const HOOK_BEFORE_UNLOAD = self::class . '@beforeUnload';
    /** @const string */
    public const HOOK_AFTER_UNLOAD = self::class . '@afterUnload';

    /** @const string */
    public const HOOK_BEFORE_INSERT = self::class . '@beforeInsert';
    /** @const string */
    public const HOOK_AFTER_INSERT = self::class . '@afterInsert';
    /** @const string */
    public const HOOK_BEFORE_UPDATE = self::class . '@beforeUpdate';
    /** @const string */
    public const HOOK_AFTER_UPDATE = self::class . '@afterUpdate';
    /** @const string */
    public const HOOK_BEFORE_DELETE = self::class . '@beforeDelete';
    /** @const string */
    public const HOOK_AFTER_DELETE = self::class . '@afterDelete';

    /** @const string */
    public const HOOK_BEFORE_SAVE = self::class . '@beforeSave';
    /** @const string */
    public const HOOK_AFTER_SAVE = self::class . '@afterSave';

    /** @const string Executed when execution of self::atomic() failed. */
    public const HOOK_ROLLBACK = self::class . '@rollback';

    /** @const string Executed for every field set using self::set() method. */
    public const HOOK_NORMALIZE = self::class . '@normalize';
    /** @const string Executed when self::validate() method is called. */
    public const HOOK_VALIDATE = self::class . '@validate';
    /** @const string Executed when self::setOnlyFields() method is called. */
    public const HOOK_ONLY_FIELDS = self::class . '@onlyFields';

    /** @const string */
    protected const ID_LOAD_ONE = self::class . '@idLoadOne';
    /** @const string */
    protected const ID_LOAD_ANY = self::class . '@idLoadAny';

    // {{{ Properties of the class

    /**
     * @var static|null not-null if and only if this instance is an entity
     */
    private $_model;

    /**
     * @var mixed once set, loading a different ID will result in an error
     */
    private $_entityId;

    /** @var array<string, true> */
    private static $_modelOnlyProperties;

    /**
     * The class used by addField() method.
     *
     * @var string|array
     */
    public $_default_seed_addField = [Field::class];

    /**
     * The class used by addExpression() method.
     *
     * @var string|array
     */
    public $_default_seed_addExpression = [CallbackField::class];

    /**
     * @var array<string, Field>
     */
    protected $fields = [];

    /**
     * Contains name of table, session key, collection or file where this
     * model normally lives. The interpretation of the table will be decoded
     * by persistence driver.
     *
     * @var string|false
     */
    public $table;

    /**
     * Use alias for $table.
     *
     * @var string|null
     */
    public $table_alias;

    /**
     * @var Persistence|Persistence\Sql|null
     */
    public $persistence;

    /**
     * Persistence store some custom information in here that may be useful
     * for them. The key is the name of persistence driver.
     *
     * @var array
     */
    public $persistence_data = [];

    /** @var Model\Scope\RootScope */
    private $scope;

    /**
     * Array of limit set.
     *
     * @var array
     */
    public $limit = [];

    /**
     * Array of set order by.
     *
     * @var array
     */
    public $order = [];

    /**
     * Array of WITH cursors set.
     *
     * @var array
     */
    public $with = [];

    /**
     * Currently loaded record data. This record is associative array
     * that contain field => data pairs. It may contain data for un-defined
     * fields only if $onlyFields mode is false.
     *
     * Avoid accessing $data directly, use set() / get() instead.
     *
     * @var array
     */
    private $data = [];

    /**
     * After loading an active record from DataSet it will be stored in
     * $data property and you can access it using get(). If you use
     * set() to change any of the data, the original value will be copied
     * here.
     *
     * If the value you set equal to the original value, then the key
     * in this array will be removed.
     *
     * The $dirty data will be reset after you save() the data but it is
     * still available to all before/after save handlers.
     *
     * @var array
     */
    private $dirty = [];

    /** @var array */
    private $dirtyAfterReload = [];

    /**
     * Setting model as read_only will protect you from accidentally
     * updating the model. This property is intended for UI and other code
     * detecting read-only models and acting accordingly.
     *
     * SECURITY WARNING: If you are looking for a RELIABLE way to restrict access
     * to model data, please check Secure Enclave extension.
     *
     * @var bool
     */
    public $read_only = false;

    /**
     * While in most cases your id field will be called 'id', sometimes
     * you would want to use a different one or maybe don't create field
     * at all.
     *
     * @var string|null
     */
    public $id_field = 'id';

    /**
     * Title field is used typically by UI components for a simple human
     * readable row title/description.
     *
     * @var string|null
     */
    public $title_field = 'name';

    /**
     * Caption of the model. Can be used in UI components, for example.
     * Should be in plain English and ready for proper localization.
     *
     * @var string
     */
    public $caption;

    /**
     * When using setOnlyFields() this property will contain list of desired
     * fields.
     *
     * If you set setOnlyFields() before loading the data for this model, then
     * only that set of fields will be available. Attempt to access any other
     * field will result in exception. This is to ensure that you do not
     * accidentally access field that you have explicitly excluded.
     *
     * The default behavior is to return NULL and allow you to set new
     * fields even if addField() was not used to set the field.
     *
     * setOnlyFields() always allows to access fields with system = true.
     *
     * @var array|null
     */
    public $onlyFields;

    /**
     * When set to true, loading model from database will also
     * perform value normalization. Use this if you think that
     * persistence may contain badly formatted data that may
     * impact your business logic.
     *
     * @var bool
     */
    public $load_normalization = false;

    /**
     * Models that contain expressions will automatically reload after save.
     * This is to ensure that any SQL-based calculation are executed and
     * updated correctly after you have performed any modifications to
     * the fields.
     *
     * You can set this property to "true" or "false" if you want to explicitly
     * enable or disable reloading.
     *
     * @var bool|null
     */
    public $reload_after_save;

    /**
     * If this model is "contained into" another model by using containsOne
     * or containsMany reference, then this property will contain reference
     * to top most parent model.
     *
     * @var Model|null
     */
    public $contained_in_root_model;

    /** @var Reference Only for Reference class */
    public $ownerReference;

    // }}}

    // {{{ Basic Functionality, field definition, set() and get()

    /**
     * Creation of the new model can be done in two ways:.
     *
     * $m = $db->add(new Model());
     *
     * or
     *
     * $m = new Model($db);
     *
     * The second use actually calls add() but is preferred usage because:
     *  - it's shorter
     *  - type hinting will work;
     *  - you can specify string for a table
     *
     * @param array<string, mixed> $defaults
     */
    public function __construct(Persistence $persistence = null, array $defaults = [])
    {
        $this->scope = \Closure::bind(function () {
            return new Model\Scope\RootScope();
        }, null, Model\Scope\RootScope::class)()
            ->setModel($this);

        $this->setDefaults($defaults);

        if ($persistence !== null) {
            $persistence->add($this);
        }
    }

    public function isEntity(): bool
    {
        return $this->_model !== null;
    }

    public function assertIsModel(self $expectedModelInstance = null): void
    {
        if ($this->isEntity()) {
            throw new Exception('Expected model, but instance is an entity');
        }

        if ($expectedModelInstance !== null && $expectedModelInstance !== $this) {
            throw new Exception('Unexpected entity model instance');
        }
    }

    public function assertIsEntity(self $expectedModelInstance = null): void
    {
        if (!$this->isEntity()) {
            throw new Exception('Expected entity, but instance is a model');
        }

        if ($expectedModelInstance !== null) {
            $this->getModel()->assertIsModel($expectedModelInstance);
        }
    }

    /**
     * @return static
     */
    public function getModel(bool $allowOnModel = false): self
    {
        if ($allowOnModel && !$this->isEntity()) {
            return $this;
        }

        $this->assertIsEntity();

        return $this->_model;
    }

    public function __clone()
    {
        if (!$this->isEntity()) {
            $this->scope = (clone $this->scope)->setModel($this);
            $this->_cloneCollection('fields');
            $this->_cloneCollection('elements');
        }
        $this->_cloneCollection('userActions');

        // check for clone errors immediately, otherwise not strictly needed
        $this->_rebindHooksIfCloned();
    }

    /**
     * @return array<string, true>
     */
    protected function getModelOnlyProperties(): array
    {
        $this->assertIsModel();

        if (self::$_modelOnlyProperties === null) {
            $modelOnlyProperties = [];
            foreach ((new \ReflectionClass(self::class))->getProperties() as $prop) {
                if (!$prop->isStatic()) {
                    $modelOnlyProperties[$prop->getName()] = true;
                }
            }

            foreach ([
                '_model',
                '_entityId',
                'data',
                'dirty',
                'dirtyAfterReload',

                'hooks',
                '_hookIndexCounter',
                '_hookOrigThis',

                'ownerReference', // should be removed once references/joins are non-entity
                'userActions', // should be removed once user actions are non-entity
            ] as $name) {
                unset($modelOnlyProperties[$name]);
            }

            self::$_modelOnlyProperties = $modelOnlyProperties;
        }

        return self::$_modelOnlyProperties;
    }

    /**
     * @return static
     */
    public function createEntity(): self
    {
        $this->assertIsModel();

        $this->_model = $this;
        try {
            $model = clone $this;
        } finally {
            $this->_model = null;
        }
        $model->_entityId = null;

        // unset non-entity properties, they are magically remapped to the model when accessed
        foreach (array_keys($this->getModelOnlyProperties()) as $name) {
            unset($model->{$name});
        }

        return $model;
    }

    /**
     * Extend this method to define fields of your choice.
     */
    protected function init(): void
    {
        $this->_init();

        if ($this->id_field) {
            $this->addField($this->id_field, ['type' => 'integer', 'required' => true, 'system' => true]);
        } else {
            return; // don't declare actions for model without id_field
        }

        $this->initEntityIdHooks();

        if ($this->read_only) {
            return; // don't declare action for read-only model
        }

        // Declare our basic Crud actions for the model.
        $this->addUserAction('add', [
            'fields' => true,
            'modifier' => Model\UserAction::MODIFIER_CREATE,
            'appliesTo' => Model\UserAction::APPLIES_TO_NO_RECORDS,
            'callback' => 'save',
            'description' => 'Add ' . $this->getModelCaption(),
        ]);

        $this->addUserAction('edit', [
            'fields' => true,
            'modifier' => Model\UserAction::MODIFIER_UPDATE,
            'appliesTo' => Model\UserAction::APPLIES_TO_SINGLE_RECORD,
            'callback' => 'save',
        ]);

        $this->addUserAction('delete', [
            'appliesTo' => Model\UserAction::APPLIES_TO_SINGLE_RECORD,
            'modifier' => Model\UserAction::MODIFIER_DELETE,
            'callback' => function ($model) {
                return $model->delete();
            },
        ]);

        $this->addUserAction('validate', [
            //'appliesTo' => any!
            'description' => 'Provided with modified values will validate them but will not save',
            'modifier' => Model\UserAction::MODIFIER_READ,
            'fields' => true,
            'system' => true, // don't show by default
            'args' => ['intent' => 'string'],
        ]);
    }

    private function initEntityIdAndAssertUnchanged(): void
    {
        $id = $this->getId();
        if ($id === null) { // allow unload
            return;
        }

        if ($this->_entityId === null) {
            // set entity ID to the first seen ID
            $this->_entityId = $id;
        } elseif (!$this->compare($this->id_field, $this->_entityId)) {
            $this->unload(); // data for different ID were loaded, make sure to discard them

            throw (new Exception('Model instance is an entity, ID cannot be changed to a different one'))
                ->addMoreInfo('entityId', $this->_entityId)
                ->addMoreInfo('newId', $id);
        }
    }

    private function initEntityIdHooks(): void
    {
        $fx = function () {
            $this->initEntityIdAndAssertUnchanged();
        };

        $this->onHookShort(self::HOOK_BEFORE_LOAD, $fx, [], 10);
        $this->onHookShort(self::HOOK_AFTER_LOAD, $fx, [], -10);
        $this->onHookShort(self::HOOK_BEFORE_INSERT, $fx, [], 10);
        $this->onHookShort(self::HOOK_AFTER_INSERT, $fx, [], -10);
        $this->onHookShort(self::HOOK_BEFORE_UPDATE, $fx, [], 10);
        $this->onHookShort(self::HOOK_AFTER_UPDATE, $fx, [], -10);
        $this->onHookShort(self::HOOK_BEFORE_DELETE, $fx, [], 10);
        $this->onHookShort(self::HOOK_AFTER_DELETE, $fx, [], -10);
        $this->onHookShort(self::HOOK_BEFORE_SAVE, $fx, [], 10);
        $this->onHookShort(self::HOOK_AFTER_SAVE, $fx, [], -10);
    }

    public function add(object $obj, array $defaults = []): void
    {
        $this->assertIsModel();

        if ($obj instanceof Field) {
            throw new Exception('Field can be added using addField() method only');
        }

        $this->_add($obj, $defaults);
    }

    public function _addIntoCollection(string $name, object $item, string $collection): object
    {
        // TODO $this->assertIsModel();

        return $this->__addIntoCollection($name, $item, $collection);
    }

    /**
     * @internal should be not used outside atk4/data
     */
    public function &getDataRef(): array
    {
        $this->assertIsEntity();

        return $this->data;
    }

    /**
     * @internal should be not used outside atk4/data
     */
    public function &getDirtyRef(): array
    {
        $this->assertIsEntity();

        return $this->dirty;
    }

    /**
     * Perform validation on a currently loaded values, must return Array in format:
     *  ['field' => 'must be 4 digits exactly'] or empty array if no errors were present.
     *
     * You may also use format:
     *  ['field' => ['must not have character [ch]', 'ch' => $bad_character']] for better localization of error message.
     *
     * Always use
     *   return array_merge(parent::validate($intent), $errors);
     *
     * @param string $intent by default only 'save' is used (from beforeSave) but you can use other intents yourself
     *
     * @return array<string, string> [field => err_spec]
     */
    public function validate(string $intent = null): array
    {
        $errors = [];
        foreach ($this->hook(self::HOOK_VALIDATE, [$intent]) as $handler_error) {
            if ($handler_error) {
                $errors = array_merge($errors, $handler_error);
            }
        }

        return $errors;
    }

    /** @var array<string, array> */
    protected $fieldSeedByType = [];

    /**
     * Given a field seed, return a field object.
     */
    public function fieldFactory(array $seed = null): Field
    {
        $seed = Factory::mergeSeeds(
            $seed,
            isset($seed['type']) ? ($this->fieldSeedByType[$seed['type']] ?? null) : null,
            $this->_default_seed_addField
        );

        return Field::fromSeed($seed);
    }

    /**
     * Adds new field into model.
     *
     * @param array|object $seed
     */
    public function addField(string $name, $seed = []): Field
    {
        $this->assertIsModel();

        if (is_object($seed)) {
            $field = $seed;
        } else {
            $field = $this->fieldFactory($seed);
        }

        return $this->_addIntoCollection($name, $field, 'fields');
    }

    /**
     * Adds multiple fields into model.
     *
     * @return $this
     */
    public function addFields(array $fields, array $defaults = [])
    {
        foreach ($fields as $name => $seed) {
            if (is_int($name)) {
                $name = $seed;
                $seed = [];
            }

            $this->addField($name, Factory::mergeSeeds($seed, $defaults));
        }

        return $this;
    }

    /**
     * Remove field that was added previously.
     *
     * @return $this
     */
    public function removeField(string $name)
    {
        $this->assertIsModel();

        $this->getField($name); // better exception if field does not exist

        $this->_removeFromCollection($name, 'fields');

        return $this;
    }

    public function hasField(string $name): bool
    {
        if ($this->isEntity()) {
            return $this->getModel()->hasField($name);
        }

        $this->assertIsModel();

        return $this->_hasInCollection($name, 'fields');
    }

    public function getField(string $name): Field
    {
        if ($this->isEntity()) {
            return $this->getModel()->getField($name);
        }

        $this->assertIsModel();

        try {
            return $this->_getFromCollection($name, 'fields');
        } catch (\Atk4\Core\Exception $e) {
            throw (new Exception('Field is not defined in model', 0, $e))
                ->addMoreInfo('model', $this)
                ->addMoreInfo('field', $name);
        }
    }

    /**
     * @deprecated will be removed in v4.0
     *
     * @return $this
     */
    public function onlyFields(array $fields = [])
    {
        'trigger_error'('Method is deprecated. Use setOnlyFields() instead', \E_USER_DEPRECATED);

        return $this->setOnlyFields($fields);
    }

    /**
     * @deprecated will be removed in v4.0
     *
     * @return $this
     */
    public function allFields()
    {
        'trigger_error'('Method is deprecated. Use setOnlyFields(null) instead', \E_USER_DEPRECATED);

        return $this->setOnlyFields(null);
    }

    /**
     * Sets which fields we will select.
     *
     * @param array<string>|null $fields
     *
     * @return $this
     */
    public function setOnlyFields(?array $fields)
    {
        $this->assertIsModel();

        $this->hook(self::HOOK_ONLY_FIELDS, [&$fields]);
        $this->onlyFields = $fields;

        return $this;
    }

    private function assertOnlyField(string $field): void
    {
        $this->assertIsModel();

        $this->getField($field); // assert field exists

        if ($this->onlyFields !== null) {
            if (!in_array($field, $this->onlyFields, true) && !$this->getField($field)->system) {
                throw (new Exception('Attempt to use field outside of those set by setOnlyFields'))
                    ->addMoreInfo('field', $field)
                    ->addMoreInfo('onlyFields', $this->onlyFields);
            }
        }
    }

    /**
     * Will return true if specified field is dirty.
     */
    public function isDirty(string $field): bool
    {
        $this->getModel()->assertOnlyField($field);

        $dirtyRef = &$this->getDirtyRef();
        if (array_key_exists($field, $dirtyRef)) {
            return true;
        }

        return false;
    }

    /**
     * @param string|array|null $filter
     *
     * @return array<string, Field>
     */
    public function getFields($filter = null): array
    {
        if ($this->isEntity()) {
            return $this->getModel()->getFields($filter);
        }

        $this->assertIsModel();

        if ($filter === null) {
            return $this->fields;
        } elseif (is_string($filter)) {
            $filter = [$filter];
        }

        return array_filter($this->fields, function (Field $field, $name) use ($filter) {
            // do not return fields outside of "onlyFields" scope
            if ($this->onlyFields !== null && !in_array($name, $this->onlyFields, true)) { // TODO also without filter?
                return false;
            }
            foreach ($filter as $f) {
                if (
                    ($f === 'system' && $field->system)
                    || ($f === 'not system' && !$field->system)
                    || ($f === 'editable' && $field->isEditable())
                    || ($f === 'visible' && $field->isVisible())
                ) {
                    return true;
                } elseif (!in_array($f, ['system', 'not system', 'editable', 'visible'], true)) {
                    throw (new Exception('Filter is not supported'))
                        ->addMoreInfo('filter', $f);
                }
            }

            return false;
        }, \ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Set field value.
     *
     * @param mixed $value
     *
     * @return $this
     */
    public function set(string $field, $value)
    {
        $this->getModel()->assertOnlyField($field);

        $f = $this->getField($field);

        if (!$value instanceof Persistence\Sql\Expressionable) {
            try {
                $value = $f->normalize($value);
            } catch (Exception $e) {
                $e->addMoreInfo('field', $f);
                $e->addMoreInfo('value', $value);

                throw $e;
            }
        }

        // do nothing when value has not changed
        $dataRef = &$this->getDataRef();
        $dirtyRef = &$this->getDirtyRef();
        $currentValue = array_key_exists($field, $dataRef)
            ? $dataRef[$field]
            : (array_key_exists($field, $dirtyRef) ? $dirtyRef[$field] : $f->default);
        if (!$value instanceof Persistence\Sql\Expressionable && $f->compare($value, $currentValue)) {
            return $this;
        }

        if ($f->read_only) {
            throw (new Exception('Attempting to change read-only field'))
                ->addMoreInfo('field', $field)
                ->addMoreInfo('model', $this);
        }

        if (array_key_exists($field, $dirtyRef) && $f->compare($dirtyRef[$field], $value)) {
            unset($dirtyRef[$field]);
        } elseif (!array_key_exists($field, $dirtyRef)) {
            $dirtyRef[$field] = array_key_exists($field, $dataRef) ? $dataRef[$field] : $f->default;
        }
        $dataRef[$field] = $value;

        return $this;
    }

    /**
     * Unset field value even if null value is not allowed.
     *
     * @return $this
     */
    public function setNull(string $field)
    {
        // set temporary hook to disable any normalization (null validation)
        $hookIndex = $this->getModel()->onHookShort(self::HOOK_NORMALIZE, static function () {
            throw new \Atk4\Core\HookBreaker(false);
        }, [], \PHP_INT_MIN);
        try {
            return $this->set($field, null);
        } finally {
            $this->getModel()->removeHook(self::HOOK_NORMALIZE, $hookIndex, true);
        }
    }

    /**
     * Helper method to call self::set() for each input array element.
     *
     * This method does not revert the data when an exception is thrown.
     *
     * @return $this
     */
    public function setMulti(array $fields)
    {
        foreach ($fields as $field => $value) {
            $this->set($field, $value);
        }

        return $this;
    }

    /**
     * Returns field value.
     * If no field is passed, then returns array of all field values.
     *
     * @return mixed
     */
    public function get(string $field = null)
    {
        if ($field === null) {
            $this->assertIsEntity();

            $data = [];
            foreach ($this->onlyFields ?? array_keys($this->getFields()) as $field) {
                $data[$field] = $this->get($field);
            }

            return $data;
        }

        $this->getModel()->assertOnlyField($field);

        $dataRef = &$this->getDataRef();
        if (array_key_exists($field, $dataRef)) {
            return $dataRef[$field];
        }

        return $this->getField($field)->default;
    }

    private function assertHasIdField(): void
    {
        if (!is_string($this->id_field) || !$this->hasField($this->id_field)) {
            throw new Exception('ID field is not defined');
        }
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        $this->assertHasIdField();

        return $this->get($this->id_field);
    }

    /**
     * @param mixed $value
     *
     * @return $this
     */
    public function setId($value)
    {
        $this->assertHasIdField();

        if ($value === null) {
            $this->setNull($this->id_field);
        } else {
            $this->set($this->id_field, $value);
        }

        $this->initEntityIdAndAssertUnchanged();

        return $this;
    }

    /**
     * Return (possibly localized) $model->caption.
     * If caption is not set, then generate it from model class name.
     */
    public function getModelCaption(): string
    {
        return $this->caption ?: $this->readableCaption(
            (new \ReflectionClass(static::class))->isAnonymous() ? get_parent_class(static::class) : static::class
        );
    }

    /**
     * Return value of $model->get($model->title_field). If not set, returns id value.
     *
     * @return mixed
     */
    public function getTitle()
    {
        if ($this->title_field && $this->hasField($this->title_field)) {
            return $this->get($this->title_field);
        }

        return $this->getId();
    }

    /**
     * Returns array of model record titles [id => title].
     *
     * @return array<int|string, mixed>
     */
    public function getTitles(): array
    {
        $this->assertIsModel();

        $field = $this->title_field && $this->hasField($this->title_field) ? $this->title_field : $this->id_field;

        return array_map(function ($row) use ($field) {
            return $row[$field];
        }, $this->export([$field], $this->id_field));
    }

    /**
     * @param mixed $value
     */
    public function compare(string $name, $value): bool
    {
        return $this->getField($name)->compare($this->get($name), $value);
    }

    /**
     * Does field exist?
     */
    public function _isset(string $name): bool
    {
        $this->getModel()->assertOnlyField($name);

        $dirtyRef = &$this->getDirtyRef();

        return array_key_exists($name, $dirtyRef);
    }

    /**
     * Remove current field value and use default.
     *
     * @return $this
     */
    public function _unset(string $name)
    {
        $this->getModel()->assertOnlyField($name);

        $dataRef = &$this->getDataRef();
        $dirtyRef = &$this->getDirtyRef();
        if (array_key_exists($name, $dirtyRef)) {
            $dataRef[$name] = $dirtyRef[$name];
            unset($dirtyRef[$name]);
        }

        return $this;
    }

    // }}}

    // {{{ DataSet logic

    /**
     * Get the scope object of the Model.
     */
    public function scope(): Model\Scope\RootScope
    {
        $this->assertIsModel();

        return $this->scope;
    }

    /**
     * Narrow down data-set of the current model by applying
     * additional condition. There is no way to remove
     * condition once added, so if you need - clone model.
     *
     * This is the most basic for defining condition:
     *  ->addCondition('my_field', $value);
     *
     * This condition will work across all persistence drivers universally.
     *
     * In some cases a more complex logic can be used:
     *  ->addCondition('my_field', '>', $value);
     *  ->addCondition('my_field', '!=', $value);
     *  ->addCondition('my_field', 'in', [$value1, $value2]);
     *
     * Second argument could be '=', '>', '<', '>=', '<=', '!=', 'in', 'like' or 'regexp'.
     * Those conditions are still supported by most of persistence drivers.
     *
     * There are also vendor-specific expression support:
     *  ->addCondition('my_field', $expr);
     *  ->addCondition($expr);
     *
     * Conditions on referenced models are also supported:
     *  $contact->addCondition('company/country', 'US');
     * where 'company' is the name of the reference
     * This will limit scope of $contact model to contacts whose company country is set to 'US'
     *
     * Using # in conditions on referenced model will apply the condition on the number of records:
     * $contact->addCondition('tickets/#', '>', 5);
     * This will limit scope of $contact model to contacts that have more than 5 tickets
     *
     * To use those, you should consult with documentation of your
     * persistence driver.
     *
     * @param mixed $field
     * @param mixed $operator
     * @param mixed $value
     *
     * @return $this
     */
    public function addCondition($field, $operator = null, $value = null)
    {
        $this->scope()->addCondition(...func_get_args());

        return $this;
    }

    /**
     * Shortcut for using addCondition(id_field, $id).
     *
     * @param mixed $id
     *
     * @return $this
     */
    public function withId($id)
    {
        // TODO this should wrap the current model instead of mutating it

        return $this->addCondition($this->id_field, $id);
    }

    /**
     * Adds WITH cursor.
     *
     * @param Model $model
     *
     * @return $this
     */
    public function addWith(self $model, string $alias, array $mapping = [], bool $recursive = false)
    {
        if (isset($this->with[$alias])) {
            throw (new Exception('With cursor already set with this alias'))
                ->addMoreInfo('alias', $alias);
        }

        $this->with[$alias] = [
            'model' => $model,
            'mapping' => $mapping,
            'recursive' => $recursive,
        ];

        return $this;
    }

    /**
     * Set order for model records. Multiple calls.
     *
     * @param string|array $field
     * @param string       $direction "asc" or "desc"
     *
     * @return $this
     */
    public function setOrder($field, string $direction = 'asc')
    {
        $this->assertIsModel();

        // fields passed as array
        if (is_array($field)) {
            if (func_num_args() > 1) {
                throw (new Exception('If first argument is array, second argument must not be used'))
                    ->addMoreInfo('arg1', $field)
                    ->addMoreInfo('arg2', $direction);
            }

            foreach (array_reverse($field) as $key => $direction) {
                if (is_int($key)) {
                    if (is_array($direction)) {
                        // format [field, direction]
                        $this->setOrder(...$direction);
                    } else {
                        // format "field"
                        $this->setOrder($direction);
                    }
                } else {
                    // format "field" => direction
                    $this->setOrder($key, $direction);
                }
            }

            return $this;
        }

        $direction = strtolower($direction);
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw (new Exception('Invalid order direction, direction can be only "asc" or "desc"'))
                ->addMoreInfo('field', $field)
                ->addMoreInfo('direction', $direction);
        }

        // finally set order
        $this->order[] = [$field, $direction];

        return $this;
    }

    /**
     * Set limit of DataSet.
     *
     * @return $this
     */
    public function setLimit(int $count = null, int $offset = 0)
    {
        $this->assertIsModel();

        $this->limit = [$count, $offset];

        return $this;
    }

    // }}}

    // {{{ Persistence-related logic

    public function assertHasPersistence(string $methodName = null): void
    {
        if (!$this->persistence) {
            throw new Exception('Model is not associated with a persistence');
        }

        if ($methodName && !$this->persistence->hasMethod($methodName)) {
            throw new Exception("Persistence does not support {$methodName} method");
        }
    }

    /**
     * @deprecated will be removed in v4.0
     */
    public function loaded(): bool
    {
        'trigger_error'('Method is deprecated. Use isLoaded() instead', \E_USER_DEPRECATED);

        return $this->isLoaded();
    }

    /**
     * Is entity loaded?
     */
    public function isLoaded(): bool
    {
        return $this->id_field && $this->getId() !== null && $this->_entityId !== null;
    }

    public function assertIsLoaded(): void
    {
        if (!$this->isLoaded()) {
            throw new Exception('Expected loaded entity');
        }
    }

    /**
     * Unload model.
     *
     * @return $this
     */
    public function unload()
    {
        $this->assertIsEntity();

        $this->hook(self::HOOK_BEFORE_UNLOAD);
        $dataRef = &$this->getDataRef();
        $dirtyRef = &$this->getDirtyRef();
        $dataRef = [];
        if ($this->id_field && $this->hasField($this->id_field)) {
            $this->setId(null);
        }
        $dirtyRef = [];
        $this->hook(self::HOOK_AFTER_UNLOAD);

        return $this;
    }

    /**
     * @param mixed $id
     *
     * @return mixed
     */
    private function remapIdLoadToPersistence($id)
    {
        if ($id === self::ID_LOAD_ONE) {
            return Persistence::ID_LOAD_ONE;
        } elseif ($id === self::ID_LOAD_ANY) {
            return Persistence::ID_LOAD_ANY;
        }

        return $id;
    }

    /**
     * @param mixed $id
     *
     * @return $this
     */
    private function _loadThis(bool $isTryLoad, $id)
    {
        $this->assertIsEntity();
        if ($this->isLoaded()) {
            throw new Exception('Entity must be unloaded');
        }

        $this->assertHasPersistence();

        $noId = $id === self::ID_LOAD_ONE || $id === self::ID_LOAD_ANY;
        if ($this->hook(self::HOOK_BEFORE_LOAD, [$noId ? null : $id]) === false) {
            return $this;
        }
        $dataRef = &$this->getDataRef();
        $dataRef = $this->persistence->{$isTryLoad ? 'tryLoad' : 'load'}($this->getModel(), $this->remapIdLoadToPersistence($id));
        if ($isTryLoad && $dataRef === null) {
            $dataRef = [];
            $this->unload();
        } else {
            if ($this->id_field) {
                $this->setId($this->getId());
            }

            $ret = $this->hook(self::HOOK_AFTER_LOAD);
            if ($ret === false) {
                $this->unload();
            } elseif (is_object($ret)) {
                return $ret; // @phpstan-ignore-line
            }
        }

        return $this;
    }

    /**
     * Try to load record. Will not throw an exception if record does not exist.
     *
     * @param mixed $id
     *
     * @return static|null
     */
    public function tryLoad($id)
    {
        $this->assertIsModel();

        return $this->createEntity()->_loadThis(true, $id);
    }

    /**
     * Load model.
     *
     * @param mixed $id
     *
     * @return static
     */
    public function load($id)
    {
        $this->assertIsModel();

        return $this->createEntity()->_loadThis(false, $id);
    }

    /**
     * Try to load one record. Will throw if more than one record exists, but not if there is no record.
     *
     * @return static|null
     */
    public function tryLoadOne()
    {
        return $this->tryLoad(self::ID_LOAD_ONE);
    }

    /**
     * Load one record. Will throw if more than one record exists.
     *
     * @return static
     */
    public function loadOne()
    {
        return $this->load(self::ID_LOAD_ONE);
    }

    /**
     * Try to load any record. Will not throw an exception if record does not exist.
     *
     * If only one record should match, use checked "tryLoadOne" method.
     *
     * @return static|null
     */
    public function tryLoadAny()
    {
        return $this->tryLoad(self::ID_LOAD_ANY);
    }

    /**
     * Load any record.
     *
     * If only one record should match, use checked "loadOne" method.
     *
     * @return static
     */
    public function loadAny()
    {
        return $this->load(self::ID_LOAD_ANY);
    }

    /**
     * Reload model by taking its current ID.
     *
     * @return $this
     */
    public function reload()
    {
        $id = $this->getId();
        $this->unload();

        return $this->_loadThis(false, $id);
    }

    /**
     * Keeps the model data, but wipes out the ID so
     * when you save it next time, it ends up as a new
     * record in the database.
     *
     * @return static
     */
    public function duplicate()
    {
        // deprecated, to be removed in v3.2
        if (func_num_args() > 0) {
            throw new Exception('Duplicating using existing ID is no longer supported');
        }

        $duplicate = clone $this;
        $duplicate->_entityId = null;
        $dataRef = &$this->getDataRef();
        $duplicateDirtyRef = &$duplicate->getDirtyRef();
        $duplicateDirtyRef = $dataRef;
        $duplicate->setId(null);

        return $duplicate;
    }

    /**
     * Store the data into database, but will never attempt to
     * reload the data. Additionally any data will be unloaded.
     * Use this instead of save() if you want to squeeze a
     * little more performance out.
     *
     * @return $this
     */
    public function saveAndUnload(array $data = [])
    {
        $reloadAfterSaveBackup = $this->reload_after_save;
        try {
            $this->getModel()->reload_after_save = false;
            $this->save($data);
        } finally {
            $this->getModel()->reload_after_save = $reloadAfterSaveBackup;
        }

        $this->unload();

        return $this;
    }

    /**
     * Create new model from the same base class
     * as $this. If you omit $id then when saving
     * a new record will be created with default ID.
     * If you specify $id then it will be used
     * to save/update your record. If set $id
     * to `true` then model will assume that there
     * is already record like that in the destination
     * persistence.
     *
     * See https://github.com/atk4/data/issues/111 for use-case examples.
     *
     * @param mixed                $id
     * @param class-string<static> $class
     *
     * @return self
     */
    public function withPersistence(Persistence $persistence, $id = null, string $class = null)
    {
        $class ??= static::class;

        /** @var self $model */
        $model = new $class($persistence, ['table' => $this->table]);
        if ($this->isEntity()) { // TODO should this method work with entity at all?
            $model = $model->createEntity();
        }

        if ($this->id_field && $id !== null) {
            $model->setId($id === true ? $this->getId() : $id);
        }

        // include any fields defined inline
        foreach ($this->fields as $fieldName => $field) {
            if (!$model->hasField($fieldName)) {
                $model->addField($fieldName, clone $field);
            }
        }

        if ($this->isEntity()) {
            $modelDataRef = &$model->getDataRef();
            $modelDirtyRef = &$model->getDirtyRef();
            $modelDataRef = &$this->getDataRef();
            $modelDirtyRef = &$this->getDirtyRef();
        }
        $model->limit = $this->limit;
        $model->order = $this->order;
        if (!$this->isEntity()) {
            $model->scope = (clone $this->scope)->setModel($model);
        }

        return $model;
    }

    /**
     * @param mixed $value
     *
     * @return static|null
     */
    private function _loadBy(bool $isTryLoad, string $fieldName, $value)
    {
        $this->assertIsModel();

        $field = $this->getField($fieldName);

        $scopeBak = $this->scope;
        $systemBak = $field->system;
        $defaultBak = $field->default;
        try {
            $this->scope = clone $this->scope;
            $this->addCondition($field, $value);

            return $this->{$isTryLoad ? 'tryLoadOne' : 'loadOne'}();
        } finally {
            $this->scope = $scopeBak;
            $field->system = $systemBak;
            $field->default = $defaultBak;
        }
    }

    /**
     * Load one record by condition. Will throw if more than one record exists.
     *
     * @param mixed $value
     *
     * @return static
     */
    public function loadBy(string $fieldName, $value)
    {
        return $this->_loadBy(false, $fieldName, $value);
    }

    /**
     * Try to load one record by condition. Will throw if more than one record exists, but not if there is no record.
     *
     * @param mixed $value
     *
     * @return static|null
     */
    public function tryLoadBy(string $fieldName, $value)
    {
        return $this->_loadBy(true, $fieldName, $value);
    }

    /**
     * Save record.
     *
     * @return $this
     */
    public function save(array $data = [])
    {
        $this->assertHasPersistence();

        if ($this->read_only) {
            throw new Exception('Model is read-only and cannot be saved');
        }

        $this->setMulti($data);

        return $this->atomic(function () {
            $dirtyRef = &$this->getDirtyRef();

            if (($errors = $this->validate('save')) !== []) {
                throw new ValidationException($errors, $this);
            }
            $is_update = $this->isLoaded();
            if ($this->hook(self::HOOK_BEFORE_SAVE, [$is_update]) === false) {
                return $this;
            }

            if ($is_update) {
                $data = [];
                $dirty_join = false;
                foreach ($dirtyRef as $name => $ignore) {
                    $field = $this->getField($name);
                    if ($field->read_only || $field->never_persist || $field->never_save) {
                        continue;
                    }

                    $value = $this->get($name);

                    if ($field->hasJoin()) {
                        $dirty_join = true;
                        $field->getJoin()->setSaveBufferValue($this, $name, $value);
                    } else {
                        $data[$name] = $value;
                    }
                }

                // No save needed, nothing was changed
                if (!$data && !$dirty_join) {
                    return $this;
                }

                if ($this->hook(self::HOOK_BEFORE_UPDATE, [&$data]) === false) {
                    return $this;
                }

                $this->persistence->update($this, $this->getId(), $data);

                $this->hook(self::HOOK_AFTER_UPDATE, [&$data]);
            } else {
                $data = [];
                foreach ($this->get() as $name => $value) {
                    $field = $this->getField($name);
                    if ($field->read_only || $field->never_persist || $field->never_save) {
                        continue;
                    }

                    if ($field->hasJoin()) {
                        $field->getJoin()->setSaveBufferValue($this, $name, $value);
                    } else {
                        $data[$name] = $value;
                    }
                }

                if ($this->hook(self::HOOK_BEFORE_INSERT, [&$data]) === false) {
                    return $this;
                }

                // Collect all data of a new record
                $id = $this->persistence->insert($this, $data);

                if (!$this->id_field) {
                    $this->hook(self::HOOK_AFTER_INSERT);

                    $dirtyRef = [];
                } else {
                    $this->setId($id);
                    $this->hook(self::HOOK_AFTER_INSERT);

                    if ($this->reload_after_save !== false) {
                        $d = $dirtyRef;
                        $dirtyRef = [];
                        $this->reload();
                        $this->dirtyAfterReload = $dirtyRef;
                        $dirtyRef = $d;
                    }
                }
            }

            if ($this->isLoaded()) {
                $dirtyRef = $this->dirtyAfterReload;
            }

            $this->hook(self::HOOK_AFTER_SAVE, [$is_update]);

            return $this;
        });
    }

    /**
     * This is a temporary method to avoid code duplication, but insert / import should
     * be implemented differently.
     */
    protected function _rawInsert(array $row): void
    {
        $this->unload();

        // Find any row values that do not correspond to fields, and they may correspond to
        // references instead
        $refs = [];
        foreach ($row as $key => $value) {
            // and we only support array values
            if (!is_array($value)) {
                continue;
            }

            // and reference must exist with same name
            if (!$this->hasRef($key)) {
                continue;
            }

            // Then we move value for later
            $refs[$key] = $value;
            unset($row[$key]);
        }

        // save data fields
        $reloadAfterSaveBackup = $this->reload_after_save;
        try {
            $this->getModel()->reload_after_save = false;
            $this->save($row);
        } finally {
            $this->getModel()->reload_after_save = $reloadAfterSaveBackup;
        }

        // store id value
        if ($this->id_field) {
            $this->getDataRef()[$this->id_field] = $this->getId();
        }

        // if there was referenced data, then import it
        foreach ($refs as $key => $value) {
            $this->ref($key)->import($value);
        }
    }

    /**
     * Faster method to add data, that does not modify active record.
     *
     * Will be further optimized in the future.
     *
     * @return mixed
     */
    public function insert(array $row)
    {
        $model = $this->createEntity();
        $model->_rawInsert($row);

        return $this->id_field ? $model->getId() : null;
    }

    /**
     * Even more faster method to add data, does not modify your
     * current record and will not return anything.
     *
     * Will be further optimized in the future.
     *
     * @return $this
     */
    public function import(array $rows)
    {
        $this->atomic(function () use ($rows) {
            foreach ($rows as $row) {
                $this->insert($row);
            }
        });

        return $this;
    }

    /**
     * Export DataSet as array of hashes.
     *
     * @param array|null $fields        Names of fields to export
     * @param string     $key_field     Optional name of field which value we will use as array key
     * @param bool       $typecast_data Should we typecast exported data
     */
    public function export(array $fields = null, $key_field = null, $typecast_data = true): array
    {
        $this->assertIsModel();
        $this->assertHasPersistence('export');

        // no key field - then just do export
        if ($key_field === null) {
            return $this->persistence->export($this, $fields, $typecast_data);
        }

        // do we have added key field in fields list?
        // if so, then will have to remove it afterwards
        $key_field_added = false;

        // prepare array with field names
        if ($fields === null) {
            $fields = [];

            if ($this->onlyFields !== null) {
                // Add requested fields first
                foreach ($this->onlyFields as $field) {
                    $f_object = $this->getField($field);
                    if ($f_object->never_persist) {
                        continue;
                    }
                    $fields[$field] = true;
                }

                // now add system fields, if they were not added
                foreach ($this->getFields() as $field => $f_object) {
                    if ($f_object->never_persist) {
                        continue;
                    }
                    if ($f_object->system && !isset($fields[$field])) {
                        $fields[$field] = true;
                    }
                }

                $fields = array_keys($fields);
            } else {
                // Add all model fields
                foreach ($this->getFields() as $field => $f_object) {
                    if ($f_object->never_persist) {
                        continue;
                    }
                    $fields[] = $field;
                }
            }
        }

        // add key_field to array if it's not there
        if (!in_array($key_field, $fields, true)) {
            $fields[] = $key_field;
            $key_field_added = true;
        }

        // export
        $data = $this->persistence->export($this, $fields, $typecast_data);

        // prepare resulting array
        $res = [];
        foreach ($data as $row) {
            $key = $row[$key_field];
            if ($key_field_added) {
                unset($row[$key_field]);
            }
            $res[$key] = $row;
        }

        return $res;
    }

    /**
     * Returns iterator (yield values).
     *
     * @return \Traversable<static>
     */
    public function getIterator(): \Traversable
    {
        foreach ($this->getRawIterator() as $data) {
            $thisCloned = $this->createEntity();

            $dataRef = &$thisCloned->getDataRef();
            $dataRef = $this->persistence->typecastLoadRow($this, $data);
            if ($this->id_field) {
                $thisCloned->setId($data[$this->id_field] ?? null);
            }

            // you can return false in afterLoad hook to prevent to yield this data row, example:
            // $model->onHook(self::HOOK_AFTER_LOAD, static function ($m) {
            //     if ($m->get('date') < $m->date_from) {
            //         $m->breakHook(false);
            //     }
            // })

            // you can also use breakHook() with specific object which will then be returned
            // as a next iterator value

            $ret = $thisCloned->hook(self::HOOK_AFTER_LOAD);
            if ($ret === false) {
                continue;
            }

            if (is_object($ret)) {
                if ($ret->id_field) {
                    yield $ret->getId() => $ret; // @phpstan-ignore-line
                } else {
                    yield $ret; // @phpstan-ignore-line
                }
            } else {
                if ($this->id_field) {
                    yield $thisCloned->getId() => $thisCloned;
                } else {
                    yield $thisCloned;
                }
            }
        }
    }

    /**
     * @return \Traversable<array<string, string|null>>
     */
    public function getRawIterator(): \Traversable
    {
        $this->assertIsModel();

        return $this->persistence->prepareIterator($this);
    }

    /**
     * Delete record with a specified id. If no ID is specified
     * then current record is deleted.
     *
     * @param mixed $id
     *
     * @return static
     */
    public function delete($id = null)
    {
        if ($id !== null) {
            $this->assertIsModel();

            $this->load($id)->delete();

            return $this;
        }

        $this->assertIsLoaded();

        if ($this->read_only) {
            throw new Exception('Model is read-only and cannot be deleted');
        }

        $this->atomic(function () {
            if ($this->hook(self::HOOK_BEFORE_DELETE) === false) {
                return;
            }
            $this->persistence->delete($this, $this->getId());
            $this->hook(self::HOOK_AFTER_DELETE);
        });
        $this->unload();

        return $this;
    }

    /**
     * Atomic executes operations within one begin/end transaction, so if
     * the code inside callback will fail, then all of the transaction
     * will be also rolled back.
     *
     * @return mixed
     */
    public function atomic(\Closure $fx)
    {
        try {
            return $this->persistence->atomic($fx);
        } catch (\Exception $e) {
            if ($this->hook(self::HOOK_ROLLBACK, [$e]) === false) {
                return false;
            }

            throw $e;
        }
    }

    // }}}

    // {{{ Support for actions

    /**
     * Create persistence action.
     *
     * TODO Rename this method to stress this method should not be used
     * for anything else then reading records as insert/update/delete hooks
     * will not be called.
     *
     * @return Persistence\Sql\Query
     */
    public function action(string $mode, array $args = [])
    {
        $this->assertHasPersistence('action');

        return $this->persistence->action($this, $mode, $args);
    }

    // }}}

    // {{{ Expressions

    /**
     * Add expression field.
     *
     * @param string|array|Persistence\Sql\Expressionable|\Closure $expression
     *
     * @return CallbackField|SqlExpressionField
     */
    public function addExpression(string $name, $expression)
    {
        if (!is_array($expression)) {
            $expression = ['expr' => $expression];
        } elseif (isset($expression[0])) {
            $expression['expr'] = $expression[0];
            unset($expression[0]);
        }

        /** @var CallbackField|SqlExpressionField */
        $field = Field::fromSeed($this->_default_seed_addExpression, $expression);

        $this->addField($name, $field);

        return $field;
    }

    /**
     * Add expression field which will calculate its value by using callback.
     *
     * @param string|array|\Closure $expression
     *
     * @return CallbackField
     */
    public function addCalculatedField(string $name, $expression)
    {
        if (!is_array($expression)) {
            $expression = ['expr' => $expression];
        } elseif (isset($expression[0])) {
            $expression['expr'] = $expression[0];
            unset($expression[0]);
        }

        $field = new CallbackField($expression);

        $this->addField($name, $field);

        return $field;
    }

    // }}}

    public function __isset(string $name): bool
    {
        $model = $this->getModel(true);

        if (isset($model->getHintableProps()[$name])) {
            return $this->__hintable_isset($name);
        }

        if ($this->isEntity() && isset($model->getModelOnlyProperties()[$name])) {
            return isset($model->{$name});
        }

        return $this->__di_isset($name);
    }

    /**
     * @return mixed
     */
    public function &__get(string $name)
    {
        $model = $this->getModel(true);

        if (isset($model->getHintableProps()[$name])) {
            return $this->__hintable_get($name);
        }

        if ($this->isEntity() && isset($model->getModelOnlyProperties()[$name])) {
            return $model->{$name};
        }

        return $this->__di_get($name);
    }

    /**
     * @param mixed $value
     */
    public function __set(string $name, $value): void
    {
        $model = $this->getModel(true);

        if (isset($model->getHintableProps()[$name])) {
            $this->__hintable_set($name, $value);

            return;
        }

        if ($this->isEntity() && isset($model->getModelOnlyProperties()[$name])) {
            $this->assertIsModel();
        }

        $this->__di_set($name, $value);
    }

    public function __unset(string $name): void
    {
        $model = $this->getModel(true);

        if (isset($model->getHintableProps()[$name])) {
            $this->__hintable_unset($name);

            return;
        }

        if ($this->isEntity() && isset($model->getModelOnlyProperties()[$name])) {
            $this->assertIsModel();
        }

        $this->__di_unset($name);
    }

    public function __debugInfo(): array
    {
        if ($this->isEntity()) {
            return [
                'entityId' => $this->id_field && $this->hasField($this->id_field)
                    ? (($this->_entityId !== null ? $this->_entityId . ($this->getId() !== null ? '' : ' (unloaded)') : 'null'))
                    : 'no id field',
                'model' => $this->getModel()->__debugInfo(),
            ];
        }

        return [
            'table' => $this->table,
            'scope' => $this->scope()->toWords(),
        ];
    }
}
