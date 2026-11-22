<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Shared;

/**
 * ChatGPT passes the sandbox path of a file (`/mnt/data/...`) instead of its bytes:
 * the hint says why no path can work and where the attached file goes instead.
 *
 * @internal
 */
final class Base64Content
{
    public const string INVALID_HINT = 'base64Content must be the file\'s bytes, base64-encoded. The server'
        . ' cannot read a file path (such as /mnt/data/...) or a URL from it: do not retry with one. A file'
        . ' attached to the conversation goes in the `file` argument, which the client fills in itself.'
        . ' If neither is possible, tell the user to upload the file in the Sylius admin.';
}
