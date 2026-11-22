<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The offline token a user handed to the server so that their tasks run while they are away. Encrypted.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'mcp_task_authorization')]
class TaskAuthorization
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 255)]
        private readonly string $owner,
        #[ORM\Column(type: 'text')]
        private string $token,
        #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function replace(#[\SensitiveParameter] string $token, \DateTimeImmutable $at): void
    {
        $this->token = $token;
        $this->updatedAt = $at;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
