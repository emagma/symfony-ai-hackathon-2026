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
 * Removes one member from a zone, without restating the others.
 *
 * @internal
 */
#[McpTool(
    name: 'remove_zone_member',
    description: 'Remove ONE member from a Sylius admin zone, leaving every other member untouched. Prefer this'
        . ' over upsert_zones(members=), which drops a member only by restating every other one - a single'
        . ' omission there shrinks the zone further than intended and silently changes which orders are'
        . ' taxed or shippable. Returns {removed: true, code, memberCode, diff} on success. Tool-specific'
        . ' reasons: member_not_found.',
)]
#[Permission(resource: 'zone', action: Action::Write)]
final readonly class RemoveZoneMember
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
            return ApiFailure::describe($exception, ['removed' => false, 'code' => $code]);
        }

        $entries = self::memberEntries($current);
        $codes = self::memberCodes($entries);

        if (!\in_array($memberCode, $codes, true)) {
            return [
                'ok' => false,
                'removed' => false,
                'reason' => 'member_not_found',
                'code' => $code,
                'memberCode' => $memberCode,
                'members' => $codes,
            ];
        }

        $remaining = array_values(array_filter($codes, static fn (string $c): bool => $c !== $memberCode));
        $diff = ['members' => ['before' => $codes, 'after' => $remaining]];

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm removing member "' . $memberCode . '" from zone "' . $code . '"',
            'Remove this zone member?',
            DiffPreview::targets('Remove member from zone "' . $code . '"', $diff),
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'removed' => false, 'code' => $code, 'memberCode' => $memberCode, 'diff' => $diff] + $refusal;
        }

        $body = $current;
        $body['members'] = array_values(array_filter(
            $entries,
            static fn (mixed $entry): bool => self::memberCode($entry) !== $memberCode,
        ));

        try {
            $updated = $this->client->put($path, $body);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['removed' => false, 'code' => $code, 'memberCode' => $memberCode]);
        }

        $this->audit->record(
            tool: 'remove_zone_member',
            targetType: self::TARGET_TYPE,
            targetCode: $code,
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return [
            'ok' => true,
            'removed' => true,
            'code' => $code,
            'memberCode' => $memberCode,
            'diff' => $diff,
            'after' => ZoneOutput::fromPayload($updated, $code),
        ];
    }
}
