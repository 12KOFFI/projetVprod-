<?php

namespace App\Controller;

use App\Entity\Centre;
use App\Entity\User;
use App\Repository\CentreRepository;
use App\Security\Role;
use App\Service\Statistique\StatistiqueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Écran de pilotage chiffré, partagé par tous les rôles qui encadrent le
 * dispositif.
 *
 * Un seul gabarit sert l'administration, le conseiller, l'agent d'accueil et
 * l'accompagnateur : c'est le PÉRIMÈTRE, appliqué par le repository, qui change
 * le contenu, jamais la présentation. Le candidat en est écarté par
 * security.yaml — des statistiques sur un unique dossier n'auraient pas de sens,
 * et son suivi d'étapes remplit déjà ce rôle.
 */
#[Route('/indicateurs', name: 'app_indicateurs', methods: ['GET'])]
class IndicateursController extends AbstractController
{
    public function __construct(
        private readonly StatistiqueService $statistiques,
        private readonly CentreRepository $centres,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $utilisateur */
        $utilisateur = $this->getUser();

        $estAdmin = $this->isGranted(Role::ADMIN);

        // Le filtre par centre n'a de sens qu'au national : pour les autres
        // rôles, le périmètre du repository impose déjà le centre, et offrir le
        // sélecteur laisserait croire qu'on peut en changer.
        $centre = $estAdmin ? $this->centreFiltre($request) : null;

        $parStatut = [];
        foreach ($this->statistiques->parStatut($utilisateur, $centre) as $ligne) {
            if ($ligne['effectif'] > 0) {
                $parStatut[$ligne['statut']->libelle()] = $ligne['effectif'];
            }
        }

        return $this->render('indicateurs/index.html.twig', [
            'synthese' => $this->statistiques->synthese($utilisateur, $centre),
            'par_statut' => $parStatut,
            'par_sexe' => $this->statistiques->parSexe($utilisateur, $centre),
            'par_metier' => $this->statistiques->parMetier($utilisateur, $centre),
            'par_centre' => $estAdmin ? $this->statistiques->parCentre($utilisateur) : [],
            'par_region' => $estAdmin ? $this->statistiques->parDirectionRegionale($utilisateur) : [],
            'tableau_croise' => $estAdmin ? $this->statistiques->tableauCroise() : null,
            'est_admin' => $estAdmin,
            'centres' => $estAdmin ? $this->centres->findTousTries() : [],
            'filtre_centre' => $centre,
            'perimetre' => $this->libellePerimetre($utilisateur, $centre, $estAdmin),
        ]);
    }

    private function centreFiltre(Request $request): ?Centre
    {
        $id = $request->query->getInt('centre');

        return $id > 0 ? $this->centres->find($id) : null;
    }

    /**
     * Sous-titre du bandeau : l'utilisateur doit lire d'emblée sur quelles
     * données portent les chiffres affichés.
     */
    private function libellePerimetre(User $utilisateur, ?Centre $centre, bool $estAdmin): string
    {
        if ($estAdmin) {
            return $centre?->getNom() ?? 'Vue nationale, tous centres confondus.';
        }

        if ($this->isGranted(Role::ACCOMPAGNATEUR) && !$this->isGranted(Role::CONSEILLER)) {
            return 'Les candidats dont vous assurez l\'accompagnement.';
        }

        return $utilisateur->getCentre()?->getNom() ?? 'Aucun centre de rattachement.';
    }
}
