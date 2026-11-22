<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\User\Repository\UserRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The one Sylius admin the MCP server calls the Admin API with. Every MCP
 * user acts through it downstream; who really asked is the MCP server's
 * business (its grants, its audit).
 *
 * Idempotent: an existing account only gets its password and roles set again,
 * so re-running `castor shop:db` never breaks the server's access.
 *
 * A classic command (configure/execute), not an invokable one with
 * #[Option]: that needs Symfony 7.3, and a Sylius 2.0 shop may run 6.4.
 */
#[AsCommand(name: 'sylius-mcp:service-account', description: 'Create (or reset) the admin account the MCP server calls the Admin API with')]
final class CreateServiceAccountCommand extends Command
{
    /**
     * @param UserRepositoryInterface<AdminUserInterface> $repository
     * @param FactoryInterface<AdminUserInterface>        $factory
     */
    public function __construct(
        private readonly UserRepositoryInterface $repository,
        private readonly FactoryInterface $factory,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly string $defaultLocale,
        private readonly ?string $serviceAccountEmail,
        #[\SensitiveParameter]
        private readonly ?string $serviceAccountPassword,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email of the account (defaults to MCP_SERVICE_ACCOUNT_EMAIL)')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password of the account (defaults to MCP_SERVICE_ACCOUNT_PASSWORD)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getOption('email');
        $email = \is_string($email) ? $email : ($this->serviceAccountEmail ?? '');
        $password = $input->getOption('password');
        $password = \is_string($password) ? $password : ($this->serviceAccountPassword ?? '');

        if ('' === $email || '' === $password) {
            $io->error('No service account configured: set MCP_SERVICE_ACCOUNT_EMAIL and MCP_SERVICE_ACCOUNT_PASSWORD, or pass --email and --password.');

            return 1;
        }

        $user = $this->repository->findOneByEmail($email);
        $created = !$user instanceof AdminUserInterface;
        if ($created) {
            /** @var AdminUserInterface $user */
            $user = $this->factory->createNew();
            $user->setEmail($email);
            $user->setUsername(explode('@', $email)[0]);
            $user->setLocaleCode($this->defaultLocale);
            $this->entityManager->persist($user);
        }

        // Hashed here, not by Sylius's password listener: that one only fires
        // on a Doctrine changeset, and a plain password alone is not one.
        $user->setPlainPassword($password);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $user->eraseCredentials();
        $user->setEnabled(true);
        // ROLE_API_ACCESS is all the Admin API firewall asks for. The account is
        // a machine identity whose password sits in an environment variable: it
        // must not be able to open the back-office, so ROLE_ADMINISTRATION_ACCESS
        // is withheld - and withdrawn from an account created by an older version.
        if (!$user->hasRole('ROLE_API_ACCESS')) {
            $user->addRole('ROLE_API_ACCESS');
        }
        if ($user->hasRole('ROLE_ADMINISTRATION_ACCESS')) {
            $user->removeRole('ROLE_ADMINISTRATION_ACCESS');
        }

        $this->entityManager->flush();

        $io->success(\sprintf('%s the MCP service account %s.', $created ? 'Created' : 'Updated', $email));

        return 0;
    }
}
