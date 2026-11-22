<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Unit;

use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Notification\NotifierTaskNotifier;
use Emagma\McpTasksBundle\Notification\TaskNotification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;
use Symfony\Component\Notifier\Recipient\RecipientInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

#[CoversClass(NotifierTaskNotifier::class)]
#[CoversClass(TaskNotification::class)]
final class NotifierTaskNotifierTest extends TestCase
{
    /** @var list<array{Notification, list<RecipientInterface>}> */
    private array $sent = [];

    public function testTheOwnerIsTold(): void
    {
        $notifier = $this->notifier($this->user('alice@example.com'));
        $task = $this->task();
        $task->setError('Update refused for product B.');

        $notifier->finished($task);
        $notifier->suspended($task);

        self::assertCount(2, $this->sent);
        [$done, $recipients] = $this->sent[0];
        self::assertSame('[task · done] Translate the catalogue', $done->getSubject());
        self::assertEquals([new Recipient('alice@example.com')], $recipients);

        [$stopped] = $this->sent[1];
        self::assertInstanceOf(TaskNotification::class, $stopped);
        self::assertSame('[task · stopped] Translate the catalogue', $stopped->getSubject());
        self::assertStringContainsString('step 1 of 2 (translate)', $stopped->getContent());
        self::assertStringContainsString('Update refused for product B.', $stopped->getContent());
        self::assertSame(Notification::IMPORTANCE_HIGH, $stopped->getImportance());

        // Plain text, from the configured sender: the server has no template engine for emails.
        $email = $stopped->asEmailMessage(new Recipient('alice@example.com'))->getMessage();
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $email);
        self::assertSame('tasks@example.com', $email->getFrom()[0]->getAddress());
        self::assertSame($stopped->getContent(), $email->getTextBody());
    }

    public function testWithoutAnAddressNobodyIsTold(): void
    {
        $this->notifier(null)->finished($this->task());
        $this->notifier($this->user(''))->finished($this->task());

        self::assertSame([], $this->sent);
    }

    public function testAFailureToTellDoesNotBreakTheTask(): void
    {
        $failing = $this->createStub(NotifierInterface::class);
        $failing->method('send')->willThrowException(new \RuntimeException('No transport.'));
        $tokens = new TokenStorage();
        $user = $this->user('alice@example.com');
        $tokens->setToken(new PostAuthenticationToken($user, 'mcp_tasks', []));

        (new NotifierTaskNotifier($tokens, 'tasks@example.com', $failing))->finished($this->task());

        $this->expectNotToPerformAssertions();
    }

    private function notifier(?UserInterface $user): NotifierTaskNotifier
    {
        $recording = $this->createStub(NotifierInterface::class);
        $recording->method('send')->willReturnCallback(function (Notification $notification, RecipientInterface ...$recipients): void {
            $this->sent[] = [$notification, array_values($recipients)];
        });
        $tokens = new TokenStorage();
        if (null !== $user) {
            $tokens->setToken(new PostAuthenticationToken($user, 'mcp_tasks', []));
        }

        return new NotifierTaskNotifier($tokens, 'tasks@example.com', $recording);
    }

    private function task(): Task
    {
        return new Task('id', 'alice', 'Translate the catalogue', 'recap', 'reading', [], ['translate', 'after'], new \DateTimeImmutable());
    }

    private function user(string $email): UserInterface
    {
        return new class($email) implements UserInterface {
            public function __construct(private string $email)
            {
            }

            public function getEmail(): string
            {
                return $this->email;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'alice';
            }
        };
    }
}
