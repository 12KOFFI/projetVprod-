<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suppression du statut DOSSIER_VALIDE (20).
 *
 * L'étude du dossier ne porte plus de statut propre : un dossier accepté reste
 * PREINSCRIT (10) avec etu_statut = ACCEPTE (2) jusqu'au paiement des frais de
 * dossier, qui le fait passer à INSCRIT (30).
 *
 * Sans cette migration, toute ligne encore au statut 20 ferait échouer
 * StatutCandidature::from() à l'hydratation.
 */
final class Version20260922100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppression du statut DOSSIER_VALIDE : les dossiers acceptés restent PREINSCRIT (etu_statut = ACCEPTE).';
    }

    public function up(Schema $schema): void
    {
        // Un dossier au statut 20 avait par construction une étude acceptée ;
        // on le garantit avant de le replacer en PREINSCRIT.
        $this->addSql('UPDATE candidature SET etu_statut = 2 WHERE statut_courant = 20');
        $this->addSql('UPDATE candidature SET statut_courant = 10 WHERE statut_courant = 20');

        // Historique : « PREINSCRIT → DOSSIER_VALIDE » devient une décision
        // d'étude journalisée sans changement de statut (PREINSCRIT → PREINSCRIT),
        // et « DOSSIER_VALIDE → INSCRIT » devient « PREINSCRIT → INSCRIT ».
        $this->addSql('UPDATE historique_statut SET statut_apres = 10 WHERE statut_apres = 20');
        $this->addSql('UPDATE historique_statut SET statut_avant = 10 WHERE statut_avant = 20');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE candidature SET statut_courant = 20 WHERE statut_courant = 10 AND etu_statut = 2');

        $this->addSql("UPDATE historique_statut SET statut_apres = 20 WHERE statut_avant = 10 AND statut_apres = 10 AND motif LIKE 'Étude du dossier : %' AND motif NOT LIKE '%Refus%'");
        $this->addSql('UPDATE historique_statut SET statut_avant = 20 WHERE statut_avant = 10 AND statut_apres = 30');
    }
}
