<?php

declare(strict_types=1);

namespace Emagma\McpSkillsBundle\Tests\Unit;

use Emagma\McpCoreBundle\Authorization\Grants;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpSkillsBundle\Skill;
use Emagma\McpSkillsBundle\SkillRepository;
use Emagma\McpSkillsBundle\Tool\GetSkill;
use Emagma\McpSkillsBundle\Tool\ListSkills;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillRepository::class)]
#[CoversClass(Skill::class)]
#[CoversClass(ListSkills::class)]
#[CoversClass(GetSkill::class)]
final class SkillsTest extends TestCase
{
    private const array ALLOWED = ['mcp:sylius:product_variant:read', 'mcp:sylius:product:read'];

    public function testASkillIsAFolderWithItsManifestItsVersionAndWhatItRequires(): void
    {
        $repository = new SkillRepository(__DIR__ . '/fixtures');

        self::assertSame(['stock-check'], array_keys($repository->all()));
        $skill = $repository->get('stock-check');
        self::assertInstanceOf(Skill::class, $skill);
        self::assertSame('1.2.0', $skill->version);
        self::assertStringStartsWith('Check which products are about to run out.', $skill->description);
        self::assertSame(['sylius:product_variant:read', 'sylius:product:read'], array_map(static fn ($permission): string => $permission->name(), $skill->requires));
        self::assertSame(['SKILL.md', 'thresholds.md'], array_keys($skill->files));
        self::assertSame('skill://merchant/stock-check/SKILL.md', $skill->uri('merchant'));
    }

    public function testAFolderThatIsNotASkillIsLeftOutAndSaid(): void
    {
        $repository = new SkillRepository(__DIR__ . '/fixtures');

        self::assertNull($repository->get('broken'));
        self::assertSame(['broken'], array_keys($repository->problems()));
        self::assertStringContainsString('name of its folder', $repository->problems()['broken']);
        self::assertSame([], (new SkillRepository(__DIR__ . '/nowhere'))->all());
    }

    public function testTheListHoldsTheSkillsTheCallerMayRun(): void
    {
        $listed = $this->list(...self::ALLOWED)();
        self::assertSame(1, $listed['total']);
        self::assertSame('merchant', $listed['pack']);
        self::assertSame(
            ['name' => 'stock-check', 'version' => '1.2.0', 'uri' => 'skill://merchant/stock-check/SKILL.md'],
            array_diff_key($listed['items'][0], ['description' => true]),
        );

        self::assertSame(0, $this->list('mcp:sylius:product:read')()['total'], 'one grant of the two is not enough');
        self::assertSame(1, $this->list('mcp:sylius:all:write')()['total'], 'write implies read');
    }

    public function testASkillIsServedWholeAsEmbeddedResources(): void
    {
        $content = $this->get(...self::ALLOWED)('stock-check');

        self::assertCount(3, $content);
        self::assertInstanceOf(TextContent::class, $content[0]);
        self::assertSame(
            ['found' => true, 'name' => 'stock-check', 'version' => '1.2.0', 'files' => ['SKILL.md', 'thresholds.md']],
            json_decode((string) $content[0]->text, true),
        );

        self::assertInstanceOf(EmbeddedResource::class, $content[1]);
        $manifest = $content[1]->resource;
        self::assertInstanceOf(TextResourceContents::class, $manifest);
        self::assertSame('skill://merchant/stock-check/SKILL.md', $manifest->uri);
        self::assertSame('text/markdown', $manifest->mimeType);
        self::assertStringStartsWith("---\nname: stock-check\n", $manifest->text, 'the file as it is, front matter included: a client installs it');

        self::assertInstanceOf(EmbeddedResource::class, $content[2]);
        self::assertSame('skill://merchant/stock-check/thresholds.md', $content[2]->resource->uri);
    }

    public function testASkillTheCallerMayNotRunAnswersAsAnUnknownOne(): void
    {
        $expected = ['found' => false, 'reason' => 'not_found'];

        foreach ([$this->get('mcp:sylius:product:read')('stock-check'), $this->get(...self::ALLOWED)('nope')] as $content) {
            self::assertCount(1, $content);
            self::assertInstanceOf(TextContent::class, $content[0]);
            self::assertSame($expected, array_diff_key((array) json_decode((string) $content[0]->text, true), ['name' => true]));
        }
    }

    private function list(string ...$grants): ListSkills
    {
        return new ListSkills(new SkillRepository(__DIR__ . '/fixtures'), $this->grants(...$grants), 'merchant');
    }

    private function get(string ...$grants): GetSkill
    {
        return new GetSkill(new SkillRepository(__DIR__ . '/fixtures'), $this->grants(...$grants), 'merchant');
    }

    private function grants(string ...$grants): GrantsProviderInterface
    {
        $provider = $this->createStub(GrantsProviderInterface::class);
        $provider->method('grants')->willReturn(Grants::fromStrings(array_values($grants)));

        return $provider;
    }
}
