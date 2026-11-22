<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Administrator;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Authorization\RevealsPersonalData;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\Administrator\AdministratorOutput;
use Emagma\McpSyliusBundle\Tool\Shared\RevealTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * Reads administrators one by one in a single call: the ones that exist and the missing ones.
 *
 * @internal
 */
#[McpTool(
    name: 'get_administrators',
    description: 'Get Sylius BACK-OFFICE accounts (administrators) by numeric id, at most 50 per call: username,'
        . ' email, names, localeCode, enabled, lastLogin, verifiedAt, createdAt. Returns {found: [...],'
        . ' missing: [ids]}. Discover ids with search_administrators.',
)]
#[Permission(resource: 'administrator', action: Action::Read)]
#[RevealsPersonalData]
final readonly class GetAdministrators
{
    use RevealTrait;

    private const int MAX_IDS = 50;

    public function __construct(
        private AdminApiClientInterface $client,
        private GrantsProviderInterface $grants,
        private WriteJournalInterface $audit,
    ) {
    }

    /**
     * @param list<string> $ids
     *
     * @return array{found: list<AdministratorOutput>, missing: list<string>, reason?: string}
     */
    public function __invoke(
        #[Schema(type: 'array', items: ['type' => 'string'], minItems: 1)]
        array $ids,
        #[Schema(description: 'Serve the identifiers (email, phone, login) and surplus personal data in clear although this client pseudonymises them: needs the core:pii:reveal grant, is audited per person. Without effect for a client that reads in clear.')]
        bool $reveal = false,
        ?RequestContext $context = null,
    ): array {
        if (null !== $refusal = $this->refuseReveal($reveal)) {
            return $refusal;
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (string $id): bool => '' !== trim($id))));
        if (\count($ids) > self::MAX_IDS) {
            return ['found' => [], 'missing' => [], 'reason' => 'too_many_ids'];
        }
        if ([] === $ids) {
            return ['found' => [], 'missing' => []];
        }

        $paths = [];
        foreach ($ids as $id) {
            $paths[$id] = Route::item(Resource::Administrator, $id);
        }
        $results = $this->client->getDecodedMany($paths);

        $found = [];
        $missing = [];
        foreach ($ids as $id) {
            $result = $results[$id] ?? ['ok' => false, 'status' => null];
            if (true === $result['ok']) {
                $found[] = AdministratorOutput::fromPayload($result['body'], $id);
            } else {
                $missing[] = $id;
            }
        }

        $revealed = self::revealing($reveal) && [] !== $found
            ? $this->reveal('get_administrators', 'administrator', array_map(static fn (object $item): string => $item->id, $found), $context)
            : null;

        return ['found' => $found, 'missing' => $missing] + (null === $revealed ? [] : ['revealed' => $revealed]);
    }
}
