<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Customer;

/**
 * @internal
 */
final class CustomerItem
{
    public const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'email' => ['type' => 'string', 'description' => 'The item is matched on it (case-insensitive); a customer keeps their email through this tool.'],
            'firstName' => ['type' => 'string'],
            'lastName' => ['type' => 'string'],
            'gender' => ['type' => 'string', 'enum' => ['m', 'f', 'u'], 'description' => '"u" for unspecified.'],
            'birthday' => ['type' => 'string', 'description' => 'ISO-8601 date.'],
            'phoneNumber' => ['type' => 'string'],
            'subscribedToNewsletter' => ['type' => 'boolean'],
            'groupCode' => ['type' => ['string', 'null'], 'description' => 'Code of an existing customer group; null or "" detaches. Omit to leave it alone.'],
            'password' => ['type' => 'string', 'description' => 'On a creation, also creates the shop LOGIN (without it the customer is a guest record). On an existing customer RESETS the credential (no_account if they have none). Never echoed, previewed or audited.'],
            'accountEnabled' => ['type' => 'boolean', 'description' => 'Whether the shop login may sign in; true on a creation with a password unless given. false locks an existing account reversibly.'],
        ],
        'required' => ['email'],
        'additionalProperties' => false,
    ];

    private function __construct()
    {
    }
}
