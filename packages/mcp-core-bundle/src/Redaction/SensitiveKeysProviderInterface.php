<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Redaction;

/**
 * Extension point (tag `emagma_mcp_core.sensitive_keys`): the key names a component knows to be sensitive in its payloads.
 */
interface SensitiveKeysProviderInterface
{
    /**
     * Exact key names holding personal data, in any case.
     *
     * @return list<string>
     */
    public function personalKeys(): array;

    /**
     * Free-form objects whose key names no rule can trust: dropped whole when nested under the given parent.
     *
     * @return array<string, list<string>> parent key => child keys to drop whole, lowercased
     */
    public function opaqueSubtrees(): array;
}
