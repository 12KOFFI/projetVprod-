<?php

namespace App\Controller\Accueil;

use App\Dto\CandidatureDepotDto;
use App\Entity\Candidature;
use App\Entity\Centre;
use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Form\CandidatureDepotType;
use App\Form\UserType;
use App\Repository\CandidatureRepository;
use App\Repository\CertificationRepository;
use App\Repository\HistoriqueStatutRepository;
use App\Repository\UserRepository;
use App\Security\Voter\CandidatureVoter;
use App\Service\Candidature\CandidatureService;
use App\Service\Candidature\InscriptionAssisteeService;
use App\Service\Candidature\NumeroVaeGenerator;
use App\Service\Candidature\OptionsFormulaireDepot;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace de l'agent d'accueil (écrans E3.6 à E3.10).
 *
 * L'agent ne travaille que dans son propre centre : ce périmètre est imposé par
 * le serveur à chaque écran, et jamais déduit d'un paramètre de requête.
 */
#[Route('/accueil', name: 'app_accueil_')]
#[IsGranted('ROLE_AGENT_ACCEUIL')]
class AccueilController extends AbstractController
{
    public function __construct(
        private readonly CandidatureRepository $candidatureRepository,
        private readonly UserRepository $userRepository,
        private readonly CertificationRepository $certifications,
        private readonly OptionsFormulaireDepot $optionsFormulaire,
        private readonly CandidatureService $candidatureService,
        private readonly InscriptionAssisteeService $inscriptionAssistee,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $centre = $this->agent()->getCentre();

        if ($centre === null) {
            return $this->render('accueil/sans_centre.html.twig');
        }

        return $this->render('accueil/dashboard.html.twig', [
            'centre' => $centre,
            'total' => $this->candidatureRepository->countParCentre($centre),
            'aujourdhui' => $this->candidatureRepository->compterDeposesAujourdhui($centre),
            'par_statut' => $this->candidatureRepository->compterParStatut($this->agent(), $centre),
            'conseillers' => $this->conseillersAvecCharge($centre),
        ]);
    }

    #[Route('/candidatures', name: 'candidatures', methods: ['GET'])]
    public function candidatures(Request $request, PaginatorInterface $paginator): Response
    {
        $centre = $this->agent()->getCentre();

        if ($centre === null) {
            return $this->render('accueil/sans_centre.html.twig');
        }

        $recherche = trim((string) $request->query->get('q', ''));
        $statut = $request->query->getInt('statut') ?: null;

        return $this->render('accueil/candidatures.html.twig', [
            'centre' => $centre,
            'pagination' => $paginator->paginate(
                $this->candidatureRepository->queryListeDuCentre($centre, $recherche, $statut),
                $request->query->getInt('page', 1),
                25
            ),
            'recherche' => $recherche,
            'filtre_statut' => $statut,
            'statuts' => $this->libellesStatuts(),
        ]);
    }

    #[Route('/candidature/{id}', name: 'candidature', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function candidature(Request $request, Candidature $candidature, HistoriqueStatutRepository $historiques): Response
    {
        $this->denyAccessUnlessGranted(CandidatureVoter::VIEW, $candidature);

        // Tant que le dossier reste modifiable (R3.10), l'agent d'accueil arrive
        // directement sur le formulaire plutôt que sur une fiche en lecture seule
        // qu'il faudrait quitter pour corriger le dossier. L'action « Consulter »
        // des listes demande explicitement la lecture seule.
        if ($request->query->get('mode') !== 'lecture' && $this->isGranted(CandidatureVoter::EDIT, $candidature)) {
            return $this->redirectToRoute('app_accueil_candidature_modifier', ['id' => $candidature->getId()]);
        }

        return $this->render('accueil/candidature.html.twig', [
            'candidature' => $candidature,
            'historique' => $historiques->findPourCandidature($candidature),
            'documents' => $this->documents($candidature),
        ]);
    }

    #[Route('/candidature/{id}/modifier', name: 'candidature_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Candidature $candidature): Response
    {
        // Même règle que côté candidat : modifiable tant que l'étude n'est pas acceptée (R3.10).
        $this->denyAccessUnlessGranted(CandidatureVoter::EDIT, $candidature);

        $dto = CandidatureDepotDto::depuisCandidature(
            $candidature,
            $this->certifications->findOneByLibelle((string) $candidature->getDiplomedemande())
        );

        $form = $this->createForm(
            CandidatureDepotType::class,
            $dto,
            $this->optionsFormulaire->pourModification($candidature, $request, centreImpose: true)
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $dto->documents = $this->collecterDocuments($form);
            $manquants = $this->candidatureService->documentsManquants($dto->documents, $candidature);

            if ($manquants === []) {
                $this->candidatureService->mettreAJour($candidature, $dto, $this->agent());
                $this->addFlash('success', sprintf('Le dossier %s a été mis à jour.', (string) $candidature->getNumero()));

                // Pas de retour vers /candidature/{id} : le dossier reste modifiable, donc
                // cette route y renverrait aussitôt — la liste est la vraie destination.
                // L'administrateur n'a pas de centre : sa liste est la liste nationale.
                return $this->redirectToRoute($this->isGranted('ROLE_ADMIN') ? 'app_admin_candidature_index' : 'app_accueil_candidatures');
            }

            $this->signalerDocumentsManquants($form, $manquants);
        }

        return $this->render('accueil/candidature_form.html.twig', [
            'form' => $form->createView(),
            'candidature' => $candidature,
            'documents_obligatoires' => CandidatureDepotDto::documentsObligatoires(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function libellesStatuts(): array
    {
        // L'agent d'accueil enregistre et étudie la préinscription ; la
        // recevabilité et la suite du parcours relèvent du conseiller, donc
        // seuls ces deux statuts ont leur place dans ce filtre.
        $essentiels = [
            StatutCandidature::PREINSCRIT,
            StatutCandidature::INSCRIT,
        ];

        $libelles = [];

        foreach ($essentiels as $statut) {
            $libelles[$statut->value] = $statut->libelle();
        }

        return $libelles;
    }

    #[Route('/conseillers', name: 'conseillers', methods: ['GET'])]
    public function conseillers(): Response
    {
        $centre = $this->agent()->getCentre();

        if ($centre === null) {
            return $this->render('accueil/sans_centre.html.twig');
        }

        return $this->render('accueil/conseillers.html.twig', [
            'centre' => $centre,
            'conseillers' => $this->conseillersAvecCharge($centre),
        ]);
    }

    #[Route('/inscription', name: 'inscription', methods: ['GET', 'POST'])]
    public function inscription(Request $request): Response
    {
        $agent = $this->agent();
        $centre = $agent->getCentre();

        if ($centre === null) {
            return $this->render('accueil/sans_centre.html.twig');
        }

        $candidat = (new User())->setNationalite("COTE D'IVOIRE");
        $formCandidat = $this->createForm(UserType::class, $candidat, [
            'is_register' => true,
            'mot_de_passe_obligatoire' => true,
        ]);

        // Le centre n'est pas un champ du formulaire en inscription assistée : il
        // est posé ici pour que la validation du DTO dispose du couple complet
        // (centre, métier), et réimposé par le service à la soumission (R3.7).
        $dto = new CandidatureDepotDto();
        $dto->centre = $centre;

        $formDossier = $this->createForm(
            CandidatureDepotType::class,
            $dto,
            $this->optionsFormulaire->pourDepotAssiste($centre, $request)
        );

        $formCandidat->handleRequest($request);
        $formDossier->handleRequest($request);

        if ($formCandidat->isSubmitted() && $formDossier->isSubmitted()
            && $formCandidat->isValid() && $formDossier->isValid()) {
            $this->normaliserIdentifiants($candidat);

            if ($this->identifiantsDejaPris($candidat, $formCandidat)) {
                // Les erreurs sont portées par les champs concernés.
            } else {
                $dto->documents = $this->collecterDocuments($formDossier);
                $manquants = $this->candidatureService->documentsManquants($dto->documents);

                if ($manquants === []) {
                    $candidature = $this->inscriptionAssistee->inscrire(
                        $candidat,
                        $dto,
                        $agent,
                        (string) $formCandidat->get('password')->getData()
                    );

                    $this->addFlash('success', sprintf(
                        'Dossier enregistré sous le numéro <strong class="font-mono">%s</strong>. '
                        . 'Le candidat se connecte avec son email ou son téléphone et le mot de passe choisi.',
                        htmlspecialchars((string) $candidature->getNumero(), \ENT_QUOTES)
                    ));

                    return $this->redirectToRoute('app_accueil_candidatures');
                }

                $this->signalerDocumentsManquants($formDossier, $manquants);
            }
        }

        return $this->render('accueil/inscription.html.twig', [
            'form_candidat' => $formCandidat->createView(),
            'form_dossier' => $formDossier->createView(),
            'centre' => $centre,
            'documents_obligatoires' => CandidatureDepotDto::documentsObligatoires(),
        ]);
    }

    #[Route('/reprise', name: 'reprise', methods: ['GET'])]
    public function reprise(Request $request): Response
    {
        $agent = $this->agent();
        $centre = $agent->getCentre();

        if ($centre === null) {
            return $this->render('accueil/sans_centre.html.twig');
        }

        // Recherche d'un dossier déjà déposé par son numéro VAE, limitée au centre de l'agent.
        $numeroSaisi = trim((string) $request->query->get('numero', ''));

        if ($numeroSaisi !== '') {
            $numero = NumeroVaeGenerator::normaliser($numeroSaisi);
            $trouvee = NumeroVaeGenerator::estValide($numero)
                ? $this->candidatureRepository->findOneBy(['numero' => $numero, 'centre' => $centre])
                : null;

            if ($trouvee !== null) {
                return $this->redirectToRoute('app_accueil_candidature', ['id' => $trouvee->getId()]);
            }

            $this->addFlash('error', NumeroVaeGenerator::estValide($numero)
                ? sprintf('Aucun dossier de votre centre ne porte le numéro « %s ».', $numero)
                : sprintf('« %s » n\'est pas un numéro VAE valide.', $numeroSaisi));
        }
        return $this->render('accueil/reprise.html.twig', [
            'numero' => $numeroSaisi,
            'centre' => $centre,
        ]);
    }

    /**
     * Conseillers du centre et nombre de dossiers dont ils ont la charge,
     * afin que l'agent oriente le candidat vers un conseiller peu chargé.
     *
     * @return list<array{conseiller: User, charge: int}>
     */
    private function conseillersAvecCharge(Centre $centre): array
    {
        $lignes = [];

        foreach ($this->userRepository->findConseillersParCentre($centre) as $conseiller) {
            $lignes[] = [
                'conseiller' => $conseiller,
                'charge' => $this->candidatureRepository->compterEnChargePourConseiller($conseiller),
            ];
        }

        return $lignes;
    }

    /**
     * Pièces transmises avec le dossier, pour la consultation.
     *
     * @return array<string, array{libelle: string, fichier: string}>
     */
    private function documents(Candidature $candidature): array
    {
        $documents = [];

        foreach (CandidatureDepotDto::documentsObligatoires() as $champ => $libelle) {
            $fichier = $candidature->{'get' . ucfirst($champ)}();

            if (!in_array($fichier, [null, ''], true)) {
                $documents[$champ] = ['libelle' => $libelle, 'fichier' => $fichier];
            }
        }

        return $documents;
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

    private function normaliserIdentifiants(User $candidat): void
    {
        $candidat->setEmail(strtolower(trim((string) $candidat->getEmail())) ?: null);
        $candidat->setContact(preg_replace('/\D+/', '', (string) $candidat->getContact()) ?: null);
    }

    private function identifiantsDejaPris(User $candidat, FormInterface $form): bool
    {
        $pris = false;

        if ($candidat->getEmail() !== null && $this->userRepository->emailDejaUtilise($candidat->getEmail())) {
            $form->get('email')->addError(new FormError('Cette adresse est déjà utilisée par un autre compte.'));
            $pris = true;
        }

        if ($candidat->getContact() !== null
            && $this->userRepository->findOneBy(['contact' => $candidat->getContact()]) !== null) {
            $form->get('contact')->addError(new FormError('Ce numéro est déjà utilisé par un autre compte.'));
            $pris = true;
        }

        return $pris;
    }

    private function agent(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
