<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aligne la collation de candidature sur celle du reste de la base.
 *
 * La table était la seule en utf8mb4_0900_ai_ci, héritage de sa création hors
 * migration. Ses colonnes étaient pour la plupart en utf8mb4_unicode_ci, sauf
 * lieu_exercice et ref_contrat, ajoutées plus tard et donc alignées sur la
 * valeur par défaut de la table.
 *
 * Doctrine considère qu'une colonne dont la collation diffère de celle de sa
 * table porte une collation explicite : tant que l'écart subsistait,
 * doctrine:schema:validate signalait un décalage et chaque migrations:diff
 * proposait à nouveau de « corriger » ces colonnes. L'alignement supprime ce
 * bruit récurrent, source de migrations accidentelles.
 *
 * Les deux collations sont insensibles à la casse et aux accents : la
 * conversion ne change pas les comparaisons existantes. L'index UNIQUE sur
 * numero a été vérifié sans collision avant la conversion.
 */
final class Version20260922211500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alignement de candidature sur la collation utf8mb4_unicode_ci du reste de la base.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE candidature CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE candidature CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    }
}
