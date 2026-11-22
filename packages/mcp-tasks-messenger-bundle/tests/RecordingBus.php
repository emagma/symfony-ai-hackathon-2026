<?php

declare(strict_types=1);

namespace Emagma\McpTasksMessengerBundle\Tests;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The messages sent, kept for the test instead of a queue.
 */
final class RecordingBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }

    /**
     * @return list<object> the messages sent since the last call
     */
    public function take(): array
    {
        $messages = $this->messages;
        $this->messages = [];

        return $messages;
    }
}
