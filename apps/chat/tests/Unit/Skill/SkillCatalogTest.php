<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Tests\Unit\Skill;

use Emagma\SyliusAssistantTestChat\Conversation\Conversation;
use Emagma\SyliusAssistantTestChat\Skill\SkillCatalog;
use Emagma\SyliusAssistantTestChat\Skill\SkillStore;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\AI\McpBundle\Exception\RemoteCallException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(SkillCatalog::class)]
#[CoversClass(SkillStore::class)]
#[CoversClass(Conversation::class)]
final class SkillCatalogTest extends TestCase
{
    private const array OFFERED = [
        ['name' => 'morning-brief', 'description' => 'What to handle first today.', 'version' => '1.1.0', 'uri' => 'skill://merchant/morning-brief/SKILL.md'],
        ['name' => 'open-new-channel', 'description' => 'Open a sales channel.', 'version' => '1.0.0', 'uri' => 'skill://merchant/open-new-channel/SKILL.md'],
    ];

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/testchat-skills-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testTheCatalogShowsWhatTheServerOffersNextToWhatIsInstalled(): void
    {
        $store = new SkillStore($this->directory);
        $store->install('admin', 'morning-brief', '1.0.0', 'What to handle first today.');
        $store->install('admin', 'retired-skill', '0.9.0', 'No longer served.');
        $store->install('someone-else', 'open-new-channel', '1.0.0', 'Open a sales channel.');

        self::assertSame([
            ['name' => 'morning-brief', 'description' => 'What to handle first today.', 'version' => '1.1.0', 'installed' => '1.0.0'],
            ['name' => 'open-new-channel', 'description' => 'Open a sales channel.', 'version' => '1.0.0', 'installed' => null],
            ['name' => 'retired-skill', 'description' => 'No longer served.', 'version' => null, 'installed' => '0.9.0'],
        ], $this->catalog($store)->all('admin'));
    }

    public function testOnlyASkillTheServerOffersTheUserIsInstalledAtTheVersionServed(): void
    {
        $store = new SkillStore($this->directory);
        $catalog = $this->catalog($store);

        self::assertTrue($catalog->install('admin', 'morning-brief'));
        self::assertFalse($catalog->install('admin', 'delete-everything'));
        self::assertSame(['morning-brief' => ['version' => '1.1.0', 'description' => 'What to handle first today.']], $store->installed('admin'));

        $store->remove('admin', 'morning-brief');
        self::assertSame([], $store->installed('admin'));
    }

    public function testAServerWithoutSkillsOffersNothingAndKeepsWhatIsInstalled(): void
    {
        $store = new SkillStore($this->directory);
        $store->install('admin', 'morning-brief', '1.0.0', 'What to handle first today.');

        $connection = $this->createStub(ServerConnectionInterface::class);
        $connection->method('callTool')->willThrowException(new RemoteCallException('Tool not found: "list_skills".'));

        self::assertSame(
            [['name' => 'morning-brief', 'description' => 'What to handle first today.', 'version' => null, 'installed' => '1.0.0']],
            (new SkillCatalog($connection, $store))->all('admin'),
        );
    }

    public function testTheSystemPromptListsTheInstalledSkillsAsOfEachTurn(): void
    {
        $store = new SkillStore($this->directory);
        $catalog = $this->catalog($store);
        $conversation = new Conversation('2f535536-535e-4fbd-a4a3-fefa0f6d8f9f', 'admin', new MessageBag(Message::forSystem('You are an assistant.')));
        $system = static fn (): string => (string) $conversation->messages->getSystemMessage()?->getContent();

        $conversation->installSkills(SkillCatalog::HEADING, $catalog->prompt('admin'));
        self::assertSame('You are an assistant.', $system(), 'no skill, no section');

        $catalog->install('admin', 'morning-brief');
        $conversation->installSkills(SkillCatalog::HEADING, $catalog->prompt('admin'));
        self::assertStringStartsWith("You are an assistant.\n\n## Installed skills\n\n", $system());
        self::assertStringContainsString('`get_skill`', $system());
        self::assertStringEndsWith('- `morning-brief` (v1.1.0) - What to handle first today.', $system());

        $catalog->install('admin', 'open-new-channel');
        $conversation->installSkills(SkillCatalog::HEADING, $catalog->prompt('admin'));
        self::assertSame(1, substr_count($system(), SkillCatalog::HEADING), 'the section is replaced, not stacked');
        self::assertStringContainsString('- `open-new-channel` (v1.0.0)', $system());

        $store->remove('admin', 'morning-brief');
        $store->remove('admin', 'open-new-channel');
        $conversation->installSkills(SkillCatalog::HEADING, $catalog->prompt('admin'));
        self::assertSame('You are an assistant.', $system());
    }

    private function catalog(SkillStore $store): SkillCatalog
    {
        $connection = $this->createStub(ServerConnectionInterface::class);
        $connection->method('callTool')->willReturn(new CallToolResult([], false, ['items' => self::OFFERED, 'total' => 2, 'pack' => 'merchant']));

        return new SkillCatalog($connection, $store);
    }
}
