<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Taxon;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Shared\ReferenceResolver;
use Emagma\McpSyliusBundle\Tool\Taxon\TaxonUpdatePayloadBuilder;
use Emagma\McpSyliusBundle\Tool\Taxon\UpsertTaxons;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(UpsertTaxons::class)]
final class UpsertTaxonsTest extends McpToolTestCase
{
    public function testACreationFillsEveryLocaleAndResolvesTheParentOnceForTheBatch(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())->method('getDecoded')->with('locales', ['itemsPerPage' => 100])
            ->willReturn($this->collection([['code' => 'en_US'], ['code' => 'fr_FR']]));
        $this->shop($client, existing: [], parents: ['cats' => '/api/v2/admin/taxons/cats']);
        $client->expects(self::once())->method('postMany')
            ->with([
                'persians' => ['path' => 'taxons', 'body' => [
                    'code' => 'persians',
                    'translations' => [
                        'en_US' => ['name' => 'Persians', 'slug' => 'persians', 'locale' => 'en_US'],
                        'fr_FR' => ['name' => 'Persians', 'slug' => 'persians', 'locale' => 'fr_FR'],
                    ],
                    'enabled' => true,
                    'parent' => '/api/v2/admin/taxons/cats',
                    'position' => 2,
                ]],
                'birds' => ['path' => 'taxons', 'body' => [
                    'code' => 'birds',
                    'translations' => [
                        'en_US' => ['name' => 'Birds', 'locale' => 'en_US'],
                        'fr_FR' => ['name' => 'Birds', 'locale' => 'fr_FR'],
                    ],
                    'enabled' => false,
                ]],
            ])
            ->willReturn(['persians' => ['ok' => true, 'body' => ['code' => 'persians']], 'birds' => ['ok' => true, 'body' => ['code' => 'birds']]]);

        $result = $this->call($this->tool($client), items: [
            ['code' => 'persians', 'name' => 'Persians', 'slug' => 'persians', 'parentCode' => 'cats', 'position' => 2],
            ['code' => 'birds', 'name' => 'Birds', 'enabled' => false],
        ], confirm: true);

        self::assertSame(2, $result['created']);
        self::assertSame(['before' => null, 'after' => 'cats'], $result['results']['persians']['diff']['parent']);
    }

    public function testAnUnknownParentFailsTheItemAloneBeforeAnyWrite(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US']]));
        $this->shop($client, existing: [], parents: []);
        $client->expects(self::once())->method('postMany')
            ->with(self::callback(static fn (array $requests): bool => ['birds'] === array_keys($requests)))
            ->willReturn(['birds' => ['ok' => true, 'body' => ['code' => 'birds']]]);

        $result = $this->call($this->tool($client), items: [
            ['code' => 'persians', 'name' => 'Persians', 'parentCode' => 'ghost'],
            ['code' => 'birds', 'name' => 'Birds'],
        ], confirm: true);

        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'parent_not_found', 'parentCode' => 'ghost'], $result['results']['persians']);
        self::assertSame('created', $result['results']['birds']['action']);
    }

    public function testATaxonCannotBeItsOwnParent(): void
    {
        $result = $this->call($this->tool($this->apiClient()), items: [['code' => 'cats', 'name' => 'Cats', 'parentCode' => 'cats']], confirm: true);

        self::assertSame('items_invalid', $result['reason']);
        self::assertSame('a taxon cannot be its own parent', $result['errors'][0]['message']);
    }

    public function testAnUpdateGoesThroughTheSingleToolsPayloadBuilder(): void
    {
        $current = [
            '@id' => '/api/v2/admin/taxons/cats', 'code' => 'cats', 'enabled' => true, 'position' => 0,
            'translations' => ['en_US' => ['@id' => '/api/v2/admin/taxon-translations/1', 'name' => 'Cats', 'slug' => 'cats', 'locale' => 'en_US']],
        ];
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US']]));
        $this->shop($client, existing: ['cats' => $current], parents: []);
        $expected = $current;
        $expected['translations']['en_US']['name'] = 'Felines';
        $expected['enabled'] = false;
        $client->expects(self::once())->method('putMany')
            ->with(['cats' => ['path' => 'taxons/cats', 'body' => $expected]])
            ->willReturn(['cats' => ['ok' => true, 'body' => $expected]]);

        $result = $this->call($this->tool($client), items: [['code' => 'cats', 'name' => 'Felines', 'enabled' => false, 'slug' => 'cats']], confirm: true);

        self::assertSame('updated', $result['results']['cats']['action']);
        self::assertSame(['en_US.name' => ['before' => 'Cats', 'after' => 'Felines'], 'enabled' => ['before' => true, 'after' => false]], $result['results']['cats']['diff']);
    }

    private function tool(MockObject $client): UpsertTaxons
    {
        return new UpsertTaxons($client, new ReferenceResolver($client), $this->audit(), new TaxonUpdatePayloadBuilder($this->adapter()), 'en_US');
    }

    /**
     * The probe (keyed by code) and the parent lookup (keyed resource\0code) are told apart by the key shape.
     *
     * @param array<string, array<string, mixed>> $existing code => payload
     * @param array<string, string>               $parents  code => IRI
     */
    private function shop(MockObject $client, array $existing, array $parents): void
    {
        $client->method('getDecodedMany')->willReturnCallback(static function (array $paths) use ($existing, $parents): array {
            $out = [];
            foreach ($paths as $key => $path) {
                $path = (string) $path;
                if (str_contains($key, "\0")) {
                    [, $code] = explode("\0", $key, 2);
                    $out[$key] = isset($parents[$code]) ? ['ok' => true, 'body' => ['@id' => $parents[$code], 'code' => $code]] : ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'];

                    continue;
                }
                $out[$key] = isset($existing[$key]) ? ['ok' => true, 'body' => $existing[$key]] : ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'];
            }

            return $out;
        });
    }
}
