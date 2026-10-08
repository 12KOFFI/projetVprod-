<?php

namespace App\Controller;

use App\Repository\CentreMetierRepository;
use App\Service\Referentiel\OffrePublique;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Vitrine publique de la plateforme VAE.
 *
 * Accessible sans authentification : c'est le point d'entrée du maître
 * artisan qui découvre le dispositif. Un utilisateur déjà connecté est
 * renvoyé vers son espace de travail plutôt que vers la page de présentation.
 */
class HomeController extends AbstractController
{
    /**
     * Documents officiels de la session, déposés dans public/document/.
     * Chemins relatifs à la racine web, tels qu'attendus par asset().
     */
    private const DOCUMENTS = [
        'liste' => 'document/LISTE DES METIERS PAR ETABLISSEMENT.docx',
    ];

    /**
     * Personnages 3D de l'animation du parcours VAE (une image par étape),
     * déposés dans public/image/parcours/ : etape-1 à etape-5, en .webp ou .png.
     */
    private const AVATARS = 'image/parcours/etape-%d.%s';

    /** Éléments par page sur « Métiers et centres VAE » (10, comme les listes de daip.ci). */
    private const PAR_PAGE = ['metier' => 10, 'centre' => 10];
    private const ETAPES_PARCOURS = 5;

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_dashboard');
        }

        $racineWeb = $this->getParameter('kernel.project_dir') . '/public/';

        // Tout ou rien : tant qu'une image manque, l'animation garde ses
        // pictogrammes, pour ne pas mélanger deux styles d'illustration.
        $avatars = [];
        for ($etape = 1; $etape <= self::ETAPES_PARCOURS; ++$etape) {
            foreach (['webp', 'png'] as $format) {
                $chemin = sprintf(self::AVATARS, $etape, $format);
                if (is_file($racineWeb . $chemin)) {
                    $avatars[] = $chemin;
                    break;
                }
            }
        }

        // Le référentiel (métiers et centres) vit sur sa propre page : l'accueil
        // n'interroge plus la base et n'embarque plus les deux listes complètes.
        return $this->render('public/home.html.twig', [
            'avatars' => count($avatars) === self::ETAPES_PARCOURS ? $avatars : null,
        ]);
    }

    /**
     * « Mon métier est-il concerné ? » et « Trouver mon centre VAE ».
     *
     * Tous les métiers et tous les centres sont affichés ; une seule recherche
     * les filtre pendant la frappe (côté navigateur). Le paramètre ?q= donne le
     * même filtre côté serveur, pour qui n'a pas JavaScript ou partage un lien.
     * Comme le déroulement, la page reste ouverte à l'utilisateur connecté.
     */
    #[Route('/metiers-et-centres', name: 'app_metiers_centres', methods: ['GET'])]
    public function metiersEtCentres(Request $request, CentreMetierRepository $offre, OffrePublique $offrePublique): Response
    {
        $referentiel = $offrePublique->construire($offre->findOffrePublique());
        // ?metier= : ancien nom du paramètre, encore accepté.
        $saisie = trim((string) $request->query->get('q', $request->query->get('metier', '')));

        $metiersTrouves = array_column($offrePublique->rechercher($referentiel, $saisie), 'cle');
        $centresTrouves = array_column($offrePublique->rechercherCentres($referentiel, $saisie), 'cle');

        // Sans recherche, tout est affiché ; avec, seulement ce qui correspond.
        // La pagination porte sur cette liste (?pm= pour les métiers, ?pc= pour
        // les centres) : elle sert sans JavaScript, et donne la page de départ
        // au filtre du navigateur.
        $metiers = $metiersTrouves;
        $centres = $centresTrouves;
        if ($saisie === '') {
            $metiers = $centres = [];
            foreach ($referentiel['filieres'] as $filiere) {
                array_push($metiers, ...array_column($filiere['metiers'], 'cle'));
            }
            foreach ($referentiel['villes'] as $ville) {
                array_push($centres, ...array_column($ville['centres'], 'cle'));
            }
        }

        return $this->render('public/metiers_centres.html.twig', [
            'offre' => $referentiel,
            'saisie' => $saisie,
            'metiersTrouves' => $metiersTrouves,
            'centresTrouves' => $centresTrouves,
            'pagesMetiers' => $this->paginer($metiers, self::PAR_PAGE['metier'], $request->query->getInt('pm', 1)),
            'pagesCentres' => $this->paginer($centres, self::PAR_PAGE['centre'], $request->query->getInt('pc', 1)),
            'parPage' => self::PAR_PAGE,
            'documents' => $this->documents(),
        ]);
    }

    /**
     * Deroulement detaille de la session, extrait de la page d'accueil.
     *
     * Contrairement a l'accueil, cette page ne redirige pas l'utilisateur
     * connecte : c'est une page d'information que le candidat doit pouvoir
     * consulter a tout moment de son parcours.
     */
    #[Route('/deroulement-session-2026', name: 'app_session_deroulement', methods: ['GET'])]
    public function deroulement(): Response
    {
        return $this->render('public/session.html.twig');
    }

    /**
     * Découpe une liste de clés en pages ; une page hors limites est ramenée
     * dans l'intervalle valide.
     *
     * @param list<string> $cles
     *
     * @return array{page: int, pages: int, total: int, affiches: list<string>}
     */
    private function paginer(array $cles, int $parPage, int $page): array
    {
        $pages = max(1, (int) ceil(count($cles) / $parPage));
        $page = min(max(1, $page), $pages);

        return [
            'page' => $page,
            'pages' => $pages,
            'total' => count($cles),
            'affiches' => array_slice($cles, ($page - 1) * $parPage, $parPage),
        ];
    }

    /**
     * Un lien vers un document absent mènerait à une erreur 404 : le gabarit
     * ne l'affiche que si le fichier est déposé.
     *
     * @return array<string, array{chemin: string, disponible: bool}>
     */
    private function documents(): array
    {
        $racineWeb = $this->getParameter('kernel.project_dir') . '/public/';
        $documents = [];
        foreach (self::DOCUMENTS as $cle => $chemin) {
            $documents[$cle] = [
                'chemin' => $chemin,
                'disponible' => is_file($racineWeb . $chemin),
            ];
        }

        return $documents;
    }
}
