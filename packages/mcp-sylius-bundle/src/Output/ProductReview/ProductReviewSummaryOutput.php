<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Output\ProductReview;

use Emagma\McpCoreBundle\Output\OutputFieldsTrait;
use Emagma\McpCoreBundle\Output\Untrusted;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * @internal
 */
final readonly class ProductReviewSummaryOutput implements \JsonSerializable
{
    use OutputFieldsTrait;

    public function __construct(
        public string $id,
        #[Untrusted]
        public ?string $title,
        public ?int $rating,
        #[Untrusted]
        public ?string $comment,
        public ?string $status,
        public ?string $productCode,
        public ?string $authorId,
        public ?string $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $payload raw decoded JSON-LD
     */
    public static function fromPayload(array $payload): self
    {
        $id = $payload['id'] ?? null;

        return new self(
            id: \is_int($id) || (\is_string($id) && '' !== $id)
                ? (string) $id
                : (AdminPayload::code($payload['@id'] ?? null) ?? ''),
            title: AdminPayload::string($payload['title'] ?? null),
            rating: AdminPayload::int($payload['rating'] ?? null),
            comment: AdminPayload::string($payload['comment'] ?? null),
            status: AdminPayload::string($payload['status'] ?? null),
            productCode: AdminPayload::code($payload['reviewSubject'] ?? null),
            authorId: AdminPayload::code($payload['author'] ?? null),
            createdAt: AdminPayload::string($payload['createdAt'] ?? null),
        );
    }
}
