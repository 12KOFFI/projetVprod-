<?php

namespace App\Controller\Admin;

use App\Entity\Filiere;
use App\Entity\Metier;
use App\Form\Referentiel\MetierType;
use App\Repository\FiliereRepository;
use App\Repository\MetierRepository;
use App\Service\Referentiel\ReferentielGuard;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Écran E2.4 : CRUD des métiers, filtrable par filière et par statut (F2.4). */
#[Route('/admin/metiers', name: 'app_admin_metier_')]
#[IsGranted('ROLE_ADMIN')]
class MetierController extends AbstractController
{
    public function __construct(
        private readonly MetierRepository $repository,
        private readonly FiliereRepository $filiereRepository,
        private readonly ReferentielGuard $guard,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, PaginatorInterface $paginator): Response
    {
        $recherche = trim((string) $request->query->get('q', ''));
        $statut = $this->statutFiltre($request);
        $filtreFiliere = $this->filiereFiltree($request);

        $metier = new Metier();
        $metier->setFiliere($filtreFiliere);

        $form = $this->createForm(MetierType::class, $metier);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->repository->save($metier, true);
            $this->addFlash('success', 'Le métier a été enregistré.');

            return $this->redirectToRoute('app_admin_metier_index');
        }

        return $this->render('admin/metier/index.html.twig', [
            'pagination' => $paginator->paginate(
                $this->repository->queryListe($filtreFiliere, $statut, $recherche),
                $request->query->getInt('page', 1),
                25
            ),
            'form' => $form->createView(),
            'recherche' => $recherche,
            'filieres' => $this->filiereRepository->findToutesTriees(),
            'filtre_filiere' => $filtreFiliere,
            'filtre_statut' => $statut,
        ]);
    }

    #[Route('/{id}/modifier', name: 'modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Metier $metier): Response
    {
        $form = $this->createForm(MetierType::class, $metier);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->repository->save($metier, true);
            $this->addFlash('success', 'Le métier a été modifié.');

            return $this->redirectToRoute('app_admin_metier_index');
        }

        return $this->render('admin/metier/modifier.html.twig', [
            'metier' => $metier,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Voie normale de retrait d'un métier (R2.4 et R2.7) : l'inactivation le
     * retire des formulaires de candidature sans toucher aux dossiers déposés.
     */
    #[Route('/{id}/basculer-statut', name: 'basculer_statut', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function basculerStatut(Request $request, Metier $metier): Response
    {
        if (!$this->isCsrfTokenValid('statut_metier_' . $metier->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_admin_metier_index');
        }

        $metier->setStatut($metier->getStatut() === 'actif' ? 'inactif' : 'actif');
        $this->repository->save($metier, true);

        $this->addFlash('success', sprintf(
            'Le métier « %s » est désormais %s.',
            (string) $metier->getLibelle(),
            $metier->getStatut() === 'actif' ? 'actif' : 'inactif'
        ));

        return $this->redirectToRoute('app_admin_metier_index');
    }

    #[Route('/{id}/supprimer', name: 'supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, Metier $metier): Response
    {
        if (!$this->isCsrfTokenValid('supprimer_metier_' . $metier->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_admin_metier_index');
        }

        // R2.4 : offre de centre, candidature ou accompagnateur rattaché bloquent
        // la suppression et orientent vers l'inactivation.
        $this->guard->verifierSuppressionMetier($metier);

        $this->repository->remove($metier, true);
        $this->addFlash('success', 'Le métier a été supprimé.');

        return $this->redirectToRoute('app_admin_metier_index');
    }

    private function filiereFiltree(Request $request): ?Filiere
    {
        $id = $request->query->getInt('filiere');

        return $id > 0 ? $this->filiereRepository->find($id) : null;
    }

    private function statutFiltre(Request $request): ?string
    {
        $statut = (string) $request->query->get('statut', '');

        return in_array($statut, ['actif', 'inactif'], true) ? $statut : null;
    }
}
