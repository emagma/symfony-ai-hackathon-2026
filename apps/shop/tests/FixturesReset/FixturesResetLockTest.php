<?php

declare(strict_types=1);

namespace App\Tests\FixturesReset;

use App\FixturesReset\FixturesResetLock;
use PHPUnit\Framework\TestCase;

final class FixturesResetLockTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/fixtures-reset-test-' . bin2hex(random_bytes(4)) . '.lock';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testOnlyOneHolderAtATime(): void
    {
        $first = new FixturesResetLock($this->path);
        $second = new FixturesResetLock($this->path);

        self::assertTrue($first->acquire());
        self::assertFalse($second->acquire());
        self::assertTrue($second->isHeldElsewhere());
        self::assertFalse($first->isHeldElsewhere(), 'The holder does not see its own lock as held elsewhere.');

        $first->release();

        self::assertFalse($second->isHeldElsewhere());
        self::assertTrue($second->acquire());
        $second->release();
    }

    public function testAcquireIsReentrantForTheHolder(): void
    {
        $lock = new FixturesResetLock($this->path);

        self::assertTrue($lock->acquire());
        self::assertTrue($lock->acquire());
        $lock->release();
        $lock->release();

        self::assertFalse((new FixturesResetLock($this->path))->isHeldElsewhere());
    }
}
