<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Platform;

use Emagma\SyliusAssistantTestChat\Platform\FallbackModelCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAi\Gpt;
use Symfony\AI\Platform\Bridge\OpenAi\ModelCatalog;
use Symfony\AI\Platform\Capability;

#[CoversClass(FallbackModelCatalog::class)]
final class FallbackModelCatalogTest extends TestCase
{
    public function testAKnownModelKeepsItsOwnDefinition(): void
    {
        $model = (new FallbackModelCatalog(new ModelCatalog(), Gpt::class))->getModel('gpt-5.4-nano');

        self::assertTrue($model->supports(Capability::INPUT_IMAGE));
    }

    /**
     * A model the provider released after this Symfony AI version is still
     * usable: a chat model of the bridge's class, able to call tools.
     */
    public function testAnUnknownModelBecomesAToolCallingChatModelOfTheBridge(): void
    {
        $model = (new FallbackModelCatalog(new ModelCatalog(), Gpt::class))->getModel('gpt-9-preview?temperature=0');

        self::assertInstanceOf(Gpt::class, $model);
        self::assertSame('gpt-9-preview', $model->getName());
        self::assertTrue($model->supports(Capability::TOOL_CALLING));
        self::assertSame(['temperature' => 0], $model->getOptions());
    }
}
