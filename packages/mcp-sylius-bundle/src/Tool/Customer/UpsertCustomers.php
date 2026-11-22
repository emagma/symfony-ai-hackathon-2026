<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Customer;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\Customer\CustomerOutput;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchUpsertTrait;
use Emagma\McpSyliusBundle\Tool\Shared\LeanLocaleHeaderTrait;
use Emagma\McpSyliusBundle\Tool\Shared\ReferenceResolver;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;

/**
 * Sylius only declares a PARTIAL search on the email: the probe asks one page per address and matches exactly here.
 * The password joins the body only, never the diff, the preview or the audit.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_customers',
    description: 'Create or update Sylius admin customers in one call, at most 50 items, matched on their email.'
        . ' Profile fields: firstName, lastName, gender, birthday, phoneNumber, subscribedToNewsletter,'
        . ' groupCode (null detaches). password creates the shop login on a creation and resets it on an'
        . ' existing account (no_account if they have none); accountEnabled: false locks it reversibly.'
        . ' The password is never echoed, previewed or audited. mode: upsert (default), create'
        . ' (already_exists on a known email) or update (not_found on an unknown one). An item that changes'
        . ' nothing is unchanged and not written. One confirmation for the batch; one result per item.'
        . ' Tool-specific reasons: group_not_found, no_account.',
)]
#[Permission(resource: 'customer', action: Action::Write)]
final readonly class UpsertCustomers
{
    use BatchUpsertTrait;
    use LeanLocaleHeaderTrait;
    use WriteConfirmationTrait;

    private const int PROBE_PAGE_SIZE = 50;

    /** What the audit records when the shop answered without an id: still not the email. */
    private const string UNKNOWN_ID = '(unknown id)';

    private const array PROFILE = ['firstName', 'lastName', 'gender', 'birthday', 'phoneNumber', 'subscribedToNewsletter'];

    public function __construct(
        private AdminApiClientInterface $client,
        private ReferenceResolver $references,
        private WriteJournalInterface $audit,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(type: 'array', items: CustomerItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_customers', Route::collection(Resource::Customer), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'customer';
    }

    /**
     * Names, email and phone are the customer's own text: a diff's `before` on them is marked like a read.
     *
     * @return class-string<CustomerOutput>
     */
    private static function outputClass(): string
    {
        return CustomerOutput::class;
    }

    private function label(): string
    {
        return 'customers';
    }

    /**
     * @param array<mixed> $item
     */
    private function keyOf(array $item): ?string
    {
        $email = $item['email'] ?? null;

        return \is_string($email) && '' !== trim($email) ? mb_strtolower(trim($email)) : null;
    }

    private function validateItem(array $item): ?string
    {
        foreach (['firstName', 'lastName', 'birthday', 'phoneNumber'] as $field) {
            if (\array_key_exists($field, $item) && !\is_string($item[$field])) {
                return $field . ' is a string';
            }
        }
        if (\array_key_exists('gender', $item) && !\in_array($item['gender'], ['m', 'f', 'u'], true)) {
            return 'gender is one of m, f, u';
        }
        if (\array_key_exists('password', $item) && (!\is_string($item['password']) || '' === $item['password'])) {
            return 'password is a non-empty string';
        }
        foreach (['subscribedToNewsletter', 'accountEnabled'] as $field) {
            if (\array_key_exists($field, $item) && !\is_bool($item[$field])) {
                return $field . ' is a boolean';
            }
        }
        if (\array_key_exists('groupCode', $item) && null !== $item['groupCode'] && !\is_string($item['groupCode'])) {
            return 'groupCode is a string, or null to detach';
        }

        return null;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null}>
     */
    private function probe(Route $collection, array $keys): array
    {
        $paths = [];
        foreach ($keys as $key) {
            $paths[$key] = $collection->withQuery(['email' => $key, 'itemsPerPage' => self::PROBE_PAGE_SIZE]);
        }
        $pages = $this->client->getDecodedMany($paths, self::leanHeaders());

        /** @var array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null}> $probes */
        $probes = [];
        foreach ($keys as $key) {
            $page = $pages[$key] ?? ['ok' => false, 'status' => null, 'error' => 'no_response'];
            if (true !== $page['ok']) {
                $probes[$key] = ['ok' => false, 'status' => $page['status'], 'error' => $page['error']];

                continue;
            }
            $match = null;
            foreach (AdminPayload::members($page['body']) as $member) {
                $email = AdminPayload::string($member['email'] ?? null);
                if (null !== $email && mb_strtolower($email) === $key) {
                    $match = $member;

                    break;
                }
            }
            $probes[$key] = null === $match
                ? ['ok' => false, 'status' => 404, 'error' => 'HTTP 404']
                : ['ok' => true, 'body' => $match];
        }

        return $probes;
    }

    /**
     * @param array<string, mixed> $current
     */
    private function writePath(Route $collection, string $key, array $current): Route
    {
        return $collection->withKey((string) AdminPayload::identifier($current));
    }

    /**
     * The id, never the email the batch is keyed on: the audit stores its target code as given.
     *
     * @param array<string, mixed> $resource
     */
    private function auditCode(string $key, array $resource): string
    {
        return AdminPayload::identifier($resource) ?? self::UNKNOWN_ID;
    }

    /**
     * @param array<string, array<mixed>> $itemsByKey
     * @param array<array-key, array{ok: true, body: array<string, mixed>}|array{ok: false, status: int|null, error: string, body?: array<string, mixed>|null}> $probes
     *
     * @return array{failures: array<string, array<string, mixed>>, references: array<string, string>}
     */
    private function resolveReferences(array $itemsByKey, array $probes): array
    {
        $groupCodes = [];
        foreach ($itemsByKey as $item) {
            if (\is_string($item['groupCode'] ?? null) && '' !== trim($item['groupCode'])) {
                $groupCodes[] = trim($item['groupCode']);
            }
        }
        $groups = [] === $groupCodes ? [] : ($this->references->lookupAll([Resource::CustomerGroup->value => $groupCodes])[Resource::CustomerGroup->value] ?? []);

        $failures = [];
        foreach ($itemsByKey as $key => $item) {
            $groupCode = \is_string($item['groupCode'] ?? null) ? trim($item['groupCode']) : '';
            if ('' !== $groupCode && !isset($groups[$groupCode])) {
                $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'group_not_found', 'groupCode' => $groupCode];

                continue;
            }
            $probe = $probes[$key] ?? null;
            $wantsAccountChange = \array_key_exists('password', $item) || \array_key_exists('accountEnabled', $item);
            if (null !== $probe && true === $probe['ok'] && $wantsAccountChange && null === ($probe['body']['user'] ?? null)) {
                $failures[$key] = [
                    'ok' => false,
                    'action' => 'failed',
                    'reason' => 'no_account',
                    'hint' => 'This customer has no shop account: nothing to reset or enable. A password on a creation is what makes one.',
                ];
            }
        }

        return ['failures' => $failures, 'references' => $groups];
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        if (null === $current) {
            $diff = ['email' => ['before' => null, 'after' => $this->keyOf($item)]];
            foreach (self::PROFILE as $field) {
                if (\array_key_exists($field, $item)) {
                    $diff[$field] = ['before' => null, 'after' => $item[$field]];
                }
            }
            $groupCode = \is_string($item['groupCode'] ?? null) ? trim($item['groupCode']) : '';
            if ('' !== $groupCode) {
                $diff['groupCode'] = ['before' => null, 'after' => $groupCode];
            }
            if (\array_key_exists('password', $item)) {
                $diff['account'] = ['before' => null, 'after' => false === ($item['accountEnabled'] ?? null) ? 'disabled' : 'enabled'];
            }

            return $diff;
        }

        $diff = [];
        foreach (self::PROFILE as $field) {
            if (\array_key_exists($field, $item) && ($current[$field] ?? null) !== $item[$field]) {
                $diff[$field] = ['before' => $current[$field] ?? null, 'after' => $item[$field]];
            }
        }
        if (\array_key_exists('groupCode', $item)) {
            $wanted = \is_string($item['groupCode']) && '' !== trim($item['groupCode']) ? trim($item['groupCode']) : null;
            $before = AdminPayload::code($current['group'] ?? null);
            if ($before !== $wanted) {
                $diff['groupCode'] = ['before' => $before, 'after' => $wanted];
            }
        }
        $user = \is_array($current['user'] ?? null) ? $current['user'] : [];
        if (\array_key_exists('password', $item)) {
            // Described, never quoted.
            $diff['password'] = ['before' => '(unchanged)', 'after' => '(reset)'];
        }
        if (\is_bool($item['accountEnabled'] ?? null) && ($user['enabled'] ?? null) !== $item['accountEnabled']) {
            $diff['accountEnabled'] = ['before' => $user['enabled'] ?? null, 'after' => $item['accountEnabled']];
        }

        return $diff;
    }

    private function postBody(array $item, mixed $references): array
    {
        $body = ['email' => $this->keyOf($item)];
        foreach (self::PROFILE as $field) {
            if (\array_key_exists($field, $item) && (!\is_string($item[$field]) || '' !== trim($item[$field]))) {
                $body[$field] = \is_string($item[$field]) ? trim($item[$field]) : $item[$field];
            }
        }
        $groupCode = \is_string($item['groupCode'] ?? null) ? trim($item['groupCode']) : '';
        if ('' !== $groupCode) {
            $body['group'] = self::groupIri($references, $groupCode);
        }
        if (\is_string($item['password'] ?? null)) {
            $body['user'] = ['plainPassword' => $item['password'], 'enabled' => \is_bool($item['accountEnabled'] ?? null) ? $item['accountEnabled'] : true];
        }

        return $body;
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        $body = $current;
        foreach (self::PROFILE as $field) {
            if (\array_key_exists($field, $item)) {
                $body[$field] = $item[$field];
            }
        }
        if (\array_key_exists('groupCode', $item)) {
            $groupCode = \is_string($item['groupCode']) ? trim($item['groupCode']) : '';
            $body['group'] = '' === $groupCode ? null : self::groupIri($references, $groupCode);
        }
        if (\array_key_exists('password', $item) || \array_key_exists('accountEnabled', $item)) {
            $user = \is_array($body['user'] ?? null) ? $body['user'] : [];
            if (\is_string($item['password'] ?? null)) {
                $user['plainPassword'] = $item['password'];
            }
            if (\is_bool($item['accountEnabled'] ?? null)) {
                $user['enabled'] = $item['accountEnabled'];
            }
            $body['user'] = $user;
        }

        return $body;
    }

    private static function groupIri(mixed $references, string $groupCode): ?string
    {
        $iri = \is_array($references) ? ($references[$groupCode] ?? null) : null;

        return \is_string($iri) ? $iri : null;
    }
}
