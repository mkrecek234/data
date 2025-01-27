<?php

declare(strict_types=1);

namespace Atk4\Data;

use Atk4\Core\ContainerTrait;
use Atk4\Core\DiContainerTrait;
use Atk4\Core\DynamicMethodTrait;
use Atk4\Core\Factory;
use Atk4\Core\HookTrait;
use Atk4\Core\NameTrait;
use Doctrine\DBAL\Platforms;

abstract class Persistence
{
    use ContainerTrait {
        add as private _add;
    }
    use DiContainerTrait;
    use DynamicMethodTrait;
    use HookTrait;
    use NameTrait;

    /** @const string */
    public const HOOK_AFTER_ADD = self::class . '@afterAdd';

    /** @const string */
    public const ID_LOAD_ONE = self::class . '@idLoadOne';
    /** @const string */
    public const ID_LOAD_ANY = self::class . '@idLoadAny';

    /** @var bool internal only, prevent recursion */
    private $typecastSaveSkipNormalize = false;

    /**
     * Connects database.
     *
     * @param string|array $dsn Format as PDO DSN or use "mysql://user:pass@host/db;option=blah",
     *                          leaving user and password arguments = null
     */
    public static function connect($dsn, string $user = null, string $password = null, array $args = []): self
    {
        // parse DSN string
        $dsn = Persistence\Sql\Connection::normalizeDsn($dsn, $user, $password);

        switch ($dsn['driver']) {
            case 'pdo_sqlite':
            case 'pdo_mysql':
            case 'pdo_pgsql':
            case 'pdo_sqlsrv':
            case 'pdo_oci':
                $persistence = new Persistence\Sql($dsn, $dsn['user'], $dsn['password'], $args);

                return $persistence;
            default:
                throw (new Exception('Unable to determine persistence driver type'))
                    ->addMoreInfo('dsn', $dsn);
        }
    }

    /**
     * Disconnect from database explicitly.
     */
    public function disconnect(): void
    {
    }

    /**
     * Associate model with the data driver.
     */
    public function add(Model $model, array $defaults = []): void
    {
        Factory::factory($model, $defaults);

        if ($model->persistence !== null) {
            throw new Exception('Persistence already set');
        }

        $model->persistence = $this;
        $model->persistence_data = [];
        $this->initPersistence($model);

        // invokes Model::init()
        // model is not added to elements as it does not implement TrackableTrait trait
        $this->_add($model);

        $this->hook(self::HOOK_AFTER_ADD, [$model]);
    }

    /**
     * Extend this method to enhance model to work with your persistence. Here
     * you can define additional methods or store additional data. This method
     * is executed before model's init().
     */
    protected function initPersistence(Model $m): void
    {
    }

    /**
     * Atomic executes operations within one begin/end transaction. Not all
     * persistencies will support atomic operations, so by default we just
     * don't do anything.
     *
     * @return mixed
     */
    public function atomic(\Closure $fx)
    {
        return $fx();
    }

    public function getDatabasePlatform(): Platforms\AbstractPlatform
    {
        return new Persistence\GenericPlatform();
    }

    /**
     * Tries to load data record, but will not fail if record can't be loaded.
     *
     * @param mixed $id
     */
    public function tryLoad(Model $model, $id): ?array
    {
        throw new Exception('Load is not supported.');
    }

    /**
     * Loads a record from model and returns a associative array.
     *
     * @param mixed $id
     */
    public function load(Model $model, $id): array
    {
        $data = $this->tryLoad($model, $id);

        if (!$data) {
            $noId = $id === self::ID_LOAD_ONE || $id === self::ID_LOAD_ANY;

            throw (new Exception($noId ? 'No record was found' : 'Record with specified ID was not found', 404))
                ->addMoreInfo('model', $model)
                ->addMoreInfo('id', $noId ? null : $id)
                ->addMoreInfo('scope', $model->getModel(true)->scope()->toWords());
        }

        return $data;
    }

    /**
     * Will convert one row of data from native PHP types into
     * persistence types. This will also take care of the "actual"
     * field keys.
     *
     * @return array<scalar|Persistence\Sql\Expressionable|null>
     */
    public function typecastSaveRow(Model $model, array $row): array
    {
        $result = [];
        foreach ($row as $fieldName => $value) {
            $field = $model->getField($fieldName);

            $result[$field->getPersistenceName()] = $this->typecastSaveField($field, $value);
        }

        return $result;
    }

    /**
     * Will convert one row of data from Persistence-specific
     * types to PHP native types.
     *
     * NOTE: Please DO NOT perform "actual" field mapping here, because data
     * may be "aliased" from SQL persistencies or mapped depending on persistence
     * driver.
     *
     * @param array<string, scalar|null> $row
     *
     * @return array<string, mixed>
     */
    public function typecastLoadRow(Model $model, array $row): array
    {
        $result = [];
        foreach ($row as $fieldName => $value) {
            $field = $model->getField($fieldName);

            $result[$fieldName] = $this->typecastLoadField($field, $value);
        }

        return $result;
    }

    /**
     * Prepare value of a specific field by converting it to
     * persistence-friendly format.
     *
     * @param mixed $value
     *
     * @return scalar|Persistence\Sql\Expressionable|null
     */
    public function typecastSaveField(Field $field, $value)
    {
        // SQL Expression cannot be converted
        if ($value instanceof Persistence\Sql\Expressionable) {
            return $value;
        }

        if (!$this->typecastSaveSkipNormalize) {
            $value = $field->normalize($value);
        }

        if ($value === null) {
            return null;
        }

        try {
            $v = $this->_typecastSaveField($field, $value);
            if ($v !== null && !is_scalar($v)) { // @phpstan-ignore-line
                throw new Exception('Unexpected non-scalar value');
            }

            return $v;
        } catch (\Exception $e) {
            throw (new Exception('Typecast save error', 0, $e))
                ->addMoreInfo('field', $field->short_name);
        }
    }

    /**
     * Cast specific field value from the way how it's stored inside
     * persistence to a PHP format.
     *
     * @param scalar|null $value
     *
     * @return mixed
     */
    public function typecastLoadField(Field $field, $value)
    {
        if ($value === null) {
            return null;
        } elseif (!is_scalar($value)) {
            throw new Exception('Unexpected non-scalar value');
        }

        try {
            return $this->_typecastLoadField($field, $value);
        } catch (\Exception $e) {
            throw (new Exception('Typecast parse error', 0, $e))
                ->addMoreInfo('field', $field->short_name);
        }
    }

    /**
     * This is the actual field typecasting, which you can override in your
     * persistence to implement necessary typecasting.
     *
     * @param mixed $value
     *
     * @return scalar|null
     */
    protected function _typecastSaveField(Field $field, $value)
    {
        if (in_array($field->type, ['json', 'object'], true) && $value === '') { // TODO remove later
            return null;
        }

        // native DBAL DT types have no microseconds support
        if (in_array($field->type, ['datetime', 'date', 'time'], true)
            && str_starts_with(get_class($field->getTypeObject()), 'Doctrine\DBAL\Types\\')) {
            if ($value === '') {
                return null;
            } elseif (!$value instanceof \DateTimeInterface) {
                throw new Exception('Must be instance of DateTimeInterface');
            }

            if ($field->type === 'datetime') {
                $value = new \DateTime($value->format('Y-m-d H:i:s.u'), $value->getTimezone());
                $value->setTimezone(new \DateTimeZone($field->persist_timezone));
            }

            $formats = ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s.u', 'time' => 'H:i:s.u'];
            $format = $field->persist_format ?: $formats[$field->type];
            $value = $value->format($format);

            return $value;
        }

        $res = $field->getTypeObject()->convertToDatabaseValue($value, $this->getDatabasePlatform());
        if (is_resource($res) && get_resource_type($res) === 'stream') {
            $res = stream_get_contents($res);
        }

        return $res;
    }

    /**
     * This is the actual field typecasting, which you can override in your
     * persistence to implement necessary typecasting.
     *
     * @param scalar|null $value
     *
     * @return mixed
     */
    protected function _typecastLoadField(Field $field, $value)
    {
        // TODO casting optionally to null should be handled by type itself solely
        if ($value === '' && in_array($field->type, ['boolean', 'integer', 'float', 'datetime', 'date', 'time', 'json', 'object'], true)) {
            return null;
        }

        // native DBAL DT types have no microseconds support
        if (in_array($field->type, ['datetime', 'date', 'time'], true)
            && str_starts_with(get_class($field->getTypeObject()), 'Doctrine\DBAL\Types\\')) {
            if ($field->persist_format) {
                $format = $field->persist_format;
            } else {
                // ! symbol in date format is essential here to remove time part of DateTime - don't remove, this is not a bug
                $formats = ['date' => '+!Y-m-d', 'datetime' => '+!Y-m-d H:i:s', 'time' => '+!H:i:s'];
                $format = $formats[$field->type];
                if (strpos($value, '.') !== false) { // time possibly with microseconds, otherwise invalid format
                    $format = preg_replace('~(?<=H:i:s)(?![. ]*u)~', '.u', $format);
                }
            }

            if ($field->type === 'datetime') {
                $value = \DateTime::createFromFormat($format, $value, new \DateTimeZone($field->persist_timezone));
                if ($value !== false) {
                    $value->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                }
            } else {
                $value = \DateTime::createFromFormat($format, $value);
            }

            if ($value === false) {
                throw (new Exception('Incorrectly formatted date/time'))
                    ->addMoreInfo('format', $format)
                    ->addMoreInfo('value', $value)
                    ->addMoreInfo('field', $field);
            }

            return $value;
        }

        $res = $field->getTypeObject()->convertToPHPValue($value, $this->getDatabasePlatform());
        if (is_resource($res) && get_resource_type($res) === 'stream') {
            $res = stream_get_contents($res);
        }

        return $res;
    }
}
