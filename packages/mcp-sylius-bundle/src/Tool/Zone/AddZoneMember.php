<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Zone;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\DiffPreview;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\Zone\ZoneOutput;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Adds one member to a zone, without restating the others.
 *
 * @internal
 */
#[McpTool(
    name: 'add_zone_member',
    description: 'Add ONE member to a Sylius admin zone, leaving every other member untouched. Prefer this over'
        . ' upsert_zones(members=) when you only want to append: that argument replaces the whole set and'
        . ' REMOVES the codes you omit, which silently changes which orders the zone covers. The member'
        . ' code is a country, province or zone code depending on the zone type - check it with get_zones.'
        . ' Returns {added: true, code, memberCode, diff} on success. Tool-specific reasons:'
        . ' member_already_exists.',
)]
#[Permission(resource: 'zone', action: Action::Write)]
final readonly class AddZoneMember
{
    use WriteConfirmationTrait;
    use ZoneMembersPayloadTrait;

    private const string TARGET_TYPE = 'zone';

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $code,
        string $memberCode,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        $path = Route::item(Resource::Zone, $code);

        try {
            $current = $this->client->getDecoded($path);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['added' => false, 'code' => $code]);
        }

        $entries = self::memberEntries($current);
        $codes = self::memberCodes($entries);

        if (\in_array($memberCode, $codes, true)) {
            return [
                'ok' => false,
                'added' => false,
                'reason' => 'member_already_exists',
                'code' => $code,
                'memberCode' => $memberCode,
            ];
        }

        $diff = ['members' => ['before' => $codes, 'after' => [...$codes, $memberCode]]];

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm adding member "' . $memberCode . '" to zone "' . $code . '"',
            'Add this zone member?',
            DiffPreview::targets('Add member to zone "' . $code . '"', $diff),
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'added' => false, 'code' => $code, 'memberCode' => $memberCode, 'diff' => $diff] + $refusal;
        }

        $body = $current;
        $body['members'] = [...$entries, ['code' => $memberCode]];

        try {
            $updated = $this->client->put($path, $body);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['added' => false, 'code' => $code, 'memberCode' => $memberCode]);
        }

        $this->audit->record(
            tool: 'add_zone_member',
            targetType: self::TARGET_TYPE,
            targetCode: $code,
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return [
            'ok' => true,
            'added' => true,
            'code' => $code,
            'memberCode' => $memberCode,
            'diff' => $diff,
            'after' => ZoneOutput::fromPayload($updated, $code),
        ];
    }
}
