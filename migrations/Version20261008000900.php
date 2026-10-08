<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Espace accompagnateur (étape 5 du parcours).
 *
 * - preuve_livret : pièces du livret de preuves, déposées par le candidat ou
 *   son accompagnateur (fichiers dans stockage/pieces/{numéro}/livret/).
 * - candidature.accompagnateur_souhaite_id : accompagnateur choisi par le
 *   candidat, affecté (accompagnateur_id) après paiement des frais
 *   d'accompagnement.
 */
final class Version20261008000900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Livret de preuves (preuve_livret) et accompagnateur choisi par le candidat.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE preuve_livret (id INT AUTO_INCREMENT NOT NULL, candidature_id INT NOT NULL, depose_par_id INT DEFAULT NULL, titre VARCHAR(150) NOT NULL, description LONGTEXT DEFAULT NULL, fichier VARCHAR(255) NOT NULL, nom_original VARCHAR(255) NOT NULL, taille INT NOT NULL, creation DATETIME NOT NULL, INDEX IDX_3FEA6FB1DCFF0FC4 (depose_par_id), INDEX idx_preuve_livret_candidature (candidature_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE preuve_livret ADD CONSTRAINT FK_3FEA6FB1B6121583 FOREIGN KEY (candidature_id) REFERENCES candidature (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE preuve_livret ADD CONSTRAINT FK_3FEA6FB1DCFF0FC4 FOREIGN KEY (depose_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE candidature ADD accompagnateur_souhaite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE candidature ADD CONSTRAINT FK_E33BD3B8D06A8E17 FOREIGN KEY (accompagnateur_souhaite_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_E33BD3B8D06A8E17 ON candidature (accompagnateur_souhaite_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE candidature DROP FOREIGN KEY FK_E33BD3B8D06A8E17');
        $this->addSql('DROP INDEX IDX_E33BD3B8D06A8E17 ON candidature');
        $this->addSql('ALTER TABLE candidature DROP accompagnateur_souhaite_id');
        $this->addSql('ALTER TABLE preuve_livret DROP FOREIGN KEY FK_3FEA6FB1B6121583');
        $this->addSql('ALTER TABLE preuve_livret DROP FOREIGN KEY FK_3FEA6FB1DCFF0FC4');
        $this->addSql('DROP TABLE preuve_livret');
    }
}
