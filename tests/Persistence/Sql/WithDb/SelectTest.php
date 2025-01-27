<?php

declare(strict_types=1);

namespace Atk4\Data\Tests\Persistence\Sql\WithDb;

use Atk4\Data\Model;
use Atk4\Data\Persistence\Sql\Connection;
use Atk4\Data\Persistence\Sql\Exception;
use Atk4\Data\Persistence\Sql\ExecuteException;
use Atk4\Data\Persistence\Sql\Expression;
use Atk4\Data\Persistence\Sql\Query;
use Atk4\Data\Schema\TestCase;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;

class SelectTest extends TestCase
{
    /** @var Connection */
    protected $c;

    protected function setUp(): void
    {
        parent::setUp();

        $this->c = $this->db->connection;

        $model = new Model($this->db, ['table' => 'employee']);
        $model->addField('name');
        $model->addField('surname');
        $model->addField('retired', ['type' => 'boolean']);

        $this->createMigrator($model)->create();

        $model->import([
            ['id' => 1, 'name' => 'Oliver', 'surname' => 'Smith', 'retired' => false],
            ['id' => 2, 'name' => 'Jack', 'surname' => 'Williams', 'retired' => true],
            ['id' => 3, 'name' => 'Harry', 'surname' => 'Taylor', 'retired' => true],
            ['id' => 4, 'name' => 'Charlie', 'surname' => 'Lee', 'retired' => false],
        ]);
    }

    /**
     * @param mixed  $table
     * @param string $alias
     */
    private function q($table = null, string $alias = null): Query
    {
        $q = $this->c->dsql();

        // add table to query if specified
        if ($table !== null) {
            $q->table($table, $alias);
        }

        return $q;
    }

    /**
     * @param string|array $template
     * @param array        $args
     */
    private function e($template = [], array $args = null): Expression
    {
        return $this->c->expr($template, $args);
    }

    public function testBasicQueries(): void
    {
        $this->assertSame(4, count($this->q('employee')->getRows()));

        $this->assertSame(
            ['name' => 'Oliver', 'surname' => 'Smith'],
            $this->q('employee')->field('name')->field('surname')->getRow()
        );

        $this->assertSame(
            ['surname' => 'Williams'],
            $this->q('employee')->field('surname')->where('retired', true)->getRow()
        );

        $this->assertSame(
            '4',
            $this->q()->field(new Expression('2+2'))->getOne()
        );

        $this->assertSame(
            '4',
            $this->q('employee')->field(new Expression('count(*)'))->getOne()
        );

        $names = [];
        foreach ($this->q('employee')->where('retired', false)->getRowsIterator() as $row) {
            $names[] = $row['name'];
        }

        $this->assertSame(
            ['Oliver', 'Charlie'],
            $names
        );

        $this->assertSame(
            [['now' => '4']],
            $this->q()->field(new Expression('2+2'), 'now')->getRows()
        );

        /*
         * PostgreSQL needs to have values cast, to make the query work.
         * But CAST(.. AS int) does not work in mysql. So we use two different tests..
         * (CAST(.. AS int) will work on mariaDB, whereas mysql needs it to be CAST(.. AS signed))
         */
        if ($this->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->assertSame(
                [['now' => '6']],
                $this->q()->field(new Expression('CAST([] AS int)+CAST([] AS int)', [3, 3]), 'now')->getRows()
            );
        } else {
            $this->assertSame(
                [['now' => '6']],
                $this->q()->field(new Expression('[]+[]', [3, 3]), 'now')->getRows()
            );
        }

        $this->assertSame(
            '5',
            $this->q()->field(new Expression('COALESCE([], \'5\')', [null]), 'null_test')->getOne()
        );
    }

    public function testExpression(): void
    {
        /*
         * PostgreSQL, at least versions before 10, needs to have the string cast to the
         * correct datatype.
         * But using CAST(.. AS CHAR) will return one single character on postgresql, but the
         * entire string on mysql.
         */
        if ($this->getDatabasePlatform() instanceof PostgreSQLPlatform || $this->getDatabasePlatform() instanceof SQLServerPlatform) {
            $this->assertSame(
                'foo',
                $this->e('select CAST([] AS VARCHAR)', ['foo'])->getOne()
            );
        } elseif ($this->getDatabasePlatform() instanceof OraclePlatform) {
            $this->assertSame(
                'foo',
                $this->e('select CAST([] AS VARCHAR2(100)) FROM DUAL', ['foo'])->getOne()
            );
        } else {
            $this->assertSame(
                'foo',
                $this->e('select CAST([] AS CHAR)', ['foo'])->getOne()
            );
        }
    }

    public function testOtherQueries(): void
    {
        // truncate table
        $this->q('employee')->truncate();
        $this->assertSame(
            '0',
            $this->q('employee')->field(new Expression('count(*)'))->getOne()
        );

        // insert
        $this->q('employee')
            ->setMulti(['id' => 1, 'name' => 'John', 'surname' => 'Doe', 'retired' => true])
            ->insert();
        $this->q('employee')
            ->setMulti(['id' => 2, 'name' => 'Jane', 'surname' => 'Doe', 'retired' => false])
            ->insert();
        $this->assertSame(
            [['id' => '1', 'name' => 'John'], ['id' => '2', 'name' => 'Jane']],
            $this->q('employee')->field('id')->field('name')->order('id')->getRows()
        );

        // update
        $this->q('employee')
            ->where('name', 'John')
            ->set('name', 'Johnny')
            ->update();
        $this->assertSame(
            [['id' => '1', 'name' => 'Johnny'], ['id' => '2', 'name' => 'Jane']],
            $this->q('employee')->field('id')->field('name')->order('id')->getRows()
        );

        // replace
        if ($this->getDatabasePlatform() instanceof PostgreSQLPlatform || $this->getDatabasePlatform() instanceof SQLServerPlatform || $this->getDatabasePlatform() instanceof OraclePlatform) {
            $this->q('employee')
                ->setMulti(['name' => 'Peter', 'surname' => 'Doe', 'retired' => true])
                ->where('id', 1)
                ->update();
        } else {
            $this->q('employee')
                ->setMulti(['id' => 1, 'name' => 'Peter', 'surname' => 'Doe', 'retired' => true])
                ->replace();
        }

        // In SQLite replace is just like insert, it just checks if there is
        // duplicate key and if it is it deletes the row, and inserts the new
        // one, otherwise it just inserts.
        // So order of records after REPLACE in SQLite will be [Jane, Peter]
        // not [Peter, Jane] as in MySQL, which in theory does the same thing,
        // but returns [Peter, Jane] - in original order.
        // That's why we add usort here.
        $data = $this->q('employee')->field('id')->field('name')->getRows();
        usort($data, function ($a, $b) {
            return $a['id'] - $b['id']; // @phpstan-ignore-line
        });
        $this->assertSame(
            [['id' => '1', 'name' => 'Peter'], ['id' => '2', 'name' => 'Jane']],
            $data
        );

        // delete
        $this->q('employee')
            ->where('retired', true)
            ->delete();
        $this->assertSame(
            [['id' => '2', 'name' => 'Jane']],
            $this->q('employee')->field('id')->field('name')->getRows()
        );
    }

    public function testEmptyGetOne(): void
    {
        // truncate table
        $this->q('employee')->truncate();
        $this->expectException(Exception::class);
        $this->q('employee')->field('name')->getOne();
    }

    public function testWhereExpression(): void
    {
        $this->assertSame(
            [['id' => '2', 'name' => 'Jack', 'surname' => 'Williams', 'retired' => '1']],
            $this->q('employee')->where('retired', true)->where($this->q()->expr('{}=[] or {}=[]', ['surname', 'Williams', 'surname', 'Smith']))->getRows()
        );
    }

    public function testExecuteException(): void
    {
        $this->expectException(ExecuteException::class);

        try {
            $this->q('non_existing_table')->field('non_existing_field')->getOne();
        } catch (ExecuteException $e) {
            if ($this->getDatabasePlatform() instanceof MySQLPlatform) {
                $expectedErrorCode = 1146; // SQLSTATE[42S02]: Base table or view not found: 1146 Table 'non_existing_table' doesn't exist
            } elseif ($this->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $expectedErrorCode = 7; // SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "non_existing_table" does not exist
            } elseif ($this->getDatabasePlatform() instanceof SQLServerPlatform) {
                $expectedErrorCode = 208; // SQLSTATE[42S02]: Invalid object name 'non_existing_table'
            } elseif ($this->getDatabasePlatform() instanceof OraclePlatform) {
                $expectedErrorCode = 942; // SQLSTATE[HY000]: ORA-00942: table or view does not exist
            } else {
                $expectedErrorCode = 1; // SQLSTATE[HY000]: General error: 1 no such table: non_existing_table
            }

            $this->assertSame($expectedErrorCode, $e->getCode());
            $this->assertSameSql(
                preg_replace('~\s+~', '', 'select "non_existing_field" from "non_existing_table"'),
                preg_replace('~\s+~', '', $e->getDebugQuery())
            );

            throw $e;
        }
    }

    public function testUtf8mb4Support(): void
    {
        // MariaDB has no support of utf8mb4 identifiers
        // remove once https://jira.mariadb.org/browse/MDEV-27050 is fixed
        $columnAlias = '❤';
        $tableAlias = '🚀';
        if (str_contains($_ENV['DB_DSN'], 'mariadb')) {
            $columnAlias = '仮';
            $tableAlias = '名';
        }

        $this->assertSame(
            [$columnAlias => 'žlutý_😀'],
            $this->q(
                $this->q()->field($this->e('\'žlutý_😀\''), $columnAlias),
                $tableAlias
            )
                ->where($columnAlias, 'žlutý_😀') // as param
                ->group($tableAlias . '.' . $columnAlias)
                ->having($this->e('{}', [$columnAlias])->render()[0] . ' = \'žlutý_😀\'') // as string literal (mapped to N'xxx' with MSSQL platform)
                ->getRow()
        );
    }

    public function testImportAndAutoincrement(): void
    {
        $m = new Model($this->db, ['table' => 'test']);
        $m->getField('id')->actual = 'myid';
        $m->setOrder('id');
        $m->addField('f1');
        $this->createMigrator($m)->create();

        $getLastAiFx = function (): int {
            $table = 'test';
            $pk = 'myid';
            $maxIdExpr = $this->c->dsql()->table($table)->field($this->c->expr('max({})', [$pk]));
            if ($this->getDatabasePlatform() instanceof MySQLPlatform) {
                $query = $this->c->dsql()->table('INFORMATION_SCHEMA.TABLES')
                    ->field($this->c->expr('greatest({} - 1, (' . $maxIdExpr->render()[0] . '))', ['AUTO_INCREMENT']))
                    ->where('TABLE_NAME', $table);
            } elseif ($this->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $query = $this->c->dsql()->field($this->c->expr('currval(pg_get_serial_sequence([], []))', [$table, $pk]));
            } elseif ($this->getDatabasePlatform() instanceof SQLServerPlatform) {
                $query = $this->c->dsql()->field($this->c->expr('IDENT_CURRENT([])', [$table]));
            } elseif ($this->getDatabasePlatform() instanceof OraclePlatform) {
                $query = $this->c->dsql()->field($this->c->expr('{}.CURRVAL', [$table . '_SEQ']));
            } else {
                $query = $this->c->dsql()->table('sqlite_sequence')->field('seq')->where('name', $table);
            }

            return (int) $query->getOne();
        };

        $m->import([
            ['id' => 1, 'f1' => 'A'],
            ['id' => 2, 'f1' => 'B'],
        ]);
        $this->assertSame('2', $m->action('count')->getOne());
        $this->assertSame(2, $getLastAiFx());

        $m->import([
            ['f1' => 'C'],
            ['f1' => 'D'],
        ]);
        $this->assertSame('4', $m->action('count')->getOne());
        $this->assertSame(4, $getLastAiFx());

        $m->import([
            ['id' => 6, 'f1' => 'E'],
            ['id' => 7, 'f1' => 'F'],
        ]);
        $this->assertSame('6', $m->action('count')->getOne());
        $this->assertSame(7, $getLastAiFx());

        $m->delete(6);
        $this->assertSame('5', $m->action('count')->getOne());
        $this->assertSame(7, $getLastAiFx());

        $m->import([
            ['f1' => 'G'],
            ['f1' => 'H'],
        ]);
        $this->assertSame('7', $m->action('count')->getOne());
        $this->assertSame(9, $getLastAiFx());

        $m->import([
            ['id' => 99, 'f1' => 'I'],
            ['id' => 20, 'f1' => 'J'],
        ]);
        $this->assertSame('9', $m->action('count')->getOne());
        $this->assertSame(99, $getLastAiFx());

        $m->import([
            ['f1' => 'K'],
            ['f1' => 'L'],
        ]);
        $this->assertSame('11', $m->action('count')->getOne());
        $this->assertSame(101, $getLastAiFx());

        $m->delete(100);
        $m->createEntity()->set('f1', 'M')->save();
        $this->assertSame(102, $getLastAiFx());

        $this->assertSame([
            ['id' => 1, 'f1' => 'A'],
            ['id' => 2, 'f1' => 'B'],
            ['id' => 3, 'f1' => 'C'],
            ['id' => 4, 'f1' => 'D'],
            ['id' => 7, 'f1' => 'F'],
            ['id' => 8, 'f1' => 'G'],
            ['id' => 9, 'f1' => 'H'],
            ['id' => 20, 'f1' => 'J'],
            ['id' => 99, 'f1' => 'I'],
            ['id' => 101, 'f1' => 'L'],
            ['id' => 102, 'f1' => 'M'],
        ], $m->export());
    }
}
