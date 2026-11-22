<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\WriteConfirmation;

use Emagma\McpCoreBundle\Caller\ClientInfo;
use Mcp\Schema\Elicitation\BooleanSchemaDefinition;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Server\RequestContext;

/**
 * Asks a person before a write tool applies: by elicitation when the client offers it, otherwise the policy decides
 * whether the model's `confirm` stands for consent.
 */
trait WriteConfirmationTrait
{
    /**
     * Null when the caller may proceed, otherwise a refusal the tool merges into its own response.
     * `$confirm` comes from the model and can be forged by a prompt injection: a human's answer always takes precedence.
     *
     * @return array{ok: false, reason: string}|array{ok: false, reason: string, hint: string}|null
     */
    private function confirmWrite(
        ?RequestContext $context,
        bool $confirm,
        string $elicitMessage,
        string $title,
        string $preview,
        string $applyHint,
    ): ?array {
        if (null !== $context && $context->getClientGateway()->supportsElicitation()) {
            $asked = hrtime(true);
            $elicit = $context->getClientGateway()->elicit(
                $elicitMessage,
                new ElicitationSchema(
                    properties: ['confirm' => new BooleanSchemaDefinition(
                        title: $title,
                        description: $preview,
                    )],
                    required: ['confirm'],
                ),
            );
            $elapsed = (hrtime(true) - $asked) / 1e9;

            if ($elicit->isAccepted() && true === ($elicit->content['confirm'] ?? null)) {
                return null;
            }
            // A cancel no human could have produced falls through: the session has no human channel after all.
            if (!$elicit->isCancelled() || null !== $elicit->content || !WriteConfirmationPolicy::current()->cancelledByClient($elapsed)) {
                return ['ok' => false, 'reason' => $elicit->isCancelled() ? 'cancelled' : 'declined'];
            }
        }

        if (null !== $context) {
            // A session without a human channel: `$confirm` is the model's word, so the policy decides.
            if (!WriteConfirmationPolicy::current()->acceptsModelConfirmation(
                WriteConfirmationPolicy::permissionsOf($this),
                ClientInfo::of($context->getSession())->name,
            )) {
                return [
                    'ok' => false,
                    'reason' => 'human_confirmation_required',
                    'hint' => 'This client offers no way to ask a human, and a model-supplied confirm is'
                        . ' not accepted for this action. Use an MCP client that supports elicitation,'
                        . ' list this client under emagma_mcp_core.write_confirmation'
                        . '.trusted_clients if its own UI makes a human approve every tool call, or'
                        . ' relax write_confirmation.require_human on a deployment that genuinely has'
                        . ' no human in the loop.',
                ];
            }

            if ($confirm) {
                return null;
            }

            return ['ok' => false, 'reason' => 'elicitation_unavailable_preview_only', 'hint' => $applyHint];
        }

        // No session: the caller is the host itself (CLI, tests), not a model. Not reachable from the wire.
        if ($confirm) {
            return null;
        }

        return ['ok' => false, 'reason' => 'elicitation_unavailable_preview_only', 'hint' => $applyHint];
    }
}
