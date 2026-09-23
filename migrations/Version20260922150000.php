<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suppression de user.ville, doublon de user.residence : celle-ci contenait
 * déjà une ville et devient « Ville de résidence ». Une ville saisie entre
 * les deux migrations est reportée dans residence quand celle-ci est vide.
 */
final class Version20260922150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fusion de user.ville dans user.residence (« Ville de résidence »).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE `user` SET residence = ville WHERE (residence IS NULL OR residence = '') AND ville IS NOT NULL");
        $this->addSql('ALTER TABLE `user` DROP ville');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD ville VARCHAR(100) DEFAULT NULL AFTER residence');
    }
}
