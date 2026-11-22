<?php

declare(strict_types=1);

namespace Emagma\McpTasksMessengerBundle\EventListener;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Orchestration\TaskLifecycle;
use Emagma\McpTasksBundle\Store\TaskStoreInterface;
use Emagma\McpTasksBundle\TaskStatus;
use Emagma\McpTasksMessengerBundle\EmagmaMcpTasksMessengerBundle;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;

/**
 * A worker that starts finds no step in flight: a running task whose step is still marked in flight was cut by a
 * stop of the worker, and is suspended, never played again (its step may have written). A task between two steps
 * lost nothing: the message of its next step waits in the queue.
 *
 * On a database not migrated yet (a first start: the consumers come up before the migrations), there is no
 * table of the tasks, hence no task to suspend: the worker starts. Any other failure of the store stops it.
 *
 * @internal
 */
final readonly class InterruptedTasksListener
{
    public function __construct(
        private TaskStoreInterface $store,
        private TaskLifecycle $lifecycle,
    ) {
    }

    public function __invoke(WorkerStartedEvent $event): void
    {
        if (!\in_array(EmagmaMcpTasksMessengerBundle::TRANSPORT, $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            return;
        }
        foreach ($this->runningTasks() as $task) {
            if ($this->lifecycle->isStepInFlight($task->getId())) {
                $this->lifecycle->interrupted($task->getId());
            }
        }
    }

    /**
     * @return list<Task>
     */
    private function runningTasks(): array
    {
        try {
            return $this->store->findByStatus(TaskStatus::Running);
        } catch (TableNotFoundException) {
            return [];
        }
    }
}
