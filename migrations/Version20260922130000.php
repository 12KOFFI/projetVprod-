<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nom de jeune fille, facultatif : repris par la fiche d'inscription VAE
 * (section « Identification du candidat ») lorsqu'il est renseigné.
 */
final class Version20260922130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout du nom de jeune fille (facultatif) sur user.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD nom_jeune_fille VARCHAR(150) DEFAULT NULL AFTER prenoms');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP nom_jeune_fille');
    }
}
