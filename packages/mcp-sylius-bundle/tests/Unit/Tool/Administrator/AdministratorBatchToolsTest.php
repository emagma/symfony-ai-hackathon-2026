<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Administrator;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Administrator\DeleteAdministrators;
use Emagma\McpSyliusBundle\Tool\Administrator\GetAdministrators;
use Emagma\McpSyliusBundle\Tool\Administrator\UpsertAdministrators;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DeleteAdministrators::class)]
#[CoversClass(GetAdministrators::class)]
#[CoversClass(UpsertAdministrators::class)]
final class AdministratorBatchToolsTest extends McpToolTestCase
{
    private const string SERVICE_ACCOUNT = 'api@example.com';

    public function testTheEmailIsTheKeyThePutGoesToTheIdAndThePasswordNeverLeavesTheBody(): void
    {
        $kevin = ['id' => 4, 'username' => 'kevin', 'email' => 'Kevin@shop.test', 'localeCode' => 'fr_FR', 'enabled' => true];
        $api = ['id' => 1, 'username' => 'api', 'email' => 'API@Example.com', 'enabled' => true];
        $client = $this->apiClient();
        $client->expects(self::exactly(2))->method('getDecoded')->with('administrators', ['page' => 1, 'itemsPerPage' => 500])
            ->willReturn($this->collection([$kevin, $api]));
        $client->expects(self::once())->method('postMany')
            ->with(['new@shop.test' => ['path' => 'administrators', 'body' => [
                'email' => 'new@shop.test', 'username' => 'new', 'localeCode' => 'en_US', 'enabled' => true, 'plainPassword' => 's3cret',
            ]]])
            ->willReturn(['new@shop.test' => ['ok' => true, 'body' => ['id' => 9, 'email' => 'new@shop.test']]]);
        $client->expects(self::once())->method('putMany')
            ->with(['kevin@shop.test' => ['path' => 'administrators/4', 'body' => array_replace($kevin, ['enabled' => false, 'plainPassword' => 'reset-me'])]])
            ->willReturn(['kevin@shop.test' => ['ok' => true, 'body' => $kevin]]);
        [$audit, $em] = $this->auditSpy();
        $staged = [];
        $em->method('persist')->willReturnCallback(static function (RecordedWrite $entry) use (&$staged): void {
            $staged[] = $entry;
        });

        $result = $this->call(new UpsertAdministrators($client, $audit, self::SERVICE_ACCOUNT), items: [
            ['email' => 'KEVIN@shop.test', 'enabled' => false, 'password' => 'reset-me'],
            ['email' => 'new@shop.test', 'username' => 'new', 'password' => 's3cret', 'localeCode' => 'en_US'],
            ['email' => self::SERVICE_ACCOUNT, 'enabled' => false],
            ['email' => 'half@shop.test', 'username' => 'half'],
        ], confirm: true);
        $reset = $this->call(new UpsertAdministrators($client, $audit, self::SERVICE_ACCOUNT), items: [['email' => 'api@EXAMPLE.com', 'password' => 'new-one']], confirm: true);

        self::assertSame(['enabled' => ['before' => true, 'after' => false], 'password' => ['before' => '(unchanged)', 'after' => '(reset)']], $result['results']['kevin@shop.test']['diff']);
        self::assertSame('created', $result['results']['new@shop.test']['action']);
        self::assertSame('service_account_protected', $result['results'][self::SERVICE_ACCOUNT]['reason'], 'disabling: the email match is case-insensitive on both sides');
        self::assertSame('service_account_protected', $reset['results'][self::SERVICE_ACCOUNT]['reason'], 'a password reset on the service account is refused too');
        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => ['password', 'localeCode']], $result['results']['half@shop.test']);
        self::assertStringNotContainsString('s3cret', json_encode($result, \JSON_THROW_ON_ERROR));
        foreach ($staged as $entry) {
            self::assertStringNotContainsString('s3cret', json_encode([$entry->getBeforeState(), $entry->getAfterState(), $entry->getDiff()], \JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('reset-me', json_encode([$entry->getBeforeState(), $entry->getAfterState(), $entry->getDiff()], \JSON_THROW_ON_ERROR));
        }
        self::assertEqualsCanonicalizing(['9', '4'], array_map(static fn (RecordedWrite $entry): string => $entry->getTargetCode(), $staged), 'the audit records the id, never the email the batch is keyed on');
    }

    public function testAHarmlessEditOnTheServiceAccountGoesThrough(): void
    {
        $api = ['id' => 1, 'username' => 'api', 'email' => self::SERVICE_ACCOUNT, 'enabled' => true];
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([$api]));
        $client->expects(self::once())->method('putMany')
            ->with([self::SERVICE_ACCOUNT => ['path' => 'administrators/1', 'body' => $api + ['firstName' => 'Robot']]])
            ->willReturn([self::SERVICE_ACCOUNT => ['ok' => true, 'body' => $api]]);

        $result = $this->call(new UpsertAdministrators($client, $this->audit(), self::SERVICE_ACCOUNT), items: [['email' => self::SERVICE_ACCOUNT, 'firstName' => 'Robot', 'enabled' => true]], confirm: true);

        self::assertSame('updated', $result['results'][self::SERVICE_ACCOUNT]['action']);
    }

    public function testTheServiceAccountCannotBeDeletedAndGetAnswersById(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturnCallback(static fn (array $paths): array => array_map(
            static fn (\Stringable|string $path): array => match ((string) $path) {
                'administrators/1' => ['ok' => true, 'body' => ['id' => 1, 'username' => 'api', 'email' => self::SERVICE_ACCOUNT]],
                'administrators/4' => ['ok' => true, 'body' => ['id' => 4, 'username' => 'kevin', 'email' => 'kevin@shop.test']],
                default => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            },
            $paths,
        ));
        $client->expects(self::once())->method('deleteMany')->with(['4' => 'administrators/4'])->willReturn(['4' => ['ok' => true]]);

        $deleted = $this->call(new DeleteAdministrators($client, $this->audit(), self::SERVICE_ACCOUNT), ids: ['1', '4', '9'], confirm: true);
        self::assertSame('service_account_protected', $deleted['results']['1']['reason']);
        self::assertSame('deleted', $deleted['results']['4']['action']);
        self::assertSame('not_found', $deleted['results']['9']['reason']);

        $got = $this->call(new GetAdministrators($client, $this->grants(), $this->audit()), ids: ['4', '9']);
        self::assertSame('kevin', $got['found'][0]['username']);
        self::assertSame(['9'], $got['missing']);
    }
}
