<?php

declare(strict_types=1);

namespace Emagma\McpKnowledgeBundle\Index;

use Emagma\McpKnowledgeBundle\Procedure\ProcedureLoader;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\ManagedStoreInterface;
use Symfony\AI\Store\Query\HybridQuery;
use Symfony\AI\Store\Query\TextQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The procedures of a directory in a Symfony AI store, kept in step with the files: a search first indexes
 * what was written or changed since the last one, and forgets what was deleted.
 *
 * Without a vectorizer the search is by words (full text, ranked); with one it is hybrid, words and meaning.
 */
final class KnowledgeIndex
{
    /** Stored where no embedding is computed: the store wants a vector, the text search never reads it. */
    private const array NO_VECTOR = [0.0];

    private bool $ready = false;

    public function __construct(
        private readonly StoreInterface&ManagedStoreInterface $store,
        private readonly ProcedureLoader $loader,
        private readonly string $directory,
        private readonly string $manifestFile,
        private readonly ?VectorizerInterface $vectorizer = null,
    ) {
    }

    public function mode(): string
    {
        return null === $this->vectorizer ? 'keywords' : 'hybrid';
    }

    /**
     * @return array{indexed: int, removed: int, unchanged: int} in files
     */
    public function sync(bool $rebuild = false): array
    {
        $this->store->setup();
        $manifest = $rebuild ? [] : $this->manifest();
        if ($rebuild) {
            $this->store->clear();
        }

        $summary = ['indexed' => 0, 'removed' => 0, 'unchanged' => 0];
        $files = $this->loader->files($this->directory);

        foreach (array_diff(array_keys($manifest), $files) as $deleted) {
            $this->forget($manifest[$deleted]['ids']);
            unset($manifest[$deleted]);
            ++$summary['removed'];
        }

        foreach ($files as $file) {
            $hash = sha1_file($this->directory . '/' . $file) ?: '';
            if (($manifest[$file]['hash'] ?? null) === $hash) {
                ++$summary['unchanged'];

                continue;
            }
            $this->forget($manifest[$file]['ids'] ?? []);

            /** @var list<TextDocument> $documents */
            $documents = [...$this->loader->load($this->directory, ['file' => $file])];
            if ([] !== $documents) {
                $this->store->add($this->vectors($documents));
            }
            $manifest[$file] = ['hash' => $hash, 'ids' => array_map(static fn (TextDocument $document): string => (string) $document->getId(), $documents)];
            ++$summary['indexed'];
        }

        if ($rebuild || $summary['indexed'] > 0 || $summary['removed'] > 0) {
            (new Filesystem())->dumpFile($this->manifestFile, json_encode($manifest, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
        }
        $this->ready = true;

        return $summary;
    }

    /**
     * @return list<Passage> the closest first
     */
    public function search(string $question, int $limit = 4): array
    {
        if (!$this->ready) {
            $this->sync();
        }
        $keywords = Keywords::of($question);
        if ([] === $keywords) {
            return [];
        }

        $query = null === $this->vectorizer
            ? new TextQuery($keywords)
            : new HybridQuery($this->questionVector($question), $keywords);

        $passages = [];
        foreach ($this->store->query($query, ['maxItems' => max(1, $limit)]) as $document) {
            $metadata = $document->getMetadata();
            $text = $metadata->getText() ?? '';
            $passages[] = new Passage(
                $metadata->getTitle() ?? '',
                self::string($metadata[ProcedureLoader::KEY_SECTION] ?? null) ?? '',
                // The stored text opens with "title - section": the passage is what follows.
                trim(preg_replace('/\A.*?\R\R/s', '', $text, 1) ?? $text),
                self::string($metadata[ProcedureLoader::KEY_FILE] ?? null) ?? '',
                self::string($metadata[ProcedureLoader::KEY_TEAM] ?? null),
                self::string($metadata[ProcedureLoader::KEY_UPDATED] ?? null),
            );
        }

        return $passages;
    }

    /**
     * A long-lived process looks at the files again on its next search.
     */
    public function forgetSync(): void
    {
        $this->ready = false;
    }

    public function count(): int
    {
        return array_sum(array_map(static fn (array $entry): int => \count($entry['ids']), $this->manifest()));
    }

    /**
     * @param list<TextDocument> $documents
     *
     * @return list<VectorDocumentInterface>
     */
    private function vectors(array $documents): array
    {
        if (null !== $this->vectorizer) {
            /** @var list<VectorDocumentInterface> $vectors */
            $vectors = $this->vectorizer->vectorize($documents);

            return $vectors;
        }

        return array_map(
            static fn (TextDocument $document): VectorDocument => new VectorDocument($document->getId(), new Vector(self::NO_VECTOR), $document->getMetadata()),
            $documents,
        );
    }

    private function questionVector(string $question): Vector
    {
        \assert(null !== $this->vectorizer);

        return $this->vectorizer->vectorize($question);
    }

    /**
     * @param list<string> $ids
     */
    private function forget(array $ids): void
    {
        if ([] !== $ids) {
            $this->store->remove($ids);
        }
    }

    /**
     * @return array<string, array{hash: string, ids: list<string>}> by file
     */
    private function manifest(): array
    {
        if (!is_file($this->manifestFile)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->manifestFile), true);
        $manifest = [];
        foreach (\is_array($decoded) ? $decoded : [] as $file => $entry) {
            if (\is_string($file) && \is_array($entry) && \is_string($entry['hash'] ?? null) && \is_array($entry['ids'] ?? null)) {
                $manifest[$file] = ['hash' => $entry['hash'], 'ids' => array_values(array_filter($entry['ids'], \is_string(...)))];
            }
        }

        return $manifest;
    }

    private static function string(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
