<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Output;

use Emagma\McpCoreBundle\Privacy\PrivacyContext;

/**
 * Names the untrusted fields (never sanitises them) and applies the privacy mode: part of serialisation, so no tool can forget it.
 * Under `pseudonymise` a surplus field is absent, not null, and a token is not listed as untrusted: it is the server's.
 */
trait OutputFieldsTrait
{
    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        /** @var array<string, mixed> $base */
        $base = get_object_vars($this);
        $hidden = [];

        if (PrivacyContext::hides()) {
            foreach (FieldRegistry::with(self::class, Field::Surplus) as $name) {
                unset($base[$name]);
            }
            foreach (FieldRegistry::with(self::class, Field::Identifier) as $name) {
                $value = $base[$name] ?? null;
                if (\is_string($value) && '' !== $value) {
                    $base[$name] = PrivacyContext::token($name, $value);
                    $hidden[$name] = true;
                }
            }
        }

        $fields = [];
        foreach (FieldRegistry::with(self::class, Field::Untrusted) as $name) {
            if (null !== $this->{$name} && !isset($hidden[$name])) {
                $fields[] = $name;
            }
        }

        return [] === $fields ? $base : $base + [Field::UNTRUSTED_CONTENT_KEY => ['fields' => $fields]];
    }
}
