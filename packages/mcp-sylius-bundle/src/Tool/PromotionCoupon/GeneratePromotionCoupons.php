<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\PromotionCoupon;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\Irreversible;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Generates a batch of random coupon codes on a cart promotion.
 *
 * @internal
 */
#[McpTool(
    name: 'generate_promotion_coupons',
    description: 'Generate a batch of RANDOM coupon codes on a Sylius admin cart promotion - for a mailing or a'
        . ' one-code-per-customer campaign. For readable codes you choose yourself, use upsert_promotion_coupons.'
        . ' Required: promotionCode, amount (how many codes, 1-10000) and codeLength (1-40 random'
        . ' characters). Optional: prefix, suffix (both count on top of codeLength), usageLimit,'
        . ' perCustomerUsageLimit and expiresAt (ISO-8601) applied to every generated coupon. codeLength'
        . ' must leave room for amount distinct codes, otherwise generation fails. The promotion MUST be'
        . ' couponBased or the codes would never apply. IRREVERSIBLE IN BULK: generated codes are only'
        . ' removed with delete_promotion_coupons, by code. Returns {generated: true, promotionCode,'
        . ' amount, sample} on success. Tool-specific reasons: promotion_not_found,'
        . ' promotion_not_coupon_based, amount_out_of_range, code_length_out_of_range.',
)]
#[Permission(resource: 'promotion', action: Action::Write)]
#[Irreversible]
final readonly class GeneratePromotionCoupons
{
    use LeanLocaleHeaderTrait;
    use WriteConfirmationTrait;

    private const string TARGET_TYPE = 'promotion_coupon';

    /**
     * Our ceiling, not Sylius's: a batch is one HTTP call but N inserts nothing here can take back.     */
    private const int MAX_AMOUNT = 10000;

    /** Mirrors the vendor Range constraint on `codeLength`. */
    private const int MIN_CODE_LENGTH = 1;

    private const int MAX_CODE_LENGTH = 40;

    /** How many freshly minted codes come back in the answer. */
    private const int SAMPLE_SIZE = 5;

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $promotionCode,
        int $amount,
        int $codeLength,
        ?string $prefix = null,
        ?string $suffix = null,
        ?int $usageLimit = null,
        ?int $perCustomerUsageLimit = null,
        ?string $expiresAt = null,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        if ($amount < 1 || $amount > self::MAX_AMOUNT) {
            return [
                'ok' => false,
                'generated' => false,
                'reason' => 'amount_out_of_range',
                'promotionCode' => $promotionCode,
                'hint' => \sprintf('amount must be between 1 and %d.', self::MAX_AMOUNT),
            ];
        }

        if ($codeLength < self::MIN_CODE_LENGTH || $codeLength > self::MAX_CODE_LENGTH) {
            return [
                'ok' => false,
                'generated' => false,
                'reason' => 'code_length_out_of_range',
                'promotionCode' => $promotionCode,
                'hint' => \sprintf(
                    'codeLength must be between %d and %d.',
                    self::MIN_CODE_LENGTH,
                    self::MAX_CODE_LENGTH,
                ),
            ];
        }

        try {
            $promotion = $this->client->getDecoded(
                Route::item(Resource::Promotion, $promotionCode),
            );
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['ok' => false, 'generated' => false, 'promotionCode' => $promotionCode], notFound: 'promotion_not_found');
        }

        if (true !== ($promotion['couponBased'] ?? false)) {
            return [
                'ok' => false,
                'generated' => false,
                'reason' => 'promotion_not_coupon_based',
                'promotionCode' => $promotionCode,
                'hint' => 'Set couponBased: true with upsert_promotions first, or the codes will never apply.',
            ];
        }

        $body = ['amount' => $amount, 'codeLength' => $codeLength];
        foreach (['prefix' => $prefix, 'suffix' => $suffix, 'expiresAt' => $expiresAt] as $key => $value) {
            if (null !== $value && '' !== $value) {
                $body[$key] = $value;
            }
        }
        foreach (['usageLimit' => $usageLimit, 'perCustomerUsageLimit' => $perCustomerUsageLimit] as $key => $value) {
            if (null !== $value) {
                $body[$key] = $value;
            }
        }

        $existing = $this->countExistingCoupons($promotionCode);

        $preview = implode("\n", [
            'Generate ' . $amount . ' coupon(s) on promotion "' . $promotionCode . '"',
            '',
            'Code shape: ' . ($prefix ?? '') . str_repeat('X', $codeLength) . ($suffix ?? ''),
            'Usage limit: ' . (null !== $usageLimit ? (string) $usageLimit : 'none')
                . ', per customer: ' . (null !== $perCustomerUsageLimit ? (string) $perCustomerUsageLimit : 'none'),
            'Expires: ' . ($expiresAt ?? 'never'),
            null !== $existing ? 'The promotion already has ' . $existing . ' coupon(s).' : '',
            '',
            'There is no batch delete - undoing this means deleting each code one at a time.',
        ]);

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm generating ' . $amount . ' coupons on "' . $promotionCode . '"',
            'Generate these coupons?',
            $preview,
            'Re-invoke with confirm: true to generate them.',
        );
        if (null !== $refusal) {
            $out = ['ok' => false, 'generated' => false, 'promotionCode' => $promotionCode, 'amount' => $amount] + $refusal;
            if (isset($refusal['hint'])) {
                $out['preview'] = $preview;
            }

            return $out;
        }

        try {
            $response = $this->client->post(
                Route::collectionAction(Resource::PromotionCoupon, 'generate', ['promotion' => $promotionCode]),
                $body,
            );
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['generated' => false, 'promotionCode' => $promotionCode]);
        }

        $this->audit->record(
            tool: 'generate_promotion_coupons',
            targetType: self::TARGET_TYPE,
            // The batch has no single code, so the promotion is the target.
            targetCode: $promotionCode,
            beforeState: ['couponCount' => $existing],
            afterState: ['generated' => $amount],
            diff: ['generated' => ['before' => 0, 'after' => $amount]],
            context: $context,
        );

        return [
            'ok' => true,
            'generated' => true,
            'promotionCode' => $promotionCode,
            'amount' => $amount,
            // A sample only: the whole batch would flood the caller's context.
            'sample' => self::sampleCodes($response),
        ];
    }

    /**
     * Best-effort: the count only enriches the preview and must not stop the generation.     */
    private function countExistingCoupons(string $promotionCode): ?int
    {
        try {
            $payload = $this->client->getDecoded(
                Route::collection(Resource::PromotionCoupon, ['promotion' => $promotionCode]),
                ['itemsPerPage' => 1],
                self::leanHeaders(),
            );
        } catch (\Throwable) {
            return null;
        }

        $total = $payload['totalItems'] ?? $payload['hydra:totalItems'] ?? null;

        return \is_numeric($total) ? (int) $total : null;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return list<string>
     */
    private static function sampleCodes(array $response): array
    {
        // The generate operation answers a bare JSON array of coupons, not a Hydra collection.
        $members = $response['member'] ?? $response['hydra:member'] ?? $response;
        if (!\is_array($members)) {
            return [];
        }

        $codes = [];
        foreach ($members as $member) {
            $code = \is_array($member) ? ($member['code'] ?? null) : null;
            if (\is_string($code) && '' !== $code) {
                $codes[] = $code;
            }
            if (\count($codes) >= self::SAMPLE_SIZE) {
                break;
            }
        }

        return $codes;
    }
}
