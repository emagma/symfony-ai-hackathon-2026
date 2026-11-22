<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Shared;

use Emagma\McpCoreBundle\Authorization\RevealsPersonalData;
use Emagma\McpCoreBundle\Privacy\PrivacyContext;
use Emagma\McpCoreBundle\Privacy\PrivacyMode;
use Mcp\Server\RequestContext;
use Symfony\Component\Uid\Uuid;

/**
 * The `reveal: true` of a `get_*` tool marked {@see RevealsPersonalData}. A refusal keeps
 * the read tool's shape (`found: []`, `missing: []`, `reason`), without `ok`.
 * The tool using this needs `GrantsProviderInterface $grants` and `WriteJournalInterface $audit`.
 */
trait RevealTrait
{
    /**
     * @return array{found: list<never>, missing: list<never>, reason: string, missingGrant?: string}|null the refusal, or null to proceed
     */
    private function refuseReveal(bool $reveal): ?array
    {
        if (!$reveal || PrivacyMode::Pseudonymise !== PrivacyContext::mode()) {
            return null;
        }
        if (!PrivacyContext::revealAllowed()) {
            return ['found' => [], 'missing' => [], 'reason' => 'reveal_disabled_for_client'];
        }
        if (!$this->grants->grants()->allows(RevealsPersonalData::permission())) {
            return ['found' => [], 'missing' => [], 'reason' => 'forbidden', 'missingGrant' => 'core:pii:reveal'];
        }

        return null;
    }

    /**
     * One audit entry per code found, under one batch id: a reveal is an act, not a read.
     *
     * @param list<string> $codes the identifiers actually found
     *
     * @return array{ids: list<string>, batchId: string}
     */
    private function reveal(string $tool, string $targetType, array $codes, ?RequestContext $context): array
    {
        PrivacyContext::revealThisResponse();

        $batchId = Uuid::v7()->toRfc4122();
        foreach ($codes as $code) {
            $this->audit->stage($tool, $targetType, $code, [], [], [], $context, batchId: $batchId);
        }
        $this->audit->flush();

        return ['ids' => $codes, 'batchId' => $batchId];
    }

    private static function revealing(bool $reveal): bool
    {
        return $reveal && PrivacyMode::Pseudonymise === PrivacyContext::mode();
    }
}
