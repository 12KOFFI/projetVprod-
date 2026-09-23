<?php

namespace App\Controller\Admin;

use App\Entity\Centre;
use App\Entity\DirectionRegionale;
use App\Entity\Localite;
use App\Form\Referentiel\CentreType;
use App\Repository\CentreRepository;
use App\Repository\DirectionRegionaleRepository;
use App\Repository\LocaliteRepository;
use App\Service\Referentiel\ReferentielGuard;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Écran E2.5 : CRUD des centres, filtrable par direction régionale et localité (F2.5). */
#[Route('/admin/centres', name: 'app_admin_centre_')]
#[IsGranted('ROLE_ADMIN')]
class CentreController extends AbstractController
{
    public function __construct(
        private readonly CentreRepository $repository,
        private readonly DirectionRegionaleRepository $directionRegionaleRepository,
        private readonly LocaliteRepository $localiteRepository,
        private readonly ReferentielGuard $guard,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, PaginatorInterface $paginator): Response
    {
        $recherche = trim((string) $request->query->get('q', ''));
        $filtreDr = $this->directionRegionaleFiltree($request);
        $filtreLocalite = $this->localiteFiltree($request);
        $filtreType = $this->typeFiltre($request);

        $centre = new Centre();
        $centre->setLocalite($filtreLocalite);

        $form = $this->createForm(CentreType::class, $centre);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->repository->save($centre, true);
            $this->addFlash('success', 'Le centre a été enregistré.');

            return $this->redirectToRoute('app_admin_centre_index');
        }

        return $this->render('admin/centre/index.html.twig', [
            'pagination' => $paginator->paginate(
                $this->repository->queryListe($filtreDr, $filtreLocalite, $filtreType, $recherche),
                $request->query->getInt('page', 1),
                25
            ),
            'form' => $form->createView(),
            'recherche' => $recherche,
            'directions_regionales' => $this->directionRegionaleRepository->findToutesTriees(),
            'localites' => $filtreDr !== null
                ? $this->localiteRepository->findParDirectionRegionale($filtreDr)
                : $this->localiteRepository->findToutesTriees(),
            'filtre_dr' => $filtreDr,
            'filtre_localite' => $filtreLocalite,
            'filtre_type' => $filtreType,
        ]);
    }

    #[Route('/{id}/modifier', name: 'modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Centre $centre): Response
    {
        $form = $this->createForm(CentreType::class, $centre);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->repository->save($centre, true);
            $this->addFlash('success', 'Le centre a été modifié.');

            return $this->redirectToRoute('app_admin_centre_index');
        }

        return $this->render('admin/centre/modifier.html.twig', [
            'centre' => $centre,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/supprimer', name: 'supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, Centre $centre): Response
    {
        if (!$this->isCsrfTokenValid('supprimer_centre_' . $centre->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_admin_centre_index');
        }

        // R2.5 : candidatures, comptes rattachés ou offre au catalogue bloquent.
        $this->guard->verifierSuppressionCentre($centre);

        $this->repository->remove($centre, true);
        $this->addFlash('success', 'Le centre a été supprimé.');

        return $this->redirectToRoute('app_admin_centre_index');
    }

    private function directionRegionaleFiltree(Request $request): ?DirectionRegionale
    {
        $id = $request->query->getInt('dr');

        return $id > 0 ? $this->directionRegionaleRepository->find($id) : null;
    }

    private function localiteFiltree(Request $request): ?Localite
    {
        $id = $request->query->getInt('localite');

        return $id > 0 ? $this->localiteRepository->find($id) : null;
    }

    private function typeFiltre(Request $request): ?string
    {
        $type = (string) $request->query->get('type', '');

        return in_array($type, ['etablissement', 'entreprise'], true) ? $type : null;
    }
}
