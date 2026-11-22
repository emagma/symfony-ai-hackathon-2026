<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\File;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The URL is a tool argument, written by the model: only `https` to an allow-listed host is fetched (SSRF),
 * and the response is dropped as soon as it passes the cap. The URL is a bearer: never in an answer, log or audit entry.
 */
final readonly class RemoteFileDownloader
{
    /**
     * @param HttpClientInterface $client       wired as a NoPrivateNetworkHttpClient: a private address is never connected to
     * @param list<string>        $allowedHosts each matches itself and its subdomains; a glob matches the whole host; `*` allows any
     */
    public function __construct(
        private HttpClientInterface $client,
        private array $allowedHosts,
    ) {
    }

    /**
     * @throws FileInputFailure
     */
    public function download(string $url, int $maxBytes): string
    {
        $this->assertAllowed($url);

        try {
            // No redirect: a hop could leave the allowed host. Set here so that no wiring can drop it.
            $response = $this->client->request('GET', $url, ['buffer' => false, 'max_redirects' => 0]);
            $status = $response->getStatusCode();
            if (200 !== $status) {
                $response->cancel();

                throw FileInputFailure::because(
                    'file_download_failed',
                    'The client did not serve the file (its download URL is temporary: ask the user to attach the file again).',
                    ['status' => $status],
                );
            }
            $length = $response->getHeaders(false)['content-length'][0] ?? null;
            if (null !== $length && is_numeric($length) && (int) $length > $maxBytes) {
                $response->cancel();

                throw self::tooLarge((int) $length, $maxBytes);
            }

            $bytes = '';
            foreach ($this->client->stream($response) as $chunk) {
                $bytes .= $chunk->getContent();
                if (\strlen($bytes) > $maxBytes) {
                    $response->cancel();

                    throw self::tooLarge(\strlen($bytes), $maxBytes);
                }
            }
        } catch (TransportExceptionInterface $exception) {
            throw FileInputFailure::because(
                'file_download_failed',
                'The file could not be downloaded from the client (its download URL is temporary: ask the user to attach the file again).',
                ['detail' => $exception->getMessage()],
            );
        }
        if ('' === $bytes) {
            throw FileInputFailure::because('file_download_failed', 'The client served an empty file.');
        }

        return $bytes;
    }

    private function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        $host = \is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $scheme = \is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if ('https' !== $scheme || '' === $host) {
            throw FileInputFailure::because('file_url_refused', 'file.download_url must be the https URL the client gave with the attached file; the server fetches nothing else.');
        }
        foreach ($this->allowedHosts as $allowed) {
            $allowed = strtolower($allowed);
            if (str_contains($allowed, '*')) {
                if (fnmatch($allowed, $host)) {
                    return;
                }

                continue;
            }
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return;
            }
        }

        throw FileInputFailure::because(
            'file_url_refused',
            'The server downloads files from the client\'s file store only (emagma_mcp_sylius.file_params.allowed_hosts); this URL is elsewhere. Do not retry with another URL.',
            ['host' => $host],
        );
    }

    private static function tooLarge(int $size, int $maxBytes): FileInputFailure
    {
        return FileInputFailure::because('file_too_large', 'Ask the user for a smaller file.', ['size' => $size, 'maxBytes' => $maxBytes]);
    }
}
