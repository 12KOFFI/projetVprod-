<?php

namespace App\Controller\JuryLocal;

use App\Dto\CandidatureDepotDto;
use App\Dto\RecevabiliteDto;
use App\Entity\Candidature;
use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Form\RecevabiliteType;
use App\Repository\CandidatureRepository;
use App\Repository\HistoriqueStatutRepository;
use App\Security\Voter\CandidatureVoter;
use App\Service\Candidature\CandidatureService;
use App\Service\Candidature\CandidatureStatusResolver;
use App\Service\Candidature\RecevabiliteService;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace du jury local : décision de recevabilité des dossiers inscrits.
 *
 * Le jury local est rattaché à un centre. Il reçoit les dossiers dont l'étude
 * a été acceptée par le conseiller VAE et dont les frais de dossier sont
 * réglés (statut INSCRIT), et prononce leur recevabilité. Le périmètre des
 * listes est porté par CandidatureRepository, le droit sur un dossier précis
 * par CandidatureVoter::STATUER_RECEVABILITE.
 */
#[Route('/jury-local', name: 'app_jury_local_')]
#[IsGranted('ROLE_JURY_LOCAL')]
class JuryLocalController extends AbstractController
{
    public function __construct(
        private readonly CandidatureRepository $candidatures,
        private readonly CandidatureService $candidatureService,
        private readonly RecevabiliteService $recevabilite,
        private readonly CandidatureStatusResolver $resolver,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(Request $request, PaginatorInterface $paginator): Response
    {
        $centre = $this->juryLocal()->getCentre();

        if ($centre === null) {
            return $this->render('jury_local/sans_centre.html.twig');
        }

        $vue = $request->query->get('vue') === CandidatureRepository::JURY_STATUES
            ? CandidatureRepository::JURY_STATUES
            : CandidatureRepository::JURY_A_STATUER;
        $recherche = trim((string) $request->query->get('q', ''));

        return $this->render('jury_local/dashboard.html.twig', [
            'centre' => $centre,
            'compteurs' => $this->candidatures->compteursJuryLocal($centre),
            'vue' => $vue,
            'recherche' => $recherche,
            'pagination' => $paginator->paginate(
                $this->candidatures->queryListeJuryLocal($centre, $vue, $recherche),
                $request->query->getInt('page', 1),
                25
            ),
        ]);
    }

    /**
     * Fiche du dossier en lecture seule et, tant qu'il est INSCRIT, formulaire
     * de recevabilité. Une décision enregistrée ramène à la liste des dossiers
     * à statuer : le jury enchaîne les dossiers d'une même séance.
     */
    #[Route('/candidature/{id}', name: 'candidature', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function candidature(Request $request, Candidature $candidature, HistoriqueStatutRepository $historiques): Response
    {
        $this->denyAccessUnlessGranted(CandidatureVoter::VIEW, $candidature);

        $formulaire = null;

        if ($this->resolver->resolve($candidature) === StatutCandidature::INSCRIT
            && $this->isGranted(CandidatureVoter::STATUER_RECEVABILITE, $candidature)) {
            $dto = RecevabiliteDto::depuisCandidature($candidature);
            $formulaire = $this->createForm(RecevabiliteType::class, $dto, [
                'action' => $this->generateUrl('app_jury_local_candidature', ['id' => $candidature->getId()]),
            ]);
            $formulaire->handleRequest($request);

            if ($formulaire->isSubmitted() && $formulaire->isValid()) {
                // Les règles de fond (dossier INSCRIT, décision unique) sont
                // revérifiées dans le service ; une exception métier y devient
                // un message par l'ExceptionSubscriber.
                $this->recevabilite->enregistrerRecevabilite($candidature, $dto, $this->juryLocal());

                $this->addFlash('success', sprintf(
                    'Dossier %s déclaré « %s ».',
                    (string) $candidature->getNumero(),
                    (string) $dto->statut?->libelle()
                ));

                return $this->redirectToRoute('app_jury_local_dashboard');
            }
        }

        return $this->render('jury_local/candidature.html.twig', [
            'candidature' => $candidature,
            'historique' => $historiques->findPourCandidature($candidature),
            'documents' => $this->candidatureService->piecesTransmises($candidature),
            'pieces_attendues' => CandidatureDepotDto::champsDocuments(),
            'form_recevabilite' => $formulaire?->createView(),
        ]);
    }

    private function juryLocal(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
