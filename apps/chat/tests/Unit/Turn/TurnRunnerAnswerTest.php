<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Turn;

use Emagma\SyliusAssistantTestChat\Turn\TurnRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;

#[CoversClass(TurnRunner::class)]
final class TurnRunnerAnswerTest extends TestCase
{
    public function testAPlainTextAnswerIsKeptAsIs(): void
    {
        self::assertSame('Bonjour', TurnRunner::answer(new TextResult('Bonjour')));
    }

    /**
     * OpenAI's Responses API may return a reasoning summary next to the text,
     * or the text in several parts: the reasoning is dropped, the text joined
     * (it used to be json_encoded into `[{},{}]`).
     */
    public function testAMultiPartAnswerKeepsOnlyItsText(): void
    {
        $result = new MultiPartResult([
            new ThinkingResult('the user wants the edits of today'),
            new TextResult('One change today: '),
            new TextResult('the product Apollo_T_Shirt was renamed.'),
        ]);

        self::assertSame('One change today: the product Apollo_T_Shirt was renamed.', TurnRunner::answer($result));
    }
}
