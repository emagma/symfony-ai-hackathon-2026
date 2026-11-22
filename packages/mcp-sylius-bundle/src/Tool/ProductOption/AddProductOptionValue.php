<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductOption;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\DiffPreview;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\ProductOption\ProductOptionOutput;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * ProductOptionValue is read-only in the admin API: the option PUT is the only lever and replaces
 * `values` whole, so the existing ones are re-sent exactly as the GET returned them.
 *
 * @internal
 */
#[McpTool(
    name: 'add_product_option_value',
    description: 'Add ONE value to an existing Sylius admin product option, leaving every other value untouched.'
        . ' Prefer this over upsert_product_options(values=) when you only want to append: that argument'
        . ' replaces the whole set and DELETES the codes you omit. Arguments: code (the option), valueCode'
        . ' (the new value code), label (its name in `locale`), locale. Returns {added: true, code,'
        . ' valueCode, diff, before, after} on success. Tool-specific reasons: value_already_exists.',
)]
#[Permission(resource: 'product_option', action: Action::Write)]
final readonly class AddProductOptionValue
{
    use WriteConfirmationTrait;

    private const string TARGET_TYPE = 'product_option';

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
        private string $defaultLocale,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $code,
        string $valueCode,
        string $label,
        ?string $locale = null,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        $locale ??= $this->defaultLocale;

        $optionPath = Route::item(Resource::ProductOption, $code);

        try {
            $current = $this->client->getDecoded($optionPath);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['added' => false, 'code' => $code]);
        }

        $existingLabel = $this->existingValueLabel($code, $valueCode, $locale);
        if (null !== $existingLabel) {
            return [
                'ok' => false,
                'added' => false,
                'reason' => 'value_already_exists',
                'code' => $code,
                'valueCode' => $valueCode,
                'label' => $existingLabel['label'],
                'hint' => 'Use upsert_product_options to relabel an existing value.',
            ];
        }

        $diff = ['value.' . $valueCode => ['before' => null, 'after' => $label]];

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm adding value "' . $valueCode . '" to product option "' . $code . '"',
            'Add this product option value?',
            DiffPreview::targets('Add value to product option "' . $code . '"', $diff),
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'added' => false, 'code' => $code, 'valueCode' => $valueCode, 'diff' => $diff] + $refusal;
        }

        $body = $current;
        $values = \is_array($current['values'] ?? null) ? array_values($current['values']) : [];
        $values[] = [
            'code' => $valueCode,
            'translations' => [$locale => ['value' => $label, 'locale' => $locale]],
        ];
        $body['values'] = $values;

        try {
            $updated = $this->client->put($optionPath, $body);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['added' => false, 'code' => $code, 'valueCode' => $valueCode]);
        }

        $this->audit->record(
            tool: 'add_product_option_value',
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
            'valueCode' => $valueCode,
            'diff' => $diff,
            'before' => ProductOptionOutput::fromPayload($current, $locale, $code),
            'after' => ProductOptionOutput::fromPayload($updated, $locale, $code),
        ];
    }

    /**
     * @return array{label: string|null}|null
     */
    private function existingValueLabel(string $optionCode, string $valueCode, string $locale): ?array
    {
        try {
            $value = $this->client->getDecoded(
                Route::item(Resource::ProductOptionValue, $valueCode, ['option' => $optionCode]),
            );
        } catch (ClientException $exception) {
            if (404 === $exception->getResponse()->getStatusCode()) {
                return null;
            }

            throw $exception;
        }

        return ['label' => AdminPayload::translation($value['translations'] ?? [], $locale, 'value')];
    }
}
