<?php

declare(strict_types=1);

namespace Atk4\Data\Tests\Persistence;

use Atk4\Data\Exception;
use Atk4\Data\Model;
use Atk4\Data\Persistence\Sql\Expression;
use Atk4\Data\Schema\TestCase;

class SqlTest extends TestCase
{
    public function testLoadArray(): void
    {
        $this->setDb([
            'user' => [
                1 => ['name' => 'John', 'surname' => 'Smith'],
                2 => ['name' => 'Sarah', 'surname' => 'Jones'],
            ],
        ]);

        $m = new Model($this->db, ['table' => 'user']);
        $m->addField('name');
        $m->addField('surname');

        $mm = $m->load(1);
        $this->assertSame('John', $mm->get('name'));

        $mm = $m->load(2);
        $this->assertSame('Jones', $mm->get('surname'));
        $mm->set('surname', 'Smith');
        $mm->save();

        $mm = $m->load(1);
        $this->assertSame('John', $mm->get('name'));

        $mm = $m->load(2);
        $this->assertSame('Smith', $mm->get('surname'));
    }

    public function testModelLoadOneAndAny(): void
    {
        $this->setDb([
            'user' => [
                1 => ['name' => 'John', 'surname' => 'Smith'],
                2 => ['name' => 'Sarah', 'surname' => 'Jones'],
            ],
        ]);

        $m = new Model($this->db, ['table' => 'user']);
        $m->addField('name');
        $m->addField('surname');

        $mm = (clone $m)->addCondition($m->id_field, 1);
        $this->assertSame('John', $mm->load(1)->get('name'));
        $this->assertNull($mm->tryLoad(2)->get('name'));
        $this->assertSame('John', $mm->tryLoadOne()->get('name'));
        $this->assertSame('John', $mm->loadOne()->get('name'));
        $this->assertSame('John', $mm->tryLoadAny()->get('name'));
        $this->assertSame('John', $mm->loadAny()->get('name'));

        $mm = (clone $m)->addCondition('surname', 'Jones');
        $this->assertSame('Sarah', $mm->load(2)->get('name'));
        $this->assertNull($mm->tryLoad(1)->get('name'));
        $this->assertSame('Sarah', $mm->tryLoadOne()->get('name'));
        $this->assertSame('Sarah', $mm->loadOne()->get('name'));
        $this->assertSame('Sarah', $mm->tryLoadAny()->get('name'));
        $this->assertSame('Sarah', $mm->loadAny()->get('name'));

        $m->loadAny();
        $m->tryLoadAny();
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Ambiguous conditions, more than one record can be loaded.');
        $m->tryLoadOne();
    }

    public function testPersistenceInsert(): void
    {
        $dbData = [
            'user' => [
                1 => ['name' => 'John', 'surname' => 'Smith'],
                2 => ['name' => 'Sarah', 'surname' => 'Jones'],
            ],
        ];

        $this->setDb($dbData);

        $m = new Model($this->db, ['table' => 'user']);
        $m->addField('name');
        $m->addField('surname');

        $ids = [];
        foreach ($dbData['user'] as $id => $row) {
            $ids[] = $this->db->insert($m, $row);
        }

        $mm = $m->load($ids[0]);
        $this->assertSame('John', $mm->get('name'));

        $mm = $m->load($ids[1]);
        $this->assertSame('Jones', $mm->get('surname'));
        $mm->set('surname', 'Smith');
        $mm->save();

        $mm = $m->load($ids[0]);
        $this->assertSame('John', $mm->get('name'));

        $mm = $m->load($ids[1]);
        $this->assertSame('Smith', $mm->get('surname'));
    }

    public function testModelInsert(): void
    {
        $dbData = [
            'user' => [
                1 => ['name' => 'John', 'surname' => 'Smith'],
                2 => ['name' => 'Sarah', 'surname' => 'Jones'],
            ],
        ];
        $this->setDb($dbData);

        $m = new Model($this->db, ['table' => 'user']);
        $m->addField('name');
        $m->addField('surname');

        $ms = [];
        foreach ($dbData['user'] as $id => $row) {
            $ms[] = $m->insert($row);
        }

        $this->assertSame('John', $m->load($ms[0])->get('name'));

        $this->assertSame('Jones', $m->load($ms[1])->get('surname'));
    }

    public function testModelSaveNoReload(): void
    {
        $this->setDb([
            'user' => [
                1 => ['name' => 'John', 'surname' => 'Smith'],
                2 => ['name' => 'Sarah', 'surname' => 'Jones'],
            ],
        ]);

        $m = new Model($this->db, ['table' => 'user']);
        $m->addField('name');
        $m->addField('surname');

        // insert new record, model id field
        $m->reload_after_save = false;
        $m = $m->createEntity();
        $m->save(['name' => 'Jane', 'surname' => 'Doe']);
        $this->assertSame('Jane', $m->get('name'));
        $this->assertSame('Doe', $m->get('surname'));
        $this->assertEquals(3, $m->getId());
        // id field value is set with new id value even if reload_after_save = false
        $this->assertEquals(3, $m->getId());
    }

    public function testModelInsertRows(): void
    {
        $dbData = [
            'user' => [
                1 => ['name' => 'John', 'surname' => 'Smith'],
                2 => ['name' => 'Sarah', 'surname' => 'Jones'],
            ],
        ];
        $this->setDb($dbData, false); // create empty table

        $m = new Model($this->db, ['table' => 'user']);
        $m->addField('name');
        $m->addField('surname');

        $this->assertEquals(0, $m->action('exists')->getOne());

        $m->import($dbData['user']); // import data

        $this->assertEquals(1, $m->action('exists')->getOne());

        $this->assertEquals(2, $m->action('count')->getOne());
    }

    public function testPersistenceDelete(): void
    {
        $dbData = [
            'user' => [
                1 => ['name' => 'John', 'surname' => 'Smith'],
                2 => ['name' => 'Sarah', 'surname' => 'Jones'],
            ],
        ];
        $this->setDb($dbData);

        $m = new Model($this->db, ['table' => 'user']);
        $m->addField('name');
        $m->addField('surname');

        $ids = [];
        foreach ($dbData['user'] as $id => $row) {
            $ids[] = $this->db->insert($m, $row);
        }

        $m->delete($ids[0]);

        $m2 = $m->load($ids[1]);
        $this->assertSame('Jones', $m2->get('surname'));
        $m2->set('surname', 'Smith');
        $m2->save();

        $m2 = $m->tryLoad($ids[0]);
        $this->assertFalse($m2->isLoaded());

        $m2 = $m->load($ids[1]);
        $this->assertSame('Smith', $m2->get('surname'));
    }

    public function testExport(): void
    {
        $this->setDb([
            'user' => [
                1 => ['name' => 'John', 'surname' => 'Smith'],
                2 => ['name' => 'Sarah', 'surname' => 'Jones'],
            ],
        ]);

        $m = new Model($this->db, ['table' => 'user']);
        $m->addField('name');
        $m->addField('surname');

        $this->assertEquals([
            ['id' => 1, 'name' => 'John', 'surname' => 'Smith'],
            ['id' => 2, 'name' => 'Sarah', 'surname' => 'Jones'],
        ], $m->export());

        $this->assertSame([
            ['surname' => 'Smith'],
            ['surname' => 'Jones'],
        ], $m->export(['surname']));
    }

    public function testSameRowFieldStability(): void
    {
        $this->setDb([
            'user' => [
                1 => ['name' => 'John', 'surname' => 'Smith'],
                2 => ['name' => 'Sarah', 'surname' => 'Jones'],
            ],
        ]);

        if ($this->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQLPlatform) {
            $randSqlFunc = 'rand()';
        } elseif ($this->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLServerPlatform) {
            $randSqlFunc = 'checksum(newid())';
        } elseif ($this->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\OraclePlatform) {
            $randSqlFunc = 'dbms_random.random';
        } else {
            $randSqlFunc = 'random()';
        }

        $m = new Model($this->db, ['table' => 'user']);
        $m->addField('name');
        $m->addField('surname');
        $m->addExpression('rand', $randSqlFunc);
        $m->addExpression('rand_independent', $randSqlFunc);
        $m->scope()->addCondition('rand', '!=', null);
        $m->setOrder('rand');
        $m->addExpression('rand2', new Expression('([] + 1) - 1', [$m->getField('rand')]));
        $createSeedForSelfHasOne = function (Model $model, string $alias, $joinByFieldName) {
            return ['model' => $model, 'table_alias' => $alias, 'our_field' => $joinByFieldName, 'their_field' => $joinByFieldName];
        };
//        $m->hasOne('one', $createSeedForSelfHasOne($m, 'one', 'name'))
//            ->addField('rand3', 'rand2');
//        $m->hasOne('one_one', $createSeedForSelfHasOne($m->ref('one'), 'one_one', 'surname'))
//            ->addField('rand4', 'rand3');
//        $manyModel = $m/*->ref('one')*/; // TODO MySQL Subquery returns more than 1 row
//        $manyModel->addExpression('rand_many', ['expr' => $manyModel->getField('rand3')]);
//        $m->hasMany('many_one', ['model' => $manyModel, 'our_field' => 'name', 'their_field' => 'name']);
//        $m->hasOne('one_many_one', $createSeedForSelfHasOne($m->ref('many_one'), 'one_many_one', 'surname'))
//            ->addField('rand5', 'rand_many');

        $this->debug = true; // TODO

        $export = $m->export();
        $this->assertSame([0, 1], array_keys($export));
        $randRow0 = $export[0]['rand'];
        $randRow1 = $export[1]['rand'];
        $this->assertNotSame($randRow0, $randRow1); // $this->assertGreaterThan($randRow0, $randRow1);
        // TODO this can be the same, depending on how we implement it
        // already stable under some circumstances on PostgreSQL http://sqlfiddle.com/#!17/4b040/4
        // $this->assertNotSame($randRow0, $export[0]['rand_independent']);

        $this->assertSame($randRow0, $export[0]['rand2']);
        $this->assertSame($randRow1, $export[1]['rand2']);
//        $this->assertSame($randRow0, $export[0]['rand3']);
//        $this->assertSame($randRow1, $export[1]['rand3']);
//        $this->assertSame($randRow0, $export[0]['rand4']);
//        $this->assertSame($randRow1, $export[1]['rand4']);
//        $this->assertSame($randRow0, $export[0]['rand5']);
//        $this->assertSame($randRow1, $export[1]['rand5']);

        // TODO test with hasOne group by
    }
}
