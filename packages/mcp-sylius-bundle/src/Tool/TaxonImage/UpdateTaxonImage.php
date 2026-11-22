<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\TaxonImage;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\TaxonImage\TaxonImageOutput;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * `type` is the only writable field, but the Sylius admin PUT still requires the full current payload.
 *
 * @internal
 */
#[McpTool(
    name: 'update_taxon_image',
    description: 'Update the type discriminator of a Sylius admin taxon image. Requires the parent taxon `code`'
        . ' and the numeric image `id`. `type` is the only writable field - pass an empty string to clear'
        . ' it. Returns {updated: true, code, id, diff, before, after} on success.',
)]
#[Permission(resource: 'taxon_image', action: Action::Write)]
final readonly class UpdateTaxonImage
{
    use WriteConfirmationTrait;

    private const string TARGET_TYPE = 'taxon_image';

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
        int $id,
        ?string $type = null,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        if (null === $type) {
            return ['ok' => false, 'updated' => false, 'reason' => 'no_change_requested', 'code' => $code, 'id' => $id];
        }

        try {
            $current = $this->client->getDecoded(
                Route::item(Resource::TaxonImage, $id, ['taxon' => $code]),
            );
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['updated' => false, 'code' => $code, 'id' => $id]);
        }

        $before = \is_string($current['type'] ?? null) ? $current['type'] : null;
        $after = '' === $type ? null : $type;
        if ($before === $after) {
            return ['ok' => false, 'updated' => false, 'reason' => 'no_change', 'code' => $code, 'id' => $id];
        }
        $diff = ['type' => ['before' => $before, 'after' => $after]];

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm taxon image update on "' . $code . '" #' . $id,
            'Apply this taxon image type update?',
            $this->renderPreview($code, $id, $before, $after),
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'updated' => false, 'code' => $code, 'id' => $id, 'diff' => $diff] + $refusal;
        }

        $body = $current;
        $body['type'] = $after;

        try {
            $updated = $this->client->put(
                Route::item(Resource::TaxonImage, $id, ['taxon' => $code]),
                $body,
            );
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['updated' => false, 'code' => $code, 'id' => $id]);
        }

        $this->audit->record(
            tool: 'update_taxon_image',
            targetType: self::TARGET_TYPE,
            targetCode: $code . ':' . $id,
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return [
            'ok' => true,
            'updated' => true,
            'code' => $code,
            'id' => $id,
            'diff' => $diff,
            'before' => TaxonImageOutput::fromPayload($current, $code, $id),
            'after' => TaxonImageOutput::fromPayload($updated, $code, $id),
        ];
    }

    private function renderPreview(string $code, int $id, ?string $before, ?string $after): string
    {
        return implode("\n", [
            'Update taxon image "' . $code . '" #' . $id,
            '',
            '- type: ' . (null === $before ? 'null' : '"' . $before . '"')
                . ' -> ' . (null === $after ? 'null' : '"' . $after . '"'),
        ]);
    }
}
