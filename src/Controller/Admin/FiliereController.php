<?php

namespace App\Controller\Admin;

use App\Entity\Filiere;
use App\Form\Referentiel\FiliereType;
use App\Repository\FiliereRepository;
use App\Service\Referentiel\ReferentielGuard;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Écran E2.3 : CRUD des filières (F2.3). */
#[Route('/admin/filieres', name: 'app_admin_filiere_')]
#[IsGranted('ROLE_ADMIN')]
class FiliereController extends AbstractController
{
    public function __construct(
        private readonly FiliereRepository $repository,
        private readonly ReferentielGuard $guard,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, PaginatorInterface $paginator): Response
    {
        $recherche = trim((string) $request->query->get('q', ''));

        $filiere = new Filiere();
        $form = $this->createForm(FiliereType::class, $filiere);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->repository->save($filiere, true);
            $this->addFlash('success', 'La filière a été enregistrée.');

            return $this->redirectToRoute('app_admin_filiere_index');
        }

        return $this->render('admin/filiere/index.html.twig', [
            'pagination' => $paginator->paginate(
                $this->repository->queryListe($recherche),
                $request->query->getInt('page', 1),
                25
            ),
            'form' => $form->createView(),
            'recherche' => $recherche,
        ]);
    }

    #[Route('/{id}/modifier', name: 'modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Filiere $filiere): Response
    {
        $form = $this->createForm(FiliereType::class, $filiere);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->repository->save($filiere, true);
            $this->addFlash('success', 'La filière a été modifiée.');

            return $this->redirectToRoute('app_admin_filiere_index');
        }

        return $this->render('admin/filiere/modifier.html.twig', [
            'filiere' => $filiere,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/supprimer', name: 'supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, Filiere $filiere): Response
    {
        if (!$this->isCsrfTokenValid('supprimer_filiere_' . $filiere->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_admin_filiere_index');
        }

        // R2.3 : une filière portant des métiers n'est pas supprimable.
        $this->guard->verifierSuppressionFiliere($filiere);

        $this->repository->remove($filiere, true);
        $this->addFlash('success', 'La filière a été supprimée.');

        return $this->redirectToRoute('app_admin_filiere_index');
    }
}
