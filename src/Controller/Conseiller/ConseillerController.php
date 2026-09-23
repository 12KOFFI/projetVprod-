<?php

namespace App\Controller\Conseiller;

use App\Dto\CandidatureDepotDto;
use App\Dto\EtudeDto;
use App\Dto\RecevabiliteDto;
use App\Entity\Candidature;
use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Exception\RecevabiliteException;
use App\Form\CandidatureDepotType;
use App\Form\EtudeType;
use App\Form\RecevabiliteType;
use App\Repository\CandidatureRepository;
use App\Repository\CertificationRepository;
use App\Repository\HistoriqueStatutRepository;
use App\Repository\PaiementRepository;
use App\Security\Voter\CandidatureVoter;
use App\Service\Candidature\CandidatureService;
use App\Service\Candidature\CandidatureStatusResolver;
use App\Service\Candidature\OptionsFormulaireDepot;
use App\Service\Candidature\RecevabiliteService;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace du conseiller VAE : étude des dossiers et décision de recevabilité.
 *
 * Le conseiller ne travaille que sur les dossiers de son centre : le périmètre
 * des listes est porté par CandidatureRepository, et l'accès à un dossier
 * précis par CandidatureVoter (règle métier R4.2).
 *
 * Les éditions et les statistiques ne vivent plus ici : elles ont rejoint les
 * écrans transverses /impressions et /indicateurs, communs à tous les rôles.
 */
#[Route('/conseiller', name: 'app_conseiller_')]
#[IsGranted('ROLE_CONSEILLER')]
class ConseillerController extends AbstractController
{
    public function __construct(
        private readonly CandidatureRepository $candidatureRepository,
        private readonly RecevabiliteService $recevabilite,
        private readonly CandidatureService $candidatureService,
        private readonly CertificationRepository $certifications,
        private readonly OptionsFormulaireDepot $optionsFormulaire,
        private readonly PaiementRepository $paiementRepository,
        private readonly CandidatureStatusResolver $resolver,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $conseiller = $this->conseiller();
        $centre = $conseiller->getCentre();

        if ($centre === null) {
            return $this->render('conseiller/sans_centre.html.twig');
        }

        return $this->render('conseiller/dashboard.html.twig', [
            'centre' => $centre,
            'compteurs' => $this->candidatureRepository->compteursConseiller($centre, $conseiller),
            'ma_charge' => $this->candidatureRepository->compterEnChargePourConseiller($conseiller),
            // Seuls les dossiers dont l'étude reste à rendre : un dossier accepté
            // attend le paiement du candidat, plus l'action du conseiller.
            'recents' => $this->candidatureRepository
                ->queryListeConseiller($centre, etude: CandidatureRepository::ETUDE_A_RENDRE)
                ->setMaxResults(5)
                ->getResult(),
        ]);
    }

    #[Route('/candidatures', name: 'candidatures', methods: ['GET'])]
    public function candidatures(Request $request, PaginatorInterface $paginator): Response
    {
        $conseiller = $this->conseiller();
        $centre = $conseiller->getCentre();

        if ($centre === null) {
            return $this->render('conseiller/sans_centre.html.twig');
        }

        $recherche = trim((string) $request->query->get('q', ''));
        $statutParam = $request->query->get('statut');
        $statut = null;
        $etuStatut = null;
        $etude = null;

        if ($statutParam === 'etu_2') {
            $etuStatut = \App\Enum\StatutEtude::ACCEPTE->value;
        } elseif ($statutParam === CandidatureRepository::ETUDE_A_RENDRE || $statutParam === CandidatureRepository::ETUDE_ACCEPTEE) {
            $etude = $statutParam;
        } elseif ($statutParam !== null && $statutParam !== '') {
            $statut = (int) $statutParam;
        }

        $portee = (string) $request->query->get('portee', 'tous');

        return $this->render('conseiller/candidatures.html.twig', [
            'centre' => $centre,
            'pagination' => $paginator->paginate(
                $this->candidatureRepository->queryListeConseiller(
                    $centre,
                    $recherche,
                    $statut,
                    $portee === 'moi' ? $conseiller : null,
                    $portee === 'non_affectes' ? true : null,
                    $etuStatut,
                    $etude
                ),
                $request->query->getInt('page', 1),
                25
            ),
            'recherche' => $recherche,
            'filtre_statut' => $statutParam,
            'portee' => $portee,
            'statuts' => $this->libellesStatuts(),
        ]);
    }

    /**
     * Page unique de consultation d'un dossier. Tant qu'il est PREINSCRIT, elle
     * s'ouvre en mode édition : le conseiller peut corriger les données du
     * dossier ET enregistrer le résultat de l'étude en une seule fois, via un
     * unique formulaire et un unique bouton (soumis vers recevabilite() ci-
     * dessous, qui ne rend plus de page propre). Une fois INSCRIT, seule la
     * décision de recevabilité reste modifiable, séparément (juryLocal()).
     */
    #[Route('/candidature/{id}', name: 'candidature', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function candidature(Request $request, Candidature $candidature, HistoriqueStatutRepository $historiques): Response
    {
        $this->denyAccessUnlessGranted(CandidatureVoter::VIEW, $candidature);

        $formCandidature = null;
        $formEtude = null;
        $formRecevabilite = null;

        // Mode édition tant que l'étude reste à rendre ; une fois acceptée, le
        // dossier reste préinscrit jusqu'au paiement mais n'est plus modifiable.
        if ($this->resolver->estEnAttenteEtude($candidature)) {
            $formCandidature = $this->createForm(
                CandidatureDepotType::class,
                CandidatureDepotDto::depuisCandidature(
                    $candidature,
                    $this->certifications->findOneByLibelle((string) $candidature->getDiplomedemande())
                ),
                $this->optionsFormulaire->pourModification($candidature, $request, centreImpose: true)
            )->createView();
            $formEtude = $this->createForm(EtudeType::class, EtudeDto::depuisCandidature($candidature))->createView();
        } elseif ($this->resolver->resolve($candidature) === StatutCandidature::INSCRIT) {
            $formRecevabilite = $this->createForm(RecevabiliteType::class, RecevabiliteDto::depuisCandidature($candidature))->createView();
        }

        return $this->render('conseiller/candidature.html.twig', [
            'candidature' => $candidature,
            'historique' => $historiques->findPourCandidature($candidature),
            'etude_rendue' => $this->recevabilite->etudeRendue($candidature),
            'documents' => $this->documents($candidature),
            'form_candidature' => $formCandidature,
            'form_etude' => $formEtude,
            'form_recevabilite' => $formRecevabilite,
            'documents_obligatoires' => CandidatureDepotDto::documentsObligatoires(),
        ]);
    }

    /**
     * Traite en une seule soumission la mise à jour du dossier ET le résultat
     * de l'étude, postés ensemble depuis un unique formulaire. N'affiche
     * jamais de page propre : en cas d'erreur, on revient sur la fiche avec
     * les deux formulaires réinjectés et leurs erreurs.
     */
    #[Route('/candidature/{id}/recevabilite', name: 'recevabilite', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function recevabilite(Request $request, Candidature $candidature, HistoriqueStatutRepository $historiques): Response
    {
        $this->denyAccessUnlessGranted(CandidatureVoter::EVALUATE_RECEVABILITE, $candidature);

        // Refus avant toute écriture : sans cette garde, un dossier déjà accepté
        // verrait ses données modifiées avant que l'étude ne soit rejetée.
        if (!$this->resolver->estEnAttenteEtude($candidature)) {
            throw RecevabiliteException::etudeImpossible((string) $candidature->getNumero());
        }

        $conseiller = $this->conseiller();

        $dtoCandidature = CandidatureDepotDto::depuisCandidature(
            $candidature,
            $this->certifications->findOneByLibelle((string) $candidature->getDiplomedemande())
        );
        $formCandidature = $this->createForm(CandidatureDepotType::class, $dtoCandidature, $this->optionsFormulaire->pourModification($candidature, $request, centreImpose: true));
        $formCandidature->handleRequest($request);

        $dtoEtude = EtudeDto::depuisCandidature($candidature);
        $formEtude = $this->createForm(EtudeType::class, $dtoEtude);
        $formEtude->handleRequest($request);

        if ($formCandidature->isSubmitted() && $formCandidature->isValid()
            && $formEtude->isSubmitted() && $formEtude->isValid()) {
            $dtoCandidature->documents = $this->collecterDocuments($formCandidature);
            $manquants = $this->candidatureService->documentsManquants($dtoCandidature->documents, $candidature);

            if ($manquants === []) {
                $this->candidatureService->mettreAJour($candidature, $dtoCandidature, $conseiller);

                // Les règles de fond sont revérifiées dans le service : une
                // exception métier y est convertie en message par l'ExceptionSubscriber.
                $this->recevabilite->etudier($candidature, $dtoEtude, $conseiller);

                $this->addFlash('success', sprintf(
                    'Dossier mis à jour et résultat d\'étude « %s » enregistré pour le dossier %s.',
                    (string) $dtoEtude->statut?->libelle(),
                    (string) $candidature->getNumero()
                ));

                return $this->redirectToRoute('app_conseiller_candidature', ['id' => $candidature->getId()]);
            }

            $this->signalerDocumentsManquants($formCandidature, $manquants);
        }

        return $this->render('conseiller/candidature.html.twig', [
            'candidature' => $candidature,
            'historique' => $historiques->findPourCandidature($candidature),
            'etude_rendue' => $this->recevabilite->etudeRendue($candidature),
            'documents' => $this->documents($candidature),
            'form_candidature' => $formCandidature->createView(),
            'form_etude' => $formEtude->createView(),
            'form_recevabilite' => null,
            'documents_obligatoires' => CandidatureDepotDto::documentsObligatoires(),
        ]);
    }

    /**
     * Traite le formulaire de recevabilité posté depuis la page du dossier.
     */
    #[Route('/candidature/{id}/jury-local', name: 'jury_local', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function juryLocal(Request $request, Candidature $candidature, HistoriqueStatutRepository $historiques): Response
    {
        $this->denyAccessUnlessGranted(CandidatureVoter::EVALUATE_RECEVABILITE, $candidature);

        $dto = RecevabiliteDto::depuisCandidature($candidature);
        $form = $this->createForm(RecevabiliteType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->recevabilite->enregistrerRecevabilite($candidature, $dto, $this->conseiller());

            $this->addFlash('success', sprintf(
                'Décision de recevabilité enregistrée pour le dossier %s.',
                (string) $candidature->getNumero()
            ));

            return $this->redirectToRoute('app_conseiller_candidature', ['id' => $candidature->getId()]);
        }

        return $this->render('conseiller/candidature.html.twig', [
            'candidature' => $candidature,
            'historique' => $historiques->findPourCandidature($candidature),
            'etude_rendue' => $this->recevabilite->etudeRendue($candidature),
            'documents' => $this->documents($candidature),
            'form_candidature' => null,
            'form_etude' => null,
            'form_recevabilite' => $form->createView(),
            'documents_obligatoires' => CandidatureDepotDto::documentsObligatoires(),
        ]);
    }

    #[Route('/candidature/{id}/prendre-en-charge', name: 'affecter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function prendreEnCharge(Request $request, Candidature $candidature): Response
    {
        $this->denyAccessUnlessGranted(CandidatureVoter::EVALUATE_RECEVABILITE, $candidature);

        if (!$this->isCsrfTokenValid('affecter_' . $candidature->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_conseiller_candidature', ['id' => $candidature->getId()]);
        }

        $this->recevabilite->affecter($candidature, $this->conseiller());

        $this->addFlash('success', sprintf('Vous avez pris en charge le dossier %s.', (string) $candidature->getNumero()));

        return $this->redirectToRoute('app_conseiller_candidature', ['id' => $candidature->getId()]);
    }

    #[Route('/mes-analyses', name: 'mes_analyses', methods: ['GET'])]
    public function mesAnalyses(Request $request): Response
    {
        // 0 signifie « en attente » (rec_statut non encore renseigné), distinct
        // de « pas de filtre » (paramètre absent).
        $filtre = $request->query->has('rec_statut') ? $request->query->getInt('rec_statut') : null;

        return $this->render('conseiller/mes_analyses.html.twig', [
            'candidatures' => $this->candidatureRepository->findInscritesDuConseiller($this->conseiller(), $filtre),
            'filtre_rec_statut' => $filtre,
        ]);
    }


    /**
     * @return array<string, \Symfony\Component\HttpFoundation\File\UploadedFile|null>
     */
    private function collecterDocuments(FormInterface $form): array
    {
        $documents = [];

        foreach (array_keys(CandidatureDepotDto::documentsObligatoires()) as $champ) {
            if ($form->has($champ)) {
                $documents[$champ] = $form->get($champ)->getData();
            }
        }

        return $documents;
    }

    /**
     * @param array<string, string> $manquants
     */
    private function signalerDocumentsManquants(FormInterface $form, array $manquants): void
    {
        foreach ($manquants as $champ => $libelle) {
            if ($form->has($champ)) {
                $form->get($champ)->addError(new FormError(sprintf('%s est obligatoire.', $libelle)));
            }
        }
    }

    /**
     * Pièces transmises avec le dossier, pour la consultation (F4.3).
     *
     * @return array<string, array{libelle: string, fichier: string}>
     */
    private function documents(Candidature $candidature): array
    {
        $libelles = [
            'fextrait' => 'Extrait de naissance',
            'fpiece' => "Pièce d'identité",
            'fexperiencepro' => "Justificatif d'expérience professionnelle",
            'fcmu' => 'Attestation CMU',
            'fphoto' => "Photo d'identité",
        ];

        $documents = [];

        foreach ($libelles as $champ => $libelle) {
            $fichier = $candidature->{'get' . ucfirst($champ)}();

            if (!in_array($fichier, [null, ''], true)) {
                $documents[$champ] = ['libelle' => $libelle, 'fichier' => $fichier];
            }
        }

        return $documents;
    }

    private function libellesStatuts(): array
    {
        // Réduit pour l'instant aux statuts que le conseiller traite lui-même
        // (étude et recevabilité) ; éligibilité/admissibilité/admission
        // arrivent par import jury et n'ont pas leur place dans ce filtre.
        $essentiels = [
            StatutCandidature::PREINSCRIT,
            StatutCandidature::INSCRIT,
            StatutCandidature::DOSSIER_RECEVABLE,
            StatutCandidature::DOSSIER_NON_RECEVABLE,
            StatutCandidature::ELIGIBLE,
            StatutCandidature::NON_ELIGIBLE,
        ];

        $libelles = [];

        foreach ($essentiels as $casStatut) {
            $libelles[(string) $casStatut->value] = $casStatut->libelle();
        }

        return $libelles;
    }

    private function conseiller(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
