<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi\Gate;

use Emagma\McpCoreBundle\Authorization\HandlerClass;
use Emagma\McpCoreBundle\Authorization\ToolGateInterface;
use Emagma\McpCoreBundle\Authorization\ToolRefusal;
use Emagma\McpSyliusBundle\AdminApi\ApiFeature;
use Emagma\McpSyliusBundle\AdminApi\SyliusVersion;
use Mcp\Capability\Registry\ToolReference;

/**
 * Hides a tool the configured Sylius version cannot serve, and refuses its call.
 *
 * @internal
 */
final readonly class SyliusVersionGate implements ToolGateInterface
{
    public function __construct(
        private SyliusVersion $syliusVersion,
    ) {
    }

    public function refusal(ToolReference $reference): ?ToolRefusal
    {
        $class = HandlerClass::of($reference->handler);
        if (null === $class) {
            return null;
        }

        foreach (self::requiredBy($class) as $feature) {
            if ($feature->isAvailableOn($this->syliusVersion)) {
                continue;
            }

            return new ToolRefusal(
                [
                    'ok' => false,
                    'reason' => 'sylius_version_unsupported',
                    'feature' => $feature->value,
                    'hint' => \sprintf('This tool needs Sylius %s or later; this shop runs Sylius %s (MCP_SYLIUS_VERSION). Tell the human; do not retry.', $feature->since(), $this->syliusVersion),
                ],
                'MCP tool call on a tool the shop\'s Sylius version lacks.',
                ['feature' => $feature->value, 'syliusVersion' => (string) $this->syliusVersion],
            );
        }

        return null;
    }

    /**
     * @param class-string $class
     *
     * @return list<ApiFeature>
     */
    public static function requiredBy(string $class): array
    {
        /** @var array<class-string, list<ApiFeature>> $cache */
        static $cache = [];
        if (!\array_key_exists($class, $cache)) {
            $cache[$class] = array_map(
                static fn (\ReflectionAttribute $attribute): ApiFeature => $attribute->newInstance()->feature,
                (new \ReflectionClass($class))->getAttributes(RequiresApiFeature::class),
            );
        }

        return $cache[$class];
    }
}
