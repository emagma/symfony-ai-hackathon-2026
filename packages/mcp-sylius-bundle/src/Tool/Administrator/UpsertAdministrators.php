<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Administrator;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminPayload;
use Emagma\McpSyliusBundle\Output\Administrator\AdministratorOutput;
use Emagma\McpSyliusBundle\Tool\Shared\Batch\BatchUpsertTrait;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * The collection declares no filter: the probe is one bounded GET matched on the email.
 * The password joins the body only, never the diff, the preview or the audit.
 *
 * @internal
 */
#[McpTool(
    name: 'upsert_administrators',
    description: 'Create or update Sylius BACK-OFFICE accounts in one call, at most 50 items, matched on their'
        . ' email. A creation needs username, password and localeCode and GRANTS admin access; on an'
        . ' existing account password resets the credential and enabled: false revokes access reversibly.'
        . ' The password is never echoed, previewed or audited. mode: upsert (default), create'
        . ' (already_exists on a known email) or update (not_found on an unknown one). An item that'
        . ' changes nothing is unchanged and not written. One confirmation for the batch; one result per'
        . ' item. Tool-specific reasons: fields_required, service_account_protected.',
)]
#[Permission(resource: 'administrator', action: Action::Write)]
final readonly class UpsertAdministrators
{
    use BatchUpsertTrait;
    use ServiceAccountTrait;
    use WriteConfirmationTrait;

    private const int FETCH_HARD_CAP = 500;

    /** What the audit records when the shop answered without an id: still not the email. */
    private const string UNKNOWN_ID = '(unknown id)';

    private const array CREATE_FIELDS = ['username', 'password', 'localeCode'];

    private const array SCALARS = ['username', 'localeCode', 'firstName', 'lastName', 'enabled'];

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
        private string $serviceAccountEmail,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(type: 'array', items: AdministratorItem::SCHEMA, minItems: 1)]
        array $items,
        #[Schema(enum: ['create', 'update', 'upsert'])]
        string $mode = 'upsert',
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        return $this->runUpsert('upsert_administrators', Route::collection(Resource::Administrator), $items, $mode, $confirm, $context);
    }

    private function targetType(): string
    {
        return 'administrator';
    }

    /**
     * Login and email are identifiers: a diff's `before` on them is served like a read.
     *
     * @return class-string<AdministratorOutput>
     */
    private static function outputClass(): string
    {
        return AdministratorOutput::class;
    }

    private function label(): string
    {
        return 'back-office accounts';
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
        foreach (['username', 'password', 'localeCode'] as $field) {
            if (\array_key_exists($field, $item) && (!\is_string($item[$field]) || '' === trim($item[$field]))) {
                return $field . ' is a non-empty string';
            }
        }
        foreach (['firstName', 'lastName'] as $field) {
            if (\array_key_exists($field, $item) && !\is_string($item[$field])) {
                return $field . ' is a string';
            }
        }
        if (\array_key_exists('enabled', $item) && !\is_bool($item['enabled'])) {
            return 'enabled is a boolean';
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
        try {
            $payload = $this->client->getDecoded($collection, ['page' => 1, 'itemsPerPage' => self::FETCH_HARD_CAP]);
        } catch (ClientException $exception) {
            $status = $exception->getResponse()->getStatusCode();

            return array_fill_keys($keys, ['ok' => false, 'status' => $status, 'error' => 'HTTP ' . $status]);
        }

        $byEmail = [];
        foreach (AdminPayload::members($payload) as $member) {
            $email = AdminPayload::string($member['email'] ?? null);
            if (null !== $email) {
                $byEmail[mb_strtolower($email)] = $member;
            }
        }

        $probes = [];
        foreach ($keys as $key) {
            $probes[$key] = isset($byEmail[$key])
                ? ['ok' => true, 'body' => $byEmail[$key]]
                : ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'];
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
     * @return array{failures: array<string, array<string, mixed>>, references: null}
     */
    private function resolveReferences(array $itemsByKey, array $probes): array
    {
        $failures = [];
        foreach ($itemsByKey as $key => $item) {
            $probe = $probes[$key] ?? null;
            if (null !== $probe && true === $probe['ok']) {
                if ($this->isServiceAccount($probe['body']) && (\array_key_exists('password', $item) || false === ($item['enabled'] ?? null))) {
                    $failures[$key] = [
                        'ok' => false,
                        'action' => 'failed',
                        'reason' => 'service_account_protected',
                        'hint' => 'This is the account the assistant authenticates with: disabling it or resetting its password would lock the assistant out of the shop.',
                    ];
                }

                continue;
            }
            if (null !== $probe && 404 !== ($probe['status'] ?? null)) {
                continue;
            }
            $missing = array_values(array_filter(self::CREATE_FIELDS, static fn (string $field): bool => !\array_key_exists($field, $item)));
            if ([] !== $missing) {
                $failures[$key] = ['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => $missing];
            }
        }

        return ['failures' => $failures, 'references' => null];
    }

    private function diffFor(?array $current, array $item, mixed $references): array
    {
        if (null === $current) {
            $diff = ['email' => ['before' => null, 'after' => $this->keyOf($item)]];
            foreach (['username', 'localeCode', 'firstName', 'lastName'] as $field) {
                if (\is_string($item[$field] ?? null)) {
                    $diff[$field] = ['before' => null, 'after' => trim($item[$field])];
                }
            }
            $diff['enabled'] = ['before' => null, 'after' => \is_bool($item['enabled'] ?? null) ? $item['enabled'] : true];

            return $diff;
        }

        $diff = [];
        foreach (self::SCALARS as $field) {
            if (!\array_key_exists($field, $item)) {
                continue;
            }
            $after = \is_string($item[$field]) ? trim($item[$field]) : $item[$field];
            $before = $current[$field] ?? null;
            if ($before !== $after) {
                $diff[$field] = ['before' => $before, 'after' => $after];
            }
        }
        if (\array_key_exists('password', $item)) {
            // Described, never quoted.
            $diff['password'] = ['before' => '(unchanged)', 'after' => '(reset)'];
        }

        return $diff;
    }

    private function postBody(array $item, mixed $references): array
    {
        $body = [
            'email' => $this->keyOf($item),
            'username' => \is_string($item['username'] ?? null) ? trim($item['username']) : '',
            'localeCode' => \is_string($item['localeCode'] ?? null) ? trim($item['localeCode']) : '',
            'enabled' => \is_bool($item['enabled'] ?? null) ? $item['enabled'] : true,
        ];
        foreach (['firstName', 'lastName'] as $field) {
            if (\is_string($item[$field] ?? null) && '' !== trim($item[$field])) {
                $body[$field] = trim($item[$field]);
            }
        }
        $body['plainPassword'] = $item['password'] ?? null;

        return $body;
    }

    private function putBody(array $current, array $item, mixed $references): array
    {
        $body = $current;
        foreach (self::SCALARS as $field) {
            if (\array_key_exists($field, $item)) {
                $body[$field] = \is_string($item[$field]) ? trim($item[$field]) : $item[$field];
            }
        }
        if (\is_string($item['password'] ?? null)) {
            $body['plainPassword'] = $item['password'];
        }

        return $body;
    }
}
