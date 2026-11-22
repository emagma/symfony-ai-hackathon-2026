<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Province;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\DiffPreview;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\Province\ProvinceOutput;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * `code` is not in the `province:update` serialization group: it cannot change here.
 *
 * @internal
 */
#[McpTool(
    name: 'update_province',
    description: 'Update one Sylius admin province. BOTH codes are required (the province is addressed under its'
        . ' country). code CANNOT change - the admin API does not accept it on update; use'
        . ' remove_province + add_province for that. Editable: name, abbreviation. Returns {updated: true,'
        . ' countryCode, code, diff, before, after} on success.',
)]
#[Permission(resource: 'province', action: Action::Write)]
final readonly class UpdateProvince
{
    use WriteConfirmationTrait;

    private const string TARGET_TYPE = 'province';

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $countryCode,
        string $code,
        ?string $name = null,
        ?string $abbreviation = null,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        if (null === $name && null === $abbreviation) {
            return [
                'ok' => false,
                'updated' => false,
                'reason' => 'no_change_requested',
                'countryCode' => $countryCode,
                'code' => $code,
            ];
        }

        $path = Route::item(Resource::Province, $code, ['country' => $countryCode]);

        try {
            $current = $this->client->getDecoded($path);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['updated' => false, 'countryCode' => $countryCode, 'code' => $code]);
        }

        $diff = [];
        foreach (['name' => $name, 'abbreviation' => $abbreviation] as $field => $requested) {
            if (null === $requested) {
                continue;
            }
            $before = \is_string($current[$field] ?? null) ? $current[$field] : null;
            if ($before !== $requested) {
                $diff[$field] = ['before' => $before, 'after' => $requested];
            }
        }

        if ([] === $diff) {
            return ['ok' => false, 'updated' => false, 'reason' => 'no_change', 'countryCode' => $countryCode, 'code' => $code];
        }

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm province update on "' . $code . '"',
            'Apply this province update?',
            DiffPreview::targets('Update province "' . $code . '" of country "' . $countryCode . '"', $diff),
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'updated' => false, 'countryCode' => $countryCode, 'code' => $code, 'diff' => $diff] + $refusal;
        }

        $body = $current;
        foreach ($diff as $field => $change) {
            $body[$field] = $change['after'];
        }

        try {
            $updated = $this->client->put($path, $body);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['updated' => false, 'countryCode' => $countryCode, 'code' => $code]);
        }

        $this->audit->record(
            tool: 'update_province',
            targetType: self::TARGET_TYPE,
            targetCode: $code,
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return [
            'ok' => true,
            'updated' => true,
            'countryCode' => $countryCode,
            'code' => $code,
            'diff' => $diff,
            'before' => ProvinceOutput::fromPayload($current, $code),
            'after' => ProvinceOutput::fromPayload($updated, $code),
        ];
    }
}
