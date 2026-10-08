<?php

namespace App\Controller\EspaceCandidat;

use App\Entity\Candidature;
use App\Entity\Paiement;
use App\Entity\User;
use App\Enum\MoyenPaiement;
use App\Enum\StatutCandidature;
use App\Enum\TypeFrais;
use App\Exception\PaiementException;
use App\Repository\CandidatureRepository;
use App\Repository\PaiementRepository;
use App\Security\Voter\CandidatureVoter;
use App\Service\Impression\FicheInscriptionAssets;
use App\Service\Paiement\PaiementService;
use App\Service\PdfGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Règlements du candidat et documents qui en découlent (écrans E5.1 à E5.5).
 *
 * Seul le candidat propriétaire paie ses propres frais : la garde est portée
 * par CandidatureVoter::PAY, y compris pour l'administrateur.
 */
#[Route('/espace-candidat', name: 'app_candidat_')]
#[IsGranted('ROLE_CANDIDAT')]
class PaiementController extends AbstractController
{
    public function __construct(
        private readonly CandidatureRepository $candidatureRepository,
        private readonly PaiementRepository $paiementRepository,
        private readonly PaiementService $paiementService,
        private readonly FicheInscriptionAssets $ficheAssets,
    ) {
    }

    #[Route('/paiements', name: 'paiements', methods: ['GET'])]
    public function index(): Response
    {
        $candidature = $this->candidatureActive();

        if ($candidature === null) {
            $this->addFlash('info', "Vous n'avez pas encore déposé de dossier de candidature.");

            return $this->redirectToRoute('app_candidat_dashboard');
        }

        $this->denyAccessUnlessGranted(CandidatureVoter::VIEW, $candidature);

        return $this->render('espace_candidat/paiements.html.twig', [
            'candidature' => $candidature,
            'frais' => $this->paiementService->tableauDeBord($candidature),
            'simulation' => $this->paiementService->passerelleEstSimulee(),
            'fiche_disponible' => $this->ficheDisponible($candidature),
        ]);
    }

    #[Route('/paiements/{type}/payer', name: 'paiement_payer', methods: ['GET', 'POST'])]
    public function payer(Request $request, string $type): Response
    {
        $candidature = $this->candidatureActive();

        if ($candidature === null) {
            return $this->redirectToRoute('app_candidat_dashboard');
        }

        $this->denyAccessUnlessGranted(CandidatureVoter::PAY, $candidature);

        $typeFrais = TypeFrais::tryFrom($type);

        if ($typeFrais === null) {
            throw $this->createNotFoundException('Type de frais inconnu.');
        }

        // Garde d'affichage ; celle qui fait foi est appliquée par le service à
        // la soumission, qu'aucun formulaire posté ne contourne (R5.4).
        if ($this->paiementRepository->existeReussi($candidature, $typeFrais)) {
            $this->addFlash('info', sprintf('Les %s ont déjà été réglés.', mb_strtolower($typeFrais->libelle())));

            return $this->redirectToRoute('app_candidat_paiements');
        }

        // Frais pas encore dus : pas de formulaire de paiement à l'écran. Le
        // service refait ce contrôle à la soumission (R5.5).
        if (!$this->paiementService->estDebloque($candidature, $typeFrais)) {
            $this->addFlash('info', sprintf(
                'Les %s ne peuvent pas encore être réglés : votre dossier n\'a pas atteint l\'étape correspondante.',
                mb_strtolower($typeFrais->libelle())
            ));

            return $this->redirectToRoute('app_candidat_paiements');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('paiement_' . $typeFrais->value, (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Action expirée, veuillez réessayer.');

                return $this->redirectToRoute('app_candidat_paiement_payer', ['type' => $typeFrais->value]);
            }

            $moyen = MoyenPaiement::tryFrom((string) $request->request->get('moyen', ''));

            if ($moyen === null || !$moyen->estProposable()) {
                throw PaiementException::moyenNonSupporte((string) $request->request->get('moyen', ''));
            }

            $paiement = $this->paiementService->initier(
                $candidature,
                $typeFrais,
                $moyen,
                $this->candidat(),
                $this->generateUrl('app_candidat_paiement_retour', [], UrlGeneratorInterface::ABSOLUTE_URL)
            );

            // Passerelle réelle : le candidat règle sur la page du fournisseur,
            // qui le renverra sur la page de retour. La démo confirme sur place.
            $urlPasserelle = $this->paiementService->urlPasserelle($paiement);
            if ($urlPasserelle !== null) {
                return $this->redirect($urlPasserelle);
            }

            return $this->redirectToRoute('app_candidat_paiement_retour', ['reference' => $paiement->getReferencePaiement()]);
        }

        return $this->render('espace_candidat/paiement_payer.html.twig', [
            'candidature' => $candidature,
            'type' => $typeFrais,
            'montant' => $this->paiementService->tarif($typeFrais),
            'moyens' => MoyenPaiement::proposables(),
            'simulation' => $this->paiementService->passerelleEstSimulee(),
        ]);
    }

    #[Route('/paiements/retour', name: 'paiement_retour', methods: ['GET'])]
    public function retour(Request $request): Response
    {
        $reference = trim((string) $request->query->get('reference', ''));
        $paiement = $reference !== '' ? $this->paiementRepository->findOneBy(['referencePaiement' => $reference]) : null;

        if ($paiement === null) {
            $this->addFlash('error', 'Ce règlement est introuvable.');

            return $this->redirectToRoute('app_candidat_paiements');
        }

        $this->denyAccessUnlessGranted(CandidatureVoter::PAY, $paiement->getCandidature());

        // Passerelle réelle : au retour du candidat, l'état est redemandé au
        // fournisseur (la notification serveur peut ne pas être encore arrivée).
        // Jamais avec la démo, dont la vérification répond toujours « réussi ».
        if (!$paiement->estReussi() && !$this->paiementService->passerelleEstSimulee()) {
            try {
                $this->paiementService->confirmer($paiement);
            } catch (\Throwable) {
                $this->addFlash('info', 'Le Trésor n\'a pas encore confirmé votre règlement : actualisez cette page dans quelques instants.');
            }
        }

        // L'état affiché provient de la base, jamais d'un paramètre d'URL : le
        // retour du navigateur ne prouve rien (règle de sécurité S2).
        return $this->render('espace_candidat/paiement_retour.html.twig', [
            'paiement' => $paiement,
            'candidature' => $paiement->getCandidature(),
            'fiche_disponible' => $this->ficheDisponible($paiement->getCandidature()),
        ]);
    }

    #[Route('/paiements/{id}/recu', name: 'paiement_recu', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function recu(Paiement $paiement, PdfGenerator $pdf): Response
    {
        $this->denyAccessUnlessGranted(CandidatureVoter::PAY, $paiement->getCandidature());

        if (!$paiement->estReussi()) {
            $this->addFlash('error', 'Le reçu n\'est disponible qu\'une fois le règlement abouti.');

            return $this->redirectToRoute('app_candidat_paiements');
        }

        return $pdf->stream('print/recu.html.twig', [
            'paiement' => $paiement,
            'candidature' => $paiement->getCandidature(),
            'candidat' => $paiement->getCandidature()?->getUser(),
            'edite_le' => new \DateTime(),
        ]);
    }

    /**
     * Fiche d'inscription, émise après le règlement des frais de dossier
     * (F5.8) : c'est la contrepartie du paiement 1 promise au candidat.
     */
    #[Route('/fiche-inscription', name: 'fiche_inscription', methods: ['GET'])]
    public function ficheInscription(PdfGenerator $pdf): Response
    {
        $candidature = $this->candidatureActive();

        if ($candidature === null) {
            return $this->redirectToRoute('app_candidat_dashboard');
        }

        // PRINT_FICHE exige que le dossier ait atteint le statut INSCRIT :
        // la fiche ne peut donc pas être obtenue avant le règlement confirmé.
        $this->denyAccessUnlessGranted(CandidatureVoter::PRINT_FICHE, $candidature);

        return $pdf->stream('print/fiche_inscription.html.twig', [
            'candidature' => $candidature,
            'candidat' => $candidature->getUser(),
            'paiement' => $this->paiementRepository->findParTypePourCandidature($candidature, TypeFrais::DOSSIER),
            'edite_le' => new \DateTime(),
            'logo' => $this->ficheAssets->logo(),
            'photo_candidat' => $this->ficheAssets->photoCandidat($candidature),
        ]);
    }

    /**
     * La fiche n'existe qu'une fois l'inscription acquise : les écrans
     * n'affichent le lien que si le voter l'accorde.
     */
    private function ficheDisponible(?Candidature $candidature): bool
    {
        return $candidature !== null
            && $this->isGranted(CandidatureVoter::PRINT_FICHE, $candidature);
    }

    /**
     * Le dernier dossier du candidat, même clos : ses reçus et sa fiche
     * doivent rester accessibles après une décision défavorable.
     */
    private function candidatureActive(): ?Candidature
    {
        return $this->candidatureRepository->findDernierePourCandidat($this->candidat());
    }

    private function candidat(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
