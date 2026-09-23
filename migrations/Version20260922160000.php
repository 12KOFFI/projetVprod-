<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une seule colonne par information sur candidature :
 *
 *  - diplome absorbe diplome_academique ; orientation_diplome disparaît
 *    (diplomedemande porte déjà le diplôme visé) ;
 *  - lieu_exercice remplace lieuentreprise, lieu_region, lieu_departement et
 *    lieu_sous_prefecture ;
 *  - ref_contrat remplace contrat, contrat_numero et contrat_date.
 *
 * Les valeurs existantes sont regroupées avant la suppression des colonnes.
 */
final class Version20260922160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Candidature : fusion des colonnes diplôme, lieu d\'exercice et contrat de travail.';
    }

    public function up(Schema $schema): void
    {
        // Diplôme : un diplôme professionnel déjà saisi reste prioritaire.
        $this->addSql('UPDATE candidature SET diplome = diplome_academique WHERE (diplome IS NULL OR diplome = \'\') AND diplome_academique IS NOT NULL');

        // Lieu d'exercice : région, département, sous-préfecture, puis localité.
        $this->addSql('ALTER TABLE candidature CHANGE lieuentreprise lieu_exercice VARCHAR(255) DEFAULT NULL');
        $this->addSql("UPDATE candidature SET lieu_exercice = NULLIF(CONCAT_WS(', ',
            NULLIF(lieu_region, ''), NULLIF(lieu_departement, ''), NULLIF(lieu_sous_prefecture, ''), NULLIF(lieu_exercice, '')), '')");

        // Contrat : type, numéro et date regroupés en une référence lisible.
        $this->addSql('ALTER TABLE candidature CHANGE contrat ref_contrat VARCHAR(255) DEFAULT NULL');
        $this->addSql("UPDATE candidature SET ref_contrat = NULLIF(CONCAT_WS(' ',
            NULLIF(NULLIF(ref_contrat, ''), 'AUCUN'),
            IF(contrat_numero IS NULL OR contrat_numero = '', NULL, CONCAT('N° ', contrat_numero)),
            IF(contrat_date IS NULL, NULL, CONCAT('du ', DATE_FORMAT(contrat_date, '%d/%m/%Y')))), '')");

        $this->addSql('ALTER TABLE candidature
            DROP orientation_diplome, DROP diplome_academique,
            DROP lieu_region, DROP lieu_departement, DROP lieu_sous_prefecture,
            DROP contrat_numero, DROP contrat_date');
    }

    public function down(Schema $schema): void
    {
        // Les valeurs regroupées ne sont pas redécoupées : seule la structure revient.
        $this->addSql('ALTER TABLE candidature
            ADD orientation_diplome VARCHAR(10) DEFAULT NULL,
            ADD diplome_academique VARCHAR(50) DEFAULT NULL,
            ADD lieu_region VARCHAR(100) DEFAULT NULL,
            ADD lieu_departement VARCHAR(100) DEFAULT NULL,
            ADD lieu_sous_prefecture VARCHAR(100) DEFAULT NULL,
            ADD contrat_numero VARCHAR(100) DEFAULT NULL,
            ADD contrat_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE candidature CHANGE lieu_exercice lieuentreprise VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE candidature CHANGE ref_contrat contrat VARCHAR(255) DEFAULT NULL');
    }
}
