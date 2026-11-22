<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Tests;

use Emagma\McpAuditBundle\AuditScope;
use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpAuditBundle\Repository\AuditRepositoryInterface;
use Emagma\McpCoreBundle\Authorization\Grants;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpCoreBundle\Authorization\ToolPermissionResolver;
use Emagma\McpCoreBundle\Call\ToolClassMap;
use Emagma\McpCoreBundle\Caller\ActorProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * A repository stub that answers every query with the same entries, and a real AuditScope
 * (final, readonly) on the grants and the actor a test gives.
 *
 * @phpstan-require-extends TestCase
 */
trait AuditTestTrait
{
    /**
     * @param list<AuditEntry> $paginateItems what every list query returns
     */
    protected function createAuditRepository(
        ?AuditEntry $findResult = null,
        array $paginateItems = [],
        int $paginateTotal = 0,
    ): AuditRepositoryInterface {
        $repository = $this->createStub(AuditRepositoryInterface::class);
        $repository->method('find')->willReturn($findResult);
        $repository->method('findByIds')->willReturnCallback(static fn (array $ids): array => [] === $ids ? [] : $paginateItems);
        $repository->method('findByBatch')->willReturn($paginateItems);
        $repository->method('findConcerning')->willReturn($paginateItems);
        $repository->method('paginate')->willReturn(['items' => $paginateItems, 'total' => $paginateTotal]);
        $repository->method('countBefore')->willReturn($paginateTotal);

        return $repository;
    }

    /**
     * @param list<string>|null           $grants `mcp:<domain>:<resource>:<action>` strings; null for an explicit allow-all
     * @param array<string, class-string> $tools   tool name => class, for the grant a revert also needs
     * @param array<string, string>       $domains namespace prefix => permission domain of those classes
     */
    protected function createAuditScope(?array $grants = null, ?string $actor = null, array $tools = [], array $domains = []): AuditScope
    {
        $provider = new class(null === $grants ? Grants::allowAll() : Grants::fromStrings($grants)) implements GrantsProviderInterface {
            public function __construct(
                private readonly Grants $grants,
            ) {
            }

            public function grants(): Grants
            {
                return $this->grants;
            }
        };
        $actors = new class($actor) implements ActorProviderInterface {
            public function __construct(
                private readonly ?string $actor,
            ) {
            }

            public function actor(): ?string
            {
                return $this->actor;
            }
        };

        return new AuditScope($provider, $actors, new ToolPermissionResolver(['Emagma\\McpAuditBundle\\' => 'core'] + $domains), new ToolClassMap($tools));
    }
}
