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
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * The admin API declares no POST on Province: creating one means PUTting the country with its
 * `provinces` collection, replaced wholesale - hence the country write permission.
 *
 * @internal
 */
#[McpTool(
    name: 'add_province',
    description: 'Add a province to a Sylius admin country. Required: countryCode, code, name. Optional:'
        . ' abbreviation. There is no POST on provinces in the admin API - this writes the COUNTRY with'
        . ' its province collection, leaving the existing provinces untouched. Returns {added: true,'
        . ' countryCode, code, diff} on success. Tool-specific reasons: country_not_found,'
        . ' province_already_exists.',
)]
#[Permission(resource: 'province', action: Action::Write)]
#[Permission(resource: 'country', action: Action::Write)]
final readonly class AddProvince
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
        string $name,
        ?string $abbreviation = null,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        $path = Route::item(Resource::Country, $countryCode);

        try {
            $country = $this->client->getDecoded($path);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['ok' => false, 'added' => false, 'countryCode' => $countryCode], notFound: 'country_not_found');
        }

        $entries = \is_array($country['provinces'] ?? null) ? array_values($country['provinces']) : [];
        $codes = array_values(array_filter(array_map(
            static fn (mixed $entry): ?string => AdminPayload::codeOf($entry),
            $entries,
        )));

        if (\in_array($code, $codes, true)) {
            return [
                'ok' => false,
                'added' => false,
                'reason' => 'province_already_exists',
                'countryCode' => $countryCode,
                'code' => $code,
            ];
        }

        $province = ['code' => $code, 'name' => $name];
        if (null !== $abbreviation && '' !== $abbreviation) {
            $province['abbreviation'] = $abbreviation;
        }

        $diff = ['provinces' => ['before' => $codes, 'after' => [...$codes, $code]]];

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm adding province "' . $code . '" to country "' . $countryCode . '"',
            'Add this province?',
            DiffPreview::targets('Add province to country "' . $countryCode . '"', $diff),
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'added' => false, 'countryCode' => $countryCode, 'code' => $code, 'diff' => $diff] + $refusal;
        }

        $body = $country;
        $body['provinces'] = [...$entries, $province];

        try {
            $updated = $this->client->put($path, $body);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['added' => false, 'countryCode' => $countryCode, 'code' => $code]);
        }

        $this->audit->record(
            tool: 'add_province',
            targetType: self::TARGET_TYPE,
            targetCode: $countryCode,
            beforeState: $country,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return ['ok' => true, 'added' => true, 'countryCode' => $countryCode, 'code' => $code, 'diff' => $diff];
    }
}
