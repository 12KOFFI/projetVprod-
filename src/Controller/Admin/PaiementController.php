<?php

namespace App\Controller\Admin;

use App\Entity\Paiement;
use App\Entity\User;
use App\Enum\StatutPaiement;
use App\Enum\TypeFrais;
use App\Repository\PaiementRepository;
use App\Repository\TransactionPaiementRepository;
use App\Service\Paiement\PaiementService;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Suivi des règlements par l'administration (écran E5.6, F5.10).
 */
#[Route('/admin/paiements', name: 'app_admin_paiement_')]
#[IsGranted('ROLE_ADMIN')]
class PaiementController extends AbstractController
{
    public function __construct(
        private readonly PaiementRepository $paiementRepository,
        private readonly PaiementService $paiementService,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, PaginatorInterface $paginator): Response
    {
        $recherche = trim((string) $request->query->get('q', ''));
        $type = TypeFrais::tryFrom((string) $request->query->get('type', ''));
        $statut = StatutPaiement::tryFrom((string) $request->query->get('statut', ''));

        return $this->render('admin/paiement/index.html.twig', [
            'pagination' => $paginator->paginate(
                $this->paiementRepository->queryListeAdmin($recherche, $type, $statut),
                $request->query->getInt('page', 1),
                25
            ),
            'recherche' => $recherche,
            'filtre_type' => $type,
            'filtre_statut' => $statut,
            'totaux' => $this->paiementRepository->totauxParType(),
            'simulation' => $this->paiementService->passerelleEstSimulee(),
        ]);
    }

    #[Route('/{id}', name: 'detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(Paiement $paiement, TransactionPaiementRepository $transactions): Response
    {
        return $this->render('admin/paiement/detail.html.twig', [
            'paiement' => $paiement,
            'candidature' => $paiement->getCandidature(),
            'transactions' => $transactions->findPourPaiement($paiement),
        ]);
    }

    #[Route('/{id}/rembourser', name: 'rembourser', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rembourser(Request $request, Paiement $paiement): Response
    {
        if (!$this->isCsrfTokenValid('rembourser_' . $paiement->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_admin_paiement_detail', ['id' => $paiement->getId()]);
        }

        /** @var User $auteur */
        $auteur = $this->getUser();

        $this->paiementService->rembourser($paiement, $auteur);

        $this->addFlash('success', sprintf(
            'Le règlement %s a été remboursé. Le dossier conserve l\'avancement déjà acquis.',
            (string) $paiement->getReferencePaiement()
        ));

        return $this->redirectToRoute('app_admin_paiement_detail', ['id' => $paiement->getId()]);
    }
}
