<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Elicitation;

use Emagma\SyliusAssistantTestChat\Elicitation\ElicitationBroker;
use Emagma\SyliusAssistantTestChat\Elicitation\UiElicitationCallback;
use Emagma\SyliusAssistantTestChat\Turn\TurnContext;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Request\ElicitRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(UiElicitationCallback::class)]
final class UiElicitationCallbackTest extends TestCase
{
    /**
     * Nobody clicks within the delay: the chat accepts on the user's behalf,
     * with `confirm: true` - an accept without it is a refusal to the server.
     */
    public function testNoClickBeforeTheDeadlineAutoAccepts(): void
    {
        $events = [];
        $turn = new TurnContext();
        $turn->start('jwt', static function (string $event, array $data) use (&$events): void {
            $events[] = [$event, $data];
        });

        $callback = new UiElicitationCallback(new ElicitationBroker(new ArrayAdapter()), $turn, timeout: 0.2);
        $result = $callback($this->request());

        self::assertSame(ElicitAction::Accept, $result->action);
        self::assertSame(['confirm' => true], $result->content);
        self::assertSame(['elicitation', 'elicitation_resolved'], array_column($events, 0));
        self::assertSame('Create customer group "vip"?', $events[0][1]['message']);
        self::assertSame('auto_accept', $events[1][1]['outcome']);
        self::assertSame('auto_accept', $callback->pullDecisions()[0]->outcome());
    }

    public function testAPresetAnswerSkipsTheUi(): void
    {
        $turn = new TurnContext();
        $turn->start('jwt', presetAnswer: 'decline');

        $result = (new UiElicitationCallback(new ElicitationBroker(new ArrayAdapter()), $turn))($this->request());

        self::assertSame(ElicitAction::Decline, $result->action);
        self::assertNull($result->content);
    }

    private function request(): ElicitRequest
    {
        return new ElicitRequest('Create customer group "vip"?', new ElicitationSchema(['confirm' => new BooleanSchemaDefinition('Create it?')], ['confirm']));
    }
}
