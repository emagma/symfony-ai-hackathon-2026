<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Integration;

use Emagma\McpTasksBundle\Delegation\DelegationInterface;
use Emagma\McpTasksBundle\Delegation\OfflineTokenDelegation;
use Emagma\McpTasksBundle\EmagmaMcpTasksBundle;
use Emagma\McpTasksBundle\Orchestration\StepExecutor;
use Emagma\McpTasksBundle\Orchestration\TaskLifecycle;
use Emagma\McpTasksBundle\Orchestration\TaskOrchestratorInterface;
use Emagma\McpTasksBundle\TaskManager;
use Emagma\McpTasksBundle\Testing\RecordingOrchestrator;
use Emagma\McpTasksBundle\Tests\Integration\Fixture\TestKernel;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/** The bundle booted in a host: the container compiles, the contracts resolve, the route answers. */
#[CoversClass(EmagmaMcpTasksBundle::class)]
final class BundleWiringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testTheServicesAnOrchestratorCallsAreReachableByTheirClass(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(TaskManager::class, $container->get('test.manager'));
        self::assertInstanceOf(StepExecutor::class, $container->get('test.executor'));
        self::assertInstanceOf(TaskLifecycle::class, $container->get('test.lifecycle'));
        self::assertInstanceOf(OfflineTokenDelegation::class, $container->get('test.delegation'));
        self::assertInstanceOf(DelegationInterface::class, $container->get('test.delegation'));
    }

    public function testTheOrchestratorIsTheOneTheHostNamed(): void
    {
        self::bootKernel();

        self::assertInstanceOf(RecordingOrchestrator::class, self::getContainer()->get('test.orchestrator'));
        self::assertInstanceOf(TaskOrchestratorInterface::class, self::getContainer()->get('test.orchestrator'));
    }

    public function testTheCallbackOfTheIdentityProviderHasItsRoute(): void
    {
        self::bootKernel();

        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $match = $router->match('/tasks/authorize/callback');

        self::assertSame('mcp_tasks_authorize_callback', $match['_route']);
        self::assertSame('emagma_mcp_tasks.controller.authorize', $match['_controller']);
    }

    public function testTheCallbackAnswersWithoutACode(): void
    {
        $kernel = self::bootKernel();

        $response = $kernel->handle(Request::create('/tasks/authorize/callback'));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('was not recorded', (string) $response->getContent());
    }
}
