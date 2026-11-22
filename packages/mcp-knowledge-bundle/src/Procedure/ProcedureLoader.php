<?php

declare(strict_types=1);

namespace Emagma\McpKnowledgeBundle\Procedure;

use Symfony\AI\Store\Document\LoaderInterface;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\Component\Finder\Finder;

/**
 * Reads a directory of procedures written in Markdown: an optional front matter (title, team, updated), then
 * one `##` section per step or case. Each section is a document of its own: what a search finds and quotes.
 *
 * @internal
 */
final readonly class ProcedureLoader implements LoaderInterface
{
    public const string KEY_FILE = 'file';

    public const string KEY_SECTION = 'section';

    public const string KEY_TEAM = 'team';

    public const string KEY_UPDATED = 'updated';

    /**
     * @param string|null          $source  the directory
     * @param array<string, mixed> $options `file`: only this file, relative to the directory
     *
     * @return iterable<TextDocument>
     */
    public function load(?string $source = null, array $options = []): iterable
    {
        if (null === $source || !is_dir($source)) {
            return;
        }
        $only = \is_string($options['file'] ?? null) ? $options['file'] : null;

        foreach ((new Finder())->files()->in($source)->name('*.md')->sortByName() as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            if (null !== $only && $only !== $relative) {
                continue;
            }
            yield from $this->sections($relative, $file->getContents());
        }
    }

    /**
     * @return list<string> the procedures of the directory, relative paths
     */
    public function files(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        foreach ((new Finder())->files()->in($directory)->name('*.md')->sortByName() as $file) {
            $files[] = str_replace('\\', '/', $file->getRelativePathname());
        }

        return $files;
    }

    /**
     * @return list<TextDocument>
     */
    private function sections(string $file, string $markdown): array
    {
        [$front, $body] = self::frontMatter($markdown);
        $title = $front['title'] ?? self::firstHeading($body) ?? pathinfo($file, \PATHINFO_FILENAME);

        // Text before the first `##` is the introduction of the procedure.
        $parts = preg_split('/^##\s+(.+)$/m', $body, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [$body];
        $sections = [['Introduction', (string) array_shift($parts)]];
        for ($i = 0; $i + 1 < \count($parts); $i += 2) {
            $sections[] = [trim($parts[$i]), $parts[$i + 1]];
        }

        $documents = [];
        foreach ($sections as $index => [$section, $text]) {
            $text = trim(preg_replace('/^#\s+.+$/m', '', $text) ?? $text);
            if ('' === $text) {
                continue;
            }
            $content = \sprintf("%s - %s\n\n%s", $title, $section, $text);
            $metadata = new Metadata([
                self::KEY_FILE => $file,
                self::KEY_SECTION => $section,
                self::KEY_TEAM => $front['team'] ?? null,
                self::KEY_UPDATED => $front['updated'] ?? null,
            ]);
            $metadata->setTitle($title);
            $metadata->setSource($file);
            $metadata->setText($content);

            $documents[] = new TextDocument(sha1($file . '#' . $index), $content, $metadata);
        }

        return $documents;
    }

    /**
     * @return array{array<string, string>, string} the `key: value` lines between two `---`, and the rest
     */
    private static function frontMatter(string $markdown): array
    {
        if (1 !== preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $markdown, $matches)) {
            return [[], $markdown];
        }
        $front = [];
        foreach (preg_split('/\R/', $matches[1]) ?: [] as $line) {
            if (1 === preg_match('/^([A-Za-z_]+)\s*:\s*(.+)$/', trim($line), $pair)) {
                $front[strtolower($pair[1])] = trim($pair[2], " \t\"'");
            }
        }

        return [$front, $matches[2]];
    }

    private static function firstHeading(string $body): ?string
    {
        return 1 === preg_match('/^#\s+(.+)$/m', $body, $matches) ? trim($matches[1]) : null;
    }
}
