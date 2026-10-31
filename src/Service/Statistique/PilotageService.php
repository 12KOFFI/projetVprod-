<?php

namespace App\Service\Statistique;

use App\Dto\FiltresPilotage;
use App\Entity\Centre;
use App\Entity\Metier;
use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Enum\StatutEtude;
use App\Repository\CandidatureRepository;
use App\Repository\CentreMetierRepository;
use App\Repository\CentreRepository;
use App\Repository\DirectionRegionaleRepository;
use App\Repository\FiliereRepository;
use App\Repository\LocaliteRepository;
use App\Repository\MetierRepository;
use App\Service\Jury\SuiviRecevabiliteService;

/**
 * Pilotage de la campagne : tableau de bord de l'administration (/admin,
 * tableauDeBord) et écran Indicateurs (vueIndicateurs, filtres réservés à
 * l'administration ; indicateursParcours pour les autres rôles).
 *
 * Lecture seule, sur le modèle existant : centre → localité → direction
 * régionale, métier → filière, sexe du candidat, date de dépôt. Une seule
 * requête groupée sur les dossiers (CandidatureRepository::agregatPilotage) ;
 * tout le reste se calcule sur ce résultat compact.
 *
 * Les étapes sont CUMULATIVES, comme dans StatistiqueService::synthese() : un
 * dossier éligible compte aussi parmi les inscrits et les recevables. La
 * numérotation de StatutCandidature le permet directement (un dossier non
 * recevable, 40, s'arrête avant le seuil « recevable », 41).
 */
class PilotageService
{
    /** Étape => [libellé, seuil de statut atteint]. */
    public const ETAPES = [
        'preinscrits' => ['Préinscrits', StatutCandidature::PREINSCRIT],
        'inscrits' => ['Inscrits', StatutCandidature::INSCRIT],
        'recevables' => ['Recevables', StatutCandidature::DOSSIER_RECEVABLE],
        'eligibles' => ['Éligibles', StatutCandidature::ELIGIBLE],
        'admissibles' => ['Admissibles', StatutCandidature::ADMISSIBLE],
        'admis' => ['Admis définitifs', StatutCandidature::ADMIS_DEFINITIF],
    ];

    /** Parts nommées d'un anneau ; au-delà, regroupées en « Autres ». */
    private const PARTS_MAX = 5;
    /** Le tableau de bord, plus dense, ne nomme que trois parts par anneau. */
    private const PARTS_MAX_TABLEAU_DE_BORD = 3;
    /** Éléments affichés dans un bloc (classement, alertes). */
    private const ELEMENTS_MAX = 5;
    private const CLASSEMENT_MAX = 10;

    public function __construct(
        private readonly CandidatureRepository $candidatures,
        private readonly CentreRepository $centres,
        private readonly LocaliteRepository $localites,
        private readonly DirectionRegionaleRepository $directions,
        private readonly FiliereRepository $filieres,
        private readonly MetierRepository $metiers,
        private readonly CentreMetierRepository $offres,
    ) {
    }

    /**
     * Tout ce qu'affiche l'écran, pour un administrateur et des filtres donnés.
     * Les filtres incohérents avec la cascade sont retirés (et l'objet reçu
     * est corrigé en conséquence).
     *
     * @return array<string, mixed>
     */
    public function vue(User $user, FiltresPilotage $f): array
    {
        $ref = $this->referentiel();
        $this->corrigerCascade($f, $ref);

        $lignes = $this->candidatures->agregatPilotage($user, $f);
        $centresVus = $this->centresDuPerimetre($f, $ref);

        return [
            'options' => $this->options($f, $ref),
            'pastilles' => $this->pastilles($f, $ref),
            'perimetre' => $this->libellePerimetre($f, $ref),
            'etapes' => array_map(static fn (array $e): string => $e[0], self::ETAPES),
            'indicateurs' => $this->indicateurs($lignes),
            'centres' => $this->tableauCentres($lignes, $centresVus, $ref),
            'repartitions' => $this->repartitions($lignes, $f->etape, $ref),
            'classement' => $this->classement($lignes, $f->etape, $centresVus),
            'alertes' => $this->alertes($lignes, $f, $centresVus, $ref),
            'courbe' => $this->courbe($user, $f),
        ];
    }

    /**
     * Tableau de bord de l'administration (/admin) : toute la campagne, sans
     * filtre. Répartitions et classement sont calculés pour les six étapes à
     * la fois, sur le même résultat de requête : changer d'étape à l'écran ne
     * recharge pas la page.
     *
     * @return array<string, mixed>
     */
    public function tableauDeBord(User $user): array
    {
        $f = new FiltresPilotage();
        $ref = $this->referentiel();
        $lignes = $this->candidatures->agregatPilotage($user, $f);
        $centresVus = $this->centresDuPerimetre($f, $ref);

        $repartitions = $classements = [];
        foreach (array_keys(self::ETAPES) as $etape) {
            $r = $this->repartitions($lignes, $etape, $ref, self::PARTS_MAX_TABLEAU_DE_BORD);
            // La filière est retirée de l'écran pour l'instant.
            $repartitions[$etape] = ['centre' => $r['centre'], 'localite' => $r['localite'], 'dr' => $r['dr'], 'metier' => $r['metier'], 'sexe' => $r['sexe']];
            $classements[$etape] = $this->classement($lignes, $etape, $centresVus);
        }

        // Centres actifs d'abord (préinscrits décroissants), puis les centres
        // sans dossier, par nom : ces derniers ferment la liste.
        $centres = $this->tableauCentres($lignes, $centresVus, $ref);
        usort($centres, static fn (array $a, array $b): int => [$b['etapes']['preinscrits'], $a['nom']] <=> [$a['etapes']['preinscrits'], $b['nom']]);

        return [
            'etapes' => array_map(static fn (array $e): string => $e[0], self::ETAPES),
            'indicateurs' => $this->indicateurs($lignes),
            'repartitions' => $repartitions,
            'classements' => $classements,
            'alertes' => $this->alertes($lignes, $f, $centresVus, $ref),
            'courbe' => $this->courbe($user, $f),
            'centres' => $centres,
        ];
    }

    /**
     * Écran Indicateurs de l'administration : les filtres en cascade
     * s'appliquent à tout le contenu. Comme sur le tableau de bord, les
     * répartitions sont prêtes pour les six étapes.
     *
     * @return array<string, mixed>
     */
    public function vueIndicateurs(User $user, FiltresPilotage $f): array
    {
        $ref = $this->referentiel();
        $this->corrigerCascade($f, $ref);

        $lignes = $this->candidatures->agregatPilotage($user, $f);
        $centresVus = $this->centresDuPerimetre($f, $ref);

        $repartitions = [];
        foreach (array_keys(self::ETAPES) as $etape) {
            $r = $this->repartitions($lignes, $etape, $ref);
            $repartitions[$etape] = ['statut' => $this->parStatut($lignes, $etape), 'sexe' => $r['sexe'], 'metier' => $r['metier'], 'dr' => $r['dr']];
        }

        $parCentre = [];
        foreach ($this->tableauCentres($lignes, $centresVus, $ref) as $c) {
            if ($c['etapes']['preinscrits'] > 0) {
                $parCentre[$c['nom']] = $c['etapes']['preinscrits'];
            }
        }
        arsort($parCentre);

        return [
            'options' => $this->options($f, $ref),
            'pastilles' => $this->pastilles($f, $ref),
            'perimetre' => $this->libellePerimetre($f, $ref),
            'etapes' => array_map(static fn (array $e): string => $e[0], self::ETAPES),
            'indicateurs' => $this->indicateurs($lignes),
            'repartitions' => $repartitions,
            'par_centre' => $parCentre,
            'tableau_croise' => $this->tableauCroise($lignes, $centresVus),
        ];
    }

    /**
     * Les six étapes du parcours sur le seul périmètre du rôle, sans filtre
     * d'écran : cartes de l'écran Indicateurs hors administration.
     *
     * @return list<array{cle: string, libelle: string, effectif: int, passage: ?int, precedente: ?string, part: ?int}>
     */
    public function indicateursParcours(User $user): array
    {
        return $this->indicateurs($this->candidatures->agregatPilotage($user, new FiltresPilotage()));
    }

    // ------------------------------------------------------------------
    // Référentiel et cascade
    // ------------------------------------------------------------------

    /**
     * Référentiel complet, chargé une fois (quelques dizaines de lignes).
     *
     * @return array{dr: array<int, string>, localites: array<int, array{libelle: string, dr: ?int}>, centres: array<int, array{nom: string, localite: ?int, localite_libelle: string, dr: ?int, dr_libelle: string}>, filieres: array<int, string>, metiers: array<int, array{libelle: string, filiere: ?int, filiere_libelle: string}>}
     */
    private function referentiel(): array
    {
        $dr = [];
        foreach ($this->directions->findToutesTriees() as $d) {
            $dr[$d->getId()] = (string) $d->getLibelle();
        }

        $localites = [];
        foreach ($this->localites->findToutesTriees() as $l) {
            $localites[$l->getId()] = ['libelle' => (string) $l->getLibelle(), 'dr' => $l->getDirectionRegionale()?->getId()];
        }

        $centres = [];
        foreach ($this->centres->findTousTries() as $c) {
            $loc = $c->getLocalite();
            $idDr = $loc?->getDirectionRegionale()?->getId();
            $centres[$c->getId()] = [
                'nom' => (string) $c->getNom(),
                'sigle' => self::sigle((string) $c->getNom()),
                'localite' => $loc?->getId(),
                'localite_libelle' => (string) $loc?->getLibelle(),
                'dr' => $idDr,
                'dr_libelle' => $idDr !== null ? ($dr[$idDr] ?? '') : '',
            ];
        }

        $filieres = [];
        foreach ($this->filieres->findToutesTriees() as $fi) {
            $filieres[$fi->getId()] = (string) $fi->getLibelle();
        }

        $metiers = [];
        foreach ($this->metiers->findBy([], ['libelle' => 'ASC']) as $m) {
            /** @var Metier $m */
            $metiers[$m->getId()] = [
                'libelle' => (string) $m->getLibelle(),
                'filiere' => $m->getFiliere()?->getId(),
                'filiere_libelle' => (string) $m->getFiliere()?->getLibelle(),
            ];
        }

        return ['dr' => $dr, 'localites' => $localites, 'centres' => $centres, 'filieres' => $filieres, 'metiers' => $metiers];
    }

    /**
     * Retire les filtres inconnus ou incompatibles avec leur parent (une
     * localité hors de la direction choisie, un métier hors de la filière…).
     */
    private function corrigerCascade(FiltresPilotage $f, array $ref): void
    {
        if ($f->dr !== null && !isset($ref['dr'][$f->dr])) {
            $f->dr = null;
        }
        if ($f->localite !== null && (!isset($ref['localites'][$f->localite])
            || ($f->dr !== null && $ref['localites'][$f->localite]['dr'] !== $f->dr))) {
            $f->localite = null;
        }
        if ($f->centre !== null && (!isset($ref['centres'][$f->centre])
            || ($f->dr !== null && $ref['centres'][$f->centre]['dr'] !== $f->dr)
            || ($f->localite !== null && $ref['centres'][$f->centre]['localite'] !== $f->localite))) {
            $f->centre = null;
        }
        if ($f->filiere !== null && !isset($ref['filieres'][$f->filiere])) {
            $f->filiere = null;
        }
        if ($f->metier !== null && (!isset($ref['metiers'][$f->metier])
            || ($f->filiere !== null && $ref['metiers'][$f->metier]['filiere'] !== $f->filiere))) {
            $f->metier = null;
        }
    }

    /** Centres du périmètre géographique choisi : TOUS, même sans dossier. */
    private function centresDuPerimetre(FiltresPilotage $f, array $ref): array
    {
        return array_filter($ref['centres'], static fn (array $c, int $id): bool =>
            ($f->dr === null || $c['dr'] === $f->dr)
            && ($f->localite === null || $c['localite'] === $f->localite)
            && ($f->centre === null || $id === $f->centre), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Listes des filtres, chaque option portant son parent : le navigateur
     * masque les choix incompatibles avec le parent sélectionné (cascade),
     * le serveur corrigeant de toute façon une combinaison incohérente.
     */
    private function options(FiltresPilotage $f, array $ref): array
    {
        return [
            'dr' => $ref['dr'],
            'localite' => array_map(static fn (array $l): array => ['libelle' => $l['libelle'], 'dr' => $l['dr']], $ref['localites']),
            'centre' => array_map(static fn (array $c): array => ['libelle' => $c['nom'], 'dr' => $c['dr'], 'localite' => $c['localite']], $ref['centres']),
            'filiere' => $ref['filieres'],
            'metier' => array_map(static fn (array $m): array => ['libelle' => $m['libelle'], 'filiere' => $m['filiere']], $ref['metiers']),
            'sexe' => FiltresPilotage::SEXES,
            'periode' => FiltresPilotage::PERIODES,
        ];
    }

    /** @return list<array{libelle: string, valeur: string, parametres: array}> */
    private function pastilles(FiltresPilotage $f, array $ref): array
    {
        $p = [];
        $ajouter = static function (string $cle, string $libelle, ?string $valeur) use (&$p, $f): void {
            if ($valeur !== null && $valeur !== '') {
                $p[] = ['libelle' => $libelle, 'valeur' => $valeur, 'parametres' => $f->parametresSans($cle)];
            }
        };

        $ajouter('dr', 'Direction régionale', $f->dr !== null ? $ref['dr'][$f->dr] : null);
        $ajouter('localite', 'Localité', $f->localite !== null ? $ref['localites'][$f->localite]['libelle'] : null);
        $ajouter('centre', 'Centre', $f->centre !== null ? $ref['centres'][$f->centre]['nom'] : null);
        $ajouter('filiere', 'Filière', $f->filiere !== null ? $ref['filieres'][$f->filiere] : null);
        $ajouter('metier', 'Métier', $f->metier !== null ? $ref['metiers'][$f->metier]['libelle'] : null);
        $ajouter('sexe', 'Sexe', $f->sexe !== null ? FiltresPilotage::SEXES[$f->sexe] : null);
        if ($f->periode !== 'session') {
            $ajouter('periode', 'Période', $f->periode === 'perso'
                ? trim(sprintf('%s – %s', $f->du?->format('d/m/Y') ?? '…', $f->au?->format('d/m/Y') ?? '…'))
                : FiltresPilotage::PERIODES[$f->periode]);
        }

        return $p;
    }

    private function libellePerimetre(FiltresPilotage $f, array $ref): string
    {
        if ($f->centre !== null) {
            return $ref['centres'][$f->centre]['nom'];
        }
        if ($f->localite !== null) {
            return 'Localité de ' . $ref['localites'][$f->localite]['libelle'];
        }
        if ($f->dr !== null) {
            return $ref['dr'][$f->dr];
        }

        return 'Vue nationale, tous centres confondus';
    }

    // ------------------------------------------------------------------
    // Calculs
    // ------------------------------------------------------------------

    /**
     * Effectif de chaque étape (cumulatif) pour un sous-ensemble de lignes.
     *
     * @param iterable<array{statut: int, effectif: int}> $lignes
     *
     * @return array<string, int>
     */
    private function parEtape(iterable $lignes): array
    {
        $n = array_fill_keys(array_keys(self::ETAPES), 0);
        foreach ($lignes as $l) {
            foreach (self::ETAPES as $cle => [, $seuil]) {
                if ($l['statut'] >= $seuil->value) {
                    $n[$cle] += $l['effectif'];
                }
            }
        }

        return $n;
    }

    private function atteint(array $ligne, string $etape): bool
    {
        return $ligne['statut'] >= self::ETAPES[$etape][1]->value;
    }

    /** @return list<array{cle: string, libelle: string, effectif: int, passage: ?int, precedente: ?string, part: ?int}> */
    private function indicateurs(array $lignes): array
    {
        $n = $this->parEtape($lignes);
        $precedent = null;
        $libellePrecedent = null;
        $sortie = [];

        foreach (self::ETAPES as $cle => [$libelle]) {
            $sortie[] = [
                'cle' => $cle,
                'libelle' => $libelle,
                'effectif' => $n[$cle],
                // Taux de passage depuis l'étape précédente, tous dossiers
                // compris (ceux encore en attente de décision aussi).
                'passage' => $precedent === null ? null : $this->pourcentage($n[$cle], $precedent),
                'precedente' => $libellePrecedent,
                // Avancement rapporté aux inscrits, base du parcours une fois
                // les frais de dossier réglés ; les inscrits eux-mêmes sont
                // rapportés aux préinscrits.
                'part' => match ($cle) {
                    'preinscrits' => null,
                    'inscrits' => $this->pourcentage($n['inscrits'], $n['preinscrits']),
                    default => $this->pourcentage($n[$cle], $n['inscrits']),
                },
            ];
            $precedent = $n[$cle];
            $libellePrecedent = mb_strtolower($libelle);
        }

        return $sortie;
    }

    /**
     * Une ligne par centre du périmètre (centres sans dossier compris), avec
     * le détail par métier.
     *
     * @return list<array<string, mixed>>
     */
    private function tableauCentres(array $lignes, array $centresVus, array $ref): array
    {
        $parCentre = [];
        foreach ($lignes as $l) {
            if (isset($centresVus[$l['centre']])) {
                $parCentre[$l['centre']][] = $l;
            }
        }

        $sortie = [];
        foreach ($centresVus as $id => $c) {
            $sesLignes = $parCentre[$id] ?? [];
            $n = $this->parEtape($sesLignes);

            $parMetier = [];
            foreach ($sesLignes as $l) {
                $parMetier[$l['metier'] ?? 0][] = $l;
            }
            $metiers = [];
            foreach ($parMetier as $idMetier => $lm) {
                $nm = $this->parEtape($lm);
                $metiers[] = [
                    'id' => $idMetier ?: null,
                    'libelle' => $idMetier ? ($ref['metiers'][$idMetier]['libelle'] ?? 'Métier inconnu') : 'Métier non renseigné',
                    'etapes' => $nm,
                    'reussite' => $this->pourcentage($nm['admis'], $nm['preinscrits']),
                ];
            }
            usort($metiers, static fn (array $a, array $b): int => [$b['etapes']['preinscrits'], $a['libelle']] <=> [$a['etapes']['preinscrits'], $b['libelle']]);

            $sortie[] = [
                'id' => $id,
                'nom' => $c['nom'],
                'sigle' => $c['sigle'],
                'localite' => $c['localite_libelle'],
                'dr' => $c['dr_libelle'],
                'etapes' => $n,
                'reussite' => $this->pourcentage($n['admis'], $n['preinscrits']),
                'metiers' => $metiers,
            ];
        }

        return $sortie;
    }

    /**
     * Répartitions de l'étape analysée : centre, localité, direction
     * régionale, filière, métier, sexe. Au plus $partsMax parts nommées, le
     * reste en « Autres ».
     *
     * @return array<string, array{titre: string, filtre: ?string, parts: list<array{libelle: string, effectif: int, id: int|string|null}>, total: int, nombre_parts: int, unite: string}>
     */
    private function repartitions(array $lignes, string $etape, array $ref, int $partsMax = self::PARTS_MAX): array
    {
        $centre = $localite = $dr = $filiere = $metier = $sexe = [];
        foreach ($lignes as $l) {
            if (!$this->atteint($l, $etape)) {
                continue;
            }
            $c = $ref['centres'][$l['centre']] ?? null;
            $m = $l['metier'] !== null ? ($ref['metiers'][$l['metier']] ?? null) : null;

            $centre[$l['centre']] = ($centre[$l['centre']] ?? 0) + $l['effectif'];
            $idLoc = $c['localite'] ?? 0;
            $localite[$idLoc] = ($localite[$idLoc] ?? 0) + $l['effectif'];
            $idDr = $c['dr'] ?? 0;
            $dr[$idDr] = ($dr[$idDr] ?? 0) + $l['effectif'];
            $idFi = $m['filiere'] ?? 0;
            $filiere[$idFi] = ($filiere[$idFi] ?? 0) + $l['effectif'];
            $idMe = $l['metier'] ?? 0;
            $metier[$idMe] = ($metier[$idMe] ?? 0) + $l['effectif'];
            $idSe = $l['sexe'] ?? '';
            $sexe[$idSe] = ($sexe[$idSe] ?? 0) + $l['effectif'];
        }

        return [
            'centre' => $this->parts('Par centre', 'centre', $centre, static fn ($id) => $ref['centres'][$id]['sigle'] ?? 'Centre inconnu', $partsMax, 'centres'),
            'localite' => $this->parts('Par localité', 'localite', $localite, static fn ($id) => $ref['localites'][$id]['libelle'] ?? 'Non renseignée', $partsMax, 'localités'),
            'dr' => $this->parts('Par direction régionale', 'dr', $dr, static fn ($id) => isset($ref['dr'][$id]) ? (preg_replace("/^Direction\\s+R[ée]gionale\\s+(de\\s+la\\s+|de\\s+l['’]|des\\s+|du\\s+|de\\s+|d['’])?/iu", '', $ref['dr'][$id]) ?: $ref['dr'][$id]) : 'Non renseignée', $partsMax, 'directions régionales'),
            'filiere' => $this->parts('Par filière', 'filiere', $filiere, static fn ($id) => $ref['filieres'][$id] ?? 'Non renseignée', $partsMax, 'filières'),
            'metier' => $this->parts('Par métier', 'metier', $metier, static fn ($id) => $ref['metiers'][$id]['libelle'] ?? 'Non renseigné', $partsMax, 'métiers'),
            'sexe' => $this->parts('Par sexe', 'sexe', $sexe, static fn ($id) => FiltresPilotage::SEXES[$id] ?? 'Non renseigné', $partsMax, 'valeurs'),
        ];
    }

    /** Statut exact des dossiers ayant atteint l'étape (écran Indicateurs). */
    private function parStatut(array $lignes, string $etape): array
    {
        $statuts = [];
        foreach ($lignes as $l) {
            if ($this->atteint($l, $etape)) {
                $statuts[$l['statut']] = ($statuts[$l['statut']] ?? 0) + $l['effectif'];
            }
        }

        // Le statut n'est pas un filtre de l'écran : aucune part n'est cliquable.
        $rep = $this->parts('Par statut', 'statut', $statuts, static fn ($id) => StatutCandidature::tryFrom((int) $id)?->libelle() ?? 'Statut inconnu');
        $rep['filtre'] = null;

        return $rep;
    }

    private function parts(string $titre, string $filtre, array $effectifs, callable $libelle, int $max = self::PARTS_MAX, string $unite = 'éléments'): array
    {
        arsort($effectifs);
        // Une seule part en trop est nommée plutôt que regroupée : « Autres »
        // ne couvrirait qu'elle.
        if (count($effectifs) === $max + 1) {
            ++$max;
        }
        $parts = [];
        $autres = $regroupees = 0;
        foreach ($effectifs as $id => $effectif) {
            if (count($parts) < $max) {
                // Une part « non renseignée » n'est pas filtrable : id null.
                $parts[] = ['libelle' => $libelle($id), 'effectif' => $effectif, 'id' => ($id === 0 || $id === '') ? null : $id];
            } else {
                $autres += $effectif;
                ++$regroupees;
            }
        }
        if ($autres > 0) {
            $parts[] = ['libelle' => 'Autres', 'effectif' => $autres, 'id' => null, 'autres' => true, 'nombre' => $regroupees];
        }

        return ['titre' => $titre, 'filtre' => $filtre, 'parts' => $parts, 'total' => array_sum($effectifs), 'nombre_parts' => count($effectifs), 'unite' => $unite];
    }

    /** @return list<array{id: int, nom: string, localite: string, effectif: int, part: ?int}> */
    private function classement(array $lignes, string $etape, array $centresVus): array
    {
        $effectifs = $preinscrits = [];
        foreach ($lignes as $l) {
            if (!isset($centresVus[$l['centre']])) {
                continue;
            }
            $preinscrits[$l['centre']] = ($preinscrits[$l['centre']] ?? 0) + $l['effectif'];
            if ($this->atteint($l, $etape)) {
                $effectifs[$l['centre']] = ($effectifs[$l['centre']] ?? 0) + $l['effectif'];
            }
        }

        $classement = [];
        foreach ($effectifs as $id => $effectif) {
            $classement[] = [
                'id' => $id,
                'nom' => $centresVus[$id]['nom'],
                'sigle' => $centresVus[$id]['sigle'],
                'localite' => $centresVus[$id]['localite_libelle'],
                'effectif' => $effectif,
                'part' => $this->pourcentage($effectif, $preinscrits[$id] ?? 0),
            ];
        }
        usort($classement, static fn (array $a, array $b): int => [$b['effectif'], $a['nom']] <=> [$a['effectif'], $b['nom']]);

        return array_slice($classement, 0, self::CLASSEMENT_MAX);
    }

    /**
     * Points d'attention, calculés sur le périmètre filtré.
     *
     * @return array<string, mixed>
     */
    private function alertes(array $lignes, FiltresPilotage $f, array $centresVus, array $ref): array
    {
        $avecDossier = $enAttenteEtude = [];
        $metiersAvecDossier = [];
        $attente = 0;
        foreach ($lignes as $l) {
            $avecDossier[$l['centre']] = true;
            if ($l['metier'] !== null) {
                $metiersAvecDossier[$l['centre'] . '-' . $l['metier']] = true;
            }
            if ($l['statut'] === StatutCandidature::PREINSCRIT->value && $l['etude'] === StatutEtude::EN_ATTENTE->value) {
                $attente += $l['effectif'];
            }
        }

        $sansDossier = [];
        foreach ($centresVus as $id => $c) {
            if (!isset($avecDossier[$id])) {
                $sansDossier[] = ['id' => $id, 'nom' => $c['nom'], 'sigle' => $c['sigle'], 'localite' => $c['localite_libelle']];
            }
        }

        // Métiers OUVERTS dans un centre du périmètre (offre centre_metier) et
        // sans aucun dossier, dans la filière / le métier filtrés le cas échéant.
        $metiersSansCandidat = [];
        foreach ($this->offres->findBy([]) as $offre) {
            $idCentre = $offre->getCentre()?->getId();
            $idMetier = $offre->getMetier()?->getId();
            if ($idCentre === null || $idMetier === null || !isset($centresVus[$idCentre])) {
                continue;
            }
            if (($f->metier !== null && $idMetier !== $f->metier)
                || ($f->filiere !== null && ($ref['metiers'][$idMetier]['filiere'] ?? null) !== $f->filiere)) {
                continue;
            }
            if (!isset($metiersAvecDossier[$idCentre . '-' . $idMetier])) {
                $metiersSansCandidat[] = [
                    'centre' => $centresVus[$idCentre]['nom'],
                    'centre_sigle' => $centresVus[$idCentre]['sigle'],
                    'centre_id' => $idCentre,
                    'metier' => $ref['metiers'][$idMetier]['libelle'] ?? '',
                ];
            }
        }
        usort($metiersSansCandidat, static fn (array $a, array $b): int => [$a['centre'], $a['metier']] <=> [$b['centre'], $b['metier']]);

        $meilleur = null;
        foreach ($this->tableauCentres($lignes, $centresVus, $ref) as $ligne) {
            if ($ligne['etapes']['admis'] > 0 && ($meilleur === null || $ligne['reussite'] > $meilleur['reussite'])) {
                $meilleur = $ligne;
            }
        }

        return [
            'centres_sans_dossier' => ['total' => count($sansDossier), 'elements' => array_slice($sansDossier, 0, self::ELEMENTS_MAX)],
            'en_attente_etude' => $attente,
            'metiers_sans_candidat' => ['total' => count($metiersSansCandidat), 'elements' => array_slice($metiersSansCandidat, 0, self::ELEMENTS_MAX)],
            'meilleure_reussite' => $meilleur === null ? null : [
                'id' => $meilleur['id'],
                'nom' => $meilleur['nom'],
                'sigle' => $meilleur['sigle'],
                'localite' => $meilleur['localite'],
                'taux' => $meilleur['reussite'],
                'admis' => $meilleur['etapes']['admis'],
                'preinscrits' => $meilleur['etapes']['preinscrits'],
            ],
        ];
    }

    /**
     * Tableau croisé centre × statut du périmètre filtré, même forme que
     * SuiviRecevabiliteService::tableauCroise() (écran Indicateurs).
     *
     * @return array{centres: list<array{centre: string, localite: ?string, effectifs: array<int, int>, total: int}>, statuts: list<StatutCandidature>, totaux: array<int, int>, total_general: int}
     */
    private function tableauCroise(array $lignes, array $centresVus): array
    {
        $statuts = SuiviRecevabiliteService::statutsSuivis();
        $totaux = array_fill_keys(array_map(static fn (StatutCandidature $s): int => $s->value, $statuts), 0);
        $parCentre = [];
        $totalGeneral = 0;

        foreach ($lignes as $l) {
            $c = $centresVus[$l['centre']] ?? null;
            if ($c === null) {
                continue;
            }
            $parCentre[$l['centre']] ??= [
                'centre' => $c['nom'],
                'localite' => $c['localite_libelle'] !== '' ? $c['localite_libelle'] : null,
                'effectifs' => array_fill_keys(array_keys($totaux), 0),
                'total' => 0,
            ];
            $parCentre[$l['centre']]['total'] += $l['effectif'];
            $totalGeneral += $l['effectif'];

            // Un statut hors du suivi compte dans le total sans avoir sa colonne.
            if (array_key_exists($l['statut'], $totaux)) {
                $parCentre[$l['centre']]['effectifs'][$l['statut']] += $l['effectif'];
                $totaux[$l['statut']] += $l['effectif'];
            }
        }
        usort($parCentre, static fn (array $a, array $b): int => strcmp($a['centre'], $b['centre']));

        return ['centres' => $parCentre, 'statuts' => $statuts, 'totaux' => $totaux, 'total_general' => $totalGeneral];
    }

    /**
     * Dépôts par semaine (lundi → dimanche) sur la période filtrée, ou sur
     * les 12 dernières semaines pour toute la session.
     *
     * @return array{series: array<string, int>, total: int, semaine: int, precedente: int}
     */
    private function courbe(User $user, FiltresPilotage $f): array
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $fin = $f->au ?? $aujourdhui;
        $lundiFin = $fin->modify(sprintf('-%d days', (int) $fin->format('N') - 1));
        $debut = $f->du !== null
            ? $f->du->modify(sprintf('-%d days', (int) $f->du->format('N') - 1))
            : $lundiFin->modify('-11 weeks');
        // Au plus 26 semaines : au-delà, la courbe n'est plus lisible.
        if ($debut < $lundiFin->modify('-25 weeks')) {
            $debut = $lundiFin->modify('-25 weeks');
        }

        $series = [];
        for ($s = $debut; $s <= $lundiFin; $s = $s->modify('+1 week')) {
            $series[$s->format('Y-m-d')] = 0;
        }
        foreach ($this->candidatures->datesDepotPilotage($user, $f, $debut) as $date) {
            $jour = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
            $lundi = $jour->modify(sprintf('-%d days', (int) $jour->format('N') - 1))->format('Y-m-d');
            if (isset($series[$lundi])) {
                ++$series[$lundi];
            }
        }

        $valeurs = array_values($series);
        $libelles = [];
        foreach (array_keys($series) as $lundi) {
            $libelles[] = (new \DateTimeImmutable($lundi))->format('d/m');
        }

        return [
            'series' => array_combine($libelles, $valeurs),
            'total' => array_sum($valeurs),
            'semaine' => $valeurs[count($valeurs) - 1] ?? 0,
            'precedente' => $valeurs[count($valeurs) - 2] ?? 0,
        ];
    }

    private function pourcentage(int $partie, int $tout): ?int
    {
        return $tout > 0 ? (int) round($partie * 100 / $tout) : null;
    }

    /**
     * Sigle d'un centre, lu entre parenthèses à la fin de son nom
     * (« Lycée Professionnel Industriel de Bouaké (LPI BOUAKE) ») ; à défaut,
     * le nom entier.
     */
    private static function sigle(string $nom): string
    {
        return preg_match('/\(([^()]+)\)\s*$/u', $nom, $m) === 1 ? trim($m[1]) : $nom;
    }
}
