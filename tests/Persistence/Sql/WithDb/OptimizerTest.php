<?php

declare(strict_types=1);

namespace Atk4\Data\Tests\Persistence\Sql\WithDb;

use Atk4\Data\Persistence\Sql\Expression;
use Atk4\Data\Persistence\Sql\Optimizer\Util;
use Atk4\Data\Schema\TestCase;

class OptimizerTest extends TestCase
{
    public function testUtilParseIdentifier(): void
    {
        $this->assertSame([null, 'a'], Util::tryParseIdentifier('a'));
        $this->assertSame([null, 'a'], Util::tryParseIdentifier('"a"'));
        $this->assertSame([null, 'a'], Util::tryParseIdentifier(new Expression('{}', ['a'])));
        $this->assertSame(['a', 'b'], Util::tryParseIdentifier('a.b'));
        $this->assertSame(['a', 'b'], Util::tryParseIdentifier('"a".b'));
        $this->assertSame(['a', 'b'], Util::tryParseIdentifier('"a"."b"'));
        $this->assertSame(['a', 'b'], Util::tryParseIdentifier(new Expression('{}.{}', ['a', 'b'])));
        $this->assertFalse(Util::tryParseIdentifier('a b'));
        $this->assertFalse(Util::tryParseIdentifier('*'));
        $this->assertFalse(Util::tryParseIdentifier('(a)'));

        $this->assertTrue(Util::isSingleIdentifier('a'));
        $this->assertTrue(Util::isSingleIdentifier('"a"'));
        $this->assertTrue(Util::isSingleIdentifier(new Expression('{}', ['a'])));
        $this->assertFalse(Util::isSingleIdentifier('a.b'));
        $this->assertFalse(Util::isSingleIdentifier('"a".b'));
        $this->assertFalse(Util::isSingleIdentifier('"a"."b"'));
        $this->assertFalse(Util::isSingleIdentifier(new Expression('{}.{}', ['a', 'b'])));
        $this->assertFalse(Util::isSingleIdentifier('a b'));
        $this->assertFalse(Util::isSingleIdentifier('*'));
        $this->assertFalse(Util::isSingleIdentifier('(a)'));
    }
}
