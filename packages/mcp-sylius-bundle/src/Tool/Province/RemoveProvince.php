<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Province;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\Irreversible;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\DiffPreview;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * The admin API declares no DELETE on Province: this goes through the country PUT.
 *
 * @internal
 */
#[McpTool(
    name: 'remove_province',
    description: 'Remove a province from a Sylius admin country. Required: countryCode, code. There is no DELETE'
        . ' on provinces in the admin API - this writes the COUNTRY with its province collection, leaving'
        . ' every other province untouched. Addresses and zones referencing the province lose their target,'
        . ' so Sylius often refuses: that comes back as reason `conflict`. Returns {removed: true,'
        . ' countryCode, code, diff} on success. Tool-specific reasons: country_not_found,'
        . ' province_not_found. NOT revertable.',
)]
#[Permission(resource: 'province', action: Action::Write)]
#[Permission(resource: 'country', action: Action::Write)]
#[Irreversible]
final readonly class RemoveProvince
{
    use WriteConfirmationTrait;

    private const string TARGET_TYPE = 'country';

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
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        $path = Route::item(Resource::Country, $countryCode);

        try {
            $country = $this->client->getDecoded($path);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['ok' => false, 'removed' => false, 'countryCode' => $countryCode], notFound: 'country_not_found');
        }

        $entries = \is_array($country['provinces'] ?? null) ? array_values($country['provinces']) : [];
        $codes = array_values(array_filter(array_map(
            static fn (mixed $entry): ?string => AdminPayload::codeOf($entry),
            $entries,
        )));

        if (!\in_array($code, $codes, true)) {
            return [
                'ok' => false,
                'removed' => false,
                'reason' => 'province_not_found',
                'countryCode' => $countryCode,
                'code' => $code,
                'provinces' => $codes,
            ];
        }

        $remaining = array_values(array_filter($codes, static fn (string $c): bool => $c !== $code));
        $diff = ['provinces' => ['before' => $codes, 'after' => $remaining]];

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm removing province "' . $code . '" from country "' . $countryCode . '"',
            'Remove this province?',
            DiffPreview::targets('Remove province from country "' . $countryCode . '"', $diff),
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'removed' => false, 'countryCode' => $countryCode, 'code' => $code, 'diff' => $diff] + $refusal;
        }

        $body = $country;
        $body['provinces'] = array_values(array_filter(
            $entries,
            static fn (mixed $entry): bool => AdminPayload::codeOf($entry) !== $code,
        ));

        try {
            $updated = $this->client->put($path, $body);
        } catch (ClientException $exception) {
            // A 409 here: Sylius refuses to orphan the addresses and zones referencing the province.
            return ApiFailure::describe($exception, ['removed' => false, 'countryCode' => $countryCode, 'code' => $code]);
        }

        $this->audit->record(
            tool: 'remove_province',
            targetType: self::TARGET_TYPE,
            targetCode: $countryCode,
            beforeState: $country,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return ['ok' => true, 'removed' => true, 'countryCode' => $countryCode, 'code' => $code, 'diff' => $diff];
    }
}
