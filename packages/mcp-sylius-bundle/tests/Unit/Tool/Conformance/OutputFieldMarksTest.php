<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Conformance;

use Emagma\McpCoreBundle\Output\OutputFieldsTrait;
use Emagma\McpCoreBundle\Privacy\PrivacyContext;
use Emagma\McpCoreBundle\Privacy\PrivacyMode;
use Emagma\McpCoreBundle\Privacy\Pseudonymiser;
use Emagma\McpSyliusBundle\Output\Address\AddressOutput;
use Emagma\McpSyliusBundle\Output\Administrator\AdministratorOutput;
use Emagma\McpSyliusBundle\Output\Customer\CustomerOutput;
use Emagma\McpSyliusBundle\Output\Order\OrderOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutputFieldsTrait::class)]
final class OutputFieldMarksTest extends TestCase
{
    protected function tearDown(): void
    {
        PrivacyContext::reset();
    }

    public function testUnderPseudonymiseIdentifiersBecomeTokensAndSurplusIsLeftOut(): void
    {
        PrivacyContext::configure(PrivacyMode::Pseudonymise, new Pseudonymiser('s3cret'));
        $customer = CustomerOutput::fromPayload([
            'id' => 12, 'email' => 'jean@example.com', 'firstName' => 'Jean', 'birthday' => '1984-03-12', 'gender' => 'm',
            'phoneNumber' => '+33 6 12 34 56 78',
            'defaultAddress' => ['street' => '12 rue des Lilas', 'phoneNumber' => '+33612345678'],
        ], '12');

        $json = json_decode(json_encode($customer, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('birthday', $json, 'surplus is absent, not null');
        self::assertArrayNotHasKey('gender', $json);
        self::assertMatchesRegularExpression('/^email:[0-9a-f]{8}$/', $json['email']);
        self::assertSame($json['phoneNumber'], $json['defaultAddress']['phoneNumber'], 'the same phone is the same token wherever it appears');
        self::assertSame('Jean', $json['firstName'], 'names pass: the model needs them to act');
        self::assertSame(['firstName'], $json['untrustedContent']['fields'], 'a token is the server\'s, not the public\'s');
        self::assertSame(['street'], $json['defaultAddress']['untrustedContent']['fields']);

        PrivacyContext::revealThisResponse();
        $revealed = json_decode(json_encode($customer, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('jean@example.com', $revealed['email']);
        self::assertSame('1984-03-12', $revealed['birthday']);
        self::assertSame(['email', 'firstName', 'phoneNumber'], $revealed['untrustedContent']['fields']);
    }

    public function testAnAdministratorIsTokenisedTooAndNeverMarkedUntrusted(): void
    {
        PrivacyContext::configure(PrivacyMode::Pseudonymise, new Pseudonymiser('s3cret'));
        $admin = AdministratorOutput::fromPayload(['id' => 3, 'username' => 'jean.dupont', 'email' => 'jean@example.com', 'firstName' => 'Jean'], '3');

        $json = json_decode(json_encode($admin, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        self::assertStringStartsWith('login:', $json['username']);
        self::assertStringStartsWith('email:', $json['email']);
        self::assertSame('Jean', $json['firstName']);
        self::assertArrayNotHasKey('untrustedContent', $json, 'staff data is the shop\'s, not the public\'s');
    }

    public function testTheMarkNamesOnlyTheUntrustedFieldsThatAreSetAndComesLast(): void
    {
        $customer = CustomerOutput::fromPayload([
            'id' => 12,
            'email' => 'jean@example.com',
            'firstName' => 'Jean',
            'birthday' => '1984-03-12',
            'defaultAddress' => ['street' => '12 rue des Lilas', 'city' => 'Lyon', 'countryCode' => 'FR'],
        ], '12');

        $json = json_decode(json_encode($customer, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('untrustedContent', array_key_last($json));
        self::assertSame(['email', 'firstName'], $json['untrustedContent']['fields'], 'lastName is null, birthday is personal but not typed text');
        self::assertArrayNotHasKey('notice', $json['untrustedContent'], 'the notice travels once per response, not per item');
        self::assertSame(['street', 'city'], $json['defaultAddress']['untrustedContent']['fields'], 'an embedded DTO marks itself');
        self::assertSame(array_keys(get_object_vars($customer)), \array_slice(array_keys($json), 0, -1), 'declaration order is the contract');
    }

    public function testAnOrderIsMarkedThroughItsAddressesOnly(): void
    {
        $order = OrderOutput::fromPayload([
            'tokenValue' => 'tok',
            'shippingAddress' => ['firstName' => 'Jean', 'company' => 'ACME'],
            'billingAddress' => null,
        ], 'tok');

        $json = json_decode(json_encode($order, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('untrustedContent', $json);
        self::assertSame(['firstName', 'company'], $json['shippingAddress']['untrustedContent']['fields']);
        self::assertNull($json['billingAddress']);
    }

    public function testAnAddressWithNothingSetCarriesNoMark(): void
    {
        $json = json_decode(json_encode(AddressOutput::fromPayload(['countryCode' => 'FR']), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('untrustedContent', $json);
    }
}
