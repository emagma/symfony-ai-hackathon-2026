<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Province;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Province\AddProvince;
use Emagma\McpSyliusBundle\Tool\Province\GetProvince;
use Emagma\McpSyliusBundle\Tool\Province\RemoveProvince;
use Emagma\McpSyliusBundle\Tool\Province\SearchProvinces;
use Emagma\McpSyliusBundle\Tool\Province\UpdateProvince;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchProvinces::class)]
#[CoversClass(GetProvince::class)]
#[CoversClass(UpdateProvince::class)]
#[CoversClass(AddProvince::class)]
#[CoversClass(RemoveProvince::class)]
#[CoversClass(ProvinceShaper::class)]
final class ProvinceToolsTest extends McpToolTestCase
{
    public function testTheToolsDeclareTheirMcpAttribute(): void
    {
        $expected = [
            SearchProvinces::class => 'search_provinces',
            GetProvince::class => 'get_province',
            UpdateProvince::class => 'update_province',
            AddProvince::class => 'add_province',
            RemoveProvince::class => 'remove_province',
        ];

        foreach ($expected as $class => $name) {
            $attributes = (new \ReflectionClass($class))->getAttributes(McpTool::class);
            self::assertCount(1, $attributes);
            self::assertSame($name, $attributes[0]->newInstance()->name);
        }
    }

    public function testListReadsTheNestedCollection(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            // There is no flat /admin/provinces collection.
            ->with('countries/FR/provinces', [], [])
            ->willReturn($this->collection([
                ['code' => 'FR-IDF', 'name' => 'Île-de-France', 'abbreviation' => 'IDF'],
            ]));

        $result = $this->call(new SearchProvinces($client), 'FR');

        self::assertTrue($result['found']);
        self::assertSame([['code' => 'FR-IDF', 'name' => 'Île-de-France', 'abbreviation' => 'IDF']], $result['items']);
    }

    public function testListReportsAMissingCountryRatherThanAnEmptyList(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));

        $result = $this->call(new SearchProvinces($client), 'NOPE');

        self::assertFalse($result['found']);
        self::assertSame([], $result['items']);
    }

    public function testListPaginatesClientSide(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection(
            array_fill(0, 7, ['code' => 'X', 'name' => 'X']),
        ));

        $result = $this->call(new SearchProvinces($client), 'FR', page: 2, itemsPerPage: 3);

        self::assertSame(7, $result['total']);
        self::assertCount(3, $result['items']);
        self::assertTrue($result['hasMore']);
    }

    public function testGetAddressesTheProvinceUnderItsCountry(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('countries/FR/provinces/FR-IDF', [], [])
            ->willReturn(['code' => 'FR-IDF', 'name' => 'Île-de-France', 'abbreviation' => 'IDF']);

        $result = $this->call(new GetProvince($client), 'FR', 'FR-IDF');

        self::assertTrue($result['found']);
        self::assertSame('FR', $result['countryCode']);
        self::assertSame('IDF', $result['abbreviation']);
    }

    public function testGetReturnsNotFoundOn404(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));

        self::assertSame(
            ['found' => false, 'countryCode' => 'FR', 'code' => 'NOPE'],
            $this->call(new GetProvince($client), 'FR', 'NOPE'),
        );
    }

    public function testUpdateShortCircuitsWithoutReachingTheNetwork(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecoded');

        $result = $this->call(new UpdateProvince($client, $this->audit()), 'FR', 'FR-IDF', confirm: true);

        self::assertSame('no_change_requested', $result['reason']);
    }

    public function testUpdatePutsOnTheProvinceEndpoint(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn([
            '@id' => '/api/v2/admin/countries/FR/provinces/FR-IDF',
            'code' => 'FR-IDF',
            'name' => 'Ile de France',
            'abbreviation' => 'IDF',
        ]);
        $client->expects(self::once())
            ->method('put')
            ->with('countries/FR/provinces/FR-IDF', self::callback(static function (array $body): bool {
                self::assertSame('Île-de-France', $body['name']);
                // The code is not writable on update and must survive untouched.
                self::assertSame('FR-IDF', $body['code']);

                return true;
            }), [])
            ->willReturn(['code' => 'FR-IDF', 'name' => 'Île-de-France', 'abbreviation' => 'IDF']);

        $result = $this->call(new UpdateProvince($client, $this->audit()), 'FR', 'FR-IDF', name: 'Île-de-France', confirm: true);

        self::assertTrue($result['updated']);
        self::assertSame(['name' => ['before' => 'Ile de France', 'after' => 'Île-de-France']], $result['diff']);
    }

    public function testUpdateReturnsNoChangeWhenIdentical(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['code' => 'FR-IDF', 'name' => 'IDF']);
        $client->expects(self::never())->method('put');

        $result = $this->call(new UpdateProvince($client, $this->audit()), 'FR', 'FR-IDF', name: 'IDF', confirm: true);

        self::assertSame('no_change', $result['reason']);
    }

    public function testAddWritesTheCountryAndKeepsExistingProvincesVerbatim(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->country());
        $client->expects(self::once())
            ->method('put')
            // No POST exists on Province: the country is what gets written.
            ->with('countries/FR', self::callback(static function (array $body): bool {
                self::assertCount(2, $body['provinces']);
                self::assertSame('/api/v2/admin/countries/FR/provinces/FR-IDF', $body['provinces'][0]['@id']);
                self::assertSame(
                    ['code' => 'FR-BRE', 'name' => 'Bretagne', 'abbreviation' => 'BRE'],
                    $body['provinces'][1],
                );

                return true;
            }), [])
            ->willReturn($this->country());

        $result = $this->call(
            new AddProvince($client, $this->audit()),
            'FR',
            'FR-BRE',
            'Bretagne',
            abbreviation: 'BRE',
            confirm: true,
        );

        self::assertTrue($result['added']);
        self::assertSame(['provinces' => ['before' => ['FR-IDF'], 'after' => ['FR-IDF', 'FR-BRE']]], $result['diff']);
    }

    public function testAddRefusesADuplicateCode(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->country());
        $client->expects(self::never())->method('put');

        $result = $this->call(new AddProvince($client, $this->audit()), 'FR', 'FR-IDF', 'Île-de-France', confirm: true);

        self::assertSame('province_already_exists', $result['reason']);
    }

    public function testAddReportsAMissingCountry(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));

        $result = $this->call(new AddProvince($client, $this->audit()), 'NOPE', 'X', 'X', confirm: true);

        self::assertSame('country_not_found', $result['reason']);
    }

    public function testAddOmitsAnEmptyAbbreviation(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->country());
        $client->expects(self::once())
            ->method('put')
            ->with('countries/FR', self::callback(static function (array $body): bool {
                self::assertArrayNotHasKey('abbreviation', $body['provinces'][1]);

                return true;
            }), [])
            ->willReturn($this->country());

        $this->call(new AddProvince($client, $this->audit()), 'FR', 'FR-BRE', 'Bretagne', abbreviation: '', confirm: true);
    }

    public function testAddRecordsTheAuditAgainstTheCountry(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->country());
        $client->method('put')->willReturn($this->country());

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'add_province' === $e->getTool() &&
                'country' === $e->getTargetType() &&
                'FR' === $e->getTargetCode(),
        ));

        $this->call(new AddProvince($client, $audit), 'FR', 'FR-BRE', 'Bretagne', confirm: true);
    }

    public function testRemoveDropsOnlyTheTargetedProvince(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->country(twoProvinces: true));
        $client->expects(self::once())
            ->method('put')
            ->with('countries/FR', self::callback(static function (array $body): bool {
                self::assertCount(1, $body['provinces']);
                self::assertSame('FR-BRE', $body['provinces'][0]['code']);

                return true;
            }), [])
            ->willReturn($this->country());

        $result = $this->call(new RemoveProvince($client, $this->audit()), 'FR', 'FR-IDF', confirm: true);

        self::assertTrue($result['removed']);
    }

    public function testRemoveRefusesAnAbsentProvinceAndSaysWhatIsThere(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->country());
        $client->expects(self::never())->method('put');

        $result = $this->call(new RemoveProvince($client, $this->audit()), 'FR', 'FR-BRE', confirm: true);

        self::assertSame('province_not_found', $result['reason']);
        self::assertSame(['FR-IDF'], $result['provinces']);
    }

    public function testRemoveMapsConflictWhenTheProvinceIsStillReferenced(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->country());
        $client->method('put')->willThrowException($this->clientException(409));

        $result = $this->call(new RemoveProvince($client, $this->audit()), 'FR', 'FR-IDF', confirm: true);

        self::assertFalse($result['removed']);
        self::assertSame('conflict', $result['reason']);
    }

    public function testTheWriteToolsRefuseWithoutElicitation(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->country());
        $client->expects(self::never())->method('put');

        $context = $this->noElicitContext();

        self::assertSame(
            'human_confirmation_required',
            $this->call(new AddProvince($client, $this->audit()), 'FR', 'FR-BRE', 'Bretagne', context: $context)['reason'],
        );
        self::assertSame(
            'human_confirmation_required',
            $this->call(new RemoveProvince($client, $this->audit()), 'FR', 'FR-IDF', context: $context)['reason'],
        );
    }

    public function testElicitationDeclinedOverridesModelSuppliedConfirm(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->country());
        $client->expects(self::never())->method('put');

        $tool = new RemoveProvince($client, $this->audit());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool('FR', 'FR-IDF', confirm: true, context: $context),
            ElicitAction::Decline,
        );

        self::assertFalse($result['removed']);
        self::assertSame('declined', $result['reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function country(bool $twoProvinces = false): array
    {
        $provinces = [
            ['@id' => '/api/v2/admin/countries/FR/provinces/FR-IDF', 'code' => 'FR-IDF', 'name' => 'Île-de-France'],
        ];
        if ($twoProvinces) {
            $provinces[] = ['@id' => '/api/v2/admin/countries/FR/provinces/FR-BRE', 'code' => 'FR-BRE', 'name' => 'Bretagne'];
        }

        return [
            '@id' => '/api/v2/admin/countries/FR',
            'code' => 'FR',
            'name' => 'France',
            'enabled' => true,
            'provinces' => $provinces,
        ];
    }
}
