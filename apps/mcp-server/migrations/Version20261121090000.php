<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The steps of a task are played by an orchestrator: mcp_task.step_in_flight tells a step being played from a task between two steps.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mcp_task ADD step_in_flight TINYINT NOT NULL');
        // Before, a running task whose step was claimed and not yet over had current_step = claimed_step; between
        // two steps, current_step was already the next one.
        $this->addSql('UPDATE mcp_task SET step_in_flight = 1 WHERE status = \'running\' AND current_step = claimed_step');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mcp_task DROP step_in_flight');
    }
}
