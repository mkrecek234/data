<?php

declare(strict_types=1);

namespace atk4\data\tests;

use atk4\data\Model;
use atk4\data\Model\Scope\CompoundCondition;
use atk4\data\Model\Scope\Condition;
use atk4\dsql\Expression;

class SCountry extends Model
{
    public $table = 'country';

    public $caption = 'Country';

    public function init(): void
    {
        parent::init();

        $this->addField('name');
        $this->addField('code');

        $this->addField('is_eu', ['type' => 'boolean', 'default' => false]);

        $this->hasMany('Users', new SUser())
            ->addField('user_names', ['field' => 'name', 'concat' => ',']);
    }
}

class SUser extends Model
{
    public $table = 'user';

    public $caption = 'User';

    public function init(): void
    {
        parent::init();

        $this->addField('name');
        $this->addField('surname');
        $this->addField('is_vip', ['type' => 'boolean', 'default' => false]);

        $this->hasOne('country_id', new SCountry())
            ->withTitle()
            ->addFields(['country_code' => 'code', 'is_eu']);

        $this->hasMany('Tickets', [new STicket(), 'their_field' => 'user']);
    }
}

class STicket extends Model
{
    public $table = 'ticket';

    public $caption = 'Ticket';

    public function init(): void
    {
        parent::init();

        $this->addField('number');
        $this->addField('venue');
        $this->addField('is_vip', ['type' => 'boolean', 'default' => false]);

        $this->hasOne('user', new SUser());
    }
}

class ScopeTest extends \atk4\schema\PhpunitTestCase
{
    protected $user;
    protected $country;
    protected $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->country = new SCountry($this->db);

        $this->getMigrator($this->country)->drop()->create();

        // Specifying hasMany here will perform input
        $this->country->import([
            ['name' => 'Canada', 'code' => 'CA'],
            ['name' => 'Latvia', 'code' => 'LV'],
            ['name' => 'Japan', 'code' => 'JP'],
            ['name' => 'Lithuania', 'code' => 'LT', 'is_eu' => true],
            ['name' => 'Russia', 'code' => 'RU'],
            ['name' => 'France', 'code' => 'FR'],
            ['name' => 'Brazil', 'code' => 'BR'],
        ]);

        $this->user = new SUser($this->db);

        $this->getMigrator($this->user)->drop()->create();

        $this->user->import([
            ['name' => 'John', 'surname' => 'Smith', 'country_code' => 'CA'],
            ['name' => 'Jane', 'surname' => 'Doe', 'country_code' => 'LV'],
            ['name' => 'Alain', 'surname' => 'Prost', 'country_code' => 'FR'],
            ['name' => 'Aerton', 'surname' => 'Senna', 'country_code' => 'BR'],
            ['name' => 'Rubens', 'surname' => 'Barichello', 'country_code' => 'BR'],
        ]);

        $this->ticket = new STicket($this->db);

        $this->getMigrator($this->ticket)->drop()->create();

        $this->ticket->import([
            ['number' => '001', 'venue' => 'Best Stadium', 'user' => 1],
            ['number' => '002', 'venue' => 'Best Stadium', 'user' => 2],
            ['number' => '003', 'venue' => 'Best Stadium', 'user' => 2],
            ['number' => '004', 'venue' => 'Best Stadium', 'user' => 4],
            ['number' => '005', 'venue' => 'Best Stadium', 'user' => 5],
        ]);
    }

    public function testCondition()
    {
        $user = clone $this->user;

        $condition = new Condition('name', 'John');

        $user->scope()->add($condition);

        $user->loadAny();

        $this->assertEquals('Smith', $user->get('surname'));
    }

    public function testContitionToWords()
    {
        $user = clone $this->user;

        $condition = new Condition(new Expression('false'));

        $this->assertEquals('expression \'false\'', $condition->toWords($user));

        $condition = new Condition('country_id/code', 'US');

        $this->assertEquals('User that has reference Country Id where Code is equal to \'US\'', $condition->toWords($user));

        $condition = new Condition('country_id', 2);

        $this->assertEquals('Country Id is equal to \'Latvia\'', $condition->toWords($user));

        if ($this->driverType == 'sqlite') {
            $condition = new Condition('name', $user->expr('[surname]'));

            $this->assertEquals('Name is equal to expression \'"user"."surname"\'', $condition->toWords($user));
        }

        $condition = new Condition('country_id', null);

        $this->assertEquals('Country Id is equal to empty', $condition->toWords($user));

        $condition = new Condition('name', '>', 'Test');

        $this->assertEquals('Name is greater than \'Test\'', $condition->toWords($user));

        $condition = (new Condition('country_id', 2))->negate();

        $this->assertEquals('Country Id is not equal to \'Latvia\'', $condition->toWords($user));

        $condition = new Condition($user->getField('surname'), $user->getField('name'));

        $this->assertEquals('Surname is equal to User Name', $condition->toWords($user));

        $country = clone $this->country;

        $country->addCondition('Users/#');

        $this->assertEquals('Country that has reference Users where any referenced record exists', $country->scope()->toWords());
    }

    public function testConditionOnReferencedRecords()
    {
        $user = clone $this->user;

        $user->addCondition('country_id/code', 'LV');

        $this->assertEquals(1, $user->action('count')->getOne());

        foreach ($user as $u) {
            $this->assertEquals('LV', $u->get('country_code'));
        }

        $user = clone $this->user;

        // users that have no ticket
        $user->addCondition('Tickets/#', 0);

        $this->assertEquals(1, $user->action('count')->getOne());

        foreach ($user as $u) {
            $this->assertTrue(in_array($u->get('name'), ['Alain', 'Aerton', 'Rubens'], true));
        }

        $country = clone $this->country;

        // countries with more than one user
        $country->addCondition('Users/#', '>', 1);

        foreach ($country as $c) {
            $this->assertEquals('BR', $c->get('code'));
        }

        $country = clone $this->country;

        // countries with users that have ticket number 001
        $country->addCondition('Users/Tickets/number', '001');

        foreach ($country as $c) {
            $this->assertEquals('CA', $c->get('code'));
        }

        $country = clone $this->country;

        // countries with users that have more than one ticket
        $country->addCondition('Users/Tickets/#', '>', 1);

        foreach ($country as $c) {
            $this->assertEquals('LV', $c->get('code'));
        }

        $country = clone $this->country;

        // countries with users that have any tickets
        $country->addCondition('Users/Tickets/#');

        $this->assertEquals(3, $country->action('count')->getOne());

        foreach ($country as $c) {
            $this->assertTrue(in_array($c->get('code'), ['LV', 'CA', 'BR'], true));
        }

        $country = clone $this->country;

        // countries with users that have no tickets
        $country->addCondition('Users/Tickets/#', 0);

        $this->assertEquals(1, $country->action('count')->getOne());

        foreach ($country as $c) {
            $this->assertTrue(in_array($c->get('code'), ['FR'], true));
        }

        $user = clone $this->user;

        // users with tickets that have more than two users per country
        // test if a model can be referenced multiple times
        // and if generated query has no duplicate column names
        // because of counting/# field if added multiple times
        $user->addCondition('Tickets/user/country_id/Users/#', '>', 1);
        $user->addCondition('Tickets/user/country_id/Users/#', '>', 1);
        $user->addCondition('Tickets/user/country_id/Users/#', '>=', 2);
        $user->addCondition('Tickets/user/country_id/Users/country_id/Users/#', '>', 1);
        if ($this->driverType !== 'sqlite') {
            // not supported because of limitation/issue in Sqlite, the generated query fails
            // with error: "parser stack overflow"
            $user->addCondition('Tickets/user/country_id/Users/country_id/Users/name', '!=', null); // should be always true
        }

        $this->assertEquals(2, $user->action('count')->getOne());
        foreach ($user as $u) {
            $this->assertTrue(in_array($u->get('name'), ['Aerton', 'Rubens'], true));
        }
    }

    public function testScope()
    {
        $user = clone $this->user;

        $condition1 = new Condition('name', 'John');
        $condition2 = new Condition('country_code', 'CA');

        $condition3 = new Condition('surname', 'Doe');
        $condition4 = new Condition('country_code', 'LV');

        $compoundCondition1 = CompoundCondition::createAnd($condition1, $condition2);
        $compoundCondition2 = CompoundCondition::createAnd($condition3, $condition4);

        $compoundCondition = CompoundCondition::createOr($compoundCondition1, $compoundCondition2);

        $this->assertEquals(CompoundCondition::OR, $compoundCondition->getJunction());

        $this->assertEquals('(Name is equal to \'John\' and Code is equal to \'CA\') or (Surname is equal to \'Doe\' and Code is equal to \'LV\')', $compoundCondition->toWords($user));

        $user->scope()->add($compoundCondition);

        $this->assertSame($user, $compoundCondition->getModel());

        $this->assertEquals(2, count($user->export()));

        $this->assertEquals($compoundCondition->toWords($user), $user->scope()->toWords());

        // TODO once PHP7.3 support is dropped, we should use WeakRef for owner
        // and unset($compoundCondition); here
        // now we need a clone
        // we should fix then also the short_name issue (if it was generated on adding
        // to an owner but owner is removed, the short_name should be removed as well)
        $compoundCondition1 = clone $compoundCondition1;
        $compoundCondition2 = clone $compoundCondition2;
        $compoundCondition = CompoundCondition::createOr($compoundCondition1, $compoundCondition2);

        $compoundCondition->addCondition('country_code', 'BR');

        $this->assertEquals('(Name is equal to \'John\' and Code is equal to \'CA\') or (Surname is equal to \'Doe\' and Code is equal to \'LV\') or Code is equal to \'BR\'', $compoundCondition->toWords($user));

        $user = clone $this->user;

        $user->scope()->add($compoundCondition);

        $this->assertEquals(4, count($user->export()));
    }

    public function testScopeToWords()
    {
        $user = clone $this->user;

        $condition1 = new Condition('name', 'Alain');
        $condition2 = new Condition('country_code', 'CA');

        $compoundCondition1 = CompoundCondition::createAnd($condition1, $condition2);
        $condition3 = (new Condition('surname', 'Prost'))->negate();

        $compoundCondition = CompoundCondition::createAnd($compoundCondition1, $condition3);

        $this->assertEquals('(Name is equal to \'Alain\' and Code is equal to \'CA\') and Surname is not equal to \'Prost\'', $compoundCondition->toWords($user));
    }

    public function testNegate()
    {
        $user = clone $this->user;

        $condition1 = new Condition('name', '!=', 'Alain');
        $condition2 = new Condition('country_code', '!=', 'FR');

        $condition = CompoundCondition::createOr($condition1, $condition2)->negate();

        $user->scope()->add($condition);

        foreach ($user as $u) {
            $this->assertTrue($u->get('name') == 'Alain' && $u->get('country_code') == 'FR');
        }
    }

    public function testAnd()
    {
        $user = clone $this->user;

        $condition1 = new Condition('name', 'Alain');
        $condition2 = new Condition('country_code', 'FR');

        $compoundCondition = CompoundCondition::createAnd($condition1, $condition2);

        $compoundCondition = CompoundCondition::createOr($compoundCondition, new Condition('name', 'John'));

        $this->assertEquals('(Name is equal to \'Alain\' and Code is equal to \'FR\') or Name is equal to \'John\'', $compoundCondition->toWords($user));
    }

    public function testOr()
    {
        $user = clone $this->user;

        $condition1 = new Condition('name', 'Alain');
        $condition2 = new Condition('country_code', 'FR');

        $compoundCondition = CompoundCondition::createOr($condition1, $condition2);

        $compoundCondition = CompoundCondition::createAnd($compoundCondition, new Condition('name', 'John'));

        $this->assertEquals('(Name is equal to \'Alain\' or Code is equal to \'FR\') and Name is equal to \'John\'', $compoundCondition->toWords($user));
    }

    public function testMerge()
    {
        $user = clone $this->user;

        $condition1 = new Condition('name', 'Alain');
        $condition2 = new Condition('country_code', 'FR');

        $compoundCondition = CompoundCondition::createAnd($condition1, $condition2);

        $this->assertEquals('Name is equal to \'Alain\' and Code is equal to \'FR\'', $compoundCondition->toWords($user));
    }

    public function testDestroyEmpty()
    {
        $user = clone $this->user;

        $condition1 = new Condition('name', 'Alain');
        $condition2 = new Condition('country_code', 'FR');

        $compoundCondition = CompoundCondition::createAnd($condition1, $condition2);

        $compoundCondition->clear();

        $this->assertTrue($compoundCondition->isEmpty());

        $this->assertEmpty($compoundCondition->toWords($user));
    }
}