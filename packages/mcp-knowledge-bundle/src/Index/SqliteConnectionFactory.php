<?php

declare(strict_types=1);

namespace Emagma\McpKnowledgeBundle\Index;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Opens the SQLite file of the index, creating its directory.
 *
 * @internal
 */
final class SqliteConnectionFactory
{
    private function __construct()
    {
    }

    /** A file the server and its worker both write to: its directory is created on first use. */
    public static function create(string $file): \PDO
    {
        (new Filesystem())->mkdir(\dirname($file));
        $pdo = new \PDO('sqlite:' . $file);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
