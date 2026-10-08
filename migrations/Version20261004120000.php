<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Numéros de téléphone au format international (App\Referentiel\Telephone).
 *
 * Les champs de numéro proposent désormais un indicatif au choix : un numéro
 * est enregistré complet (« +2250707080910 »). Les numéros existants, tous
 * ivoiriens et saisis sur dix chiffres sans indicatif, reçoivent « +225 ».
 * Seules les valeurs d'exactement dix chiffres sont converties ; la connexion
 * par numéro sans indicatif reste possible (Telephone::depuisIdentifiant).
 *
 * Colonnes : user.contact (identifiant de connexion), user.contact2,
 * candidature.contactemployeur. Toutes peuvent contenir 22 caractères au moins.
 */
final class Version20261004120000 extends AbstractMigration
{
    private const COLONNES = [
        ['`user`', 'contact'],
        ['`user`', 'contact2'],
        ['candidature', 'contactemployeur'],
    ];

    public function getDescription(): string
    {
        return 'Numéros de téléphone existants au format international (+225 devant les numéros de 10 chiffres).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::COLONNES as [$table, $colonne]) {
            $this->addSql(sprintf(
                "UPDATE %1\$s SET %2\$s = CONCAT('+225', %2\$s) WHERE %2\$s REGEXP '^[0-9]{10}$'",
                $table,
                $colonne,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::COLONNES as [$table, $colonne]) {
            $this->addSql(sprintf(
                "UPDATE %1\$s SET %2\$s = SUBSTRING(%2\$s, 5) WHERE %2\$s REGEXP '^\\\\+225[0-9]{10}$'",
                $table,
                $colonne,
            ));
        }
    }
}
