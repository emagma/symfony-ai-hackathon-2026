<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Zone;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Zone\AddZoneMember;
use Emagma\McpSyliusBundle\Tool\Zone\RemoveZoneMember;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AddZoneMember::class)]
#[CoversClass(RemoveZoneMember::class)]
final class ZoneMemberToolsTest extends McpToolTestCase
{
    private const string PATH = 'zones/EU';

    public function testTheToolsDeclareTheirMcpAttribute(): void
    {
        foreach ([AddZoneMember::class => 'add_zone_member', RemoveZoneMember::class => 'remove_zone_member'] as $class => $name) {
            $attributes = (new \ReflectionClass($class))->getAttributes(McpTool::class);
            self::assertCount(1, $attributes);
            self::assertSame($name, $attributes[0]->newInstance()->name);
        }
    }

    public function testAddReturnsNotFoundWhenTheZoneIsAbsent(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));
        $client->expects(self::never())->method('put');

        $result = $this->call(new AddZoneMember($client, $this->audit()), 'NOPE', 'FR', confirm: true);

        self::assertFalse($result['added']);
        self::assertSame('not_found', $result['reason']);
    }

    public function testAddRefusesAMemberThatIsAlreadyThere(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->zone());
        $client->expects(self::never())->method('put');

        $result = $this->call(new AddZoneMember($client, $this->audit()), 'EU', 'FR', confirm: true);

        self::assertFalse($result['added']);
        self::assertSame('member_already_exists', $result['reason']);
    }

    public function testAddAppendsAndKeepsExistingEntriesVerbatim(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->zone());
        $client->expects(self::once())
            ->method('put')
            ->with(self::PATH, self::callback(static function (array $body): bool {
                self::assertCount(3, $body['members']);
                // The pre-existing entries keep their @id so Doctrine updates the rows instead of recreating them.
                self::assertSame('/api/v2/admin/zone-members/1', $body['members'][0]['@id']);
                self::assertSame('/api/v2/admin/zone-members/2', $body['members'][1]['@id']);
                self::assertSame(['code' => 'PT'], $body['members'][2]);

                return true;
            }), [])
            ->willReturn($this->zone());

        $result = $this->call(new AddZoneMember($client, $this->audit()), 'EU', 'PT', confirm: true);

        self::assertTrue($result['added']);
        self::assertSame(
            ['members' => ['before' => ['FR', 'DE'], 'after' => ['FR', 'DE', 'PT']]],
            $result['diff'],
        );
    }

    public function testAddRecordsAnAuditEntryAgainstTheZone(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->zone());
        $client->method('put')->willReturn($this->zone());

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'add_zone_member' === $e->getTool() &&
                'zone' === $e->getTargetType() &&
                'EU' === $e->getTargetCode(),
        ));

        $this->call(new AddZoneMember($client, $audit), 'EU', 'PT', confirm: true);
    }

    public function testRemoveRefusesAMemberThatIsNotThere(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->zone());
        $client->expects(self::never())->method('put');

        $result = $this->call(new RemoveZoneMember($client, $this->audit()), 'EU', 'PT', confirm: true);

        self::assertFalse($result['removed']);
        self::assertSame('member_not_found', $result['reason']);
        self::assertSame(['FR', 'DE'], $result['members']);
    }

    public function testRemoveDropsOnlyTheTargetedMember(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->zone());
        $client->expects(self::once())
            ->method('put')
            ->with(self::PATH, self::callback(static function (array $body): bool {
                self::assertCount(1, $body['members']);
                self::assertSame('DE', $body['members'][0]['code']);
                self::assertSame('/api/v2/admin/zone-members/2', $body['members'][0]['@id']);

                return true;
            }), [])
            ->willReturn($this->zone());

        $result = $this->call(new RemoveZoneMember($client, $this->audit()), 'EU', 'FR', confirm: true);

        self::assertTrue($result['removed']);
        self::assertSame(
            ['members' => ['before' => ['FR', 'DE'], 'after' => ['DE']]],
            $result['diff'],
        );
    }

    public function testItReadsMembersGivenAsBareIris(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn([
            'code' => 'EU',
            'members' => ['/api/v2/admin/zone-members/FR'],
        ]);
        $client->expects(self::never())->method('put');

        // A serialization group change can turn inlined members into IRIs.
        $result = $this->call(new AddZoneMember($client, $this->audit()), 'EU', 'FR', confirm: true);

        self::assertSame('member_already_exists', $result['reason']);
    }

    public function testAddMapsValidationErrors(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->zone());
        $client->method('put')->willThrowException($this->clientException(422, [
            ['propertyPath' => 'members[2].code', 'message' => 'Invalid member.'],
        ]));

        $result = $this->call(new AddZoneMember($client, $this->audit()), 'EU', 'XX', confirm: true);

        self::assertFalse($result['added']);
        self::assertSame('validation_error', $result['reason']);
    }

    public function testBothRefuseWithoutElicitation(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->zone());
        $client->expects(self::never())->method('put');

        $context = $this->noElicitContext();

        self::assertSame(
            'human_confirmation_required',
            $this->call(new AddZoneMember($client, $this->audit()), 'EU', 'PT', context: $context)['reason'],
        );
        self::assertSame(
            'human_confirmation_required',
            $this->call(new RemoveZoneMember($client, $this->audit()), 'EU', 'FR', context: $context)['reason'],
        );
    }

    public function testElicitationDeclinedOverridesModelSuppliedConfirm(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->zone());
        $client->expects(self::never())->method('put');

        $tool = new RemoveZoneMember($client, $this->audit());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool('EU', 'FR', confirm: true, context: $context),
            ElicitAction::Decline,
        );

        self::assertFalse($result['removed']);
        self::assertSame('declined', $result['reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function zone(): array
    {
        return [
            '@id' => '/api/v2/admin/zones/EU',
            'code' => 'EU',
            'name' => 'Europe',
            'type' => 'country',
            'members' => [
                ['@id' => '/api/v2/admin/zone-members/1', 'code' => 'FR'],
                ['@id' => '/api/v2/admin/zone-members/2', 'code' => 'DE'],
            ],
        ];
    }
}
