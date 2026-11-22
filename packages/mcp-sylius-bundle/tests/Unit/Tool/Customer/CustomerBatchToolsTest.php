<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Customer;

use Emagma\McpCoreBundle\Privacy\PrivacyContext;
use Emagma\McpCoreBundle\Privacy\PrivacyMode;
use Emagma\McpCoreBundle\Privacy\Pseudonymiser;
use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Customer\DeleteCustomerUsers;
use Emagma\McpSyliusBundle\Tool\Customer\GetCustomers;
use Emagma\McpSyliusBundle\Tool\Customer\UpsertCustomers;
use Emagma\McpSyliusBundle\Tool\Shared\ReferenceResolver;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DeleteCustomerUsers::class)]
#[CoversClass(GetCustomers::class)]
#[CoversClass(UpsertCustomers::class)]
#[CoversClass(ReferenceResolver::class)]
final class CustomerBatchToolsTest extends McpToolTestCase
{
    protected function tearDown(): void
    {
        PrivacyContext::reset();
    }

    public function testUnderAPseudonymisingClientTheStoredPhoneComesBackAsItsToken(): void
    {
        PrivacyContext::configure(PrivacyMode::Pseudonymise, new Pseudonymiser('s3cret'));
        $ada = ['@id' => '/api/v2/admin/customers/42', 'id' => 42, 'email' => 'ada@example.com', 'firstName' => 'Ada', 'phoneNumber' => '+33 6 00 00 00 01', 'user' => null];
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturnCallback(fn (array $paths): array => array_map(
            fn (\Stringable|string $path): array => str_starts_with((string) $path, 'customers?') ? ['ok' => true, 'body' => $this->collection([$ada])] : ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            $paths,
        ));
        $client->method('putMany')->willReturn(['ada@example.com' => ['ok' => true, 'body' => $ada]]);

        $result = $this->call(new UpsertCustomers($client, new ReferenceResolver($client), $this->audit()), items: [
            ['email' => 'ada@example.com', 'firstName' => 'Ada L.', 'phoneNumber' => '+33 6 00 00 00 02'],
        ], confirm: true);

        $diff = $result['results']['ada@example.com']['diff'];
        self::assertMatchesRegularExpression('/^phone:[0-9a-f]{8}$/', $diff['phoneNumber']['before']);
        self::assertSame('+33 6 00 00 00 02', $diff['phoneNumber']['after'], 'what the operator typed is not hidden from them');
        self::assertSame('Ada', $diff['firstName']['before'], 'a name passes, marked');
        self::assertSame(['fields' => ['diff.firstName.before']], $result['results']['ada@example.com']['untrustedContent'], 'a token is not public text');
    }

    public function testTheEmailIsProbedExactlyGroupsAreResolvedOnceAndThePasswordNeverLeavesTheBody(): void
    {
        $ada = ['@id' => '/api/v2/admin/customers/42', 'id' => 42, 'email' => 'Ada@example.com', 'firstName' => 'Ada', 'group' => '/api/v2/admin/customer-groups/retail', 'user' => ['@id' => '/u/1', 'enabled' => true]];
        $guest = ['@id' => '/api/v2/admin/customers/7', 'id' => 7, 'email' => 'guest@example.com', 'user' => null];
        $client = $this->apiClient();
        $fanOuts = [];
        $client->method('getDecodedMany')->willReturnCallback(function (array $paths) use ($ada, $guest, &$fanOuts): array {
            $fanOuts[] = array_map(strval(...), array_values($paths));
            $out = [];
            foreach ($paths as $key => $path) {
                $path = (string) $path;
                $out[$key] = match (true) {
                    str_starts_with((string) $path, 'customers?') && str_contains((string) $path, 'ada%40example.com') => ['ok' => true, 'body' => $this->collection([['email' => 'not-ada@example.com'], $ada])],
                    str_starts_with((string) $path, 'customers?') && str_contains((string) $path, 'guest%40example.com') => ['ok' => true, 'body' => $this->collection([$guest])],
                    str_starts_with((string) $path, 'customers?') => ['ok' => true, 'body' => $this->collection([])],
                    'customer-groups/wholesale' === (string) $path => ['ok' => true, 'body' => ['@id' => '/api/v2/admin/customer-groups/wholesale']],
                    default => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
                };
            }

            return $out;
        });
        $client->expects(self::once())->method('postMany')
            ->with(['new@example.com' => ['path' => 'customers', 'body' => [
                'email' => 'new@example.com', 'firstName' => 'New', 'group' => '/api/v2/admin/customer-groups/wholesale',
                'user' => ['plainPassword' => 's3cret', 'enabled' => true],
            ]]])
            ->willReturn(['new@example.com' => ['ok' => true, 'body' => ['id' => 99, 'email' => 'new@example.com']]]);
        $client->expects(self::once())->method('putMany')
            ->with(['ada@example.com' => ['path' => 'customers/42', 'body' => array_replace($ada, [
                'firstName' => 'Ada L.', 'group' => null, 'user' => ['@id' => '/u/1', 'enabled' => false, 'plainPassword' => 'reset-me'],
            ])]])
            ->willReturn(['ada@example.com' => ['ok' => true, 'body' => $ada]]);
        [$audit, $em] = $this->auditSpy();
        $staged = [];
        $em->method('persist')->willReturnCallback(static function (RecordedWrite $entry) use (&$staged): void {
            $staged[] = $entry;
        });

        $result = $this->call(new UpsertCustomers($client, new ReferenceResolver($client), $audit), items: [
            ['email' => 'ADA@example.com', 'firstName' => 'Ada L.', 'groupCode' => '', 'password' => 'reset-me', 'accountEnabled' => false],
            ['email' => 'new@example.com', 'firstName' => 'New', 'groupCode' => 'wholesale', 'password' => 's3cret'],
            ['email' => 'guest@example.com', 'accountEnabled' => false],
            ['email' => 'lost@example.com', 'groupCode' => 'nope'],
        ], confirm: true);

        self::assertCount(2, $fanOuts, 'one probe fan-out, one reference fan-out');
        self::assertSame(['customer-groups/wholesale', 'customer-groups/nope'], $fanOuts[1]);
        self::assertSame(
            ['firstName' => ['before' => 'Ada', 'after' => 'Ada L.'], 'groupCode' => ['before' => 'retail', 'after' => null], 'password' => ['before' => '(unchanged)', 'after' => '(reset)'], 'accountEnabled' => ['before' => true, 'after' => false]],
            $result['results']['ada@example.com']['diff'],
        );
        // The `before` of a name is what the customer typed; the operator's `after` is not marked.
        self::assertSame(['fields' => ['diff.firstName.before']], $result['results']['ada@example.com']['untrustedContent']);
        self::assertArrayNotHasKey('untrustedContent', $result['results']['new@example.com']);
        self::assertSame('created', $result['results']['new@example.com']['action']);
        self::assertSame('no_account', $result['results']['guest@example.com']['reason']);
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'group_not_found', 'groupCode' => 'nope'], $result['results']['lost@example.com']);
        self::assertStringNotContainsString('s3cret', json_encode($result, \JSON_THROW_ON_ERROR));
        foreach ($staged as $entry) {
            self::assertStringNotContainsString('reset-me', json_encode([$entry->getBeforeState(), $entry->getAfterState(), $entry->getDiff()], \JSON_THROW_ON_ERROR));
        }
        self::assertEqualsCanonicalizing(['99', '42'], array_map(static fn (RecordedWrite $entry): string => $entry->getTargetCode(), $staged), 'the audit records the id, never the email the batch is keyed on');
    }

    public function testTheAccountIsDeletedUnderTheCustomerAndAGuestIsRefused(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturnCallback(static fn (array $paths): array => array_map(
            static fn (\Stringable|string $path): array => match ((string) $path) {
                'customers/42' => ['ok' => true, 'body' => ['id' => 42, 'email' => 'ada@example.com', 'user' => ['enabled' => true]]],
                'customers/7' => ['ok' => true, 'body' => ['id' => 7, 'email' => 'guest@example.com', 'user' => null]],
                default => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            },
            $paths,
        ));
        $client->expects(self::once())->method('deleteMany')->with(['42' => 'customers/42/user'])->willReturn(['42' => ['ok' => true]]);

        $deleted = $this->call(new DeleteCustomerUsers($client, $this->audit()), ids: ['42', '7'], confirm: true);
        self::assertSame('deleted', $deleted['results']['42']['action']);
        self::assertSame('no_account', $deleted['results']['7']['reason']);

        $got = $this->call(new GetCustomers($client, $this->grants(), $this->audit()), ids: ['42', '9']);
        self::assertSame('ada@example.com', $got['found'][0]['email']);
        self::assertSame(['email'], $got['found'][0]['untrustedContent']['fields'], 'only the fields actually set are named');
        self::assertSame(['9'], $got['missing']);
    }
}
