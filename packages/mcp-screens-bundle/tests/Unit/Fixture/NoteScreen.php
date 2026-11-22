<?php

declare(strict_types=1);

namespace Emagma\McpScreensBundle\Tests\Unit\Fixture;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpScreensBundle\Exception\ScreenException;
use Emagma\McpScreensBundle\ScreenInterface;
use Emagma\McpScreensBundle\ScreenResult;
use Mcp\Server\RequestContext;

/**
 * A screen of one field, kept in memory.
 */
final class NoteScreen implements ScreenInterface
{
    public string $note = 'first';

    public function name(): string
    {
        return 'note';
    }

    public function description(): string
    {
        return 'One note. params: {id}.';
    }

    public function readPermissions(): array
    {
        return [new Permission('note', Action::Read, 'demo')];
    }

    public function writePermissions(): array
    {
        return [new Permission('note', Action::Write, 'demo')];
    }

    public function template(): string
    {
        return 'note.html.twig';
    }

    public function load(array $params): array
    {
        if (!isset($params['id'])) {
            throw new ScreenException('This screen needs params.id.');
        }

        return ['note' => $this->note];
    }

    public function submit(array $params, array $values, ?RequestContext $context): ScreenResult
    {
        $note = \is_string($values['note'] ?? null) ? trim($values['note']) : '';
        if ('' === $note) {
            return ScreenResult::invalid(['note' => 'Required.']);
        }
        $this->note = $note;

        return ScreenResult::saved('Note saved.');
    }
}
