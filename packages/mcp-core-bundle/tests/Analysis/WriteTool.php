<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Analysis;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Mcp\Server\RequestContext;

/**
 * Not a test: it puts WriteConfirmationTrait under PHPStan's analysis, which covers a trait only where it is used.
 */
#[Permission(resource: 'thing', action: Action::Write)]
final class WriteTool
{
    use WriteConfirmationTrait;

    /**
     * @return array<string, mixed>
     */
    public function __invoke(bool $confirm = false, ?RequestContext $context = null): array
    {
        $refusal = $this->confirmWrite($context, $confirm, 'Confirm the change', 'Apply it?', '- name: Old -> New', 'Re-invoke with confirm: true.');

        return $refusal ?? ['ok' => true];
    }
}
