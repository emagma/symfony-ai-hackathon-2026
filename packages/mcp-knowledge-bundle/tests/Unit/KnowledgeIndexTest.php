<?php

declare(strict_types=1);

namespace Emagma\McpKnowledgeBundle\Tests\Unit;

use Emagma\McpKnowledgeBundle\Index\Keywords;
use Emagma\McpKnowledgeBundle\Index\KnowledgeIndex;
use Emagma\McpKnowledgeBundle\Procedure\ProcedureLoader;
use Emagma\McpKnowledgeBundle\Tool\SearchKnowledge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Bridge\Sqlite\Store;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(KnowledgeIndex::class)]
#[CoversClass(Keywords::class)]
#[CoversClass(SearchKnowledge::class)]
final class KnowledgeIndexTest extends TestCase
{
    private string $directory;

    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/mcp-knowledge-' . bin2hex(random_bytes(4));
        $this->directory = $this->work . '/knowledge';
        (new Filesystem())->mirror(__DIR__ . '/fixtures', $this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->work);
    }

    public function testAQuestionFindsThePassageThatAnswersItWithItsSource(): void
    {
        $passages = $this->index()->search('What do we do when a returned product is damaged in transit?', 2);

        self::assertNotSame([], $passages);
        self::assertSame('Damaged product returned', $passages[0]->title);
        self::assertSame('returns/damaged-product.md', $passages[0]->file);
        self::assertSame('2026-11-10', $passages[0]->updated);
        self::assertStringNotContainsString('Damaged product returned -', $passages[0]->text, 'the passage is the text, the title is beside it');
        self::assertLessThanOrEqual(2, \count($passages));
    }

    public function testAQuestionNoProcedureCoversFindsNothingAndTheToolSaysSo(): void
    {
        $tool = new SearchKnowledge($this->index());

        $result = $tool('customs duties for Switzerland');

        self::assertSame(0, $result['total']);
        self::assertSame('keywords', $result['mode']);
        self::assertStringContainsString('no procedure covers this case', $result['hint']);

        $found = $tool('lost parcel claim carrier');
        self::assertSame('Lost parcel', $found['items'][0]['title']);
        self::assertSame('Open a claim with the carrier', $found['items'][0]['section']);
        self::assertArrayNotHasKey('hint', $found);
    }

    public function testTheIndexFollowsTheFilesChangedAddedAndDeleted(): void
    {
        $index = $this->index();
        self::assertSame(['indexed' => 2, 'removed' => 0, 'unchanged' => 0], $index->sync());
        self::assertSame(['indexed' => 0, 'removed' => 0, 'unchanged' => 2], $index->sync(), 'nothing changed: nothing is read again');

        file_put_contents($this->directory . '/lost-parcel.md', "# Lost parcel\n\n## Refund the customer\n\nRefund after the carrier confirms the loss.\n");
        unlink($this->directory . '/returns/damaged-product.md');
        file_put_contents($this->directory . '/size-exchange.md', "# Size exchange\n\n## Send the new size\n\nShip the new size once the first one is scanned by the carrier.\n");

        self::assertSame(['indexed' => 2, 'removed' => 1, 'unchanged' => 0], $index->sync());
        self::assertSame([], $index->search('photograph damaged item'), 'the deleted procedure is forgotten');
        self::assertSame('Refund the customer', $index->search('refund carrier confirms loss')[0]->section, 'the changed one is read again');
        self::assertSame([], $index->search('claim portal'), 'its old text is gone');
        self::assertSame(2, $index->count());
    }

    public function testASearchIndexesWhatWasNeverIndexed(): void
    {
        self::assertNotSame([], $this->index()->search('claim carrier'), 'no explicit sync');
    }

    public function testTheWordsOfAQuestionWorthSearchingFor(): void
    {
        self::assertSame(['returned', 'product', 'damaged'], Keywords::of('What do we do when a returned product is DAMAGED?'));
        self::assertSame(['colis', 'perdu'], Keywords::of('Que faire pour un colis perdu ?'));
        self::assertSame([], Keywords::of('??'));
    }

    private function index(): KnowledgeIndex
    {
        $pdo = new \PDO('sqlite:' . $this->work . '/index.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return new KnowledgeIndex(new Store($pdo, 'mcp_knowledge'), new ProcedureLoader(), $this->directory, $this->work . '/index.sqlite.manifest.json');
    }
}
