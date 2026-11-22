<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Authorization;

use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\PromptNotFoundException;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Extension\Apps\UiToolMeta;
use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Psr\Log\LoggerInterface;

/**
 * Enforces the grants on tools/list and tools/call alike: a forbidden tool answers as an unknown one, so nothing leaks.
 * Resources, templates and prompts are refused unless the grants are an explicit allow-all, except the `ui://` resource
 * of an MCP App: it is served to whoever is served one of the tools linked to it, and to nobody else.
 *
 * @internal
 */
final class ToolAuthorizationRegistry implements RegistryInterface
{
    /** @var list<ToolGateInterface> */
    private readonly array $gates;

    /**
     * @param iterable<ToolGateInterface> $gates the first refusal wins
     */
    public function __construct(
        private readonly RegistryInterface $inner,
        private readonly ToolPermissionResolver $permissions,
        private readonly GrantsProviderInterface $grantsProvider,
        private readonly LoggerInterface $logger,
        iterable $gates = [],
    ) {
        // Built here, not lazily: a gate that cannot be built must stop every MCP request.
        $this->gates = array_values([...$gates]);
    }

    public function getTool(string $name): ToolReference
    {
        $reference = $this->inner->getTool($name);
        $required = $this->permissions->resolve($reference);
        $grants = $this->grantsProvider->grants();

        if (!$grants->allowsAll($required)) {
            $this->logger->warning('MCP tool call denied by authorization.', [
                'tool' => $name,
                'required' => array_map(
                    static fn (Permission $p): string => $p->name(),
                    $required,
                ),
                'declared' => [] !== $required,
            ]);

            throw new ToolNotFoundException($name);
        }

        // After the grants check: a refusal's reason only reaches a caller the grants allow.
        $refusal = $this->refusalOf($reference);
        if (null !== $refusal) {
            $this->logger->notice($refusal->logMessage, ['tool' => $name] + $refusal->logContext);

            return self::refused($reference, $refusal->answer);
        }

        if (null !== ($replacedBy = HandlerClass::deprecatedFor($reference->handler))) {
            $this->logger->notice('MCP tool call on a deprecated tool.', ['tool' => $name, 'replacedBy' => $replacedBy]);
        }

        return $reference;
    }

    public function getTools(?int $limit = null, ?string $cursor = null): Page
    {
        $page = $this->inner->getTools($limit, $cursor);
        $grants = $this->grantsProvider->grants();

        $allowed = [];
        foreach ($page->references as $item) {
            try {
                $reference = $this->inner->getTool($item->name);
            } catch (ToolNotFoundException) {
                continue;
            }

            $required = $this->permissions->resolve($reference);
            if (!$grants->allowsAll($required)) {
                continue;
            }
            if (null !== $this->refusalOf($reference)) {
                continue;
            }
            if (null !== HandlerClass::deprecatedFor($reference->handler)) {
                // Hidden, not gone: getTool() still serves it.
                continue;
            }

            $allowed[] = ToolAnnotationsDeriver::derive($item, $required);
        }

        return new Page($allowed, $page->nextCursor);
    }

    public function hasTools(): bool
    {
        return $this->inner->hasTools();
    }

    /**
     * Answers as getTool() does, so that neither tells a forbidden tool from a missing one.
     */
    public function hasTool(string $name): bool
    {
        try {
            $reference = $this->inner->getTool($name);
        } catch (ToolNotFoundException) {
            return false;
        }

        return $this->grantsProvider->grants()->allowsAll($this->permissions->resolve($reference));
    }

    private function refusalOf(ToolReference $reference): ?ToolRefusal
    {
        foreach ($this->gates as $gate) {
            if (null !== ($refusal = $gate->refusal($reference))) {
                return $refusal;
            }
        }

        return null;
    }

    /**
     * A permissive schema: the SDK validates the arguments first, and the answer must be the reason, not a schema error.
     * fromArray() turns the empty `properties` into the JSON object the validator insists on.
     *
     * @param array<string, mixed> $answer
     */
    private static function refused(ToolReference $reference, array $answer): ToolReference
    {
        return new ToolReference(
            Tool::fromArray([
                'name' => $reference->tool->name,
                'inputSchema' => ['type' => 'object', 'properties' => [], 'required' => []],
                'description' => $reference->tool->description,
            ]),
            static fn (): array => $answer,
        );
    }

    public function getResources(?int $limit = null, ?string $cursor = null): Page
    {
        $page = $this->inner->getResources($limit, $cursor);
        if ($this->grantsProvider->grants()->isAllowAll()) {
            return $page;
        }

        $apps = $this->appResources();
        if ([] === $apps) {
            return new Page([], null);
        }

        return new Page(
            array_values(array_filter($page->references, static fn (ResourceDefinition $resource): bool => isset($apps[$resource->uri]))),
            $page->nextCursor,
        );
    }

    public function getResource(string $uri, bool $includeTemplates = true): ResourceReference|ResourceTemplateReference
    {
        if (!$this->grantsProvider->grants()->isAllowAll() && !isset($this->appResources()[$uri])) {
            $this->logger->warning('MCP resource read denied by authorization.', ['uri' => $uri]);

            throw new ResourceNotFoundException($uri);
        }

        return $this->inner->getResource($uri, $includeTemplates);
    }

    public function hasResources(): bool
    {
        return $this->inner->hasResources() && ($this->grantsProvider->grants()->isAllowAll() || [] !== $this->appResources());
    }

    public function hasResource(string $uri): bool
    {
        return $this->inner->hasResource($uri) && ($this->grantsProvider->grants()->isAllowAll() || isset($this->appResources()[$uri]));
    }

    /**
     * The `ui://` resources the tools served to the caller are linked to (`_meta.ui.resourceUri`).
     *
     * @return array<string, true>
     */
    private function appResources(): array
    {
        $uris = [];
        $cursor = null;
        do {
            $page = $this->getTools(null, $cursor);
            foreach ($page->references as $tool) {
                $ui = $tool->meta['ui'] ?? null;
                $uri = match (true) {
                    $ui instanceof UiToolMeta => $ui->resourceUri,
                    \is_array($ui) && \is_string($ui['resourceUri'] ?? null) => $ui['resourceUri'],
                    default => null,
                };
                if (null !== $uri && str_starts_with($uri, 'ui://')) {
                    $uris[$uri] = true;
                }
            }
            $cursor = $page->nextCursor;
        } while (null !== $cursor);

        return $uris;
    }

    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): Page
    {
        if (!$this->grantsProvider->grants()->isAllowAll()) {
            return new Page([], null);
        }

        return $this->inner->getResourceTemplates($limit, $cursor);
    }

    public function getResourceTemplate(string $uriTemplate): ResourceTemplateReference
    {
        if (!$this->grantsProvider->grants()->isAllowAll()) {
            $this->logger->warning('MCP resource template read denied by authorization.', ['uriTemplate' => $uriTemplate]);

            throw new ResourceNotFoundException($uriTemplate);
        }

        return $this->inner->getResourceTemplate($uriTemplate);
    }

    public function hasResourceTemplates(): bool
    {
        return $this->grantsProvider->grants()->isAllowAll() && $this->inner->hasResourceTemplates();
    }

    public function hasResourceTemplate(string $uriTemplate): bool
    {
        return $this->grantsProvider->grants()->isAllowAll() && $this->inner->hasResourceTemplate($uriTemplate);
    }

    public function getPrompts(?int $limit = null, ?string $cursor = null): Page
    {
        if (!$this->grantsProvider->grants()->isAllowAll()) {
            return new Page([], null);
        }

        return $this->inner->getPrompts($limit, $cursor);
    }

    public function getPrompt(string $name): PromptReference
    {
        if (!$this->grantsProvider->grants()->isAllowAll()) {
            $this->logger->warning('MCP prompt read denied by authorization.', ['prompt' => $name]);

            throw new PromptNotFoundException($name);
        }

        return $this->inner->getPrompt($name);
    }

    public function hasPrompts(): bool
    {
        return $this->grantsProvider->grants()->isAllowAll() && $this->inner->hasPrompts();
    }

    public function hasPrompt(string $name): bool
    {
        return $this->grantsProvider->grants()->isAllowAll() && $this->inner->hasPrompt($name);
    }

    public function registerTool(Tool $tool, callable|array|string $handler): ToolReference
    {
        return $this->inner->registerTool($tool, $handler);
    }

    public function registerResource(ResourceDefinition $resource, callable|array|string $handler): ResourceReference
    {
        return $this->inner->registerResource($resource, $handler);
    }

    public function registerResourceTemplate(ResourceTemplate $template, callable|array|string $handler, array $completionProviders = []): ResourceTemplateReference
    {
        return $this->inner->registerResourceTemplate($template, $handler, $completionProviders);
    }

    public function registerPrompt(Prompt $prompt, callable|array|string $handler, array $completionProviders = []): PromptReference
    {
        return $this->inner->registerPrompt($prompt, $handler, $completionProviders);
    }

    public function unregisterTool(string $name): void
    {
        $this->inner->unregisterTool($name);
    }

    public function unregisterResource(string $uri): void
    {
        $this->inner->unregisterResource($uri);
    }

    public function unregisterResourceTemplate(string $uriTemplate): void
    {
        $this->inner->unregisterResourceTemplate($uriTemplate);
    }

    public function unregisterPrompt(string $name): void
    {
        $this->inner->unregisterPrompt($name);
    }

    public function publishesListChanges(): bool
    {
        return $this->inner->publishesListChanges();
    }
}
