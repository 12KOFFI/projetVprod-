<?php

namespace App\Controller\EspaceCandidat;

use App\Dto\CandidatureDepotDto;
use App\Entity\Candidature;
use App\Entity\User;
use App\Form\CandidatureDepotType;
use App\Repository\CandidatureRepository;
use App\Repository\CertificationRepository;
use App\Security\Voter\CandidatureVoter;
use App\Service\Candidature\CandidatureService;
use App\Service\Candidature\OptionsFormulaireDepot;
use App\Service\Candidature\SuiviParcours;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace personnel du candidat (écrans E3.1 à E3.5).
 *
 * Le candidat ne voit et ne modifie que son propre dossier : la restriction est
 * portée par CandidatureVoter et par le repository, jamais par le template.
 */
#[Route('/espace-candidat', name: 'app_candidat_')]
#[IsGranted('ROLE_CANDIDAT')]
class CandidatureController extends AbstractController
{
    public function __construct(
        private readonly CandidatureRepository $candidatureRepository,
        private readonly CandidatureService $candidatureService,
        private readonly CertificationRepository $certifications,
        private readonly OptionsFormulaireDepot $optionsFormulaire,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(SuiviParcours $suivi): Response
    {
        // Le dernier dossier, et non le dossier actif : une démarche close par
        // un refus reste consultable par son candidat.
        $candidature = $this->candidatureRepository->findDernierePourCandidat($this->candidat());

        return $this->render('espace_candidat/dashboard.html.twig', [
            'candidature' => $candidature,
            'etapes' => $candidature !== null ? $suivi->etapes($candidature) : [],
            'prochaine_action' => $candidature !== null ? $suivi->prochaineAction($candidature) : null,
            'peut_deposer' => $this->candidatureService->peutDeposer($this->candidat()),
        ]);
    }

    #[Route('/candidature/nouvelle', name: 'candidature_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelle(Request $request): Response
    {
        $candidat = $this->candidat();

        // Double vérification voulue (R3.1) : à l'affichage pour éviter un
        // formulaire condamné d'avance, et à la soumission dans le service,
        // qu'aucun formulaire posté ne peut contourner.
        if (!$this->candidatureService->peutDeposer($candidat)) {
            $this->addFlash('error', 'Vous avez déjà un dossier de candidature en cours.');

            return $this->redirectToRoute('app_candidat_dashboard');
        }

        $dto = new CandidatureDepotDto();
        $form = $this->createForm(
            CandidatureDepotType::class,
            $dto,
            $this->optionsFormulaire->pourDepot($request)
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $dto->documents = $this->collecterDocuments($form);
            $manquants = $this->candidatureService->documentsManquants($dto->documents);

            if ($manquants === []) {
                $candidature = $this->candidatureService->deposer($dto, $candidat, $candidat);

                return $this->redirectToRoute('app_candidat_candidature_succes', ['id' => $candidature->getId()]);
            }

            $this->signalerDocumentsManquants($form, $manquants);
        }

        return $this->render('espace_candidat/candidature_form.html.twig', [
            'form' => $form->createView(),
            'candidature' => null,
            'documents_obligatoires' => CandidatureDepotDto::documentsObligatoires(),
        ]);
    }

    #[Route('/candidature/succes/{id}', name: 'candidature_succes', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function succes(Candidature $candidature): Response
    {
        $this->denyAccessUnlessGranted(CandidatureVoter::VIEW, $candidature);

        return $this->render('espace_candidat/candidature_succes.html.twig', [
            'candidature' => $candidature,
        ]);
    }

    #[Route('/candidature/{id}/modifier', name: 'candidature_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Candidature $candidature): Response
    {
        // Le voter refuse dès que le dossier est entré en instruction (R3.10).
        $this->denyAccessUnlessGranted(CandidatureVoter::EDIT, $candidature);

        $dto = CandidatureDepotDto::depuisCandidature(
            $candidature,
            $this->certifications->findOneByLibelle((string) $candidature->getDiplomedemande())
        );
        $form = $this->createForm(
            CandidatureDepotType::class,
            $dto,
            $this->optionsFormulaire->pourModification($candidature, $request)
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $dto->documents = $this->collecterDocuments($form);
            $manquants = $this->candidatureService->documentsManquants($dto->documents, $candidature);

            if ($manquants === []) {
                $this->candidatureService->mettreAJour($candidature, $dto, $this->candidat());
                $this->addFlash('success', 'Votre dossier a été mis à jour.');

                return $this->redirectToRoute('app_candidat_dashboard');
            }

            $this->signalerDocumentsManquants($form, $manquants);
        }

        return $this->render('espace_candidat/candidature_form.html.twig', [
            'form' => $form->createView(),
            'candidature' => $candidature,
            'documents_obligatoires' => CandidatureDepotDto::documentsObligatoires(),
        ]);
    }

    /**
     * Pièces transmises, indexées par champ d'entité.
     *
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
     * Métiers proposables : la liste dépend du centre, qui n'est connu qu'une
     * fois celui-ci choisi. À la première ouverture, la liste est vide et
     * l'écran la peuple via l'endpoint JSON.
     *
     * @return \App\Entity\Metier[]
     */

    /**
     * @return \App\Entity\Metier[]
     */

    private function candidat(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
