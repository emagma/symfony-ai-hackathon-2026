<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductReview;

use Emagma\McpCoreBundle\Redaction\SensitiveDataRedactor;
use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductReview\AcceptProductReview;
use Emagma\McpSyliusBundle\Tool\ProductReview\DeleteProductReview;
use Emagma\McpSyliusBundle\Tool\ProductReview\RejectProductReview;
use Emagma\McpSyliusBundle\Tool\ProductReview\SearchProductReviews;
use Emagma\McpSyliusBundle\Tool\ProductReview\UpdateProductReview;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SearchProductReviews::class)]
#[CoversClass(UpdateProductReview::class)]
#[CoversClass(AcceptProductReview::class)]
#[CoversClass(RejectProductReview::class)]
#[CoversClass(DeleteProductReview::class)]
#[CoversClass(ProductReviewShaper::class)]
final class ProductReviewToolsTest extends McpToolTestCase
{
    private const string PATH = 'product-reviews/7';

    public function testListSendsTheModerationQueueQuery(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-reviews', [
                'status' => 'new',
                'page' => 1,
                'itemsPerPage' => 30,
            ])
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductReviews($client, $this->adapter()), status: 'new');
    }

    public function testListTurnsTheProductCodeIntoTheIriTheSearchFilterExpects(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-reviews', self::callback(static function (array $query): bool {
                self::assertSame('/api/v2/admin/products/MUG', $query['reviewSubject']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductReviews($client, $this->adapter()), product: 'MUG');
    }

    public function testListForwardsAWhitelistedSort(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-reviews', self::callback(static function (array $query): bool {
                self::assertSame(['rating' => 'asc'], $query['order']);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductReviews($client, $this->adapter()), sort: 'rating', direction: 'asc');
    }

    public function testListIgnoresASortFieldThatIsNotSortableServerSide(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-reviews', self::callback(static function (array $query): bool {
                self::assertArrayNotHasKey('order', $query);

                return true;
            }))
            ->willReturn($this->collection([], total: 0));

        $this->call(new SearchProductReviews($client, $this->adapter()), sort: 'comment');
    }

    public function testListCapsThePageSizeAndReportsMorePages(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('product-reviews', self::callback(static function (array $query): bool {
                self::assertSame(50, $query['itemsPerPage']);

                return true;
            }))
            ->willReturn($this->collection([$this->review()], total: 120));

        $result = $this->call(new SearchProductReviews($client, $this->adapter()), itemsPerPage: 500);

        self::assertSame(120, $result['total']);
        self::assertTrue($result['hasMore']);
    }

    public function testListProjectsTheAuthorAsAnIdRatherThanACustomerRecord(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([$this->review()], total: 1));

        $items = $this->call(new SearchProductReviews($client, $this->adapter()))['items'];

        self::assertSame('20', $items[0]['authorId']);
        self::assertSame('MUG', $items[0]['productCode']);
        self::assertArrayNotHasKey('found', $items[0]);
    }

    /** Writing a review is free, and reading one puts a stranger's words in the model's context. */
    public function testTheModerationQueueMarksTheTextItReturnsAsUntrusted(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([$this->review()], total: 1));

        $result = $this->call(new SearchProductReviews($client, $this->adapter()), status: 'new');

        self::assertSame(['title', 'comment'], $result['items'][0]['untrustedContent']['fields']);
        self::assertArrayNotHasKey('untrustedContent', $result);
    }

    public function testUpdateShortCircuitsWithoutReachingTheNetwork(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecoded');

        self::assertSame(
            'no_change_requested',
            $this->call(new UpdateProductReview($client, $this->audit()), '7', confirm: true)['reason'],
        );
    }

    public function testUpdateReturnsNoChangeWhenIdentical(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->review());
        $client->expects(self::never())->method('put');

        self::assertSame(
            'no_change',
            $this->call(new UpdateProductReview($client, $this->audit()), '7', title: 'Great mug', confirm: true)['reason'],
        );
    }

    public function testUpdateResendsTheUntouchedFieldsSoThePutDoesNotBlankThem(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->review());
        $client->expects(self::once())
            ->method('put')
            ->with(self::PATH, [
                'title' => 'Great mug',
                'rating' => 4,
                // PUT replaces what the denormalization group sees: a field left alone still has to travel.
                'comment' => 'Keeps coffee hot.',
            ], [])
            ->willReturn(['id' => 7, 'rating' => 4] + $this->review());

        $result = $this->call(new UpdateProductReview($client, $this->audit()), '7', rating: 4, confirm: true);

        self::assertTrue($result['updated']);
        self::assertSame(['rating' => ['before' => 5, 'after' => 4]], $result['diff']);
    }

    public function testUpdateSurfacesValidationErrors(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->review());
        $client->method('put')->willThrowException($this->clientException(422, [
            ['propertyPath' => 'rating', 'message' => 'This value should be between 1 and 5.'],
        ]));

        $result = $this->call(new UpdateProductReview($client, $this->audit()), '7', rating: 9, confirm: true);

        self::assertSame('validation_error', $result['reason']);
        self::assertSame(
            [['property' => 'rating', 'message' => 'This value should be between 1 and 5.']],
            $result['errors'],
        );
    }

    public function testUpdateReturnsNotFoundOn404(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));

        self::assertSame(
            'not_found',
            $this->call(new UpdateProductReview($client, $this->audit()), '999', title: 'X', confirm: true)['reason'],
        );
    }

    public function testAcceptPatchesTheTransitionEndpointWithNoBody(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->review());
        $client->expects(self::once())
            ->method('patch')
            ->with(self::PATH . '/accept', [], [])
            ->willReturn(['status' => 'accepted'] + $this->review());

        $result = $this->call(new AcceptProductReview($client, $this->audit()), '7', confirm: true);

        self::assertTrue($result['accepted']);
        self::assertSame(['status' => ['before' => 'new', 'after' => 'accepted']], $result['diff']);
        self::assertSame('accepted', $result['after']['status']);
    }

    public function testRejectPatchesItsOwnTransitionEndpoint(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->review());
        $client->expects(self::once())
            ->method('patch')
            ->with(self::PATH . '/reject', [], [])
            ->willReturn(['status' => 'rejected'] + $this->review());

        $result = $this->call(new RejectProductReview($client, $this->audit()), '7', confirm: true);

        self::assertTrue($result['rejected']);
        self::assertSame(['status' => ['before' => 'new', 'after' => 'rejected']], $result['diff']);
    }

    /** The state machine only allows `new → accepted|rejected`; Sylius would 422 without saying why. */
    public function testTheTransitionToolsRefuseAnAlreadyDecidedReview(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['status' => 'accepted'] + $this->review());
        $client->expects(self::never())->method('patch');

        self::assertSame(
            'already_accepted',
            $this->call(new AcceptProductReview($client, $this->audit()), '7', confirm: true)['reason'],
        );
        self::assertSame(
            'already_accepted',
            $this->call(new RejectProductReview($client, $this->audit()), '7', confirm: true)['reason'],
        );
    }

    public function testRejectReportsAnAlreadyRejectedReviewAsSuch(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['status' => 'rejected'] + $this->review());

        self::assertSame(
            'already_rejected',
            $this->call(new RejectProductReview($client, $this->audit()), '7', confirm: true)['reason'],
        );
    }

    public function testDeleteRecordsAnAuditEntry(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->review());
        $client->expects(self::once())->method('delete')->with(self::PATH);

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'delete_product_review' === $e->getTool() &&
                'product_review' === $e->getTargetType() &&
                '7' === $e->getTargetCode(),
        ));

        $result = $this->call(new DeleteProductReview($client, $audit), '7', confirm: true);

        self::assertTrue($result['deleted']);
        self::assertSame('Great mug', $result['before']['title']);
    }

    public function testTheAuditKeepsTheReviewTextAndStillScrubsAnEmailInIt(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(
            ['comment' => 'Reach me at ada@example.com'] + $this->review(),
        );
        $client->method('patch')->willReturn(['status' => 'accepted'] + $this->review());

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static function (RecordedWrite $entry): bool {
                $before = $entry->getBeforeState();
                self::assertSame('Great mug', $before['title']);
                self::assertSame('Reach me at ' . SensitiveDataRedactor::MARKER, $before['comment']);

                return true;
            },
        ));

        $result = $this->call(new AcceptProductReview($client, $audit), '7', confirm: true);

        // The tool's own answer keeps the real text: the caller holds product_review:write.
        self::assertSame('Reach me at ada@example.com', $result['before']['comment']);
    }

    public function testTheWriteToolsRefuseWithoutElicitation(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->review());
        $client->expects(self::never())->method('put');
        $client->expects(self::never())->method('patch');
        $client->expects(self::never())->method('delete');

        $context = $this->noElicitContext();

        self::assertSame(
            'human_confirmation_required',
            $this->call(new UpdateProductReview($client, $this->audit()), '7', title: 'X', context: $context)['reason'],
        );
        self::assertSame(
            'human_confirmation_required',
            $this->call(new AcceptProductReview($client, $this->audit()), '7', context: $context)['reason'],
        );
        self::assertSame(
            'human_confirmation_required',
            $this->call(new RejectProductReview($client, $this->audit()), '7', context: $context)['reason'],
        );
        self::assertSame(
            'human_confirmation_required',
            $this->call(new DeleteProductReview($client, $this->audit()), '7', context: $context)['reason'],
        );
    }

    public function testElicitationAcceptedProceedsToWrite(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->review());
        $client->expects(self::once())->method('patch')->willReturn(['status' => 'accepted'] + $this->review());

        $tool = new AcceptProductReview($client, $this->audit());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool('7', context: $context),
            ElicitAction::Accept,
            ['confirm' => true],
        );

        self::assertTrue($result['accepted']);
    }

    public function testElicitationDeclinedLeavesTheReviewPending(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->review());
        $client->expects(self::never())->method('patch');

        $tool = new RejectProductReview($client, $this->audit());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool('7', context: $context),
            ElicitAction::Decline,
        );

        self::assertFalse($result['rejected']);
    }

    /**
     * @return array<string, mixed>
     */
    private function review(): array
    {
        return [
            '@id' => '/api/v2/admin/product-reviews/7',
            'id' => 7,
            'title' => 'Great mug',
            'rating' => 5,
            'comment' => 'Keeps coffee hot.',
            'status' => 'new',
            'author' => '/api/v2/admin/customers/20',
            'reviewSubject' => '/api/v2/admin/products/MUG',
            'createdAt' => '2026-09-01T10:00:00+00:00',
        ];
    }
}
