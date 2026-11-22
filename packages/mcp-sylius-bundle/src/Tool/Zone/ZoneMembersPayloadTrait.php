<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Zone;

use Emagma\McpSyliusBundle\AdminApi\AdminPayload;

/**
 * ZoneMember has no admin API resource: the zone PUT replaces the whole `members` collection, so the
 * existing entries are round-tripped verbatim.
 *
 * @internal
 */
trait ZoneMembersPayloadTrait
{
    /**
     * @param array<string, mixed> $zone
     *
     * @return list<mixed>
     */
    private static function memberEntries(array $zone): array
    {
        return \is_array($zone['members'] ?? null) ? array_values($zone['members']) : [];
    }

    /**
     * A member arrives either inlined as `{code: "FR"}` or as a bare IRI,
     * depending on the serialization group in play.
     */
    private static function memberCode(mixed $entry): ?string
    {
        return AdminPayload::codeOf($entry);
    }

    /**
     * @param list<mixed> $entries
     *
     * @return list<string>
     */
    private static function memberCodes(array $entries): array
    {
        $codes = [];
        foreach ($entries as $entry) {
            $code = self::memberCode($entry);
            if (null !== $code) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
