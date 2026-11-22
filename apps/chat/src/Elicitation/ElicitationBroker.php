<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Elicitation;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hands the browser's answer to a write confirmation over to the turn waiting
 * for it.
 *
 * Two requests meet here: the turn's SSE request, blocked inside the MCP
 * elicitation callback, and the browser's POST with the answer, served by
 * another FrankenPHP thread. A filesystem cache pool is what both see; the
 * session cannot be it, the turn released it before streaming.
 */
final class ElicitationBroker
{
    public const ACTIONS = ['accept', 'decline', 'cancel'];

    private const PENDING = 'pending';

    public function __construct(
        #[Autowire(service: 'chat.elicitations')]
        private readonly CacheItemPoolInterface $store,
    ) {
    }

    /**
     * An unguessable id: the answer endpoint has no session, the id is the
     * credential.
     */
    public function open(): string
    {
        $id = bin2hex(random_bytes(16));
        $this->write($id, self::PENDING);

        return $id;
    }

    /**
     * @return bool false when no turn waits on this id (unknown, already answered or timed out)
     */
    public function answer(string $id, string $action): bool
    {
        if (!\in_array($action, self::ACTIONS, true) || 1 !== preg_match('/^[0-9a-f]{32}$/', $id)) {
            return false;
        }

        $item = $this->store->getItem($id);
        if (!$item->isHit() || self::PENDING !== $item->get()) {
            return false;
        }

        $this->write($id, $action);

        return true;
    }

    /**
     * Blocks until the browser answers or the timeout elapses.
     *
     * @return string|null the action, or null on timeout
     */
    public function await(string $id, float $timeout, float $poll = 0.25): ?string
    {
        $deadline = microtime(true) + $timeout;

        do {
            $item = $this->store->getItem($id);
            $value = $item->isHit() ? $item->get() : null;
            if (\is_string($value) && self::PENDING !== $value) {
                $this->store->deleteItem($id);

                return $value;
            }
            usleep((int) ($poll * 1_000_000));
        } while (microtime(true) < $deadline);

        $this->store->deleteItem($id);

        return null;
    }

    private function write(string $id, string $value): void
    {
        $item = $this->store->getItem($id)->set($value)->expiresAfter(300);
        $this->store->save($item);
    }
}
