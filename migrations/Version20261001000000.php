<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aligne le référentiel sur le communiqué de la session 2026 du 25/09/2026
 * (« BON vf-Projet Communiqué VAE session 2026 25 09 2026.docx »).
 *
 * 1. Sept certifications reprennent le libellé du communiqué (même formation,
 *    autre écriture) : simple renommage, les dossiers déjà déposés restent
 *    rattachés par identifiant.
 * 2. Trois certifications nouvelles du communiqué entrent au référentiel, avec
 *    leurs métiers et leur centre :
 *    - CQP Maraicher et CQP Aviculteur (poulet chair, poule pondeuse) au Lycée
 *      Professionnel Industriel de Bouaké, filière nouvelle « Agriculture et
 *      Élevage » ;
 *    - CAP Mécanique d'usinage au Lycée Professionnel de Mankono. Le métier
 *      porte le nom « Mécanicien d'usinage », celui du fichier Excel, pour que
 *      app:certifications:importer le reconnaisse.
 *    Places par couple centre/métier : 20, valeur par défaut de l'import, à
 *    ajuster dans Paramètres.
 *
 * Les lignes sont retrouvées par libellé (les identifiants diffèrent d'une base
 * à l'autre) et chaque insertion vérifie l'absence préalable : la migration
 * peut passer sur une base déjà partiellement alignée.
 */
final class Version20261001000000 extends AbstractMigration
{
    /** Libellé en base => libellé du communiqué du 25/09/2026. */
    private const RENOMMAGES = [
        'CQP Maçonnerie-Briqueterie' => 'CQP Briqueterie',
        'CQP Maçonnerie-Coffrage' => 'CQP Coffrage',
        'CQP Maçonnerie-Ferraillage' => 'CQP Ferraillage',
        'CAP Plomberie Sanitaire' => 'CAP Installation Sanitaire',
        'CAP Mécanique agricole' => 'CAP Agro-Mécanique',
        'CQP Tapisserie-Décoration' => 'CQP Tapisserie d\'ameublement',
        'CAP Carrosserie-Peinture Automobile' => 'CAP Carrosserie-Tôlerie-Peinture Automobile',
    ];

    private const FILIERE_AGRICULTURE = 'Agriculture et Élevage';

    /** Certification => [type, métier, filière, centre]. */
    private const NOUVEAUTES = [
        'CQP Maraicher' => ['CQP', 'Maraîcher', self::FILIERE_AGRICULTURE, 'Lycée Professionnel Industriel de Bouaké (LPI BOUAKE)'],
        'CQP Aviculteur (poulet chair, poule pondeuse)' => ['CQP', 'Aviculteur', self::FILIERE_AGRICULTURE, 'Lycée Professionnel Industriel de Bouaké (LPI BOUAKE)'],
        'CAP Mécanique d\'usinage' => ['CAP', 'Mécanicien d\'usinage', 'Mécanique et Automobile', 'Lycée Professionnel de Mankono (LP MANKONO)'],
    ];

    private const PLACES_PAR_DEFAUT = 20;

    public function getDescription(): string
    {
        return 'Référentiel aligné sur le communiqué VAE 2026 du 25/09/2026 : 7 libellés de certification, 3 certifications nouvelles.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::RENOMMAGES as $ancien => $nouveau) {
            $this->addSql(
                'UPDATE certification SET libelle = :nouveau, modification = NOW() WHERE libelle = :ancien',
                ['ancien' => $ancien, 'nouveau' => $nouveau],
            );
        }

        $this->addSql(
            'INSERT INTO filiere (libelle, creation) SELECT :f, NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM filiere WHERE libelle = :f)',
            ['f' => self::FILIERE_AGRICULTURE],
        );

        foreach (self::NOUVEAUTES as $certification => [$type, $metier, $filiere, $centre]) {
            $this->addSql(
                'INSERT INTO metier (filiere_id, libelle, statut, creation)
                 SELECT f.id, :m, \'actif\', NOW() FROM filiere f
                 WHERE f.libelle = :f AND NOT EXISTS (SELECT 1 FROM metier WHERE libelle = :m)',
                ['m' => $metier, 'f' => $filiere],
            );
            $this->addSql(
                'INSERT INTO certification (metier_id, libelle, type, actif, creation)
                 SELECT m.id, :c, :t, 1, NOW() FROM metier m
                 WHERE m.libelle = :m AND NOT EXISTS (SELECT 1 FROM certification WHERE libelle = :c)',
                ['c' => $certification, 't' => $type, 'm' => $metier],
            );
            $this->addSql(
                'INSERT INTO centre_metier (centre_id, metier_id, nbrplace, creation)
                 SELECT ce.id, m.id, :places, NOW() FROM centre ce JOIN metier m ON m.libelle = :m
                 WHERE ce.nom = :centre
                   AND NOT EXISTS (SELECT 1 FROM centre_metier cm WHERE cm.centre_id = ce.id AND cm.metier_id = m.id)',
                ['places' => self::PLACES_PAR_DEFAUT, 'm' => $metier, 'centre' => $centre],
            );
            $this->addSql(
                'INSERT INTO centre_metier_certification (centre_metier_id, certification_id)
                 SELECT cm.id, cert.id FROM centre_metier cm
                 JOIN centre ce ON ce.id = cm.centre_id AND ce.nom = :centre
                 JOIN metier m ON m.id = cm.metier_id AND m.libelle = :m
                 JOIN certification cert ON cert.libelle = :c
                 WHERE NOT EXISTS (SELECT 1 FROM centre_metier_certification x WHERE x.centre_metier_id = cm.id AND x.certification_id = cert.id)',
                ['centre' => $centre, 'm' => $metier, 'c' => $certification],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Les ajouts ne sont retirés que s'ils n'ont servi à aucun dossier : un
        // métier déjà choisi par un candidat reste, avec son offre.
        foreach (self::NOUVEAUTES as $certification => [, $metier, , $centre]) {
            $this->addSql(
                'DELETE x FROM centre_metier_certification x JOIN certification cert ON cert.id = x.certification_id WHERE cert.libelle = :c',
                ['c' => $certification],
            );
            $this->addSql(
                'DELETE cm FROM centre_metier cm JOIN metier m ON m.id = cm.metier_id JOIN centre ce ON ce.id = cm.centre_id
                 WHERE m.libelle = :m AND ce.nom = :centre AND NOT EXISTS (SELECT 1 FROM candidature ca WHERE ca.metier_id = m.id)',
                ['m' => $metier, 'centre' => $centre],
            );
            // Seul le lien centre/métier référence une certification (supprimé ci-dessus).
            $this->addSql('DELETE FROM certification WHERE libelle = :c', ['c' => $certification]);
            $this->addSql(
                'DELETE m FROM metier m WHERE m.libelle = :m
                 AND NOT EXISTS (SELECT 1 FROM centre_metier cm WHERE cm.metier_id = m.id)
                 AND NOT EXISTS (SELECT 1 FROM certification cert WHERE cert.metier_id = m.id)
                 AND NOT EXISTS (SELECT 1 FROM candidature ca WHERE ca.metier_id = m.id)',
                ['m' => $metier],
            );
        }
        $this->addSql(
            'DELETE f FROM filiere f WHERE f.libelle = :f AND NOT EXISTS (SELECT 1 FROM metier m WHERE m.filiere_id = f.id)',
            ['f' => self::FILIERE_AGRICULTURE],
        );

        foreach (self::RENOMMAGES as $ancien => $nouveau) {
            $this->addSql(
                'UPDATE certification SET libelle = :ancien, modification = NOW() WHERE libelle = :nouveau',
                ['ancien' => $ancien, 'nouveau' => $nouveau],
            );
        }
    }
}
