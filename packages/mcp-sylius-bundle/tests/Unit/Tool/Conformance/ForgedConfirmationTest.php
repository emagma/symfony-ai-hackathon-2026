<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Conformance;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationPolicy;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Administrator\UpsertAdministrators;
use Emagma\McpSyliusBundle\Tool\Payment\RefundPayment;
use Emagma\McpSyliusBundle\Tool\Promotion\DeletePromotions;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversNothing;

/** A review body read into the model's context can tell it to set `confirm`: its conviction must not be enough. */
#[CoversNothing]
final class ForgedConfirmationTest extends McpToolTestCase
{
    protected function tearDown(): void
    {
        WriteConfirmationPolicy::reset();
    }

    public function testAForgedConfirmationCannotRefundAPaymentOverASessionWithNoHuman(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn([
            'id' => 7,
            'state' => 'completed',
            'amount' => 13358,
            'currencyCode' => 'EUR',
            'order' => '/api/v2/admin/orders/TOKEN123',
        ]);
        $client->expects(self::never())->method('patch');

        $result = $this->call(
            new RefundPayment($client, $this->audit()),
            '7',
            confirm: true,
            context: $this->noElicitContext(),
        );

        self::assertFalse($result['refunded']);
        self::assertSame('human_confirmation_required', $result['reason']);
    }

    public function testAClientOnTheTrustedListIsHonouredButOnlyThatOne(): void
    {
        WriteConfirmationPolicy::configure(
            new WriteConfirmationPolicy([Action::Write, Action::Delete], ['Anthropic/ClaudeAI']),
        );

        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn([
            'id' => 7,
            'state' => 'completed',
            'amount' => 13358,
            'currencyCode' => 'EUR',
            'order' => '/api/v2/admin/orders/TOKEN123',
        ]);
        $client->expects(self::once())->method('patch');

        $result = $this->call(
            new RefundPayment($client, $this->audit()),
            '7',
            confirm: true,
            context: $this->noElicitContext('Some/OtherClient'),
        );
        self::assertFalse($result['refunded']);
        self::assertSame('human_confirmation_required', $result['reason']);

        // The trusted client: its UI already had the human approve the call.
        $result = $this->call(
            new RefundPayment($client, $this->audit()),
            '7',
            confirm: true,
            context: $this->noElicitContext('Anthropic/ClaudeAI'),
        );
        self::assertTrue($result['refunded']);
    }

    public function testAForgedConfirmationCannotDeleteOrMintPrivileges(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([]));
        $client->method('getDecodedMany')->willReturnCallback(static fn (array $paths): array => array_map(
            static fn (string $path): array => 'promotions/summer' === $path
                ? ['ok' => true, 'body' => ['code' => 'summer', 'name' => 'Summer', 'used' => 0]]
                : ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            $paths,
        ));
        $client->expects(self::never())->method('deleteMany');
        $client->expects(self::never())->method('postMany');

        $context = $this->noElicitContext();
        $audit = $this->audit();

        self::assertSame(
            'human_confirmation_required',
            $this->call(new DeletePromotions($client, $audit), codes: ['summer'], confirm: true, context: $context)['reason'],
        );
        self::assertSame(
            'human_confirmation_required',
            $this->call(
                new UpsertAdministrators($client, $audit, 'api@example.test'),
                items: [['email' => 'attacker@example.test', 'username' => 'attacker', 'password' => 'hunter2!', 'localeCode' => 'en_US']],
                confirm: true,
                context: $context,
            )['reason'],
        );
    }

    /** A client that declares elicitation and cancels it at once has no human channel after all. */
    public function testAClientSideCancelIsDecidedByThePolicyOnlyWhenTheDeploymentSaysSo(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['id' => 7, 'state' => 'completed', 'amount' => 1, 'currencyCode' => 'EUR', 'order' => '/api/v2/admin/orders/T']);
        $client->expects(self::once())->method('patch');
        $refund = fn (string $clientName, bool $confirm): array => $this->runWithElicitation(
            fn (): array => (new RefundPayment($client, $this->audit()))('7', confirm: $confirm, context: $this->elicitingContext($clientName)),
            ElicitAction::Cancel,
        );

        WriteConfirmationPolicy::configure(new WriteConfirmationPolicy([Action::Write, Action::Delete], ['claude-code']));
        self::assertSame('cancelled', $refund('claude-code', true)['reason'], 'no threshold: every cancel is a refusal');

        WriteConfirmationPolicy::configure(new WriteConfirmationPolicy([Action::Write, Action::Delete], ['claude-code'], autoCancelThreshold: 1e-9));
        self::assertSame('cancelled', $refund('claude-code', true)['reason'], 'slower than the threshold: a human cancelled');

        WriteConfirmationPolicy::configure(new WriteConfirmationPolicy([Action::Write, Action::Delete], ['claude-code'], autoCancelThreshold: 60.0));
        self::assertSame('human_confirmation_required', $refund('Some/OtherClient', true)['reason'], 'an untrusted client gains nothing');
        self::assertSame('elicitation_unavailable_preview_only', $refund('claude-code', false)['reason'], 'a trusted one gets the preview first');
        self::assertTrue($refund('claude-code', true)['refunded'], 'then its confirm applies');
    }

    public function testADeclineIsNeverTakenForTheClientsOwn(): void
    {
        WriteConfirmationPolicy::configure(new WriteConfirmationPolicy([Action::Write, Action::Delete], ['claude-code'], autoCancelThreshold: 60.0));
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['id' => 7, 'state' => 'completed', 'amount' => 1]);
        $client->expects(self::never())->method('patch');
        $tool = new RefundPayment($client, $this->audit());
        $context = $this->elicitingContext('claude-code');

        $result = $this->runWithElicitation(static fn (): array => $tool('7', confirm: true, context: $context), ElicitAction::Decline);

        self::assertSame('declined', $result['reason']);
    }

    public function testTheHumanIsStillAskedWhenTheModelClaimsConsent(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['id' => 7, 'state' => 'completed', 'amount' => 1]);
        $client->expects(self::never())->method('patch');

        $tool = new RefundPayment($client, $this->audit());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool('7', confirm: true, context: $context),
            \Mcp\Schema\Enum\ElicitAction::Decline,
        );

        self::assertFalse($result['refunded']);
        self::assertSame('declined', $result['reason']);
    }

    public function testARelaxedDeploymentStillAcceptsTheModelConfirmation(): void
    {
        WriteConfirmationPolicy::configure(new WriteConfirmationPolicy([Action::Delete]));

        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['id' => 7, 'state' => 'completed', 'amount' => 1]);
        $client->expects(self::once())->method('patch')->willReturn(['id' => 7, 'state' => 'refunded']);

        $result = $this->call(
            new RefundPayment($client, $this->audit()),
            '7',
            confirm: true,
            context: $this->noElicitContext(),
        );

        self::assertTrue($result['refunded']);
    }

    /** Direct instantiation is the host application calling a tool as a service, not a model asking. */
    public function testACallWithNoSessionIsStillTheHostsOwnDecision(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['id' => 7, 'state' => 'completed', 'amount' => 1]);
        $client->expects(self::once())->method('patch')->willReturn(['id' => 7, 'state' => 'refunded']);

        self::assertTrue($this->call(new RefundPayment($client, $this->audit()), '7', confirm: true)['refunded']);
    }
}
