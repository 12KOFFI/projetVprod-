<?php

namespace App\Controller\Admin;

use App\Entity\Centre;
use App\Entity\CentreMetier;
use App\Form\Referentiel\CentreMetierType;
use App\Repository\CentreMetierRepository;
use App\Service\Referentiel\ReferentielGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Écran E2.6 : offre de formation d'un centre (F2.6).
 *
 * Le centre vient toujours de l'URL : le formulaire ne l'expose pas, et le
 * couple (centre, métier) est vérifié avant insertion pour rendre un message
 * métier plutôt qu'une violation de contrainte SQL (R2.6).
 */
#[Route('/admin/centres/{id}/metiers', name: 'app_admin_centre_metier_', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_ADMIN')]
class CentreMetierController extends AbstractController
{
    public function __construct(
        private readonly CentreMetierRepository $repository,
        private readonly ReferentielGuard $guard,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, Centre $centre): Response
    {
        $centreMetier = new CentreMetier();
        $centreMetier->setCentre($centre);

        $form = $this->createForm(CentreMetierType::class, $centreMetier);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Le centre est réaffecté après la soumission : aucune donnée du
            // client ne doit pouvoir déplacer l'offre vers un autre centre (S2).
            $centreMetier->setCentre($centre);
            $metier = $centreMetier->getMetier();

            if ($metier !== null && $this->repository->findCouple($centre, $metier) !== null) {
                $this->addFlash('error', sprintf(
                    'Le métier « %s » est déjà ouvert dans ce centre. Modifiez le nombre de places de la ligne existante.',
                    (string) $metier->getLibelle()
                ));

                return $this->redirectToRoute('app_admin_centre_metier_index', ['id' => $centre->getId()]);
            }

            $this->repository->save($centreMetier, true);
            $this->addFlash('success', 'Le métier a été ouvert dans ce centre.');

            return $this->redirectToRoute('app_admin_centre_metier_index', ['id' => $centre->getId()]);
        }

        return $this->render('admin/centre_metier/index.html.twig', [
            'centre' => $centre,
            'offre' => $this->repository->findOffreDuCentre($centre),
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{offre}/modifier', name: 'modifier', requirements: ['offre' => '\d+'], methods: ['POST'])]
    public function modifier(Request $request, Centre $centre, CentreMetier $offre): Response
    {
        if (!$this->isCsrfTokenValid('places_offre_' . $offre->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_admin_centre_metier_index', ['id' => $centre->getId()]);
        }

        $this->verifierAppartenance($centre, $offre);

        $places = $request->request->getInt('nbrplace');

        if ($places < 1) {
            $this->addFlash('error', 'Le nombre de places doit être supérieur à zéro.');

            return $this->redirectToRoute('app_admin_centre_metier_index', ['id' => $centre->getId()]);
        }

        $offre->setNbrplace($places);
        $this->repository->save($offre, true);
        $this->addFlash('success', 'Le nombre de places a été mis à jour.');

        return $this->redirectToRoute('app_admin_centre_metier_index', ['id' => $centre->getId()]);
    }

    #[Route('/{offre}/supprimer', name: 'supprimer', requirements: ['offre' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, Centre $centre, CentreMetier $offre): Response
    {
        if (!$this->isCsrfTokenValid('supprimer_offre_' . $offre->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_admin_centre_metier_index', ['id' => $centre->getId()]);
        }

        $this->verifierAppartenance($centre, $offre);

        // Bloque si des dossiers ont déjà été déposés sur ce couple.
        $this->guard->verifierSuppressionCentreMetier($offre);

        $this->repository->remove($offre, true);
        $this->addFlash('success', 'Le métier a été retiré de l\'offre de ce centre.');

        return $this->redirectToRoute('app_admin_centre_metier_index', ['id' => $centre->getId()]);
    }

    /**
     * Les deux identifiants de l'URL sont indépendants : sans ce contrôle, une
     * URL forgée modifierait l'offre d'un autre centre.
     */
    private function verifierAppartenance(Centre $centre, CentreMetier $offre): void
    {
        if ($offre->getCentre()?->getId() !== $centre->getId()) {
            throw $this->createNotFoundException();
        }
    }
}
