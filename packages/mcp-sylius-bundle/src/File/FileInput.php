<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\File;

use Emagma\McpSyliusBundle\Tool\Shared\Base64Content;
use Symfony\Component\Mime\MimeTypes;

/**
 * The file argument of the upload tools: `base64Content` (the bytes) or `file` (ChatGPT's
 * `openai/fileParams` object, snake case being the client's contract), exactly one. The MIME type comes from the bytes.
 */
final readonly class FileInput
{
    /** The `_meta` of a tool whose `file` argument ChatGPT fills with an attached file. */
    public const array META = ['openai/fileParams' => ['file']];

    /**
     * Nullable: OpenAI models send every property and null the unused ones. Set with `#[Schema]` on
     * `__invoke`: the parameter attribute types `type` as a string, which cannot say null.
     */
    public const array SCHEMA = [
        'type' => ['object', 'null'],
        'description' => 'A file attached to the conversation, as the client hands it over (ChatGPT fills it in'
            . ' itself for an attached file). Alternative to base64Content: give one of the two.',
        'properties' => [
            'download_url' => ['type' => 'string', 'description' => 'Temporary URL the server downloads the file from.'],
            'file_id' => ['type' => 'string', 'description' => 'The file\'s id in the client.'],
            'mime_type' => ['type' => 'string'],
            'file_name' => ['type' => 'string', 'description' => 'Used as filename when that argument is omitted.'],
        ],
        'required' => ['download_url', 'file_id'],
    ];

    public const string DESCRIPTION = 'The file is EITHER base64Content (its bytes, base64-encoded; never a'
        . ' /mnt/data path or a URL) OR file (a file attached to the conversation, which ChatGPT fills in'
        . ' itself). filename names the upload (its extension is kept); with file it defaults to the'
        . ' attached file\'s name.';

    public function __construct(
        private RemoteFileDownloader $downloader,
    ) {
    }

    /**
     * @param array<string, mixed>|null $file
     *
     * @throws FileInputFailure
     */
    public function resolve(?string $base64Content, ?array $file, ?string $filename, int $maxBytes): ResolvedFile
    {
        $filename = null !== $filename && '' !== trim($filename) ? trim($filename) : null;
        $hasContent = null !== $base64Content && '' !== trim($base64Content);
        $hasFile = null !== $file && [] !== $file;

        if ($hasContent && $hasFile) {
            throw FileInputFailure::because('file_ambiguous', 'Give the file once: base64Content or file, not both.');
        }

        if ($hasContent) {
            $bytes = self::decode((string) $base64Content);
            if (\strlen($bytes) > $maxBytes) {
                throw FileInputFailure::because('file_too_large', 'Ask the user for a smaller file.', ['size' => \strlen($bytes), 'maxBytes' => $maxBytes]);
            }
            if (null === $filename) {
                throw FileInputFailure::because('filename_required', 'filename names the upload and gives its extension.');
            }

            return new ResolvedFile($bytes, $filename, self::sniff($bytes));
        }
        if (null === $file || [] === $file) {
            throw FileInputFailure::because('file_required', 'Give the file as base64Content (its bytes, base64-encoded) or as file (a file attached to the conversation, which ChatGPT fills in itself).');
        }

        $url = $file['download_url'] ?? null;
        $fileId = $file['file_id'] ?? null;
        if (!\is_string($url) || '' === $url || !\is_string($fileId) || '' === $fileId) {
            throw FileInputFailure::because('file_invalid', 'file must be the object the client gave for the attached file: {download_url, file_id, mime_type?, file_name?}. Do not write it yourself.');
        }
        $bytes = $this->downloader->download($url, $maxBytes);
        $mimeType = self::sniff($bytes);
        $filename ??= self::nameFrom($file, $mimeType);
        if (null === $filename) {
            throw FileInputFailure::because('filename_required', 'The attached file has no name and its type gives no extension: pass filename.', ['mimeType' => $mimeType]);
        }

        return new ResolvedFile($bytes, $filename, $mimeType, $fileId);
    }

    public static function sniff(string $bytes): string
    {
        $mimeType = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($bytes);

        return \is_string($mimeType) && '' !== $mimeType ? $mimeType : 'application/octet-stream';
    }

    /**
     * @throws FileInputFailure
     */
    private static function decode(string $base64Content): string
    {
        // Strict: a lenient decode silently drops characters outside the alphabet and returns junk bytes.
        $normalized = preg_replace('/\s+/', '', $base64Content) ?? '';
        $bytes = '' !== $normalized ? base64_decode($normalized, strict: true) : false;
        if (false === $bytes || '' === $bytes) {
            throw FileInputFailure::because('base64_invalid', Base64Content::INVALID_HINT);
        }

        return $bytes;
    }

    /**
     * @param array<string, mixed> $file
     */
    private static function nameFrom(array $file, string $mimeType): ?string
    {
        $name = $file['file_name'] ?? null;
        if (\is_string($name) && '' !== trim($name)) {
            // A name only: never a path given by the client.
            return basename(trim($name));
        }
        $extensions = MimeTypes::getDefault()->getExtensions($mimeType);

        return [] === $extensions ? null : 'file.' . $extensions[0];
    }
}
