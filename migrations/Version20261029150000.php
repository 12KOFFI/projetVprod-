<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Table technique messenger_messages alignée sur Symfony Messenger 6.4.
 *
 * Le passage de Symfony 6.2 à 6.4 change la définition attendue de cette file
 * d'attente : trois index simples remplacés par un index composé, et les dates
 * marquées datetime_immutable. Les colonnes ne changent pas ; la table est vide
 * (l'application n'envoie aucun message asynchrone).
 *
 * Migration défensive : chaque étape vérifie l'état réel de la base, de sorte
 * qu'une base de production légèrement différente ne fasse pas échouer la
 * migration à mi-chemin (MySQL ne sait pas annuler une instruction DDL).
 */
final class Version20261029150000 extends AbstractMigration
{
    private const TABLE = 'messenger_messages';
    private const INDEX_COMPOSE = 'IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750';
    private const INDEX_SIMPLES = ['IDX_75EA56E016BA31DB', 'IDX_75EA56E0E3BD61CE', 'IDX_75EA56E0FB7336F0'];

    public function getDescription(): string
    {
        return 'messenger_messages : index et types alignés sur Symfony Messenger 6.4';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable(self::TABLE)) {
            $this->write('Table messenger_messages absente : rien à faire (Messenger la créera au besoin).');

            return;
        }

        $table = $schema->getTable(self::TABLE);

        foreach (self::INDEX_SIMPLES as $index) {
            if ($table->hasIndex($index)) {
                $this->addSql(sprintf('DROP INDEX %s ON %s', $index, self::TABLE));
            }
        }

        $this->addSql("ALTER TABLE messenger_messages CHANGE created_at created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE available_at available_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', CHANGE delivered_at delivered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");

        if (!$table->hasIndex(self::INDEX_COMPOSE)) {
            $this->addSql(sprintf('CREATE INDEX %s ON %s (queue_name, available_at, delivered_at, id)', self::INDEX_COMPOSE, self::TABLE));
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->getTable(self::TABLE);

        if ($table->hasIndex(self::INDEX_COMPOSE)) {
            $this->addSql(sprintf('DROP INDEX %s ON %s', self::INDEX_COMPOSE, self::TABLE));
        }

        $this->addSql("ALTER TABLE messenger_messages CHANGE created_at created_at DATETIME NOT NULL, CHANGE available_at available_at DATETIME NOT NULL, CHANGE delivered_at delivered_at DATETIME DEFAULT NULL");
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)');
    }
}
