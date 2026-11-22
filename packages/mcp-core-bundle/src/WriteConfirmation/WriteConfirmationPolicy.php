<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\WriteConfirmation;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;

/**
 * `confirm` is a tool argument, so a prompt injection can forge it: over a session it is not consent for a mutation.
 * A static holder because the trait that enforces it cannot be injected; it holds configuration, never request state.
 */
final class WriteConfirmationPolicy
{
    private static ?self $current = null;

    /** @var array<string, true> */
    private readonly array $requireHuman;

    /** @var array<string, true> MCP `clientInfo.name` values whose sessions may confirm on the model's word */
    private readonly array $trustedClients;

    /**
     * A `cancel` faster than `$autoCancelThreshold` seconds is taken as the client's own; 0 disables the detection.
     *
     * @param list<Action> $requireHumanFor actions a model may not confirm on its own
     * @param list<string> $trustedClients  clients whose own UI already made a human approve the call
     */
    public function __construct(array $requireHumanFor, array $trustedClients = [], private readonly float $autoCancelThreshold = 0.0)
    {
        $map = [];
        foreach ($requireHumanFor as $action) {
            $map[$action->value] = true;
        }
        $this->requireHuman = $map;
        $this->trustedClients = array_fill_keys($trustedClients, true);
    }

    public static function secureDefault(): self
    {
        return new self([Action::Write, Action::Delete]);
    }

    /**
     * Unconfigured means strict: forgetting to wire the policy cannot open the hole.
     */
    public static function current(): self
    {
        return self::$current ??= self::secureDefault();
    }

    public static function configure(self $policy): void
    {
        self::$current = $policy;
    }

    public static function reset(): void
    {
        self::$current = null;
    }

    /**
     * `$clientName` is set by the client software, not by the model: a prompt injection cannot forge it.
     *
     * @param list<Permission> $required
     */
    public function acceptsModelConfirmation(array $required, ?string $clientName = null): bool
    {
        if (null !== $clientName && isset($this->trustedClients[$clientName])) {
            return true;
        }

        foreach ($required as $permission) {
            if (isset($this->requireHuman[$permission->action->value])) {
                return false;
            }
        }

        return true;
    }

    /**
     * A client that declares elicitation but cannot show it cancels at once: no human answers that fast.
     */
    public function cancelledByClient(float $elapsed): bool
    {
        return $this->autoCancelThreshold > 0.0 && $elapsed < $this->autoCancelThreshold;
    }

    /**
     * @return list<Permission>
     */
    public static function permissionsOf(object $tool): array
    {
        /** @var array<class-string, list<Permission>> $cache */
        static $cache = [];

        return $cache[$tool::class] ??= array_map(
            static fn (\ReflectionAttribute $attribute): Permission => $attribute->newInstance(),
            (new \ReflectionClass($tool))->getAttributes(Permission::class),
        );
    }
}
