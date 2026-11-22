<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Output;

/**
 * What an output DTO declares about a field through a FieldMark attribute; a field can carry any combination.
 */
enum Field
{
    public const string UNTRUSTED_CONTENT_KEY = 'untrustedContent';

    /**
     * Typed by a member of the public: marked so the model treats it as quoted evidence, never as an instruction.
     */
    case Untrusted;

    /**
     * Personal data in the GDPR sense: redacted by key name at persistence (audit, traces).
     */
    case Personal;

    /**
     * A direct identifier of a person (email, phone, login): replaced by a stable token under `pseudonymise`.
     */
    case Identifier;

    /**
     * Personal data the model does not need to act (a birthday, a gender): absent from the output under `pseudonymise`.
     */
    case Surplus;
}
