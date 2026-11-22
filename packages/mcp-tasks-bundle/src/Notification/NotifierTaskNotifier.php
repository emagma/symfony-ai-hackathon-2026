<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Notification;

use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\TaskNotifierInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Through Symfony Notifier, to the address the identity provider gives for the owner: the worker acts as
 * them at that moment. Without an address, or without a notifier, nothing: the owner asks in the chat.
 *
 * @internal
 */
final readonly class NotifierTaskNotifier implements TaskNotifierInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private string $sender,
        private ?NotifierInterface $notifier = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function finished(Task $task): void
    {
        $this->send($task, 'done', \sprintf('The task is over, after %d tool calls.', $task->getCalls()), Notification::IMPORTANCE_LOW);
    }

    public function suspended(Task $task): void
    {
        $steps = $task->getSteps();
        $this->send($task, 'stopped', \sprintf(
            "The task stopped in step %d of %d (%s), after %d tool calls:\n\n%s\n\nWhat it wrote before stays written. It does not resume: ask the assistant where it stands (task %s).",
            $task->getCurrentStep() + 1,
            \count($steps),
            $steps[$task->getCurrentStep()] ?? '?',
            $task->getCalls(),
            (string) $task->getError(),
            $task->getId(),
        ), Notification::IMPORTANCE_HIGH);
    }

    private function send(Task $task, string $outcome, string $content, string $importance): void
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        $email = null !== $user && method_exists($user, 'getEmail') ? $user->getEmail() : null;
        if (null === $this->notifier || !\is_string($email) || '' === $email) {
            return;
        }

        try {
            $this->notifier->send(
                (new TaskNotification(\sprintf('[task · %s] %s', $outcome, mb_substr($task->getObjective(), 0, 120)), $this->sender))->content($content)->importance($importance),
                new Recipient($email),
            );
        } catch (\Throwable $exception) {
            // Telling is a courtesy: the state of the task is in the database either way.
            $this->logger?->warning('The owner of the task {task} could not be notified: {error}', ['task' => $task->getId(), 'error' => $exception->getMessage()]);
        }
    }
}
