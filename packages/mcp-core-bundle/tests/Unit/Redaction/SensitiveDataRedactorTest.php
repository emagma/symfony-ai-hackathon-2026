<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Redaction;

use Emagma\McpCoreBundle\Redaction\SensitiveDataRedactor;
use Emagma\McpCoreBundle\Redaction\SensitiveKeysProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SensitiveDataRedactor::class)]
final class SensitiveDataRedactorTest extends TestCase
{
    private const string M = SensitiveDataRedactor::MARKER;

    public function testAFreeTextKeepsEverythingButItsEmails(): void
    {
        $redactor = $this->redactor();

        self::assertSame('Order of ' . self::M . ' (call back)', $redactor->redactText('Order of jane@example.com (call back)'));
        self::assertSame('12', $redactor->redactText('12'));
        self::assertTrue(SensitiveDataRedactor::containsEmail('jane@example.com'));
        self::assertFalse(SensitiveDataRedactor::containsEmail('12'));
        self::assertFalse(SensitiveDataRedactor::containsEmail('@handle'));
    }

    public function testItLeavesAnInnocentPayloadAlone(): void
    {
        $payload = [
            'code' => 'MUG',
            'enabled' => true,
            'position' => 3,
            'translations' => ['en_US' => ['name' => 'Mug', 'slug' => 'mug']],
        ];

        self::assertSame($payload, $this->redactor()->redact($payload));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function secretKeyProvider(): array
    {
        return [
            'secret' => ['secret'],
            'camel secret' => ['secretKey'],
            'snake secret' => ['api_secret'],
            'password' => ['password'],
            'passphrase' => ['passphrase'],
            'api key camel' => ['apiKey'],
            'api key snake' => ['api_key'],
            'credential' => ['credentials'],
            'private key' => ['privateKey'],
        ];
    }

    #[DataProvider('secretKeyProvider')]
    public function testItRedactsSecretsByKeyFragment(string $key): void
    {
        $out = $this->redactor()->redact(['code' => 'STRIPE', $key => 'sk_live_deadbeef']);

        self::assertSame(self::M, $out[$key]);
        self::assertSame('STRIPE', $out['code']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function personalKeyProvider(): array
    {
        return [
            'email' => ['email'],
            'canonical email' => ['emailCanonical'],
            'first name' => ['firstName'],
            'last name' => ['lastName'],
            'full name' => ['fullName'],
            'phone' => ['phoneNumber'],
            'birthday' => ['birthday'],
            'street' => ['street'],
            'postcode' => ['postcode'],
            'city' => ['city'],
            'customer ip' => ['customerIp'],
        ];
    }

    #[DataProvider('personalKeyProvider')]
    public function testItRedactsPersonalDataByKey(string $key): void
    {
        $out = $this->redactor()->redact(['id' => 42, $key => 'something personal']);

        self::assertSame(self::M, $out[$key]);
        self::assertSame(42, $out['id']);
    }

    /**
     * A back-office login is usually a person's name.
     */
    public function testItRedactsABackOfficeUsername(): void
    {
        $out = $this->redactor()->redact(['id' => 3, 'username' => 'jean.dupont', 'enabled' => true]);

        self::assertSame(self::M, $out['username']);
        self::assertTrue($out['enabled']);
    }

    public function testAHostCanNameItsOwnSensitiveFields(): void
    {
        $redactor = new SensitiveDataRedactor(
            extraPersonalKeys: ['loyaltyCardNumber'],
            extraSecretFragments: ['hmac'],
        );

        $out = $redactor->redact([
            'loyaltyCardNumber' => '4970-1234',
            'webhookHmacKey' => 'deadbeef',
            'code' => 'MUG',
        ]);

        self::assertSame(self::M, $out['loyaltyCardNumber']);
        self::assertSame(self::M, $out['webhookHmacKey']);
        self::assertSame('MUG', $out['code']);
    }

    public function testTheExtraKeysAreMatchedCaseInsensitivelyLikeTheBuiltInOnes(): void
    {
        $redactor = new SensitiveDataRedactor(extraPersonalKeys: ['LoyaltyCardNumber'], providers: [self::backendKeys()]);

        self::assertSame(self::M, $redactor->redact(['loyaltycardnumber' => 'x'])['loyaltycardnumber']);
    }

    public function testItRedactsTheCustomerAuthoredOrderNotes(): void
    {
        $out = $this->redactor()->redact(['number' => '000000042', 'notes' => 'Leave with Ada next door']);

        self::assertSame(self::M, $out['notes']);
        self::assertSame('000000042', $out['number']);
    }

    public function testItScrubsAnEmailHidingInsideAnInnocentValue(): void
    {
        $out = $this->redactor()->redact([
            'label' => 'Refund requested by ada.lovelace@example.com on Tuesday',
        ]);

        self::assertSame('Refund requested by ' . self::M . ' on Tuesday', $out['label']);
    }

    public function testItScrubsEmailsNestedAnywhere(): void
    {
        $out = $this->redactor()->redact([
            'errors' => [['message' => 'ada@example.com is already used']],
        ]);

        self::assertStringNotContainsString('ada@example.com', json_encode($out, \JSON_THROW_ON_ERROR));
    }

    public function testItLeavesStringsWithoutAnEmailAlone(): void
    {
        $out = $this->redactor()->redact([
            '@id' => '/api/v2/admin/customers/20',
            '@type' => 'Customer',
            'path' => 'a/b/mug.png',
            'label' => 'Winter sale',
        ]);

        self::assertSame('/api/v2/admin/customers/20', $out['@id']);
        self::assertSame('Customer', $out['@type']);
        self::assertSame('a/b/mug.png', $out['path']);
        self::assertSame('Winter sale', $out['label']);
    }

    public function testItLeavesNonStringScalarsAlone(): void
    {
        $out = $this->redactor()->redact(['total' => 12500, 'enabled' => true, 'gone' => null]);

        self::assertSame(12500, $out['total']);
        self::assertTrue($out['enabled']);
        self::assertNull($out['gone']);
    }

    public function testItDropsTheWholeGatewayConfigSubtree(): void
    {
        $out = $this->redactor()->redact([
            'code' => 'stripe',
            'gatewayConfig' => [
                'gatewayName' => 'stripe',
                'factoryName' => 'stripe_checkout',
                'config' => ['publishable_key' => 'pk_live_x', 'whatever' => 'sk_live_y'],
            ],
        ]);

        self::assertSame(self::M, $out['gatewayConfig']['config']);
        // The siblings survive, so the entry still says which gateway was touched.
        self::assertSame('stripe', $out['gatewayConfig']['gatewayName']);
        self::assertSame('stripe_checkout', $out['gatewayConfig']['factoryName']);
    }

    public function testItKeepsAConfigKeyThatIsNotUnderAGateway(): void
    {
        $out = $this->redactor()->redact(['config' => ['amount' => 500]]);

        self::assertSame(['amount' => 500], $out['config']);
    }

    public function testItRecursesIntoNestedStructures(): void
    {
        $out = $this->redactor()->redact([
            'tokenValue' => 'TOKEN123',
            'shippingAddress' => ['city' => 'Paris', 'countryCode' => 'FR'],
            'items' => [
                ['productName' => 'Mug', 'quantity' => 2],
                ['productName' => 'Tee', 'quantity' => 1],
            ],
        ]);

        self::assertSame(self::M, $out['shippingAddress']['city']);
        self::assertSame('FR', $out['shippingAddress']['countryCode']);
        self::assertSame('Mug', $out['items'][0]['productName']);
        self::assertSame(2, $out['items'][0]['quantity']);
    }

    public function testItPreservesTheOrderTokenValue(): void
    {
        // `token` is not a secret fragment on purpose: tokenValue is the order's identifier.
        $out = $this->redactor()->redact(['tokenValue' => 'TOKEN123']);

        self::assertSame('TOKEN123', $out['tokenValue']);
    }

    public function testItRedactsADottedDiffKey(): void
    {
        $out = $this->redactor()->redactDiff([
            'gatewayConfig.config' => ['before' => ['k' => 'old'], 'after' => ['k' => 'new']],
            'enabled' => ['before' => false, 'after' => true],
        ]);

        self::assertSame(self::M, $out['gatewayConfig.config']);
        self::assertSame(['before' => false, 'after' => true], $out['enabled']);
    }

    public function testItRedactsADiffKeyWhoseLastSegmentIsPersonal(): void
    {
        $out = $this->redactor()->redactDiff([
            'en_US.name' => ['before' => 'Old', 'after' => 'New'],
            'email' => ['before' => 'a@b.c', 'after' => 'x@y.z'],
        ]);

        self::assertSame(['before' => 'Old', 'after' => 'New'], $out['en_US.name']);
        self::assertSame(self::M, $out['email']);
    }

    public function testItLeavesAComposedNonSensitiveDiffKeyAlone(): void
    {
        $out = $this->redactor()->redactDiff([
            'attribute.material.en_US' => ['before' => 'Ceramic', 'after' => 'Porcelain'],
            'value.opt_size_s' => ['before' => null, 'after' => 'Small'],
        ]);

        self::assertSame(['before' => 'Ceramic', 'after' => 'Porcelain'], $out['attribute.material.en_US']);
        self::assertSame(['before' => null, 'after' => 'Small'], $out['value.opt_size_s']);
    }

    public function testItDetectsARedactedSnapshot(): void
    {
        self::assertTrue($this->redactor()->containsRedaction(['email' => self::M]));
        self::assertTrue($this->redactor()->containsRedaction(['a' => ['b' => ['c' => self::M]]]));
        self::assertFalse($this->redactor()->containsRedaction(['code' => 'MUG', 'nested' => ['x' => 1]]));
    }

    private function redactor(): SensitiveDataRedactor
    {
        return new SensitiveDataRedactor(providers: [self::backendKeys()]);
    }

    public function testAHostCanChooseItsOwnMarker(): void
    {
        $redactor = new SensitiveDataRedactor(providers: [self::backendKeys()], marker: '<hidden>');

        $out = $redactor->redact(['code' => 'MUG', 'email' => 'jane@example.com', 'note' => 'write to jane@example.com']);

        self::assertSame('<hidden>', $redactor->marker());
        self::assertSame(['code' => 'MUG', 'email' => '<hidden>', 'note' => 'write to <hidden>'], $out);
        self::assertTrue($redactor->containsRedaction($out));
        self::assertFalse($this->redactor()->containsRedaction($out), 'a redactor only recognises its own marker');
    }

    private static function backendKeys(): SensitiveKeysProviderInterface
    {
        return new class() implements SensitiveKeysProviderInterface {
            public function personalKeys(): array
            {
                return [
                    'firstName', 'lastName', 'company', 'street', 'postcode', 'city', 'provinceName', 'phoneNumber',
                    'username', 'email', 'fullName', 'gender', 'birthday',
                    'emailCanonical', 'usernameCanonical', 'customerIp', 'notes',
                ];
            }

            public function opaqueSubtrees(): array
            {
                return ['gatewayconfig' => ['config']];
            }
        };
    }
}
