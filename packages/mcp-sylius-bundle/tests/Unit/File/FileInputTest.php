<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\File;

use Emagma\McpSyliusBundle\File\FileInput;
use Emagma\McpSyliusBundle\File\FileInputFailure;
use Emagma\McpSyliusBundle\File\RemoteFileDownloader;
use Emagma\McpSyliusBundle\File\ResolvedFile;
use Emagma\McpSyliusBundle\Tool\Shared\Base64Content;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(FileInput::class)]
#[CoversClass(ResolvedFile::class)]
#[CoversClass(Base64Content::class)]
final class FileInputTest extends TestCase
{
    private const string PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const array ATTACHED = ['download_url' => 'https://files.oaiusercontent.com/file-1?sig=s', 'file_id' => 'file_1', 'mime_type' => 'image/png', 'file_name' => 'mug.png'];

    public function testBase64ContentIsDecodedStrictlyAndSniffed(): void
    {
        $file = $this->input()->resolve(' ' . self::PIXEL . "\n", null, 'mug.png', 1024);

        self::assertSame((string) base64_decode(self::PIXEL, true), $file->bytes);
        self::assertSame(['mug.png', 'image/png', 70, null], [$file->filename, $file->mimeType, $file->size, $file->fileId]);
    }

    public function testAPathInBase64ContentIsRefusedWithTheHintPointingAtFile(): void
    {
        try {
            $this->input()->resolve('/mnt/data/mug.png', null, 'mug.png', 1024);
            self::fail('expected a failure');
        } catch (FileInputFailure $failure) {
            self::assertSame(['reason' => 'base64_invalid', 'hint' => Base64Content::INVALID_HINT], $failure->failure);
            self::assertStringContainsString('`file` argument', Base64Content::INVALID_HINT);
        }
    }

    public function testBase64ContentNeedsAFilename(): void
    {
        $this->expectException(FileInputFailure::class);
        $this->expectExceptionMessage('filename_required');
        $this->input()->resolve(self::PIXEL, null, ' ', 1024);
    }

    public function testBase64ContentOverTheCapIsRefused(): void
    {
        try {
            $this->input()->resolve(self::PIXEL, null, 'mug.png', 10);
            self::fail('expected a failure');
        } catch (FileInputFailure $failure) {
            self::assertSame(['reason' => 'file_too_large', 'size' => 70, 'maxBytes' => 10], array_diff_key($failure->failure, ['hint' => true]));
        }
    }

    public function testAnAttachedFileIsDownloadedAndNamedAfterTheClientsName(): void
    {
        $png = (string) base64_decode(self::PIXEL, true);
        $http = new MockHttpClient(new MockResponse($png));

        $file = $this->input($http)->resolve(null, self::ATTACHED, null, 1024);

        self::assertSame($png, $file->bytes);
        self::assertSame(['mug.png', 'image/png', 'file_1'], [$file->filename, $file->mimeType, $file->fileId]);
    }

    public function testTheFilenameArgumentWinsOverTheClientsName(): void
    {
        $http = new MockHttpClient(new MockResponse((string) base64_decode(self::PIXEL, true)));

        self::assertSame('hero.png', $this->input($http)->resolve(null, self::ATTACHED, 'hero.png', 1024)->filename);
    }

    public function testTheClientsNameIsKeptWithoutItsPath(): void
    {
        $http = new MockHttpClient(new MockResponse((string) base64_decode(self::PIXEL, true)));

        self::assertSame('mug.png', $this->input($http)->resolve(null, ['file_name' => '/mnt/data/mug.png'] + self::ATTACHED, null, 1024)->filename);
    }

    public function testANamelessFileIsNamedFromItsSniffedType(): void
    {
        $http = new MockHttpClient(new MockResponse((string) base64_decode(self::PIXEL, true)));

        $file = $this->input($http)->resolve(null, ['download_url' => self::ATTACHED['download_url'], 'file_id' => 'file_1', 'mime_type' => 'text/plain'], null, 1024);

        self::assertSame('file.png', $file->filename, 'the bytes decide, not the client\'s mime_type');
        self::assertSame('image/png', $file->mimeType);
    }

    public function testAFileObjectWithoutItsUrlOrIdIsRefused(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => self::fail('nothing to download'));

        try {
            $this->input($http)->resolve(null, ['download_url' => 'https://files.oaiusercontent.com/f'], null, 1024);
            self::fail('expected a failure');
        } catch (FileInputFailure $failure) {
            self::assertSame('file_invalid', $failure->failure['reason']);
        }
    }

    public function testNeitherArgumentIsRefusedWithBothNamed(): void
    {
        try {
            $this->input()->resolve('', [], 'mug.png', 1024);
            self::fail('expected a failure');
        } catch (FileInputFailure $failure) {
            self::assertSame('file_required', $failure->failure['reason']);
            self::assertStringContainsString('base64Content', $failure->failure['hint']);
            self::assertStringContainsString('as file', $failure->failure['hint']);
        }
    }

    public function testBothArgumentsAreRefused(): void
    {
        $this->expectException(FileInputFailure::class);
        $this->expectExceptionMessage('file_ambiguous');
        $this->input()->resolve(self::PIXEL, self::ATTACHED, 'mug.png', 1024);
    }

    public function testTheSchemaIsWhatChatGptRequires(): void
    {
        self::assertSame(['openai/fileParams' => ['file']], FileInput::META);
        self::assertSame(['download_url', 'file_id', 'mime_type', 'file_name'], array_keys(FileInput::SCHEMA['properties']), 'all four properties declared');
        self::assertSame(['download_url', 'file_id'], FileInput::SCHEMA['required'], 'and only these two required');
        self::assertSame(['object', 'null'], FileInput::SCHEMA['type'], 'an OpenAI model nulls the arguments it does not use');
    }

    private function input(?MockHttpClient $http = null): FileInput
    {
        return new FileInput(new RemoteFileDownloader($http ?? new MockHttpClient(), ['oaiusercontent.com']));
    }
}
