<?php

namespace App\Controller\Accompagnement;

use App\Dto\CandidatureDepotDto;
use App\Entity\Candidature;
use App\Entity\User;
use App\Form\PreuveLivretType;
use App\Repository\CandidatureRepository;
use App\Repository\PreuveLivretRepository;
use App\Security\Voter\CandidatureVoter;
use App\Service\Accompagnement\AccompagnementService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Espace de l'accompagnateur (étape 5 du parcours).
 *
 * Il ne voit que les candidats qui l'ont choisi ET ont réglé les frais
 * d'accompagnement (Candidature::$accompagnateur) ; chaque fiche est en plus
 * contrôlée par CandidatureVoter::ACCOMPAGNER. Il aide le candidat à
 * constituer son livret de preuves.
 */
#[Route('/accompagnement', name: 'app_accompagnement_')]
class AccompagnementController extends AbstractController
{
    public function __construct(
        private readonly CandidatureRepository $candidatures,
        private readonly PreuveLivretRepository $preuves,
        private readonly AccompagnementService $accompagnement,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $accompagnateur = $this->accompagnateur();
        $suivis = $this->candidatures->findAccompagnesPar($accompagnateur);
        $nbPreuves = $this->preuves->compterParCandidature($suivis);

        return $this->render('accompagnement/dashboard.html.twig', [
            'accompagnateur' => $accompagnateur,
            'suivis' => $suivis,
            'nb_preuves' => $nbPreuves,
            'total_preuves' => array_sum($nbPreuves),
            'sans_preuve' => count(array_filter($suivis, static fn (Candidature $c) => ($nbPreuves[$c->getId()] ?? 0) === 0)),
        ]);
    }

    #[Route('/candidat/{id}', name: 'candidat', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function candidat(Request $request, Candidature $candidature): Response
    {
        $this->denyAccessUnlessGranted(CandidatureVoter::ACCOMPAGNER, $candidature);

        $formulaire = $this->createForm(PreuveLivretType::class);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $this->denyAccessUnlessGranted(CandidatureVoter::LIVRET_DEPOSER, $candidature);
            $this->accompagnement->deposerPreuve(
                $candidature,
                $formulaire->get('fichier')->getData(),
                (string) $formulaire->get('titre')->getData(),
                $formulaire->get('description')->getData(),
                $this->accompagnateur()
            );
            $this->addFlash('success', 'Preuve ajoutée au livret.');

            return $this->redirectToRoute('app_accompagnement_candidat', ['id' => $candidature->getId()]);
        }

        $pieces = [];
        foreach (CandidatureDepotDto::champsDocuments() as $champ => $libelle) {
            if (!in_array($candidature->{'get' . ucfirst($champ)}(), [null, ''], true)) {
                $pieces[$champ] = $libelle;
            }
        }

        return $this->render('accompagnement/candidat.html.twig', [
            'candidature' => $candidature,
            'pieces' => $pieces,
            'preuves' => $this->preuves->findPourCandidature($candidature),
            'formulaire' => $formulaire->createView(),
        ], new Response(null, $formulaire->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function accompagnateur(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
