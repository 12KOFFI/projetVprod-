<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rétablit l'intégrité référentielle au niveau de la base.
 *
 * Deux défauts hérités du schéma d'origine sont corrigés ensemble, le second
 * dépendant du premier.
 *
 * 1. candidature était la seule table en MyISAM, moteur qui ignore aussi bien
 *    les clés étrangères que les transactions. Cette dernière limite était la
 *    plus grave : ImportJuryService ouvre une transaction autour de l'import
 *    des décisions du jury et appelle rollBack() quand le taux d'erreur
 *    dépasse le seuil, en supposant qu'aucune décision n'a été appliquée. En
 *    MyISAM, les écritures sur candidature étaient déjà validées et
 *    survivaient au rollback : des dossiers changeaient d'éligibilité ou
 *    d'admission sans historique (celui-ci, en InnoDB, était bien annulé) et
 *    sans que le rapport d'import ne les signale.
 *
 * 2. Dix-sept relations déclarées dans les entités n'avaient pas de contrainte
 *    correspondante en base : seuls des index existaient sur les colonnes de
 *    jointure. L'intégrité ne tenait que par Doctrine, côté applicatif.
 *
 * Les règles ON DELETE reprennent exactement les attributs JoinColumn des
 * entités : CASCADE pour historique_statut, paiement et transaction_paiement,
 * RESTRICT (défaut MySQL) partout ailleurs. Aucune donnée orpheline n'existait
 * au moment de l'écriture, la conversion et les ajouts sont donc sans perte.
 *
 * Sur une base volumineuse, ALTER TABLE ... ENGINE reconstruit la table et la
 * verrouille le temps de l'opération : à jouer hors heures de service.
 */
final class Version20260922210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Passage de candidature en InnoDB et ajout des 17 clés étrangères manquantes.';
    }

    public function up(Schema $schema): void
    {
        // Sans InnoDB, toutes les contraintes qui suivent seraient acceptées
        // puis silencieusement ignorées par MySQL.
        $this->addSql('ALTER TABLE candidature ENGINE = InnoDB');

        // Doctrine attend un index dédié sur la colonne de jointure ; l'index
        // composite existant (paiement_id, creation) ne porte pas son nom.
        $this->addSql('CREATE INDEX IDX_FFAE53762A4C4478 ON transaction_paiement (paiement_id)');

        $this->addSql('ALTER TABLE candidature ADD CONSTRAINT FK_E33BD3B8463CD7C3 FOREIGN KEY (centre_id) REFERENCES centre (id)');
        $this->addSql('ALTER TABLE candidature ADD CONSTRAINT FK_E33BD3B8ED16FA20 FOREIGN KEY (metier_id) REFERENCES metier (id)');
        $this->addSql('ALTER TABLE candidature ADD CONSTRAINT FK_E33BD3B8A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE candidature ADD CONSTRAINT FK_E33BD3B81BDB4BD1 FOREIGN KEY (userupdate_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE candidature ADD CONSTRAINT FK_E33BD3B8DD8ECCE FOREIGN KEY (agent_accueil_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE candidature ADD CONSTRAINT FK_E33BD3B81AC39A0D FOREIGN KEY (conseiller_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE candidature ADD CONSTRAINT FK_E33BD3B8CA21A6AD FOREIGN KEY (accompagnateur_id) REFERENCES `user` (id)');

        $this->addSql('ALTER TABLE historique_statut ADD CONSTRAINT FK_2C2650E3B6121583 FOREIGN KEY (candidature_id) REFERENCES candidature (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE historique_statut ADD CONSTRAINT FK_2C2650E360BB6FE6 FOREIGN KEY (auteur_id) REFERENCES `user` (id)');

        $this->addSql('ALTER TABLE import_jury ADD CONSTRAINT FK_FE195EAC60BB6FE6 FOREIGN KEY (auteur_id) REFERENCES `user` (id)');

        $this->addSql('ALTER TABLE localite ADD CONSTRAINT FK_F5D7E4A94C4A5FBB FOREIGN KEY (direction_regionale_id) REFERENCES direction_regionale (id)');

        $this->addSql('ALTER TABLE metier ADD CONSTRAINT FK_51A00D8C180AA129 FOREIGN KEY (filiere_id) REFERENCES filiere (id)');

        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1EB6121583 FOREIGN KEY (candidature_id) REFERENCES candidature (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE paiement ADD CONSTRAINT FK_B1DC7A1EA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');

        $this->addSql('ALTER TABLE transaction_paiement ADD CONSTRAINT FK_FFAE53762A4C4478 FOREIGN KEY (paiement_id) REFERENCES paiement (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT FK_8D93D649463CD7C3 FOREIGN KEY (centre_id) REFERENCES centre (id)');
        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT FK_8D93D649ED16FA20 FOREIGN KEY (metier_id) REFERENCES metier (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D649ED16FA20');
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D649463CD7C3');

        $this->addSql('ALTER TABLE transaction_paiement DROP FOREIGN KEY FK_FFAE53762A4C4478');

        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1EA76ED395');
        $this->addSql('ALTER TABLE paiement DROP FOREIGN KEY FK_B1DC7A1EB6121583');

        $this->addSql('ALTER TABLE metier DROP FOREIGN KEY FK_51A00D8C180AA129');

        $this->addSql('ALTER TABLE localite DROP FOREIGN KEY FK_F5D7E4A94C4A5FBB');

        $this->addSql('ALTER TABLE import_jury DROP FOREIGN KEY FK_FE195EAC60BB6FE6');

        $this->addSql('ALTER TABLE historique_statut DROP FOREIGN KEY FK_2C2650E360BB6FE6');
        $this->addSql('ALTER TABLE historique_statut DROP FOREIGN KEY FK_2C2650E3B6121583');

        $this->addSql('ALTER TABLE candidature DROP FOREIGN KEY FK_E33BD3B8CA21A6AD');
        $this->addSql('ALTER TABLE candidature DROP FOREIGN KEY FK_E33BD3B81AC39A0D');
        $this->addSql('ALTER TABLE candidature DROP FOREIGN KEY FK_E33BD3B8DD8ECCE');
        $this->addSql('ALTER TABLE candidature DROP FOREIGN KEY FK_E33BD3B81BDB4BD1');
        $this->addSql('ALTER TABLE candidature DROP FOREIGN KEY FK_E33BD3B8A76ED395');
        $this->addSql('ALTER TABLE candidature DROP FOREIGN KEY FK_E33BD3B8ED16FA20');
        $this->addSql('ALTER TABLE candidature DROP FOREIGN KEY FK_E33BD3B8463CD7C3');

        $this->addSql('DROP INDEX IDX_FFAE53762A4C4478 ON transaction_paiement');

        $this->addSql('ALTER TABLE candidature ENGINE = MyISAM');
    }
}
