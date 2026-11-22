<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\WriteConfirmation;

use Emagma\McpCoreBundle\Testing\ElicitationTestTrait;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Server\RequestContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WriteConfirmationTrait::class)]
final class WriteConfirmationTraitTest extends TestCase
{
    use ElicitationTestTrait;

    public function testConfirmWriteProceedsWhenConfirmTrue(): void
    {
        $subject = $this->subject();

        self::assertNull($subject->confirm(null, true, 'msg', 'title', 'preview', 'hint'));
    }

    public function testElicitationOverridesModelSuppliedConfirmTrue(): void
    {
        $subject = $this->subject();

        $refusal = $this->runWithElicitation(
            fn (): array => ['refusal' => $subject->confirm($this->elicitingContext(), true, 'msg', 'title', 'preview', 'hint')],
            ElicitAction::Decline,
        );

        self::assertSame(['ok' => false, 'reason' => 'declined'], $refusal['refusal']);
    }

    public function testConfirmWriteWithoutElicitationReturnsPreviewOnly(): void
    {
        $subject = $this->subject();

        $refusal = $subject->confirm($this->noElicitContext(), false, 'msg', 'title', 'preview', 'do the thing');

        self::assertSame(
            ['ok' => false, 'reason' => 'elicitation_unavailable_preview_only', 'hint' => 'do the thing'],
            $refusal,
        );
    }

    public function testConfirmWriteWithoutContextReturnsPreviewOnly(): void
    {
        $subject = $this->subject();

        $refusal = $subject->confirm(null, false, 'msg', 'title', 'preview', 'hint');

        self::assertSame('elicitation_unavailable_preview_only', $refusal['reason'] ?? null);
    }

    public function testConfirmWriteProceedsWhenUserAccepts(): void
    {
        $subject = $this->subject();

        $refusal = $this->runWithElicitation(
            fn (): array => ['refusal' => $subject->confirm($this->elicitingContext(), false, 'msg', 'title', 'preview', 'hint')],
            ElicitAction::Accept,
            ['confirm' => true],
        );

        self::assertNull($refusal['refusal']);
    }

    public function testConfirmWriteDeclinedWhenUserRejects(): void
    {
        $subject = $this->subject();

        $refusal = $this->runWithElicitation(
            fn (): array => ['refusal' => $subject->confirm($this->elicitingContext(), false, 'msg', 'title', 'preview', 'hint')],
            ElicitAction::Decline,
        );

        self::assertSame(['ok' => false, 'reason' => 'declined'], $refusal['refusal']);
    }

    public function testConfirmWriteCancelledWhenUserCancels(): void
    {
        $subject = $this->subject();

        $refusal = $this->runWithElicitation(
            fn (): array => ['refusal' => $subject->confirm($this->elicitingContext(), false, 'msg', 'title', 'preview', 'hint')],
            ElicitAction::Cancel,
        );

        self::assertSame(['ok' => false, 'reason' => 'cancelled'], $refusal['refusal']);
    }

    public function testConfirmWriteDeclinedWhenAcceptedWithFalseConfirm(): void
    {
        $subject = $this->subject();

        $refusal = $this->runWithElicitation(
            fn (): array => ['refusal' => $subject->confirm($this->elicitingContext(), false, 'msg', 'title', 'preview', 'hint')],
            ElicitAction::Accept,
            ['confirm' => false],
        );

        self::assertSame(['ok' => false, 'reason' => 'declined'], $refusal['refusal']);
    }

    private function subject(): object
    {
        return new class() {
            use WriteConfirmationTrait;

            /** @return array{reason: string}|array{reason: string, hint: string}|null */
            public function confirm(?RequestContext $context, bool $confirm, string $m, string $t, string $p, string $h): ?array
            {
                return $this->confirmWrite($context, $confirm, $m, $t, $p, $h);
            }
        };
    }
}
