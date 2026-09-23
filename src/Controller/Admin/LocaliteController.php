<?php

namespace App\Controller\Admin;

use App\Entity\DirectionRegionale;
use App\Entity\Localite;
use App\Form\Referentiel\LocaliteType;
use App\Repository\DirectionRegionaleRepository;
use App\Repository\LocaliteRepository;
use App\Service\Referentiel\ReferentielGuard;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Écran E2.2 : CRUD des localités, filtrable par direction régionale (F2.2). */
#[Route('/admin/localites', name: 'app_admin_localite_')]
#[IsGranted('ROLE_ADMIN')]
class LocaliteController extends AbstractController
{
    public function __construct(
        private readonly LocaliteRepository $repository,
        private readonly DirectionRegionaleRepository $directionRegionaleRepository,
        private readonly ReferentielGuard $guard,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, PaginatorInterface $paginator): Response
    {
        $recherche = trim((string) $request->query->get('q', ''));
        $filtreDr = $this->directionRegionaleFiltree($request);

        $localite = new Localite();
        $localite->setDirectionRegionale($filtreDr);

        $form = $this->createForm(LocaliteType::class, $localite);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->repository->save($localite, true);
            $this->addFlash('success', 'La localité a été enregistrée.');

            return $this->redirectToRoute('app_admin_localite_index', $this->parametresDeRetour($request));
        }

        return $this->render('admin/localite/index.html.twig', [
            'pagination' => $paginator->paginate(
                $this->repository->queryListe($filtreDr, $recherche),
                $request->query->getInt('page', 1),
                25
            ),
            'form' => $form->createView(),
            'recherche' => $recherche,
            'directions_regionales' => $this->directionRegionaleRepository->findToutesTriees(),
            'filtre_dr' => $filtreDr,
        ]);
    }

    #[Route('/{id}/modifier', name: 'modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Localite $localite): Response
    {
        $form = $this->createForm(LocaliteType::class, $localite);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->repository->save($localite, true);
            $this->addFlash('success', 'La localité a été modifiée.');

            return $this->redirectToRoute('app_admin_localite_index');
        }

        return $this->render('admin/localite/modifier.html.twig', [
            'localite' => $localite,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/supprimer', name: 'supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, Localite $localite): Response
    {
        if (!$this->isCsrfTokenValid('supprimer_localite_' . $localite->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_admin_localite_index');
        }

        // R2.1 : une localité portant des centres n'est pas supprimable.
        $this->guard->verifierSuppressionLocalite($localite);

        $this->repository->remove($localite, true);
        $this->addFlash('success', 'La localité a été supprimée.');

        return $this->redirectToRoute('app_admin_localite_index');
    }

    private function directionRegionaleFiltree(Request $request): ?DirectionRegionale
    {
        $id = $request->query->getInt('dr');

        return $id > 0 ? $this->directionRegionaleRepository->find($id) : null;
    }

    /**
     * Conserve le filtre actif après un enregistrement, pour que
     * l'administrateur reste dans la direction régionale qu'il saisit.
     *
     * @return array<string, int>
     */
    private function parametresDeRetour(Request $request): array
    {
        $id = $request->query->getInt('dr');

        return $id > 0 ? ['dr' => $id] : [];
    }
}
