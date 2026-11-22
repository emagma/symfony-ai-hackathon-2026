<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121124645 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The background agents woken by the shop\'s events: the lock store (lock_keys) and the state of the agents\' schedule (cache_items). The new queues share messenger_messages.';
    }

    public function up(Schema $schema): void
    {
        // Both stores create their table on first use: a webhook or the scheduler may have been first.
        if (!$schema->hasTable('cache_items')) {
            $this->addSql('CREATE TABLE cache_items (item_id VARBINARY(255) NOT NULL, item_data MEDIUMBLOB NOT NULL, item_lifetime INT UNSIGNED DEFAULT NULL, item_time INT UNSIGNED NOT NULL, PRIMARY KEY (item_id))');
        }
        if (!$schema->hasTable('lock_keys')) {
            $this->addSql('CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT UNSIGNED NOT NULL, PRIMARY KEY (key_id))');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cache_items');
        $this->addSql('DROP TABLE lock_keys');
    }
}
