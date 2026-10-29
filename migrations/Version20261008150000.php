<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Clés étrangères du référentiel centres / métiers / certifications.
 *
 * Le schéma de base (Version20260901000000) a été relevé sur une base qui ne
 * les avait pas : toute base construite par les migrations en était privée,
 * et MySQL laissait supprimer un métier ou une localité encore référencés.
 *
 * En production, vérifier d'abord l'absence de lignes orphelines (requête dans
 * modifs.txt, point 26) : MySQL refuse une clé étrangère violée par l'existant.
 */
final class Version20261008150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clés étrangères manquantes : centre, centre_metier, centre_metier_certification, certification';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE centre ADD CONSTRAINT FK_C6A0EA75924DD2B5 FOREIGN KEY (localite_id) REFERENCES localite (id)');
        $this->addSql('ALTER TABLE centre_metier ADD CONSTRAINT FK_821D032B463CD7C3 FOREIGN KEY (centre_id) REFERENCES centre (id)');
        $this->addSql('ALTER TABLE centre_metier ADD CONSTRAINT FK_821D032BED16FA20 FOREIGN KEY (metier_id) REFERENCES metier (id)');
        $this->addSql('ALTER TABLE centre_metier_certification ADD CONSTRAINT FK_41C062EC1A7253A9 FOREIGN KEY (centre_metier_id) REFERENCES centre_metier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE centre_metier_certification ADD CONSTRAINT FK_41C062ECCB47068A FOREIGN KEY (certification_id) REFERENCES certification (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE certification ADD CONSTRAINT FK_6C3C6D75ED16FA20 FOREIGN KEY (metier_id) REFERENCES metier (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE centre DROP FOREIGN KEY FK_C6A0EA75924DD2B5');
        $this->addSql('ALTER TABLE centre_metier DROP FOREIGN KEY FK_821D032B463CD7C3');
        $this->addSql('ALTER TABLE centre_metier DROP FOREIGN KEY FK_821D032BED16FA20');
        $this->addSql('ALTER TABLE centre_metier_certification DROP FOREIGN KEY FK_41C062EC1A7253A9');
        $this->addSql('ALTER TABLE centre_metier_certification DROP FOREIGN KEY FK_41C062ECCB47068A');
        $this->addSql('ALTER TABLE certification DROP FOREIGN KEY FK_6C3C6D75ED16FA20');
    }
}
