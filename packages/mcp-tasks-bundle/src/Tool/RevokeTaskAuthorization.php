<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tool;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpTasksBundle\Delegation\DelegationInterface;
use Mcp\Capability\Attribute\McpTool;

/**
 * revoke_task_authorization: the caller takes back the right the server had to run their tasks.
 *
 * @internal
 */
#[McpTool(
    name: 'revoke_task_authorization',
    description: 'Withdraw what lets the server act for the caller while they are away: their running tasks are'
        . ' suspended at their next step or call, and the next start_task asks for the authorization again.'
        . ' Reasons: no_actor.',
)]
#[Permission(resource: 'task', action: Action::Write)]
final readonly class RevokeTaskAuthorization
{
    public function __construct(
        private DelegationInterface $delegation,
        private TaskAccess $access,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $actor = $this->access->actor();
        if (null === $actor) {
            return ['ok' => false, 'reason' => 'no_actor'];
        }
        $this->delegation->revoke($actor);

        return ['ok' => true, 'revoked' => true];
    }
}
