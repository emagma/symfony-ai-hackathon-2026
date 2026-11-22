<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Testing;

use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * RequestContext is final and builds its own ClientGateway: the confirm gate is driven through a stubbed session,
 * and the blocking elicit() round-trip is resumed by hand inside a Fiber.
 *
 * @phpstan-require-extends TestCase
 */
trait ElicitationTestTrait
{
    protected function noElicitContext(?string $clientName = null): RequestContext
    {
        return new RequestContext($this->stubSession(elicitation: false, clientName: $clientName), $this->createStub(Request::class));
    }

    /**
     * Only useful via runWithElicitation(): calling elicit() outside a Fiber throws.
     */
    protected function elicitingContext(?string $clientName = null): RequestContext
    {
        return new RequestContext($this->stubSession(elicitation: true, clientName: $clientName), $this->createStub(Request::class));
    }

    /**
     * The closure must invoke the tool with an elicitingContext().
     *
     * @param \Closure(): array<string, mixed> $invoke
     * @param array<string, mixed>             $content only sent on Accept
     *
     * @return array<string, mixed>
     */
    protected function runWithElicitation(\Closure $invoke, ElicitAction $action, array $content = []): array
    {
        $fiber = new \Fiber($invoke);
        $fiber->start();

        if ($fiber->isTerminated()) {
            // The tool returned before eliciting.
            return $fiber->getReturn();
        }

        $result = ['action' => $action->value];
        if (ElicitAction::Accept === $action) {
            $result['content'] = $content;
        }

        $fiber->resume(new Response(1, $result));

        return $fiber->getReturn();
    }

    private function stubSession(bool $elicitation, ?string $clientName = null): SessionInterface
    {
        $session = $this->createStub(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $session->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => match ($key) {
                'client_capabilities' => $elicitation ? ['elicitation' => []] : [],
                'client_info' => null === $clientName ? $default : ['name' => $clientName, 'version' => '1.0.0'],
                default => $default,
            },
        );

        return $session;
    }
}
