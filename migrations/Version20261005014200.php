<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Formulaire de candidature révisé (App\Referentiel\ProfilProfessionnel).
 *
 * - Ajoute les précisions « Autre » : situation_pro_precision, diplome_precision.
 * - Les situations retirées de la liste (apprenti, aide familial, sans emploi)
 *   deviennent « Autre » avec leur libellé en précision : rien n'est perdu.
 * - Supprime titrepro (titre professionnel) et fextrait (extrait de naissance),
 *   retirés du formulaire. Leurs valeurs sont effacées ; les fichiers d'extrait
 *   déjà déposés restent sur le disque (public/media/<numéro>/).
 */
final class Version20261005014200 extends AbstractMigration
{
    private const SITUATIONS_RETIREES = [
        'APPRENTI' => 'Apprenti',
        'AIDE FAMILIAL' => 'Aide familial',
        'SANS EMPLOI' => 'Sans emploi',
    ];

    public function getDescription(): string
    {
        return 'Candidature : précisions « Autre » (situation, diplôme), suppression du titre professionnel et de l\'extrait de naissance.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE candidature ADD diplome_precision VARCHAR(255) DEFAULT NULL, ADD situation_pro_precision VARCHAR(255) DEFAULT NULL');

        foreach (self::SITUATIONS_RETIREES as $code => $libelle) {
            $this->addSql(
                "UPDATE candidature SET situation_pro = 'AUTRE', situation_pro_precision = ? WHERE situation_pro = ?",
                [$libelle, $code]
            );
        }

        $this->addSql('ALTER TABLE candidature DROP titrepro, DROP fextrait');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE candidature ADD titrepro VARCHAR(255) DEFAULT NULL, ADD fextrait VARCHAR(255) DEFAULT NULL');

        foreach (self::SITUATIONS_RETIREES as $code => $libelle) {
            $this->addSql(
                "UPDATE candidature SET situation_pro = ? WHERE situation_pro = 'AUTRE' AND situation_pro_precision = ?",
                [$code, $libelle]
            );
        }

        $this->addSql('ALTER TABLE candidature DROP diplome_precision, DROP situation_pro_precision');
    }
}
