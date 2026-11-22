<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tool;

use Emagma\McpCoreBundle\Caller\ActorProviderInterface;
use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Store\TaskStoreInterface;

/**
 * A caller only sees and handles the tasks they created. The owner is the validated identity, never an argument.
 *
 * @internal
 */
final readonly class TaskAccess
{
    public function __construct(
        private TaskStoreInterface $store,
        private ActorProviderInterface $actors,
    ) {
    }

    public function actor(): ?string
    {
        return $this->actors->actor();
    }

    /**
     * Null for an unknown task as for someone else's: which one it is is not the caller's to know.
     */
    public function own(string $id): ?Task
    {
        $actor = $this->actors->actor();
        $task = null === $actor ? null : $this->store->find($id);

        return null !== $task && $task->getOwner() === $actor ? $task : null;
    }
}
