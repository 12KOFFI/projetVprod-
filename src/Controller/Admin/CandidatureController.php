<?php

namespace App\Controller\Admin;

use App\Entity\Centre;
use App\Enum\StatutCandidature;
use App\Repository\CandidatureRepository;
use App\Repository\CentreRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Liste nationale des dossiers, réservée à l'administration.
 *
 * C'est le seul rôle dont le périmètre n'est pas restreint : le centre est ici
 * un filtre d'affichage, et non une barrière de sécurité. Tout autre rôle passe
 * par CandidatureRepository::createQueryBuilderPourUtilisateur().
 */
#[Route('/admin/candidatures', name: 'app_admin_candidature_')]
#[IsGranted('ROLE_ADMIN')]
class CandidatureController extends AbstractController
{
    /**
     * Onglets de l'écran : chacun cible une étape précise du parcours.
     *
     * « Toutes » existe pour que les dossiers déjà recevables, éligibles ou
     * admis restent atteignables : sans lui, les seuls onglets d'étape les
     * rendraient invisibles.
     *
     * Les deux onglets de préinscrits se distinguent par l'étude (etu_statut),
     * qui ne porte plus de statut officiel propre.
     *
     * @var array<string, array{libelle: string, statuts: list<StatutCandidature>|null, etude?: string}>
     */
    private const ONGLETS = [
        'toutes' => ['libelle' => 'Tous les dossiers', 'statuts' => null],
        'preinscrits' => ['libelle' => 'Préinscrits à étudier', 'statuts' => null, 'etude' => CandidatureRepository::ETUDE_A_RENDRE],
        'acceptes' => ['libelle' => 'Acceptés, attente paiement', 'statuts' => null, 'etude' => CandidatureRepository::ETUDE_ACCEPTEE],
        'inscrits' => ['libelle' => 'Inscrits', 'statuts' => [StatutCandidature::INSCRIT]],
    ];

    public function __construct(
        private readonly CandidatureRepository $candidatures,
        private readonly CentreRepository $centres,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, PaginatorInterface $paginator): Response
    {
        $ongletDemande = (string) $request->query->get('onglet', 'toutes');
        $onglet = array_key_exists($ongletDemande, self::ONGLETS) ? $ongletDemande : 'toutes';

        $recherche = trim((string) $request->query->get('q', ''));
        $centre = $this->centreFiltre($request);

        $requete = $this->candidatures->queryListeNationale(
            $centre,
            $recherche,
            self::ONGLETS[$onglet]['statuts'],
            self::ONGLETS[$onglet]['etude'] ?? null
        );

        return $this->render('admin/candidature/index.html.twig', [
            'pagination' => $paginator->paginate($requete, $request->query->getInt('page', 1), 25),
            'onglets' => self::ONGLETS,
            'onglet_actif' => $onglet,
            'recherche' => $recherche,
            'filtre_centre' => $centre,
            'centres' => $this->centres->findTousTries(),
        ]);
    }

    private function centreFiltre(Request $request): ?Centre
    {
        $id = $request->query->getInt('centre');

        return $id > 0 ? $this->centres->find($id) : null;
    }
}
