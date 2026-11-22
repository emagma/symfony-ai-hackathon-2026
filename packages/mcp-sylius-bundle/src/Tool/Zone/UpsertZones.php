<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Zone;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\ApiFeature;
use Emagma\McpSyliusBundle\AdminApi\SyliusVersion;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchUpsertTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * Creates or updates zones in one batch, after one confirmation of the human.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_zones',
    description: 'Create or update zones in one call, at most 50 items, matched on their code. A creation needs'
        . ' name and type (zone|country|province); scope defaults to all. type and scope are immutable: on an'
        . ' existing zone only name, priority and members change, and members replaces the whole list.'
        . ' mode: upsert (default), create (already_exists on an existing code) or update (not_found on an'
        . ' unknown one). An item that changes nothing is unchanged and not written. One confirmation for'
        . ' the batch; one result per item.',
)]
#[Permission(resource: 'zone', action: Action::Write)]
final readonly class UpsertZones
{
    use BatchUpsertTrait;
    use WriteConfirmationTrait;

    private const array TYPES = ['zone', 'country', 'province'];

    private const array SCOPES = ['all', 'shipping', 'tax'];

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
        private SyliusVersion $syliusVersion = new SyliusVersion('2.3'),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(type: 'array', items: ZoneItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_zones', Route::collection(Resource::Zone), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'zone';
    }

    private function label(): string
    {
        return 'zones';
    }

    private function validateItem(array $item): ?string
    {
        if (\array_key_exists('name', $item) && (!\is_string($item['name']) || '' === trim($item['name']))) {
            return 'name is a non-empty string';
        }
        if (\array_key_exists('type', $item) && !\in_array($item['type'], self::TYPES, true)) {
            return 'type is one of zone, country, province';
        }
        if (\array_key_exists('scope', $item) && !\in_array($item['scope'], self::SCOPES, true)) {
            return 'scope is one of all, shipping, tax';
        }
        if (\array_key_exists('priority', $item) && !\is_int($item['priority'])) {
            return 'priority is an integer';
        }
        if (\array_key_exists('priority', $item) && !ApiFeature::ZonePriority->isAvailableOn($this->syliusVersion)) {
            // Older lines ignore an unknown field: the write would claim a change it never made.
            return \sprintf('priority needs Sylius %s or later; this shop runs %s', ApiFeature::ZonePriority->since(), $this->syliusVersion);
        }
        if (\array_key_exists('members', $item)) {
            if (!\is_array($item['members'])) {
                return 'members is a list of codes';
            }
            foreach ($item['members'] as $member) {
                if (!\is_string($member) || '' === trim($member)) {
                    return 'members is a list of non-empty codes';
                }
            }
        }

        return null;
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        if (null === $current) {
            $diff = [
                'code' => ['before' => null, 'after' => $item['code']],
                'name' => ['before' => null, 'after' => $item['name'] ?? null],
                'type' => ['before' => null, 'after' => $item['type'] ?? null],
                'scope' => ['before' => null, 'after' => $item['scope'] ?? 'all'],
            ];
            if (\array_key_exists('priority', $item)) {
                $diff['priority'] = ['before' => null, 'after' => $item['priority']];
            }
            if (\is_array($item['members'] ?? null) && [] !== $item['members']) {
                $diff['members'] = ['before' => null, 'after' => array_values($item['members'])];
            }

            return $diff;
        }

        $diff = [];
        if (\array_key_exists('name', $item)) {
            $before = \is_string($current['name'] ?? null) ? $current['name'] : null;
            if ($before !== $item['name']) {
                $diff['name'] = ['before' => $before, 'after' => $item['name']];
            }
        }
        if (\array_key_exists('priority', $item)) {
            $before = \is_int($current['priority'] ?? null) ? $current['priority'] : null;
            if ($before !== $item['priority']) {
                $diff['priority'] = ['before' => $before, 'after' => $item['priority']];
            }
        }
        if (\is_array($item['members'] ?? null)) {
            $beforeCodes = self::memberCodes($current['members'] ?? []);
            $afterCodes = array_values($item['members']);
            $sortedBefore = $beforeCodes;
            $sortedAfter = $afterCodes;
            sort($sortedBefore);
            sort($sortedAfter);
            if ($sortedBefore !== $sortedAfter) {
                $diff['members'] = ['before' => $beforeCodes, 'after' => $afterCodes];
            }
        }

        return $diff;
    }

    private function postBody(array $item, mixed $references): array
    {
        $body = [
            'code' => $item['code'],
            'name' => $item['name'] ?? '',
            'type' => $item['type'] ?? 'country',
            'scope' => $item['scope'] ?? 'all',
        ];
        if (\array_key_exists('priority', $item)) {
            $body['priority'] = $item['priority'];
        }
        if (\is_array($item['members'] ?? null) && [] !== $item['members']) {
            $body['members'] = array_map(static fn (mixed $code): array => ['code' => \is_string($code) ? $code : ''], array_values($item['members']));
        }

        return $body;
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        $body = $current;
        if (\array_key_exists('name', $item)) {
            $body['name'] = $item['name'];
        }
        if (\array_key_exists('priority', $item)) {
            $body['priority'] = $item['priority'];
        }
        if (\is_array($item['members'] ?? null)) {
            $body['members'] = array_map(static fn (mixed $code): array => ['code' => \is_string($code) ? $code : ''], array_values($item['members']));
        }

        return $body;
    }

    /**
     * @return list<string>
     */
    private static function memberCodes(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $codes = [];
        foreach ($raw as $member) {
            if (\is_string($member) && '' !== $member) {
                $codes[] = basename($member);
            } elseif (\is_array($member) && \is_string($member['code'] ?? null) && '' !== $member['code']) {
                $codes[] = $member['code'];
            }
        }

        return $codes;
    }
}
