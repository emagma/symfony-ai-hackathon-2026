<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\FixturesReset\FixturesResetLock;
use App\FixturesReset\ResetFixturesCommand;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The "Reset the demo data" page of the admin (Configuration menu).
 *
 * The reset runs while the request waits (~15 s on the dev stack): the console
 * command in its own process, so that the purge never shares this request's
 * Doctrine connection and its memory stays out of the web worker. When it is
 * done every admin session is dead (the admin users are re-created with new
 * ids and the user provider refreshes by id): straight to the login page.
 */
#[Route('/%sylius_admin.path_name%/fixtures-reset')]
final class FixturesResetController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'app_fixtures_reset';

    public function __construct(
        private readonly FixturesResetLock $lock,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(bool:FIXTURES_RESET_ENABLED)%')]
        private readonly bool $enabled,
    ) {
    }

    #[Route('', name: 'app_admin_fixtures_reset', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessEnabled();

        return $this->render('admin/fixtures_reset/index.html.twig', [
            'running' => $this->lock->isHeldElsewhere(),
        ]);
    }

    #[Route('', name: 'app_admin_fixtures_reset_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        $this->denyUnlessEnabled();

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'app.fixtures_reset.invalid_csrf_token');

            return $this->redirectToRoute('app_admin_fixtures_reset');
        }

        // The purge must not stop half-way because the tab was closed or the
        // request ran past max_execution_time.
        ignore_user_abort(true);
        set_time_limit(0);

        $process = new Process(
            [$this->php(), 'bin/console', 'app:fixtures:reset', '--no-interaction', '--no-ansi'],
            $this->projectDir,
            null,
            null,
            null,
        );
        $process->run();

        if (ResetFixturesCommand::ALREADY_RUNNING === $process->getExitCode()) {
            $this->addFlash('warning', 'app.fixtures_reset.already_running');

            return $this->redirectToRoute('app_admin_fixtures_reset');
        }

        if (!$process->isSuccessful()) {
            $this->logger->error('The fixtures reset failed.', [
                'exit_code' => $process->getExitCode(),
                'output' => $process->getOutput(),
                'error_output' => $process->getErrorOutput(),
            ]);
            $this->addFlash('error', 'app.fixtures_reset.failed');

            return $this->redirectToRoute('app_admin_fixtures_reset');
        }

        $this->logger->notice('The shop was reset to its fixtures from the admin.');
        $this->addFlash('success', 'app.fixtures_reset.done');

        return $this->redirectToRoute('sylius_admin_login');
    }

    private function denyUnlessEnabled(): void
    {
        if (!$this->enabled) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * The CLI binary: under FrankenPHP, PHP_BINARY is the web server.
     */
    private function php(): string
    {
        $php = (new PhpExecutableFinder())->find(false);
        if (false === $php) {
            throw new \RuntimeException('No PHP CLI binary found to run the fixtures reset.');
        }

        return $php;
    }
}
