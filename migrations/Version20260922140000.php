<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rubriques de la fiche d'inscription officielle absentes jusqu'ici :
 * références du contrat (numéro, date), lieu d'exercice détaillé (région,
 * département, sous-préfecture), diplôme académique, ville et boîte postale.
 *
 * « diplome » ne désigne plus que le diplôme ou la certification
 * professionnelle : les diplômes de l'enseignement général qui y étaient
 * saisis sont déplacés vers diplome_academique.
 */
final class Version20260922140000 extends AbstractMigration
{
    private const DIPLOMES_ACADEMIQUES = "('CEPE', 'BEPC', 'BAC')";

    public function getDescription(): string
    {
        return 'Fiche d\'inscription : contrat, lieu d\'exercice détaillé, diplôme académique, ville et boîte postale.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE candidature
            ADD diplome_academique VARCHAR(50) DEFAULT NULL AFTER diplome,
            ADD lieu_region VARCHAR(100) DEFAULT NULL AFTER lieuentreprise,
            ADD lieu_departement VARCHAR(100) DEFAULT NULL AFTER lieu_region,
            ADD lieu_sous_prefecture VARCHAR(100) DEFAULT NULL AFTER lieu_departement,
            ADD contrat_numero VARCHAR(100) DEFAULT NULL AFTER contrat,
            ADD contrat_date DATE DEFAULT NULL AFTER contrat_numero');

        $this->addSql('ALTER TABLE `user`
            ADD ville VARCHAR(100) DEFAULT NULL AFTER residence,
            ADD boite_postale VARCHAR(50) DEFAULT NULL AFTER ville');

        $this->addSql('UPDATE candidature SET diplome_academique = diplome, diplome = NULL WHERE diplome IN ' . self::DIPLOMES_ACADEMIQUES);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE candidature SET diplome = diplome_academique WHERE diplome IS NULL AND diplome_academique IN ' . self::DIPLOMES_ACADEMIQUES);

        $this->addSql('ALTER TABLE candidature
            DROP diplome_academique, DROP lieu_region, DROP lieu_departement,
            DROP lieu_sous_prefecture, DROP contrat_numero, DROP contrat_date');

        $this->addSql('ALTER TABLE `user` DROP ville, DROP boite_postale');
    }
}
