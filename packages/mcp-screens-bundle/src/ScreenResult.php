<?php

declare(strict_types=1);

namespace Emagma\McpScreensBundle;

/**
 * What a screen answers to a submit: saved, invalid values by field, or not saved. Built by its factories.
 */
final readonly class ScreenResult
{
    /**
     * @param array<string, string> $errors by field name; the form comes back with them
     */
    private function __construct(
        public bool $saved,
        public string $message,
        public array $errors = [],
    ) {
    }

    public static function saved(string $message): self
    {
        return new self(true, $message);
    }

    /**
     * @param array<string, string> $errors
     */
    public static function invalid(array $errors, string $message = 'Some values are not valid.'): self
    {
        return new self(false, $message, $errors);
    }

    /**
     * Nothing was written: the human declined, the backend refused, nothing changed.
     */
    public static function notSaved(string $message): self
    {
        return new self(false, $message);
    }
}
