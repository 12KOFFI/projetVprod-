<?php

namespace App\Service\Referentiel;

use App\Entity\CentreMetier;

/**
 * Met en forme l'offre de la session pour la recherche publique de l'accueil :
 * « Mon métier est-il concerné ? » (métiers groupés par filière, avec leurs
 * centres) et « Trouver mon centre VAE » (centres groupés par ville).
 *
 * Tout est indexé par identifiant avant d'être remis en listes : un même
 * centre n'apparaît qu'une fois pour un métier, un même métier qu'une fois
 * pour un centre, quel que soit le nombre de certifications jointes.
 */
class OffrePublique
{
    /**
     * Un mot d'au moins LONGUEUR_MOT_MIN lettres est rapproché par ses
     * LONGUEUR_RADICAL premières : « soudeur » et « Soudure » ne partagent que
     * « soud », « menuiserie » et « Menuisier » partagent « menu ».
     */
    public const LONGUEUR_MOT_MIN = 5;
    public const LONGUEUR_RADICAL = 4;

    /**
     * @param CentreMetier[] $lignes
     *
     * @return array{
     *     filieres: list<array{libelle: string, metiers: list<array{libelle: string, cle: string, radicaux: string, types: list<string>, centres: list<array{nom: string, ville: string}>}>}>,
     *     villes: list<array{libelle: string, valeur: string, centres: list<array{nom: string, metiers: list<string>}>}>,
     *     nombreMetiers: int
     * }
     */
    public function construire(array $lignes): array
    {
        $metiers = [];
        $villes = [];

        foreach ($lignes as $ligne) {
            $metier = $ligne->getMetier();
            $centre = $ligne->getCentre();
            if ($metier === null || $centre === null) {
                continue;
            }

            $ville = (string) $centre->getLocalite()?->getLibelle();
            $idMetier = $metier->getId();
            $idCentre = $centre->getId();

            $metiers[$idMetier] ??= [
                'libelle' => (string) $metier->getLibelle(),
                'filiere' => (string) $metier->getFiliere()?->getLibelle(),
                'cle' => self::normaliser((string) $metier->getLibelle()),
                'radicaux' => implode(' ', self::radicaux((string) $metier->getLibelle())),
                'types' => [],
                'centres' => [],
            ];
            foreach ($ligne->getCertifications() as $certification) {
                if ($certification->isActif() && $certification->getType() !== null) {
                    $metiers[$idMetier]['types'][$certification->getType()] = true;
                }
            }
            $metiers[$idMetier]['centres'][$idCentre] = ['nom' => (string) $centre->getNom(), 'ville' => $ville];

            $villes[$ville][$idCentre] ??= ['nom' => (string) $centre->getNom(), 'metiers' => []];
            $villes[$ville][$idCentre]['metiers'][$idMetier] = (string) $metier->getLibelle();
        }

        $filieres = [];
        foreach ($metiers as $metier) {
            $types = array_keys($metier['types']);
            sort($types);
            $centres = array_values($metier['centres']);
            usort($centres, fn (array $a, array $b) => [$a['ville'], $a['nom']] <=> [$b['ville'], $b['nom']]);

            $filieres[$metier['filiere']][] = [
                'libelle' => $metier['libelle'],
                'cle' => $metier['cle'],
                'radicaux' => $metier['radicaux'],
                'types' => $types,
                'centres' => $centres,
            ];
        }
        // Tri sur la forme normalisée : « Électricité » se range à E, pas après Z.
        uksort($filieres, fn ($a, $b) => self::normaliser((string) $a) <=> self::normaliser((string) $b));

        $listeFilieres = [];
        foreach ($filieres as $libelle => $metiersDeLaFiliere) {
            usort($metiersDeLaFiliere, fn (array $a, array $b) => $a['cle'] <=> $b['cle']);
            $listeFilieres[] = ['libelle' => (string) $libelle, 'metiers' => $metiersDeLaFiliere];
        }

        uksort($villes, fn ($a, $b) => self::normaliser((string) $a) <=> self::normaliser((string) $b));
        $listeVilles = [];
        $nombreCentres = 0;
        foreach ($villes as $libelle => $centres) {
            // Un centre se trouve par son nom, sa ville ou l'un de ses métiers :
            // « Bouaké », « LPI » ou « soudeur » le font apparaître.
            $centres = array_map(function (array $centre) use ($libelle) {
                $metiersDuCentre = array_values($centre['metiers']);
                usort($metiersDuCentre, fn ($a, $b) => self::normaliser($a) <=> self::normaliser($b));
                $texte = $centre['nom'] . ' ' . $libelle . ' ' . implode(' ', $metiersDuCentre);

                return [
                    'nom' => $centre['nom'],
                    'metiers' => $metiersDuCentre,
                    'cle' => self::normaliser($texte),
                    'radicaux' => implode(' ', self::radicaux($texte)),
                ];
            }, array_values($centres));
            $nombreCentres += count($centres);
            usort($centres, fn (array $a, array $b) => self::normaliser($a['nom']) <=> self::normaliser($b['nom']));

            $listeVilles[] = [
                'libelle' => (string) $libelle,
                'valeur' => self::normaliser((string) $libelle),
                'centres' => $centres,
            ];
        }

        return [
            'filieres' => $listeFilieres,
            'villes' => $listeVilles,
            'nombreMetiers' => count($metiers),
            'nombreCentres' => $nombreCentres,
        ];
    }

    /**
     * Même règle que normaliser() côté navigateur (metiers_centres.html.twig) :
     * décomposition NFD, suppression des diacritiques, minuscules, espaces réduits.
     */
    public static function normaliser(string $texte): string
    {
        $decompose = \Normalizer::normalize($texte, \Normalizer::FORM_D);
        $sansAccents = preg_replace('/\p{Mn}+/u', '', $decompose === false ? $texte : $decompose);

        return (string) preg_replace('/\s+/u', ' ', trim(mb_strtolower((string) $sansAccents)));
    }

    /**
     * Radicaux des mots d'au moins LONGUEUR_MOT_MIN lettres, sans doublon.
     *
     * @return list<string>
     */
    public static function radicaux(string $texte): array
    {
        $radicaux = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', self::normaliser($texte), -1, PREG_SPLIT_NO_EMPTY) as $mot) {
            if (mb_strlen($mot) >= self::LONGUEUR_MOT_MIN) {
                $radicaux[mb_substr($mot, 0, self::LONGUEUR_RADICAL)] = true;
            }
        }

        return array_keys($radicaux);
    }

    /**
     * « Mon métier est-il concerné ? » : métiers de l'offre qui correspondent à
     * la saisie, sans tenir compte des accents, de la casse ni des espaces. Un
     * mot tapé d'au moins LONGUEUR_MOT_MIN lettres trouve aussi les libellés de
     * même radical (« menuiserie » → Menuisier, « soudeur » → Soudure).
     *
     * @param array{filieres: list<array{libelle: string, metiers: list<array{cle: string, radicaux: string}>}>} $offre
     *
     * @return list<array<string, mixed>> les métiers trouvés, chacun avec sa filière
     */
    public function rechercher(array $offre, string $saisie): array
    {
        $trouves = [];
        foreach ($offre['filieres'] as $filiere) {
            foreach ($filiere['metiers'] as $metier) {
                if (self::correspond($metier['cle'], $metier['radicaux'], $saisie)) {
                    $trouves[] = $metier + ['filiere' => $filiere['libelle']];
                }
            }
        }

        return $trouves;
    }

    /**
     * « Trouver mon centre VAE » : centres dont le nom, la ville ou l'un des
     * métiers correspond à la saisie, même règle que rechercher().
     *
     * @param array{villes: list<array{libelle: string, centres: list<array{cle: string, radicaux: string}>}>} $offre
     *
     * @return list<array<string, mixed>> les centres trouvés, chacun avec sa ville
     */
    public function rechercherCentres(array $offre, string $saisie): array
    {
        $trouves = [];
        foreach ($offre['villes'] as $ville) {
            foreach ($ville['centres'] as $centre) {
                if (self::correspond($centre['cle'], $centre['radicaux'], $saisie)) {
                    $trouves[] = $centre + ['ville' => $ville['libelle']];
                }
            }
        }

        return $trouves;
    }

    /**
     * Règle de correspondance, identique côté navigateur (metiers_centres.html.twig) :
     * la saisie normalisée est contenue dans la clé, ou l'un de ses mots d'au
     * moins LONGUEUR_MOT_MIN lettres partage son radical avec un mot de la clé.
     * Une saisie vide ne correspond à rien.
     */
    public static function correspond(string $cle, string $radicaux, string $saisie): bool
    {
        $requete = self::normaliser($saisie);
        if ($requete === '') {
            return false;
        }
        if (str_contains($cle, $requete)) {
            return true;
        }

        $radicauxCle = $radicaux === '' ? [] : explode(' ', $radicaux);
        foreach (explode(' ', $requete) as $mot) {
            if (mb_strlen($mot) >= self::LONGUEUR_MOT_MIN
                && in_array(mb_substr($mot, 0, self::LONGUEUR_RADICAL), $radicauxCle, true)) {
                return true;
            }
        }

        return false;
    }
}
