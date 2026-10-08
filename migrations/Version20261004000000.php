<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire le « nom de jeune fille » du compte utilisateur : la rubrique est
 * supprimée du formulaire d'inscription, de l'inscription assistée, du profil,
 * de la fiche du personnel et de la fiche d'inscription imprimée.
 *
 * La colonne ajoutée par Version20260922130000 était vide pour tous les
 * comptes au moment du retrait ; down() la recrée vide.
 */
final class Version20261004000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppression de la colonne user.nom_jeune_fille.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP nom_jeune_fille');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD nom_jeune_fille VARCHAR(150) DEFAULT NULL AFTER prenoms');
    }
}
