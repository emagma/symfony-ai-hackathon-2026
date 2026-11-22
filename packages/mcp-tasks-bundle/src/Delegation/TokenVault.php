<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Delegation;

use Doctrine\ORM\EntityManagerInterface;
use Emagma\McpTasksBundle\Entity\TaskAuthorization;
use Psr\Clock\ClockInterface;

/**
 * The tokens in the server's database, encrypted with a key of the server: a dump of the table is not a
 * set of sessions.
 *
 * @internal
 */
final readonly class TokenVault implements TokenVaultInterface
{
    private const string CIPHER = 'aes-256-gcm';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        #[\SensitiveParameter]
        private string $secret,
    ) {
    }

    public function store(string $owner, #[\SensitiveParameter] string $token): void
    {
        $encrypted = self::encrypt($token, $this->secret);
        $authorization = $this->entityManager->find(TaskAuthorization::class, $owner);
        if (null === $authorization) {
            $this->entityManager->persist(new TaskAuthorization($owner, $encrypted, $this->clock->now()));
        } else {
            $authorization->replace($encrypted, $this->clock->now());
        }
        $this->entityManager->flush();
    }

    public function fetch(string $owner): ?string
    {
        $authorization = $this->entityManager->find(TaskAuthorization::class, $owner);

        return null === $authorization ? null : self::decrypt($authorization->getToken(), $this->secret);
    }

    public function forget(string $owner): void
    {
        $authorization = $this->entityManager->find(TaskAuthorization::class, $owner);
        if (null !== $authorization) {
            $this->entityManager->remove($authorization);
            $this->entityManager->flush();
        }
    }

    public static function encrypt(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $secret): string
    {
        $nonce = random_bytes(12);
        $cipher = openssl_encrypt($token, self::CIPHER, hash('sha256', $secret, true), \OPENSSL_RAW_DATA, $nonce, $tag);

        return base64_encode($nonce . $tag . $cipher);
    }

    public static function decrypt(string $encrypted, #[\SensitiveParameter] string $secret): ?string
    {
        $raw = base64_decode($encrypted, true);
        if (false === $raw || \strlen($raw) < 29) {
            return null;
        }
        $token = openssl_decrypt(substr($raw, 28), self::CIPHER, hash('sha256', $secret, true), \OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return false === $token ? null : $token;
    }
}
