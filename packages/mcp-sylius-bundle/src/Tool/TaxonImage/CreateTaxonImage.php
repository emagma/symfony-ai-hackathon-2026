<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\TaxonImage;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\Irreversible;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\File\FileInput;
use Emagma\McpSyliusBundle\File\FileInputFailure;
use Emagma\McpSyliusBundle\Output\TaxonImage\TaxonImageOutput;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Adds an image to a taxon from a file the client uploaded or a URL.
 *
 * @internal
 */
#[McpTool(
    name: 'create_taxon_image',
    description: 'Upload an image to a Sylius admin taxon. Requires the parent taxon `code` and the image. '
        . FileInput::DESCRIPTION
        . ' Optional fields: `type` (free-form discriminator); `mimeType` (by default, sniffed from the bytes).'
        . ' Returns {created: true, code, id, after} on success. Tool-specific reasons: taxon_not_found,'
        . ' file_required, file_ambiguous, base64_invalid, file_download_failed, file_url_refused, file_too_large.',
    meta: FileInput::META,
)]
#[Permission(resource: 'taxon_image', action: Action::Write)]
#[Irreversible]
final readonly class CreateTaxonImage
{
    use WriteConfirmationTrait;

    private const string TARGET_TYPE = 'taxon_image';

    private const int MAX_BYTES = 5 * 1024 * 1024; // 5 MB safety cap

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
        private FileInput $files,
    ) {
    }

    /**
     * @param array<string, mixed>|null $file
     *
     * @return array<string, mixed>
     */
    #[Schema(properties: ['file' => FileInput::SCHEMA])]
    public function __invoke(
        string $code,
        ?string $base64Content = null,
        ?string $filename = null,
        ?array $file = null,
        ?string $type = null,
        ?string $mimeType = null,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        try {
            $this->client->getDecoded(
                Route::item(Resource::Taxon, $code),
            );
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['ok' => false, 'created' => false, 'code' => $code], notFound: 'taxon_not_found');
        }

        try {
            $resolved = $this->files->resolve($base64Content, $file, $filename, self::MAX_BYTES);
        } catch (FileInputFailure $failure) {
            return ['ok' => false, 'created' => false, 'code' => $code] + $failure->failure;
        }
        $bytes = $resolved->bytes;
        $filename = $resolved->filename;
        $size = $resolved->size;
        $mimeType ??= $resolved->mimeType;

        $preview = $this->renderPreview($code, $filename, $mimeType, $size, $type);
        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm taxon image upload on "' . $code . '"',
            'Upload this image to the taxon?',
            $preview,
            'Re-invoke with confirm: true to upload.',
        );
        if (null !== $refusal) {
            $out = ['ok' => false, 'created' => false, 'code' => $code] + $refusal;
            if (isset($refusal['hint'])) {
                $out['preview'] = $preview;
            }

            return $out;
        }

        $parts = [
            'file' => new DataPart($bytes, $filename, $mimeType),
        ];
        if (null !== $type && '' !== $type) {
            $parts['type'] = $type;
        }

        try {
            // Not lean: the lean projection strips id, path and owner from the POST response.
            $created = $this->client->postMultipart(
                Route::collection(Resource::TaxonImage, ['taxon' => $code]),
                $parts,
            );
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['created' => false, 'code' => $code]);
        }

        $createdId = \is_int($created['id'] ?? null) ? $created['id'] : 0;
        $this->audit->record(
            tool: 'create_taxon_image',
            targetType: self::TARGET_TYPE,
            targetCode: $code . ':' . $createdId,
            beforeState: [],
            afterState: $created,
            diff: [
                'created' => ['before' => false, 'after' => true],
                'filename' => ['before' => null, 'after' => $filename],
                'mimeType' => ['before' => null, 'after' => $mimeType],
                'size' => ['before' => null, 'after' => $size],
                'type' => ['before' => null, 'after' => $type],
            ] + (null === $resolved->fileId ? [] : ['fileId' => ['before' => null, 'after' => $resolved->fileId]]),
            context: $context,
        );

        return [
            'ok' => true,
            'created' => true,
            'code' => $code,
            'id' => $createdId,
            'after' => TaxonImageOutput::fromPayload($created, $code, $createdId),
        ];
    }

    private function renderPreview(string $code, string $filename, string $mimeType, int $size, ?string $type): string
    {
        return implode("\n", [
            'Upload taxon image to "' . $code . '"',
            '',
            '- filename: ' . $filename,
            '- mimeType: ' . $mimeType,
            '- size: ' . $size . ' bytes',
            '- type: ' . ($type ?? '(none)'),
        ]);
    }
}
