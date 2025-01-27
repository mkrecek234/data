<?php

declare(strict_types=1);

namespace Atk4\Data\Persistence\Sql;

use Atk4\Core\WarnDynamicPropertyTrait;
use Atk4\Data\Persistence;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Result as DbalResult;

/**
 * @phpstan-implements \ArrayAccess<int|string, mixed>
 */
class Expression implements Expressionable, \ArrayAccess
{
    use WarnDynamicPropertyTrait;

    /** @const string "[]" in template, escape as parameter */
    protected const ESCAPE_PARAM = 'param';
    /** @const string "{}" in template, escape as identifier */
    protected const ESCAPE_IDENTIFIER = 'identifier';
    /** @const string "{{}}" in template, escape as identifier, but keep input with special characters like "." or "(" unescaped */
    protected const ESCAPE_IDENTIFIER_SOFT = 'identifier-soft';
    /** @const string keep input as is */
    protected const ESCAPE_NONE = 'none';

    /** @var string */
    protected $template;

    /**
     * Configuration accumulated by calling methods such as Query::field(), Query::table(), etc.
     *
     * $args['custom'] is used to store hash of custom template replacements.
     *
     * This property is made public to ease customization and make it accessible
     * from Connection class for example.
     *
     * @var array<array<mixed>>
     */
    public $args = ['custom' => []];

    /**
     * As per PDO, escapeParam() will convert value into :a, :b, :c .. :aa .. etc.
     *
     * @var string
     */
    protected $paramBase = 'a';

    /**
     * Identifier (table, column, ...) escaping symbol. By SQL Standard it's double
     * quote, but MySQL uses backtick.
     *
     * @var string
     */
    protected $escape_char = '"';

    /** @var string|null */
    private $renderParamBase;
    /** @var array|null */
    private $renderParams;

    /** @var Connection|null */
    public $connection;

    /** @var bool Wrap the expression in parentheses when consumed by another expression or not. */
    public $wrapInParentheses = false;

    /**
     * Specifying options to constructors will override default
     * attribute values of this class.
     *
     * If $properties is passed as string, then it's treated as template.
     *
     * @param string|array $properties
     * @param array        $arguments
     */
    public function __construct($properties = [], $arguments = null)
    {
        // save template
        if (is_string($properties)) {
            $properties = ['template' => $properties];
        } elseif (!is_array($properties)) {
            throw (new Exception('Incorrect use of Expression constructor'))
                ->addMoreInfo('properties', $properties)
                ->addMoreInfo('arguments', $arguments);
        }

        // supports passing template as property value without key 'template'
        if (isset($properties[0])) {
            $properties['template'] = $properties[0];
            unset($properties[0]);
        }

        // save arguments
        if ($arguments !== null) {
            if (!is_array($arguments)) {
                throw (new Exception('Expression arguments must be an array'))
                    ->addMoreInfo('properties', $properties)
                    ->addMoreInfo('arguments', $arguments);
            }
            $this->args['custom'] = $arguments;
        }

        // deal with remaining properties
        foreach ($properties as $key => $val) {
            $this->{$key} = $val;
        }
    }

    /**
     * @return $this
     */
    public function getDsqlExpression(self $expression): self
    {
        return $this;
    }

    /**
     * Whether or not an offset exists.
     *
     * @param int|string $offset
     */
    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->args['custom']);
    }

    /**
     * Returns the value at specified offset.
     *
     * @param int|string $offset
     *
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->args['custom'][$offset];
    }

    /**
     * Assigns a value to the specified offset.
     *
     * @param int|string|null $offset
     * @param mixed           $value  The value to set
     */
    public function offsetSet($offset, $value): void
    {
        if ($offset === null) {
            $this->args['custom'][] = $value;
        } else {
            $this->args['custom'][$offset] = $value;
        }
    }

    /**
     * Unsets an offset.
     *
     * @param int|string $offset
     */
    public function offsetUnset($offset): void
    {
        unset($this->args['custom'][$offset]);
    }

    /**
     * Use this instead of "new Expression()" if you want to automatically bind
     * new expression to the same connection as the parent.
     *
     * @param string|array $properties
     * @param array        $arguments
     *
     * @return Expression
     */
    public function expr($properties = [], $arguments = null)
    {
        if ($this->connection !== null) {
            // TODO condition above always satisfied when connection is set - adjust tests,
            // so connection is always set and remove the code below
            return $this->connection->expr($properties, $arguments);
        }

        // make a smart guess :) when connection is not set
        if ($this instanceof Query) {
            $e = new self($properties, $arguments);
        } else {
            $e = new static($properties, $arguments);
        }

        $e->escape_char = $this->escape_char;

        return $e;
    }

    /**
     * Resets arguments.
     *
     * @return $this
     */
    public function reset(string $tag = null)
    {
        // unset all arguments
        if ($tag === null) {
            $this->args = ['custom' => []];

            return $this;
        }

        // unset custom/argument or argument if such exists
        if ($this->offsetExists($tag)) {
            $this->offsetUnset($tag);
        } elseif (isset($this->args[$tag])) {
            unset($this->args[$tag]);
        }

        return $this;
    }

    /**
     * Recursively renders sub-query or expression, combining parameters.
     *
     * @param string|Expressionable $expr
     * @param string                $escapeMode Fall-back escaping mode - using one of the Expression::ESCAPE_* constants
     *
     * @return string Quoted expression
     */
    protected function consume($expr, string $escapeMode = self::ESCAPE_PARAM)
    {
        if (!is_object($expr)) {
            switch ($escapeMode) {
                case self::ESCAPE_PARAM:
                    return $this->escapeParam($expr);
                case self::ESCAPE_IDENTIFIER:
                    return $this->escapeIdentifier($expr);
                case self::ESCAPE_IDENTIFIER_SOFT:
                    return $this->escapeIdentifierSoft($expr);
                case self::ESCAPE_NONE:
                    return $expr;
            }

            throw (new Exception('$escapeMode value is incorrect'))
                ->addMoreInfo('escapeMode', $escapeMode);
        }

        if ($expr instanceof Expressionable) {
            $expr = $expr->getDsqlExpression($this);
        }

        if (!$expr instanceof self) {
            throw (new Exception('Only Expressionable object can be used in Expression'))
                ->addMoreInfo('object', $expr);
        }

        // render given expression into params of the current expression
        $expressionParamBaseBackup = $expr->paramBase;
        try {
            $expr->paramBase = $this->renderParamBase;
            [$sql, $params] = $expr->render();
            foreach ($params as $k => $v) {
                $this->renderParams[$k] = $v;
                do {
                    ++$this->renderParamBase;
                } while ($this->renderParamBase === $k);
            }
        } finally {
            $expr->paramBase = $expressionParamBaseBackup;
        }

        // wrap in parentheses if expression requires so
        if ($expr->wrapInParentheses === true) {
            $sql = '(' . $sql . ')';
        }

        return $sql;
    }

    /**
     * Creates new expression where $value appears escaped. Use this
     * method as a conventional means of specifying arguments when you
     * think they might have a nasty back-ticks or commas in the field
     * names.
     *
     * @param string $value
     *
     * @return Expression
     */
    public function escape($value)
    {
        return $this->expr('{}', [$value]);
    }

    /**
     * Converts value into parameter and returns reference. Use only during
     * query rendering. Consider using `consume()` instead, which will
     * also handle nested expressions properly.
     *
     * @param string|int|float $value
     *
     * @return string Name of parameter
     */
    protected function escapeParam($value): string
    {
        $name = ':' . $this->renderParamBase;
        ++$this->renderParamBase;
        $this->renderParams[$name] = $value;

        return $name;
    }

    /**
     * Escapes argument by adding backticks around it.
     * This will allow you to use reserved SQL words as table or field
     * names such as "table" as well as other characters that SQL
     * permits in the identifiers (e.g. spaces or equation signs).
     */
    protected function escapeIdentifier(string $value): string
    {
        // in all other cases we should escape
        $c = $this->escape_char;

        return $c . str_replace($c, $c . $c, $value) . $c;
    }

    /**
     * Soft-escaping SQL identifier. This method will attempt to put
     * escaping char around the identifier, however will not do so if you
     * are using special characters like ".", "(" or escaping char.
     *
     * It will smartly escape table.field type of strings resulting
     * in "table"."field".
     */
    protected function escapeIdentifierSoft(string $value): string
    {
        // in some cases we should not escape
        if ($this->isUnescapablePattern($value)) {
            return $value;
        }

        if (strpos($value, '.') !== false) {
            return implode('.', array_map(__METHOD__, explode('.', $value)));
        }

        return $this->escape_char . trim($value) . $this->escape_char;
    }

    /**
     * Given the string parameter, it will detect some "deal-breaker" for our
     * soft escaping, such as "*" or "(".
     * Those will typically indicate that expression is passed and shouldn't
     * be escaped.
     *
     * @param self|string $value
     */
    protected function isUnescapablePattern($value): bool
    {
        return is_object($value)
        || $value === '*'
                || strpos($value, '(') !== false
                || strpos($value, $this->escape_char) !== false;
    }

    private function _render(): array
    {
        // - [xxx] = param
        // - {xxx} = escape
        // - {{xxx}} = escapeSoft
        $nameless_count = 0;
        $res = preg_replace_callback(
            <<<'EOF'
                ~
                 '(?:[^'\\]+|\\.|'')*'\K
                |"(?:[^"\\]+|\\.|"")*"\K
                |`(?:[^`\\]+|\\.|``)*`\K
                |\[\w*\]
                |\{\w*\}
                |\{\{\w*\}\}
                ~xs
                EOF,
            function ($matches) use (&$nameless_count) {
                if ($matches[0] === '') {
                    return '';
                }

                $identifier = substr($matches[0], 1, -1);

                $escaping = null;
                if (substr($matches[0], 0, 1) === '[') {
                    $escaping = self::ESCAPE_PARAM;
                } elseif (substr($matches[0], 0, 1) === '{') {
                    if (substr($matches[0], 1, 1) === '{') {
                        $escaping = self::ESCAPE_IDENTIFIER_SOFT;
                        $identifier = substr($identifier, 1, -1);
                    } else {
                        $escaping = self::ESCAPE_IDENTIFIER;
                    }
                }

                // allow template to contain []
                if ($identifier === '') {
                    $identifier = $nameless_count++;

                    // use rendering only with named tags
                }
                $fx = '_render_' . $identifier;

                if (array_key_exists($identifier, $this->args['custom'])) {
                    $value = $this->consume($this->args['custom'][$identifier], $escaping);
                } elseif (method_exists($this, $fx)) {
                    $value = $this->{$fx}();
                } else {
                    throw (new Exception('Expression could not render tag'))
                        ->addMoreInfo('tag', $identifier);
                }

                return $value;
            },
            $this->template
        );

        return [trim($res), $this->renderParams];
    }

    /**
     * Render expression to an array with SQL string and its params.
     *
     * @return array{string, array<string, mixed>}
     */
    public function render(): array
    {
        if ($this->template === null) {
            throw new Exception('Template is not defined for Expression');
        }

        try {
            $this->renderParamBase = $this->paramBase;
            $this->renderParams = [];

            return $this->_render();
        } finally {
            $this->renderParamBase = null;
            $this->renderParams = null;
        }
    }

    /**
     * Return formatted debug SQL query.
     */
    public function getDebugQuery(): string
    {
        [$result, $params] = $this->render();

        foreach (array_reverse($params) as $key => $val) {
            if ($val === null) {
                $replacement = 'NULL';
            } elseif (is_bool($val)) {
                $replacement = $val ? '1' : '0';
            } elseif (is_int($val) || is_float($val)) {
                $replacement = (string) $val;
            } elseif (is_string($val)) {
                $replacement = '\'' . addslashes($val) . '\'';
            } else {
                continue;
            }

            $result = preg_replace('~' . $key . '(?!\w)~', $replacement, $result);
        }

        if (class_exists('SqlFormatter')) { // requires optional "jdorn/sql-formatter" package
            $result = \SqlFormatter::format($result, false);
        }

        return $result;
    }

    public function __debugInfo(): array
    {
        $arr = [
            'R' => 'n/a',
            'R_params' => 'n/a',
            'template' => $this->template,
            'templateArgs' => $this->args,
        ];

        try {
            $arr['R'] = $this->getDebugQuery();
            $arr['R_params'] = $this->render()[1];
        } catch (\Exception $e) {
            $arr['R'] = get_class($e) . ': ' . $e->getMessage();
        }

        return $arr;
    }

    /**
     * @param DbalConnection|Connection $connection
     *
     * @return DbalResult|\PDOStatement PDOStatement iff for DBAL 2.x
     */
    public function execute(object $connection = null): object
    {
        if ($connection === null) {
            $connection = $this->connection;
        }

        // If it's a DBAL connection, we're cool
        if ($connection instanceof DbalConnection) {
            [$query, $params] = $this->render();

            $platform = $this->connection->getDatabasePlatform();
            try {
                $statement = $connection->prepare($query);

                foreach ($params as $key => $val) {
                    if (is_int($val)) {
                        $type = ParameterType::INTEGER;
                    } elseif (is_bool($val)) {
                        if ($platform instanceof PostgreSQLPlatform) {
                            $type = ParameterType::STRING;
                            $val = $val ? '1' : '0';
                        } else {
                            $type = ParameterType::INTEGER;
                            $val = $val ? 1 : 0;
                        }
                    } elseif ($val === null) {
                        $type = ParameterType::NULL;
                    } elseif (is_float($val)) {
                        $type = ParameterType::STRING;
                    } elseif (is_string($val)) {
                        $type = ParameterType::STRING;

                        if ($platform instanceof PostgreSQLPlatform
                            || $platform instanceof SQLServerPlatform) {
                            $dummyPersistence = new Persistence\Sql($this->connection);
                            if (\Closure::bind(fn () => $dummyPersistence->binaryTypeValueIsEncoded($val), null, Persistence\Sql::class)()) {
                                $val = \Closure::bind(fn () => $dummyPersistence->binaryTypeValueDecode($val), null, Persistence\Sql::class)();
                                $type = ParameterType::BINARY;
                            }
                        }
                    } elseif (is_resource($val)) {
                        throw new Exception('Resource type is not supported, set value as string instead');
                    } else {
                        throw (new Exception('Incorrect param type'))
                            ->addMoreInfo('key', $key)
                            ->addMoreInfo('value', $val)
                            ->addMoreInfo('type', gettype($val));
                    }

                    if (is_string($val) && $platform instanceof OraclePlatform && strlen($val) > 2000) {
                        $valRef = $val;
                        $bind = $statement->bindParam($key, $valRef, ParameterType::STRING, strlen($val));
                        unset($valRef);
                    } else {
                        $bind = $statement->bindValue($key, $val, $type);
                    }
                    if ($bind === false) {
                        throw (new Exception('Unable to bind parameter'))
                            ->addMoreInfo('param', $key)
                            ->addMoreInfo('value', $val)
                            ->addMoreInfo('type', $type);
                    }
                }

                $result = $statement->execute(); // @phpstan-ignore-line
                if (Connection::isComposerDbal2x()) {
                    return $statement; // @phpstan-ignore-line
                }

                return $result;
            } catch (DbalException $e) {
                $firstException = $e;
                while ($firstException->getPrevious() !== null) {
                    $firstException = $firstException->getPrevious();
                }
                $errorInfo = $firstException instanceof \PDOException ? $firstException->errorInfo : null;

                $new = (new ExecuteException('Dsql execute error', $errorInfo[1] ?? 0, $e))
                    ->addMoreInfo('error', $errorInfo[2] ?? 'n/a (' . $errorInfo[0] . ')')
                    ->addMoreInfo('query', $this->getDebugQuery());

                throw $new;
            }
        }

        return $connection->execute($this);
    }

    // {{{ Result Querying

    /**
     * @param string|int|float|bool|null $v
     */
    private function castGetValue($v): ?string
    {
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        } elseif (is_bool($v)) {
            return $v ? '1' : '0';
        }

        // for PostgreSQL/Oracle CLOB/BLOB datatypes and PDO driver
        if (is_resource($v) && get_resource_type($v) === 'stream' && (
            $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            || $this->connection->getDatabasePlatform() instanceof OraclePlatform
        )) {
            $v = stream_get_contents($v);
        }

        return $v; // throw a type error if not null nor string
    }

    /**
     * @return \Traversable<array<mixed>>
     */
    public function getRowsIterator(): \Traversable
    {
        // DbalResult::iterateAssociative() is broken with streams with Oracle database
        // https://github.com/doctrine/dbal/issues/5002
        if (Connection::isComposerDbal2x()) {
            $iterator = $this->execute();
        } else {
            $iterator = $this->execute()->iterateAssociative();
        }

        foreach ($iterator as $row) {
            yield array_map(function ($v) {
                return $this->castGetValue($v);
            }, $row);
        }
    }

    /**
     * Executes expression and return whole result-set in form of array of hashes.
     *
     * @return string[][]|null[][]
     */
    public function getRows(): array
    {
        // DbalResult::fetchAllAssociative() is broken with streams with Oracle database
        // https://github.com/doctrine/dbal/issues/5002
        if (Connection::isComposerDbal2x()) {
            $result = $this->execute();
        } else {
            $result = $this->execute();
        }

        $rows = [];
        while (($row = Connection::isComposerDbal2x() ? $result->fetchAssociative() : $result->fetch()) !== false) {
            $rows[] = array_map(function ($v) {
                return $this->castGetValue($v);
            }, $row);
        }

        return $rows;
    }

    /**
     * Executes expression and returns first row of data from result-set as a hash.
     *
     * @return string[]|null[]|null
     */
    public function getRow(): ?array
    {
        if (Connection::isComposerDbal2x()) {
            $row = $this->execute()->fetch();
        } else {
            $row = $this->execute()->fetchAssociative();
        }

        if ($row === false) {
            return null;
        }

        return array_map(function ($v) {
            return $this->castGetValue($v);
        }, $row);
    }

    /**
     * Executes expression and return first value of first row of data from result-set.
     */
    public function getOne(): ?string
    {
        $row = $this->getRow();
        if ($row === null || count($row) === 0) {
            throw (new Exception('Unable to fetch single cell of data for getOne from this query'))
                ->addMoreInfo('result', $row)
                ->addMoreInfo('query', $this->getDebugQuery());
        }

        return reset($row);
    }

    // }}}
}
