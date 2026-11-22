<?php

declare(strict_types=1);

// Measures what tools/list weighs, per tool, without booting the kernel.
// Usage: php bin/tools-weight.php [--per-tool]
require __DIR__ . '/../vendor/autoload.php';

use Emagma\McpCoreBundle\Authorization\Deprecated;
use Emagma\McpSyliusBundle\AdminApi\Gate\ShopExtensionGate;
use Emagma\McpSyliusBundle\AdminApi\Gate\ShopExtensions;
use Emagma\SyliusMcpServer\ServerSources;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;

$extensions = new ShopExtensions((string) getenv('MCP_SHOP_EXTENSIONS'));
$generator = new SchemaGenerator(new DocBlockParser());
$rows = [];
$deprecated = 0;
$disabled = 0;
foreach (ServerSources::tools()->classes() as $class) {
    $reflection = new \ReflectionClass($class);
    $attributes = $reflection->getAttributes(McpTool::class);
    if ([] === $attributes) {
        continue;
    }
    $tool = $attributes[0]->newInstance();
    if ([] !== $reflection->getAttributes(Deprecated::class)) {
        ++$deprecated;

        continue;
    }
    $extension = ShopExtensionGate::requiredBy($class);
    if (null !== $extension && !$extensions->isActive($extension)) {
        ++$disabled;

        continue;
    }
    $schema = $generator->generate($reflection->getMethod('__invoke'));
    $json = json_encode(['name' => $tool->name, 'description' => $tool->description, 'inputSchema' => $schema], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    $rows[$tool->name] = ['description' => \strlen((string) $tool->description), 'schema' => \strlen(json_encode($schema, \JSON_UNESCAPED_SLASHES)), 'total' => \strlen((string) $json)];
}
ksort($rows);
$sum = fn (string $k): int => array_sum(array_column($rows, $k));
printf("tools listed: %d (+%d deprecated, +%d of an inactive shop extension, hidden)\ndescriptions: %d bytes\ninputSchemas: %d bytes\ntotal tools/list payload: %d bytes (~%d tokens at 4 bytes/token)\n", \count($rows), $deprecated, $disabled, $sum('description'), $sum('schema'), $sum('total'), intdiv($sum('total'), 4));
if (\in_array('--per-tool', $argv, true)) {
    uasort($rows, static fn (array $a, array $b): int => $b['description'] <=> $a['description']);
    foreach ($rows as $name => $r) {
        printf("%5d  %5d  %s\n", $r['description'], $r['total'], $name);
    }
}
