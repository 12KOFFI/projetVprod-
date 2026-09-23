<?php

namespace App\Controller\Admin;

use App\Entity\DirectionRegionale;
use App\Form\Referentiel\DirectionRegionaleType;
use App\Repository\DirectionRegionaleRepository;
use App\Service\Referentiel\ReferentielGuard;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Écran E2.1 : CRUD des directions régionales (F2.1). */
#[Route('/admin/directions-regionales', name: 'app_admin_direction_regionale_')]
#[IsGranted('ROLE_ADMIN')]
class DirectionRegionaleController extends AbstractController
{
    public function __construct(
        private readonly DirectionRegionaleRepository $repository,
        private readonly ReferentielGuard $guard,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, PaginatorInterface $paginator): Response
    {
        $recherche = trim((string) $request->query->get('q', ''));

        $directionRegionale = new DirectionRegionale();
        $form = $this->createForm(DirectionRegionaleType::class, $directionRegionale);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->repository->save($directionRegionale, true);
            $this->addFlash('success', 'La direction régionale a été enregistrée.');

            return $this->redirectToRoute('app_admin_direction_regionale_index');
        }

        return $this->render('admin/direction_regionale/index.html.twig', [
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
    public function modifier(Request $request, DirectionRegionale $directionRegionale): Response
    {
        $form = $this->createForm(DirectionRegionaleType::class, $directionRegionale);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->repository->save($directionRegionale, true);
            $this->addFlash('success', 'La direction régionale a été modifiée.');

            return $this->redirectToRoute('app_admin_direction_regionale_index');
        }

        return $this->render('admin/direction_regionale/modifier.html.twig', [
            'direction_regionale' => $directionRegionale,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/supprimer', name: 'supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, DirectionRegionale $directionRegionale): Response
    {
        if (!$this->isCsrfTokenValid('supprimer_dr_' . $directionRegionale->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_admin_direction_regionale_index');
        }

        // Lève SuppressionInterditeException si des localités sont rattachées (R2.2) ;
        // l'ExceptionSubscriber la transforme en message flash.
        $this->guard->verifierSuppressionDirectionRegionale($directionRegionale);

        $this->repository->remove($directionRegionale, true);
        $this->addFlash('success', 'La direction régionale a été supprimée.');

        return $this->redirectToRoute('app_admin_direction_regionale_index');
    }
}
