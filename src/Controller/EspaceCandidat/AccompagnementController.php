<?php

namespace App\Controller\EspaceCandidat;

use App\Entity\Candidature;
use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Enum\TypeFrais;
use App\Form\PreuveLivretType;
use App\Repository\CandidatureRepository;
use App\Repository\PaiementRepository;
use App\Repository\PreuveLivretRepository;
use App\Repository\UserRepository;
use App\Security\Voter\CandidatureVoter;
use App\Service\Accompagnement\AccompagnementService;
use App\Service\Candidature\CandidatureStatusResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Accompagnement côté candidat : choix de l'accompagnateur après
 * l'éligibilité, puis livret de preuves une fois l'accompagnateur affecté.
 */
#[Route('/espace-candidat', name: 'app_candidat_')]
class AccompagnementController extends AbstractController
{
    public function __construct(
        private readonly CandidatureRepository $candidatures,
        private readonly AccompagnementService $accompagnement,
        private readonly PreuveLivretRepository $preuves,
        private readonly PaiementRepository $paiements,
        private readonly CandidatureStatusResolver $resolver,
    ) {
    }

    #[Route('/accompagnement', name: 'accompagnement', methods: ['GET', 'POST'])]
    public function accompagnement(Request $request): Response
    {
        $candidature = $this->candidatures->findDernierePourCandidat($this->candidat());
        if ($candidature === null) {
            return $this->redirectToRoute('app_candidat_dashboard');
        }
        $this->denyAccessUnlessGranted(CandidatureVoter::VIEW, $candidature);

        $formulaire = null;
        if ($this->isGranted(CandidatureVoter::LIVRET_DEPOSER, $candidature)) {
            $formulaire = $this->createForm(PreuveLivretType::class);
            $formulaire->handleRequest($request);

            if ($formulaire->isSubmitted() && $formulaire->isValid()) {
                $this->accompagnement->deposerPreuve(
                    $candidature,
                    $formulaire->get('fichier')->getData(),
                    (string) $formulaire->get('titre')->getData(),
                    $formulaire->get('description')->getData(),
                    $this->candidat()
                );
                $this->addFlash('success', 'Preuve ajoutée à votre livret.');

                return $this->redirectToRoute('app_candidat_accompagnement');
            }
        }

        return $this->render('espace_candidat/accompagnement.html.twig', [
            'candidature' => $candidature,
            'eligible' => $this->resolver->resolve($candidature) === StatutCandidature::ELIGIBLE,
            'peut_choisir' => $this->accompagnement->peutChoisir($candidature),
            'disponibles' => $this->accompagnement->peutChoisir($candidature) ? $this->accompagnement->accompagnateursDisponibles($candidature) : [],
            'frais_regles' => $this->paiements->existeReussi($candidature, TypeFrais::ACCOMPAGNEMENT),
            'preuves' => $candidature->getAccompagnateur() !== null ? $this->preuves->findPourCandidature($candidature) : [],
            'formulaire' => $formulaire?->createView(),
        ], new Response(null, $formulaire !== null && $formulaire->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/accompagnement/choisir', name: 'accompagnement_choisir', methods: ['POST'])]
    public function choisir(Request $request, UserRepository $utilisateurs): Response
    {
        $candidature = $this->candidatures->findDernierePourCandidat($this->candidat());
        if ($candidature === null) {
            return $this->redirectToRoute('app_candidat_dashboard');
        }
        $this->denyAccessUnlessGranted(CandidatureVoter::PAY, $candidature);

        if (!$this->isCsrfTokenValid('choisir_accompagnateur', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_candidat_accompagnement');
        }

        $accompagnateur = $utilisateurs->find($request->request->getInt('accompagnateur'));
        if (!$accompagnateur instanceof User) {
            $this->addFlash('error', 'Choisissez un accompagnateur dans la liste.');

            return $this->redirectToRoute('app_candidat_accompagnement');
        }

        // Les règles (éligibilité, centre et métier de l'accompagnateur) sont
        // revérifiées par le service ; une exception devient un message.
        $this->accompagnement->choisir($candidature, $accompagnateur);
        $this->addFlash('success', sprintf(
            'Vous avez choisi %s. Réglez les frais d\'accompagnement pour qu\'il suive votre dossier.',
            $accompagnateur->getNomComplet()
        ));

        return $this->redirectToRoute('app_candidat_paiement_payer', ['type' => TypeFrais::ACCOMPAGNEMENT->value]);
    }

    private function candidat(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
