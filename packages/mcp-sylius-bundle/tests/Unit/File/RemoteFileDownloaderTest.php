<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\File;

use Emagma\McpSyliusBundle\File\FileInputFailure;
use Emagma\McpSyliusBundle\File\RemoteFileDownloader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(RemoteFileDownloader::class)]
#[CoversClass(FileInputFailure::class)]
final class RemoteFileDownloaderTest extends TestCase
{
    private const string URL = 'https://files.oaiusercontent.com/file-abc?sig=secret';

    public function testItDownloadsFromTheClientFileStore(): void
    {
        $requested = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = [$method, $url];

            return new MockResponse('PNG bytes');
        });

        $bytes = $this->downloader($http)->download(self::URL, 1024);

        self::assertSame('PNG bytes', $bytes);
        self::assertSame([['GET', self::URL]], $requested);
    }

    public function testASubdomainOfAnAllowedHostIsAllowed(): void
    {
        $http = new MockHttpClient(new MockResponse('ok'));

        self::assertSame('ok', $this->downloader($http, ['oaiusercontent.com'])->download('https://sdmntpr.oaiusercontent.com/f', 10));
    }

    /** What ChatGPT actually sends (2026-10): a regional Azure blob account, not oaiusercontent.com. */
    public function testAGlobMatchesChatGptsRegionalBlobAccounts(): void
    {
        $http = new MockHttpClient(new MockResponse('ok'));
        $downloader = $this->downloader($http, ['oaisdmntpr*.blob.core.windows.net']);

        self::assertSame('ok', $downloader->download('https://oaisdmntprindiasocentral.blob.core.windows.net/files/x/raw?se=1&sig=s', 10));

        $this->expectException(FileInputFailure::class);
        $downloader->download('https://evil.blob.core.windows.net/f', 10);
    }

    public function testAHostOutsideTheAllowListIsNeverFetched(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => self::fail('the server must not reach a host the model chose'));

        try {
            $this->downloader($http)->download('https://keycloak:8085/realms/sylius', 1024);
            self::fail('expected a refusal');
        } catch (FileInputFailure $failure) {
            self::assertSame('file_url_refused', $failure->failure['reason']);
            self::assertSame('keycloak', $failure->failure['host']);
            self::assertStringContainsString('allowed_hosts', $failure->failure['hint']);
        }
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testALookAlikeHostIsRefused(): void
    {
        $http = new MockHttpClient(new MockResponse('ok'));

        $this->expectException(FileInputFailure::class);
        $this->downloader($http)->download('https://oaiusercontent.com.evil.example/f', 1024);
    }

    public function testPlainHttpIsRefused(): void
    {
        $http = new MockHttpClient(new MockResponse('ok'));

        try {
            $this->downloader($http)->download('http://files.oaiusercontent.com/f', 1024);
            self::fail('expected a refusal');
        } catch (FileInputFailure $failure) {
            self::assertSame('file_url_refused', $failure->failure['reason']);
        }
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testAWildcardAllowsEveryHost(): void
    {
        $http = new MockHttpClient(new MockResponse('ok'));

        self::assertSame('ok', $this->downloader($http, ['*'])->download('https://anything.example/f', 10));
    }

    public function testThePublicOnlyClientRefusesPrivateAddressesWhateverTheAllowList(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => self::fail('a private address must not be requested'));
        $downloader = new RemoteFileDownloader(new NoPrivateNetworkHttpClient($http), ['*']);

        foreach (['https://127.0.0.1/f', 'https://10.0.0.8/f', 'https://[fe80::1]/f'] as $url) {
            try {
                $downloader->download($url, 1024);
                self::fail('expected a failure for ' . $url);
            } catch (FileInputFailure $failure) {
                self::assertSame('file_download_failed', $failure->failure['reason']);
                self::assertStringContainsString('blocked', $failure->failure['detail']);
            }
        }
    }

    public function testARedirectIsNotFollowed(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame(0, $options['max_redirects']);

            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://keycloak:8085/x']]);
        });

        try {
            $this->downloader($http)->download(self::URL, 1024);
            self::fail('expected a failure');
        } catch (FileInputFailure $failure) {
            self::assertSame(['file_download_failed', 302], [$failure->failure['reason'], $failure->failure['status']]);
        }
        self::assertSame(1, $http->getRequestsCount(), 'the Location is never requested');
    }

    public function testAnExpiredUrlIsReportedAsADownloadFailure(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 403]));

        try {
            $this->downloader($http)->download(self::URL, 1024);
            self::fail('expected a failure');
        } catch (FileInputFailure $failure) {
            self::assertSame('file_download_failed', $failure->failure['reason']);
            self::assertSame(403, $failure->failure['status']);
            self::assertStringContainsString('attach the file again', $failure->failure['hint']);
        }
    }

    public function testATransportErrorIsReportedAsADownloadFailure(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['error' => 'connection reset']));

        try {
            $this->downloader($http)->download(self::URL, 1024);
            self::fail('expected a failure');
        } catch (FileInputFailure $failure) {
            self::assertSame('file_download_failed', $failure->failure['reason']);
            self::assertStringContainsString('connection reset', $failure->failure['detail']);
        }
    }

    public function testAnAnnouncedSizeOverTheCapIsRefusedBeforeTheBody(): void
    {
        $http = new MockHttpClient(new MockResponse(str_repeat('x', 2048), ['response_headers' => ['Content-Length: 2048']]));

        try {
            $this->downloader($http)->download(self::URL, 1024);
            self::fail('expected a failure');
        } catch (FileInputFailure $failure) {
            self::assertSame(['reason' => 'file_too_large', 'size' => 2048, 'maxBytes' => 1024], array_diff_key($failure->failure, ['hint' => true]));
        }
    }

    public function testABodyOverTheCapIsDroppedWhileStreaming(): void
    {
        $http = new MockHttpClient(new MockResponse(['a chunk', 'another chunk', str_repeat('x', 100)]));

        try {
            $this->downloader($http)->download(self::URL, 15);
            self::fail('expected a failure');
        } catch (FileInputFailure $failure) {
            self::assertSame('file_too_large', $failure->failure['reason']);
            self::assertSame(15, $failure->failure['maxBytes']);
            self::assertSame(20, $failure->failure['size'], 'the stream is cut at the first chunk over the cap, not read to the end');
        }
    }

    public function testAnEmptyBodyIsAFailure(): void
    {
        $http = new MockHttpClient(new MockResponse(''));

        $this->expectException(FileInputFailure::class);
        $this->expectExceptionMessage('file_download_failed');
        $this->downloader($http)->download(self::URL, 1024);
    }

    /**
     * @param list<string> $allowedHosts
     */
    private function downloader(MockHttpClient $http, array $allowedHosts = ['oaiusercontent.com']): RemoteFileDownloader
    {
        return new RemoteFileDownloader($http, $allowedHosts);
    }
}
