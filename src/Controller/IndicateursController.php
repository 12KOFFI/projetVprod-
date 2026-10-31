<?php

namespace App\Controller;

use App\Dto\FiltresPilotage;
use App\Entity\Centre;
use App\Entity\User;
use App\Security\Role;
use App\Service\Statistique\PilotageService;
use App\Service\Statistique\StatistiqueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Écran de pilotage chiffré, partagé par tous les rôles qui encadrent le
 * dispositif.
 *
 * Un seul gabarit sert l'administration, le conseiller, le jury local et
 * l'accompagnateur : c'est le PÉRIMÈTRE, appliqué par le repository, qui change
 * le contenu. Le candidat et l'agent d'accueil en sont écartés par
 * security.yaml.
 *
 * L'administration dispose en plus des filtres en cascade (direction
 * régionale → localité → centre, filière → métier, sexe, période), lus dans
 * l'URL. Une requête faite par le navigateur en arrière-plan (filtre appliqué
 * sans rechargement) ne reçoit que le contenu de la page.
 */
#[Route('/indicateurs', name: 'app_indicateurs', methods: ['GET'])]
// Même règle que access_control, redoublée au plus près de l'action.
#[IsGranted(new Expression("is_granted('ROLE_CONSEILLER') or is_granted('ROLE_ACCOMPAGNATEUR') or is_granted('ROLE_JURY_LOCAL')"))]
class IndicateursController extends AbstractController
{
    /** Étapes montrées à l'accompagnateur : ses candidats sont au moins éligibles. */
    private const ETAPES_ACCOMPAGNATEUR = ['eligibles', 'admissibles', 'admis'];

    public function __construct(
        private readonly StatistiqueService $statistiques,
        private readonly PilotageService $pilotage,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var User $utilisateur */
        $utilisateur = $this->getUser();

        if ($this->isGranted(Role::ADMIN)) {
            $filtres = FiltresPilotage::depuisRequete($request);
            $gabarit = $request->isXmlHttpRequest() ? 'indicateurs/_contenu.html.twig' : 'indicateurs/index.html.twig';

            return $this->render($gabarit, $this->pilotage->vueIndicateurs($utilisateur, $filtres) + [
                'est_admin' => true,
                'filtres' => $filtres,
            ]);
        }

        $parStatut = [];
        foreach ($this->statistiques->parStatut($utilisateur) as $ligne) {
            if ($ligne['effectif'] > 0) {
                $parStatut[$ligne['statut']->libelle()] = $ligne['effectif'];
            }
        }

        $indicateurs = $this->pilotage->indicateursParcours($utilisateur);
        $total = $indicateurs[0]['effectif'];
        if (Role::principal($utilisateur) === Role::ACCOMPAGNATEUR) {
            $indicateurs = array_values(array_filter($indicateurs, static fn (array $i): bool => in_array($i['cle'], self::ETAPES_ACCOMPAGNATEUR, true)));
        }

        return $this->render('indicateurs/index.html.twig', [
            'est_admin' => false,
            'total' => $total,
            'indicateurs' => $indicateurs,
            'par_statut' => $parStatut,
            'par_sexe' => $this->statistiques->parSexe($utilisateur),
            'par_metier' => $this->statistiques->parMetier($utilisateur),
            'perimetre' => $this->libellePerimetre($utilisateur),
        ]);
    }

    /**
     * Sous-titre du bandeau : l'utilisateur doit lire d'emblée sur quelles
     * données portent les chiffres affichés.
     */
    private function libellePerimetre(User $utilisateur): string
    {
        if (Role::principal($utilisateur) === Role::ACCOMPAGNATEUR) {
            return 'Les candidats dont vous assurez l\'accompagnement.';
        }

        $centre = $utilisateur->getCentre();

        return $centre instanceof Centre ? (string) $centre->getNom() : 'Aucun centre de rattachement.';
    }
}
