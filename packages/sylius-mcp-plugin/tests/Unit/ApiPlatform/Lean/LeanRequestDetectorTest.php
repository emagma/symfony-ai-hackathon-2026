<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\ApiPlatform\Lean;

use Emagma\SyliusMcpPlugin\ApiPlatform\Lean\LeanRequestDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(LeanRequestDetector::class)]
final class LeanRequestDetectorTest extends TestCase
{
    public function testItReturnsFalseWhenNoMainRequestInStack(): void
    {
        self::assertFalse($this->detector(new RequestStack())->prefersLeanResponse());
    }

    public function testItReturnsFalseWhenPreferHeaderAbsent(): void
    {
        $request = Request::create('/');

        self::assertFalse($this->detector()->prefersLeanResponse($request));
    }

    /**
     * RFC 7240 token forms we MUST accept.
     */
    #[DataProvider('leanHeaderForms')]
    public function testItAcceptsLeanHeaderForm(string $headerValue): void
    {
        $request = Request::create('/');
        $request->headers->set('Prefer', $headerValue);

        self::assertTrue($this->detector()->prefersLeanResponse($request));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function leanHeaderForms(): iterable
    {
        yield 'unquoted' => ['respond-for=lean'];
        yield 'quoted' => ['respond-for="lean"'];
        yield 'leading whitespace' => ['  respond-for=lean'];
        yield 'mixed case' => ['Respond-For=Lean'];
        yield 'with respond-async sibling' => ['respond-async, respond-for=lean'];
        yield 'before another preference' => ['respond-for=lean, return=minimal'];
        yield 'with parameter separator' => ['respond-for=lean; charset=utf-8'];
    }

    /**
     * Header forms that are NOT lean - close cousins to guard against false positives.
     */
    #[DataProvider('nonLeanHeaderForms')]
    public function testItRejectsNonLeanHeaderForm(string $headerValue): void
    {
        $request = Request::create('/');
        $request->headers->set('Prefer', $headerValue);

        self::assertFalse($this->detector()->prefersLeanResponse($request));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function nonLeanHeaderForms(): iterable
    {
        yield 'different preference' => ['respond-async'];
        yield 'different value' => ['respond-for=full'];
        yield 'substring only' => ['respond-for=leanish'];
        yield 'wrong key' => ['return=lean'];
        yield 'empty' => [''];
    }

    public function testItPullsRequestFromStackWhenNoneGiven(): void
    {
        $request = Request::create('/');
        $request->headers->set('Prefer', 'respond-for=lean');
        $stack = new RequestStack();
        $stack->push($request);

        self::assertTrue($this->detector($stack)->prefersLeanResponse());
    }

    private function detector(?RequestStack $stack = null): LeanRequestDetector
    {
        return new LeanRequestDetector($stack ?? new RequestStack());
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function localeProvider(): array
    {
        return [
            'plain' => ['respond-for=lean; locale=fr_FR', 'fr_FR'],
            'quoted' => ['respond-for=lean; locale="fr_FR"', 'fr_FR'],
            'spaced' => ['respond-for=lean ;  locale = fr_FR', 'fr_FR'],
            'language only' => ['respond-for=lean; locale=fr', 'fr'],
            'absent' => ['respond-for=lean', null],
            'not lean' => ['locale=fr_FR', null],
            'malformed' => ['respond-for=lean; locale=', null],
            'not a locale' => ['respond-for=lean; locale=../../etc/passwd', null],
            'no header' => [null, null],
        ];
    }

    #[DataProvider('localeProvider')]
    public function testItReadsTheLocaleAnnouncedOnTheLeanPreference(?string $header, ?string $expected): void
    {
        $request = new Request();
        if (null !== $header) {
            $request->headers->set('Prefer', $header);
        }

        // Le détecteur accepte la Request explicitement : pas besoin de pile.
        self::assertSame($expected, $this->detector()->requestedLocale($request));
    }

    /**
     * The locale only counts on the lean preference itself: a `locale` sitting
     * on some other token is not this plugin's business.
     */
    public function testItIgnoresALocaleOnAnUnrelatedPreference(): void
    {
        $request = new Request();
        $request->headers->set('Prefer', 'wait=10; locale=fr_FR, respond-for=lean');

        self::assertNull($this->detector()->requestedLocale($request));
    }
}
