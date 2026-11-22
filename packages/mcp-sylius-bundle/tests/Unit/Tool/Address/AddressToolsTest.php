<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Address;

use Emagma\McpSyliusBundle\Output\Address\AddressOutput;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\CustomerGroup\SearchCustomerGroups;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AddressShaper::class)]
final class AddressToolsTest extends McpToolTestCase
{
    public function testTheEmbeddedProjectionReportsABareIriAsAbsent(): void
    {
        // An address that arrives as an IRI must not be reported as an address with every field null.
        self::assertNull(AddressOutput::fromPayload('/api/v2/admin/addresses/7'));
        self::assertNull(AddressOutput::fromPayload(null));
    }

    public function testCustomerGroupsAreListedWithTheirCodeAndName(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('customer-groups', ['name' => 'whole', 'page' => 1, 'itemsPerPage' => 30])
            ->willReturn($this->collection([
                ['code' => 'wholesale', 'name' => 'Wholesale'],
            ], total: 1));

        $result = $this->call(new SearchCustomerGroups($client), name: 'whole');

        self::assertSame([['code' => 'wholesale', 'name' => 'Wholesale']], $result['items']);
        self::assertFalse($result['hasMore']);
    }

    public function testCustomerGroupsCapItemsPerPageAtFifty(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('customer-groups', self::callback(static fn (array $q): bool => 50 === $q['itemsPerPage']))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchCustomerGroups($client), itemsPerPage: 999);
    }
}
