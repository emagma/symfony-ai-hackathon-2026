<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Testing;

use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\Redaction\SensitiveDataRedactor;
use Mcp\Server\RequestContext;

/**
 * For the test suites of the components that write tools: the sink is a mock, so no audit bundle is needed.
 * A conformance check on every write a test records: a `targetCode` holding an email fails the test, since
 * the target code is stored as given and read back by whoever reads the audit.
 */
final readonly class SinkWriteJournal implements WriteJournalInterface
{
    public function __construct(
        private WriteSinkInterface $sink,
        private ?SensitiveDataRedactor $redactor = null,
    ) {
    }

    public function record(string $tool, string $targetType, string $targetCode, array $beforeState, array $afterState, array $diff, ?RequestContext $context = null, ?string $batchId = null): void
    {
        $this->stage($tool, $targetType, $targetCode, $beforeState, $afterState, $diff, $context, $batchId);
        $this->flush();
    }

    public function stage(string $tool, string $targetType, string $targetCode, array $beforeState, array $afterState, array $diff, ?RequestContext $context = null, ?string $batchId = null): void
    {
        if (SensitiveDataRedactor::containsEmail($targetCode)) {
            throw new \LogicException(\sprintf('%s records the target code "%s" of a %s: an email is personal data, record the id instead.', $tool, $targetCode, $targetType));
        }

        /** @var array<string, mixed> $before */
        $before = null === $this->redactor ? $beforeState : $this->redactor->redact($beforeState);
        /** @var array<string, mixed> $after */
        $after = null === $this->redactor ? $afterState : $this->redactor->redact($afterState);

        $this->sink->persist(new RecordedWrite(
            $tool,
            $targetType,
            $targetCode,
            $before,
            $after,
            null === $this->redactor ? $diff : $this->redactor->redactDiff($diff),
            $context?->getSession()->getId()->toString(),
            $batchId,
        ));
    }

    public function flush(): void
    {
        $this->sink->flush();
    }
}
