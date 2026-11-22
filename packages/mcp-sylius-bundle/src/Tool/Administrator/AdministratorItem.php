<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Administrator;

/**
 * @internal
 */
final class AdministratorItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'email' => ['type' => 'string', 'description' => 'The item is matched on it (case-insensitive); an account keeps its email through this tool.'],
            'username' => ['type' => 'string', 'description' => 'Login name. Required on a creation.'],
            'password' => ['type' => 'string', 'description' => 'Required on a creation; on an existing account RESETS the credential. Never echoed, previewed or audited.'],
            'localeCode' => ['type' => 'string', 'description' => 'Admin UI locale, e.g. "en_US". Required on a creation.'],
            'firstName' => ['type' => 'string'],
            'lastName' => ['type' => 'string'],
            'enabled' => ['type' => 'boolean', 'description' => 'true on a creation unless given. false is the REVERSIBLE way to revoke access.'],
        ],
        'required' => ['email'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
