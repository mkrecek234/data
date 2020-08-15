<?php

declare(strict_types=1);

namespace atk4\data;

use atk4\data\Persistence\AbstractQuery;

/**
 * Persistence class.
 */
class Persistence
{
    use \atk4\core\ContainerTrait {
        add as _add;
    }
    use \atk4\core\FactoryTrait;
    use \atk4\core\HookTrait;
    use \atk4\core\DynamicMethodTrait;
    use \atk4\core\NameTrait;
    use \atk4\core\DiContainerTrait;

    // backward compatibility - will be removed in dec-2020 -->
    /** @const string */
    public const HOOK_INIT_SELECT_QUERY = Persistence\AbstractQuery::HOOK_INIT_SELECT;
    /** @const string */
    public const HOOK_BEFORE_INSERT_QUERY = Persistence\AbstractQuery::HOOK_BEFORE_INSERT;
    /** @const string */
    public const HOOK_AFTER_INSERT_QUERY = Persistence\AbstractQuery::HOOK_AFTER_INSERT;
    /** @const string */
    public const HOOK_BEFORE_UPDATE_QUERY = Persistence\AbstractQuery::HOOK_BEFORE_UPDATE;
    /** @const string */
    public const HOOK_AFTER_UPDATE_QUERY = Persistence\AbstractQuery::HOOK_AFTER_UPDATE;
    /** @const string */
    public const HOOK_BEFORE_DELETE_QUERY = Persistence\AbstractQuery::HOOK_BEFORE_DELETE;
    // <-- backward compatibility

    /** @const string */
    public const HOOK_AFTER_ADD = self::class . '@afterAdd';

    /** @var string Connection driver name, for example, mysql, pgsql, oci etc. */
    public $driverType;

    /**
     * Connects database.
     *
     * @param string $dsn      Format as PDO DSN or use "mysql://user:pass@host/db;option=blah", leaving user and password arguments = null
     * @param string $user
     * @param string $password
     * @param array  $args
     *
     * @return Persistence
     */
    public static function connect($dsn, $user = null, $password = null, $args = [])
    {
        // Process DSN string
        $dsn = \atk4\dsql\Connection::normalizeDsn($dsn, $user, $password);

        $driverType = strtolower($args['driver']/*BC compatibility*/ ?? $args['driverType'] ?? $dsn['driverType']);

        switch ($driverType) {
            case 'mysql':
            case 'oci':
            case 'oci12':
                // Omitting UTF8 is always a bad problem, so unless it's specified we will do that
                // to prevent nasty problems. This is un-tested on other databases, so moving it here.
                // It gives problem with sqlite
                if (strpos($dsn['dsn'], ';charset=') === false) {
                    $dsn['dsn'] .= ';charset=utf8mb4';
                }

                // no break
            case 'pgsql':
            case 'dumper':
            case 'counter':
            case 'sqlite':
                $db = new \atk4\data\Persistence\Sql($dsn['dsn'], $dsn['user'], $dsn['pass'], $args);
                $db->driverType = $driverType;

                return $db;
            default:
                throw (new Exception('Unable to determine persistence driver type from DSN'))
                    ->addMoreInfo('dsn', $dsn['dsn']);
        }
    }

    /**
     * Disconnect from database explicitly.
     */
    public function disconnect()
    {
    }

    /**
     * Prepare iterator.
     */
    public function prepareIterator(Model $model): iterable
    {
        return $this->query($model)->execute();
    }

    /**
     * Export all DataSet.
     *
     * @param bool $typecast Should we typecast exported data
     */
    public function export(Model $model, array $fields = null, bool $typecast = true): array
    {
        $data = $this->query($model)->select($fields)->get();

        if ($typecast) {
            $data = array_map(function ($row) use ($model) {
                return $this->typecastLoadRow($model, $row);
            }, $data);
        }

        return $data;
    }

    /**
     * Associate model with the data driver.
     */
    public function add(Model $m, array $defaults = []): Model
    {
        $m = $this->factory($m, $defaults);

        if ($m->persistence) {
            if ($m->persistence === $this) {
                return $m;
            }

            throw new Exception('Model is already related to another persistence');
        }

        $m->persistence = $this;
        $m->persistence_data = [];
        $this->initPersistence($m);
        $m = $this->_add($m);

        $this->hook(self::HOOK_AFTER_ADD, [$m]);

        return $m;
    }

    /**
     * Extend this method to enhance model to work with your persistence. Here
     * you can define additional methods or store additional data. This method
     * is executed before model's init().
     */
    protected function initPersistence(Model $model)
    {
    }

    public function query(Model $model): Persistence\AbstractQuery
    {
    }

    /**
     * Atomic executes operations within one begin/end transaction. Not all
     * persistences will support atomic operations, so by default we just
     * don't do anything.
     *
     * @return mixed
     */
    public function atomic(\Closure $fx)
    {
        return $fx();
    }

    public function getRow(Model $model, $id = null)
    {
        $query = $this->query($model);

        if ($id !== null) {
            $query->whereId($id);
        }

        $rawData = $query->getRow();

        if ($rawData === null) {
            return null;
        }

        return $this->typecastLoadRow($model, $rawData);
    }

    /**
     * Inserts record in database and returns new record ID.
     *
     * @return mixed
     */
    public function insert(Model $model, array $data)
    {
        // don't set id field at all if it's NULL
        if ($model->id_field && array_key_exists($model->id_field, $data) && $data[$model->id_field] === null) {
            unset($data[$model->id_field]);
        }

        $data = $this->typecastSaveRow($model, $data);

        $this->query($model)->insert($data)->execute();

        return $this->lastInsertId($model);
    }

    /**
     * Updates record in database.
     *
     * @param mixed $id
     * @param array $data
     */
    public function update(Model $model, $id, $data)
    {
        $data = $this->typecastSaveRow($model, $data);

        $model->onHook(AbstractQuery::HOOK_AFTER_UPDATE, function (Model $model, AbstractQuery $query, $result) use ($data) {
            if ($model->id_field && isset($data[$model->id_field]) && $model->dirty[$model->id_field]) {
                // ID was changed
                $model->id = $data[$model->id_field];
            }
        }, [], -1000);

        $result = $this->query($model)->whereId($id)->update($data)->execute();

        // if any rows were updated in database, and we had expressions, reload
        if ($model->reload_after_save === true && (!$result || $result->rowCount())) {
            $dirty = $model->dirty;
            $model->reload();
            $model->_dirty_after_reload = $model->dirty;
            $model->dirty = $dirty;
        }

        return $result;
    }

    /**
     * Deletes record from database.
     *
     * @param mixed $id
     */
    public function delete(Model $model, $id)
    {
        $this->query($model)->whereId($id)->delete()->execute();
    }

    /**
     * Will convert one row of data from native PHP types into
     * persistence types. This will also take care of the "actual"
     * field keys. Example:.
     *
     * In:
     *  [
     *    'name'=>' John Smith',
     *    'age'=>30,
     *    'password'=>'abc',
     *    'is_married'=>true,
     *  ]
     *
     *  Out:
     *   [
     *     'first_name'=>'John Smith',
     *     'age'=>30,
     *     'is_married'=>1
     *   ]
     */
    public function typecastSaveRow(Model $m, array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) {
            // We have no knowledge of the field, it wasn't defined, so
            // we will leave it as-is.
            if (!$m->hasField($key)) {
                $result[$key] = $value;

                continue;
            }

            // Look up field object
            $f = $m->getField($key);

            // Figure out the name of the destination field
            $field = isset($f->actual) && $f->actual ? $f->actual : $key;

            // check null values for mandatory fields
            if ($value === null && $f->mandatory) {
                throw new ValidationException([$key => 'Mandatory field value cannot be null']);
            }

            // Expression and null cannot be converted.
            if (
                $value instanceof \atk4\dsql\Expression ||
                $value instanceof \atk4\dsql\Expressionable ||
                $value === null
            ) {
                $result[$field] = $value;

                continue;
            }

            // typecast if we explicitly want that or there is not serialization enabled
            if ($f->typecast || ($f->typecast === null && $f->serialize === null)) {
                $value = $this->typecastSaveField($f, $value);
            }

            // serialize if we explicitly want that
            if ($f->serialize) {
                $value = $this->serializeSaveField($f, $value);
            }

            // store converted value
            $result[$field] = $value;
        }

        return $result;
    }

    /**
     * Will convert one row of data from Persistence-specific
     * types to PHP native types.
     *
     * NOTE: Please DO NOT perform "actual" field mapping here, because data
     * may be "aliased" from SQL persistences or mapped depending on persistence
     * driver.
     */
    public function typecastLoadRow(Model $m, array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) {
            // We have no knowledge of the field, it wasn't defined, so
            // we will leave it as-is.
            if (!$m->hasField($key)) {
                $result[$key] = $value;

                continue;
            }

            // Look up field object
            $f = $m->getField($key);

            // ignore null values
            if ($value === null) {
                $result[$key] = $value;

                continue;
            }

            // serialize if we explicitly want that
            if ($f->serialize) {
                $value = $this->serializeLoadField($f, $value);
            }

            // typecast if we explicitly want that or there is not serialization enabled
            if ($f->typecast || ($f->typecast === null && $f->serialize === null)) {
                $value = $this->typecastLoadField($f, $value);
            }

            // store converted value
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Prepare value of a specific field by converting it to
     * persistence-friendly format.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function typecastSaveField(Field $f, $value)
    {
        try {
            // use $f->typecast = [typecast_save_callback, typecast_load_callback]
            if (is_array($f->typecast) && isset($f->typecast[0]) && ($t = $f->typecast[0]) instanceof \Closure) {
                return $t($value, $f, $this);
            }

            // we respect null values
            if ($value === null) {
                return;
            }

            // run persistence-specific typecasting of field value
            return $this->_typecastSaveField($f, $value);
        } catch (\Exception $e) {
            throw (new Exception('Unable to typecast field value on save', 0, $e))
                ->addMoreInfo('field', $f->name);
        }
    }

    /**
     * Cast specific field value from the way how it's stored inside
     * persistence to a PHP format.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function typecastLoadField(Field $f, $value)
    {
        try {
            // use $f->typecast = [typecast_save_callback, typecast_load_callback]
            if (is_array($f->typecast) && isset($f->typecast[1]) && ($t = $f->typecast[1]) instanceof \Closure) {
                return $t($value, $f, $this);
            }

            // only string type fields can use empty string as legit value, for all
            // other field types empty value is the same as no-value, nothing or null
            if ($f->type && $f->type !== 'string' && $value === '') {
                return;
            }

            // we respect null values
            if ($value === null) {
                return;
            }

            // run persistence-specific typecasting of field value
            return $this->_typecastLoadField($f, $value);
        } catch (\Exception $e) {
            throw (new Exception('Unable to typecast field value on load', 0, $e))
                ->addMoreInfo('field', $f->name);
        }
    }

    /**
     * This is the actual field typecasting, which you can override in your
     * persistence to implement necessary typecasting.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function _typecastSaveField(Field $f, $value)
    {
        return $value;
    }

    /**
     * This is the actual field typecasting, which you can override in your
     * persistence to implement necessary typecasting.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function _typecastLoadField(Field $f, $value)
    {
        return $value;
    }

    /**
     * Provided with a value, will perform field serialization.
     * Can be used for the purposes of encryption or storing unsupported formats.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function serializeSaveField(Field $f, $value)
    {
        try {
            // use $f->serialize = [encode_callback, decode_callback]
            if (is_array($f->serialize) && isset($f->serialize[0]) && ($t = $f->typecast[0]) instanceof \Closure) {
                return $t($f, $value, $this);
            }

            // run persistence-specific serialization of field value
            return $this->_serializeSaveField($f, $value);
        } catch (\Exception $e) {
            throw (new Exception('Unable to serialize field value on save', 0, $e))
                ->addMoreInfo('field', $f->name);
        }
    }

    /**
     * Provided with a value, will perform field un-serialization.
     * Can be used for the purposes of encryption or storing unsupported formats.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function serializeLoadField(Field $f, $value)
    {
        try {
            // use $f->serialize = [encode_callback, decode_callback]
            if (is_array($f->serialize) && isset($f->serialize[1]) && ($t = $f->typecast[1]) instanceof \Closure) {
                return $t($f, $value, $this);
            }

            // run persistence-specific un-serialization of field value
            return $this->_serializeLoadField($f, $value);
        } catch (\Exception $e) {
            throw (new Exception('Unable to serialize field value on load', 0, $e))
                ->addMoreInfo('field', $f->name);
        }
    }

    /**
     * Override this to fine-tune serialization for your persistence.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function _serializeSaveField(Field $f, $value)
    {
        switch ($f->serialize === true ? 'serialize' : $f->serialize) {
        case 'serialize':
            return serialize($value);
        case 'json':
            return $this->jsonEncode($f, $value);
        case 'base64':
            if (!is_string($value)) {
                throw (new Exception('Field value can not be base64 encoded because it is not of string type'))
                    ->addMoreInfo('field', $f)
                    ->addMoreInfo('value', $value);
            }

            return base64_encode($value);
        }
    }

    /**
     * Override this to fine-tune un-serialization for your persistence.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function _serializeLoadField(Field $f, $value)
    {
        switch ($f->serialize === true ? 'serialize' : $f->serialize) {
        case 'serialize':
            return unserialize($value);
        case 'json':
            return $this->jsonDecode($f, $value, $f->type === 'array');
        case 'base64':
            return base64_decode($value, true);
        }
    }

    /**
     * JSON decoding with proper error treatment.
     *
     * @return mixed
     */
    public function jsonDecode(Field $f, string $json, bool $assoc = true)
    {
        return json_decode($json, $assoc, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * JSON encoding with proper error treatment.
     *
     * @param mixed $value
     */
    public function jsonEncode(Field $f, $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR, 512);
    }
}
