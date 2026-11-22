<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Customer;

use Emagma\McpCoreBundle\Privacy\PrivacyContext;
use Emagma\McpCoreBundle\Privacy\PrivacyMode;
use Emagma\McpCoreBundle\Privacy\Pseudonymiser;
use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Customer\GetCustomers;
use Emagma\McpSyliusBundle\Tool\Shared\RevealTrait;
use PHPUnit\Framework\Attributes\CoversClass;

/** `reveal: true` is the one way a person's identifiers reach a pseudonymising client in clear. */
#[CoversClass(GetCustomers::class)]
#[CoversClass(RevealTrait::class)]
final class GetCustomersRevealTest extends McpToolTestCase
{
    protected function tearDown(): void
    {
        PrivacyContext::reset();
    }

    public function testUnderAClientReadingInClearRevealHasNoEffectAndLeavesNoTrace(): void
    {
        [$audit, $em] = $this->auditSpy();
        $em->expects(self::never())->method('persist');

        $got = $this->call(new GetCustomers($this->client(), $this->grants(), $audit), ids: ['42'], reveal: true);

        self::assertSame('ada@example.com', $got['found'][0]['email']);
        self::assertArrayNotHasKey('revealed', $got);
    }

    public function testWithoutTheGrantARevealIsRefusedInTheToolsOwnShape(): void
    {
        PrivacyContext::configure(PrivacyMode::Pseudonymise, new Pseudonymiser('s3cret'));
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecodedMany');

        $got = $this->call(new GetCustomers($client, $this->grants('mcp:all:all:read', 'mcp:all:all:write'), $this->audit()), ids: ['42'], reveal: true);

        self::assertSame(['found' => [], 'missing' => [], 'reason' => 'forbidden', 'missingGrant' => 'core:pii:reveal'], $got);
        self::assertArrayNotHasKey('ok', $got, 'a read tool never carries ok');
    }

    public function testAClientDeniedRevealsRefusesEvenAGrantHolder(): void
    {
        PrivacyContext::configure(PrivacyMode::Pseudonymise, new Pseudonymiser('s3cret'), revealAllowed: false);

        $got = $this->call(new GetCustomers($this->apiClient(), $this->grants('mcp:core:pii:reveal'), $this->audit()), ids: ['42'], reveal: true);

        self::assertSame('reveal_disabled_for_client', $got['reason']);
    }

    public function testWithTheGrantTheResponseIsInClearAndEveryPersonFoundIsAudited(): void
    {
        PrivacyContext::configure(PrivacyMode::Pseudonymise, new Pseudonymiser('s3cret'));
        [$audit, $em] = $this->auditSpy();
        $persisted = [];
        $em->method('persist')->willReturnCallback(static function (RecordedWrite $entry) use (&$persisted): void {
            $persisted[] = $entry;
        });
        $em->expects(self::once())->method('flush');

        $tool = new GetCustomers($this->client(), $this->grants('mcp:sylius:customer:read', 'mcp:core:pii:reveal'), $audit);

        $hidden = $this->call($tool, ids: ['42']);
        self::assertStringStartsWith('email:', $hidden['found'][0]['email'], 'without reveal the client sees a token');
        self::assertArrayNotHasKey('birthday', $hidden['found'][0]);

        $got = $this->call($tool, ids: ['42', '9'], reveal: true);
        self::assertSame('ada@example.com', $got['found'][0]['email']);
        self::assertSame('1815-12-10', $got['found'][0]['birthday']);
        self::assertSame(['9'], $got['missing']);
        self::assertSame(['42'], $got['revealed']['ids'], 'only the person actually found is revealed');
        self::assertCount(1, $persisted);
        self::assertSame(['get_customers', 'customer', '42'], [$persisted[0]->getTool(), $persisted[0]->getTargetType(), $persisted[0]->getTargetCode()]);
        self::assertSame($got['revealed']['batchId'], $persisted[0]->getBatchId());
    }

    private function client(): \Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturnCallback(static fn (array $paths): array => array_map(
            static fn (\Stringable|string $path): array => match ((string) $path) {
                'customers/42' => ['ok' => true, 'body' => ['id' => 42, 'email' => 'ada@example.com', 'firstName' => 'Ada', 'birthday' => '1815-12-10']],
                default => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            },
            $paths,
        ));

        return $client;
    }
}
