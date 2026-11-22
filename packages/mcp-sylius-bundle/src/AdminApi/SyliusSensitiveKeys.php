<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi;

use Emagma\McpCoreBundle\Redaction\SensitiveKeysProviderInterface;

/**
 * The payload keys that hold personal data, taken from the `#[Personal]` marks of the output DTOs.
 *
 * @internal
 */
final readonly class SyliusSensitiveKeys implements SensitiveKeysProviderInterface
{
    /**
     * Personal keys of the raw payloads only: no output DTO exposes them, so the field registry cannot name
     * them. `notes` is free text the customer writes on an order.
     *
     * @var list<string>
     */
    private const array RAW_ONLY_KEYS = ['emailcanonical', 'usernamecanonical', 'customerip', 'notes'];

    /**
     * @param list<string> $registryPersonalKeys the registry's `#[Personal]` names
     */
    public function __construct(
        private array $registryPersonalKeys = [],
    ) {
    }

    public function personalKeys(): array
    {
        return [...self::RAW_ONLY_KEYS, ...$this->registryPersonalKeys];
    }

    /**
     * A gateway configuration is free-form: no name-based rule can catch every credential in it.
     */
    public function opaqueSubtrees(): array
    {
        return ['gatewayconfig' => ['config']];
    }
}
