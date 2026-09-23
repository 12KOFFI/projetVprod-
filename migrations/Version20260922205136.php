<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Supprime candidature_statut_archive, l'archive de sécurité créée par
 * Version20260922170000 lors du retrait de candidature.statut_courant.
 *
 * Elle n'a jamais été mappée en entité et ne sert que de filet ponctuel au
 * moment de cette migration ; ses lignes sont aujourd'hui orphelines (les
 * candidatures archivées ont depuis été purgées du jeu de démonstration).
 */
final class Version20260922205136 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Suppression de l'archive candidature_statut_archive, devenue orpheline.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE candidature_statut_archive');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE candidature_statut_archive (
            candidature_id INT NOT NULL,
            statut_courant SMALLINT NOT NULL,
            resultat INT DEFAULT NULL,
            archive_le DATETIME NOT NULL,
            PRIMARY KEY(candidature_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }
}
