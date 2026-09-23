<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suppression du statut global stocké (candidature.statut_courant).
 *
 * Le statut global est désormais calculé par CandidatureStatusResolver à partir
 * des décisions métier, chacune dans sa colonne :
 *
 *   etu_statut  → étude du dossier
 *   rec_statut  → recevabilité
 *   elig_statut → éligibilité          (1 non éligible, 2 éligible)
 *   resultat    → admissibilité SEULE  (1 non admissible, 2 admissible)
 *   admis       → admission définitive (1 non admis, 2 admis) — nouvelle
 *
 * Étapes, dans l'ordre :
 *  1. archive intégrale (statut_courant, resultat) dans candidature_statut_archive ;
 *  2. resultat 3/4 (admission définitive) → admis, resultat ramené à 2 ;
 *  3. décisions absentes reconstituées depuis statut_courant, sans jamais
 *     écraser une décision présente ;
 *  4. signalement des dossiers INSCRIT et au-delà sans frais de dossier réglés,
 *     que le calcul classerait PREINSCRIT — aucun paiement n'est inventé ;
 *  5. contraintes CHECK sur elig_statut, resultat et admis ;
 *  6. suppression de statut_courant.
 */
final class Version20260922170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Statut global calculé : ajout de candidature.admis, migration de resultat 3/4, suppression de statut_courant.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE candidature ADD admis INT DEFAULT NULL AFTER resultat');

        // 1. Rien n'est perdu : l'état d'origine reste consultable.
        $this->addSql('CREATE TABLE candidature_statut_archive (
            candidature_id INT NOT NULL,
            statut_courant SMALLINT NOT NULL,
            resultat INT DEFAULT NULL,
            archive_le DATETIME NOT NULL,
            PRIMARY KEY(candidature_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('INSERT INTO candidature_statut_archive (candidature_id, statut_courant, resultat, archive_le)
            SELECT id, statut_courant, resultat, NOW() FROM candidature');

        // 2. resultat ne porte plus que l'admissibilité. Une admission (3 ou 4)
        // n'a pu être importée que pour un dossier ADMISSIBLE : l'admissibilité
        // est donc connue (2).
        $this->addSql('UPDATE candidature SET admis = 2, resultat = 2 WHERE resultat = 4');
        $this->addSql('UPDATE candidature SET admis = 1, resultat = 2 WHERE resultat = 3');

        // 3. Décisions impliquées par l'étape atteinte, seulement si absentes.
        $this->addSql('UPDATE candidature SET etu_statut = 2 WHERE statut_courant >= 30 AND (etu_statut IS NULL OR etu_statut = 0)');
        $this->addSql('UPDATE candidature SET rec_statut = 1 WHERE statut_courant = 40 AND (rec_statut IS NULL OR rec_statut = 0)');
        $this->addSql('UPDATE candidature SET rec_statut = 2 WHERE statut_courant >= 41 AND (rec_statut IS NULL OR rec_statut = 0)');
        $this->addSql('UPDATE candidature SET elig_statut = 1 WHERE statut_courant = 50 AND elig_statut IS NULL');
        $this->addSql('UPDATE candidature SET elig_statut = 2 WHERE statut_courant >= 51 AND elig_statut IS NULL');
        $this->addSql('UPDATE candidature SET resultat = 1 WHERE statut_courant = 70 AND resultat IS NULL');
        $this->addSql('UPDATE candidature SET resultat = 2 WHERE statut_courant >= 71 AND resultat IS NULL');
        $this->addSql('UPDATE candidature SET admis = 1 WHERE statut_courant = 80 AND admis IS NULL');
        $this->addSql('UPDATE candidature SET admis = 2 WHERE statut_courant = 81 AND admis IS NULL');

        // Toute autre valeur de décision serait illisible par le calcul : la
        // valeur est archivée ci-dessus puis neutralisée plutôt que devinée.
        $this->addSql('UPDATE candidature SET elig_statut = NULL WHERE elig_statut NOT IN (1, 2)');
        $this->addSql('UPDATE candidature SET resultat = NULL WHERE resultat NOT IN (1, 2)');

        // 5. La base refuse désormais toute valeur hors convention.
        $this->addSql('ALTER TABLE candidature
            ADD CONSTRAINT chk_candidature_elig_statut CHECK (elig_statut IS NULL OR elig_statut IN (1, 2)),
            ADD CONSTRAINT chk_candidature_resultat CHECK (resultat IS NULL OR resultat IN (1, 2)),
            ADD CONSTRAINT chk_candidature_admis CHECK (admis IS NULL OR admis IN (1, 2))');

        // 6. L'index idx_candidature_statut disparaît avec sa colonne.
        $this->addSql('ALTER TABLE candidature DROP INDEX idx_candidature_statut');
        $this->addSql('ALTER TABLE candidature DROP statut_courant');
    }

    /**
     * 4. Signalement, exécuté avant les requêtes de up() : un dossier au-delà
     * de PREINSCRIT sans frais de dossier réglés changerait de statut.
     */
    public function preUp(Schema $schema): void
    {
        $orphelins = $this->connection->fetchAllAssociative(
            // Au-delà d'INSCRIT, une décision ultérieure fixe le statut : seul
            // un dossier INSCRIT dépend encore du règlement.
            "SELECT c.numero FROM candidature c
             WHERE c.statut_courant = 30
               AND NOT EXISTS (
                   SELECT 1 FROM paiement p
                   WHERE p.candidature_id = c.id AND p.type_frais = 'dossier' AND p.statut_paiement = 'reussi'
               )"
        );

        foreach ($orphelins as $ligne) {
            $this->write(sprintf(
                '<comment>ATTENTION</comment> dossier %s : INSCRIT sans frais de dossier réglés, il sera calculé PREINSCRIT. État d\'origine conservé dans candidature_statut_archive.',
                $ligne['numero']
            ));
        }

        if ($orphelins === []) {
            $this->write('Aucun dossier INSCRIT sans frais de dossier réglés : aucun statut ne change.');
        }
    }

    /**
     * Contrôle final : tout dossier dont le statut désormais calculé diffère
     * de son statut archivé est signalé (colonnes de décision contradictoires
     * héritées d'anciennes versions). Rien n'est corrigé automatiquement.
     *
     * La règle est figée ici en SQL, à l'image de CandidatureStatusResolver au
     * moment de cette migration.
     */
    public function postUp(Schema $schema): void
    {
        $ecarts = $this->connection->fetchAllAssociative("
            SELECT c.numero, a.statut_courant AS archive, calc.statut AS calcule FROM candidature c
            JOIN candidature_statut_archive a ON a.candidature_id = c.id
            JOIN (SELECT c2.id, CASE
                    WHEN c2.admis = 2 THEN 81 WHEN c2.admis = 1 THEN 80
                    WHEN c2.resultat = 2 THEN 71 WHEN c2.resultat = 1 THEN 70
                    WHEN c2.elig_statut = 2 THEN 51 WHEN c2.elig_statut = 1 THEN 50
                    WHEN c2.rec_statut = 2 THEN 41 WHEN c2.rec_statut = 1 THEN 40
                    WHEN c2.etu_statut = 2 AND EXISTS (SELECT 1 FROM paiement p WHERE p.candidature_id = c2.id
                        AND p.type_frais = 'dossier' AND p.statut_paiement = 'reussi') THEN 30
                    ELSE 10 END AS statut
                FROM candidature c2) calc ON calc.id = c.id
            WHERE calc.statut <> a.statut_courant
        ");

        foreach ($ecarts as $ecart) {
            $this->write(sprintf(
                '<comment>ATTENTION</comment> dossier %s : statut archivé %d, statut calculé %d. À vérifier (voir candidature_statut_archive).',
                $ecart['numero'],
                $ecart['archive'],
                $ecart['calcule']
            ));
        }

        if ($ecarts === []) {
            $this->write('Contrôle final : chaque dossier conserve le statut qu\'il avait avant la migration.');
        }
    }

    /**
     * Retour à l'ancien modèle à partir des décisions ACTUELLES (et non de
     * l'archive, qui ignore les dossiers créés ou décidés depuis) : le statut
     * stocké est recalculé, puis l'admission repliée dans resultat (3 / 4).
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE candidature ADD statut_courant SMALLINT DEFAULT 10 NOT NULL');
        $this->addSql("UPDATE candidature c SET c.statut_courant = CASE
                WHEN c.admis = 2 THEN 81 WHEN c.admis = 1 THEN 80
                WHEN c.resultat = 2 THEN 71 WHEN c.resultat = 1 THEN 70
                WHEN c.elig_statut = 2 THEN 51 WHEN c.elig_statut = 1 THEN 50
                WHEN c.rec_statut = 2 THEN 41 WHEN c.rec_statut = 1 THEN 40
                WHEN c.etu_statut = 2 AND EXISTS (SELECT 1 FROM paiement p WHERE p.candidature_id = c.id
                    AND p.type_frais = 'dossier' AND p.statut_paiement = 'reussi') THEN 30
                ELSE 10 END");
        $this->addSql('CREATE INDEX idx_candidature_statut ON candidature (statut_courant)');
        $this->addSql('ALTER TABLE candidature
            DROP CONSTRAINT chk_candidature_elig_statut,
            DROP CONSTRAINT chk_candidature_resultat,
            DROP CONSTRAINT chk_candidature_admis');
        $this->addSql('UPDATE candidature SET resultat = admis + 2 WHERE admis IS NOT NULL');
        $this->addSql('ALTER TABLE candidature DROP admis');
        $this->addSql('DROP TABLE candidature_statut_archive');
    }
}
