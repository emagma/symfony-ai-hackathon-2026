<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\EventListener;

use Emagma\SyliusMcpPlugin\EventListener\LeanVaryListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(LeanVaryListener::class)]
final class LeanVaryListenerTest extends TestCase
{
    public function testItDeclaresPreferInVaryOnAnApiGet(): void
    {
        $response = $this->dispatch(Request::create('/api/v2/admin/products'));

        self::assertContains('Prefer', $response->getVary());
    }

    public function testItKeepsTheVaryHeadersAlreadyThere(): void
    {
        $response = $this->dispatch(Request::create('/api/v2/admin/products'), new Response('', 200, ['Vary' => 'Accept']));

        self::assertSame(['Accept', 'Prefer'], $response->getVary());
    }

    public function testItDoesNotDuplicateAnExistingPrefer(): void
    {
        $response = $this->dispatch(Request::create('/api/v2/admin/products'), new Response('', 200, ['Vary' => 'prefer']));

        self::assertSame(['prefer'], $response->getVary());
    }

    public function testItLeavesNonApiPathsAlone(): void
    {
        $response = $this->dispatch(Request::create('/en_US/'));

        self::assertSame([], $response->getVary());
    }

    public function testItLeavesWritesAlone(): void
    {
        $response = $this->dispatch(Request::create('/api/v2/admin/products', 'POST'));

        self::assertSame([], $response->getVary());
    }

    public function testItIgnoresSubRequests(): void
    {
        $response = $this->dispatch(Request::create('/api/v2/admin/products'), null, HttpKernelInterface::SUB_REQUEST);

        self::assertSame([], $response->getVary());
    }

    private function dispatch(Request $request, ?Response $response = null, int $type = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $response ??= new Response();
        $kernel = $this->createMock(HttpKernelInterface::class);

        (new LeanVaryListener())->onResponse(new ResponseEvent($kernel, $request, $type, $response));

        return $response;
    }
}
