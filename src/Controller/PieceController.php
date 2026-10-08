<?php

namespace App\Controller;

use App\Dto\CandidatureDepotDto;
use App\Entity\Candidature;
use App\Entity\PreuveLivret;
use App\Security\Voter\CandidatureVoter;
use App\Service\Accompagnement\AccompagnementService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Seul point d'accès aux pièces justificatives d'un dossier.
 *
 * Les fichiers sont rangés hors du dossier public (stockage/pieces/{numéro}/) :
 * le serveur web ne peut plus les servir directement, et chaque
 * téléchargement passe par CandidatureVoter :
 *   - VIEW pour les pièces déposées par le candidat ;
 *   - VIEW_PIECES_CONSEILLER pour le justificatif d'expérience, que l'agent
 *     d'accueil ne voit pas.
 */
class PieceController extends AbstractController
{
    public function __construct(
        #[Autowire('%dir_media%')]
        private readonly string $dossierPieces,
    ) {
    }

    #[Route('/pieces/{id}/{champ}', name: 'app_piece', requirements: ['id' => '\d+', 'champ' => '[a-z]+'], methods: ['GET'])]
    public function telecharger(Candidature $candidature, string $champ): BinaryFileResponse
    {
        $libelles = CandidatureDepotDto::champsDocuments();

        // Le nom du champ vient de l'URL : seule une liste fermée est acceptée,
        // ce qui exclut toute lecture d'un autre fichier du dossier.
        if (!array_key_exists($champ, $libelles)) {
            throw $this->createNotFoundException('Pièce inconnue.');
        }

        $this->denyAccessUnlessGranted(CandidatureVoter::VIEW, $candidature);

        if (array_key_exists($champ, CandidatureDepotDto::documentsConseiller())) {
            $this->denyAccessUnlessGranted(CandidatureVoter::VIEW_PIECES_CONSEILLER, $candidature);
        }

        $nomFichier = (string) $candidature->{'get' . ucfirst($champ)}();
        $chemin = rtrim($this->dossierPieces, '/\\') . \DIRECTORY_SEPARATOR . $candidature->getNumero()
            . \DIRECTORY_SEPARATOR . basename($nomFichier);

        if ($nomFichier === '' || !is_file($chemin)) {
            throw $this->createNotFoundException('Pièce absente du dossier.');
        }

        $extension = strtolower(pathinfo($chemin, \PATHINFO_EXTENSION));
        $nomAffiche = sprintf('%s-%s.%s', $candidature->getNumero(), (new AsciiSlugger('fr'))->slug($libelles[$champ])->lower(), $extension);

        return $this->servir($chemin, $nomAffiche);
    }

    /**
     * Preuve du livret : visible par le candidat, son accompagnateur, le
     * conseiller du centre et l'administrateur (LIVRET_VOIR).
     */
    #[Route('/pieces/livret/{id}', name: 'app_preuve', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function preuve(PreuveLivret $preuve, AccompagnementService $accompagnement): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted(CandidatureVoter::LIVRET_VOIR, $preuve->getCandidature());

        $chemin = $accompagnement->cheminPreuve($preuve);
        if (!is_file($chemin)) {
            throw $this->createNotFoundException('Preuve absente.');
        }

        return $this->servir($chemin, sprintf('%s-preuve-%d.%s', $preuve->getCandidature()->getNumero(), $preuve->getId(), pathinfo($chemin, \PATHINFO_EXTENSION)));
    }

    /**
     * Retrait d'une preuve : par son seul auteur (ou l'administrateur), tant
     * que le livret reste ouvert au dépôt.
     */
    #[Route('/pieces/livret/{id}/supprimer', name: 'app_preuve_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimerPreuve(Request $request, PreuveLivret $preuve, AccompagnementService $accompagnement): RedirectResponse
    {
        $candidature = $preuve->getCandidature();
        $retour = $this->isGranted('ROLE_ACCOMPAGNATEUR') && !$this->isGranted('ROLE_ADMIN')
            ? $this->generateUrl('app_accompagnement_candidat', ['id' => $candidature->getId()])
            : $this->generateUrl('app_candidat_accompagnement');

        if (!$this->isCsrfTokenValid('supprimer_preuve_' . $preuve->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, veuillez réessayer.');

            return $this->redirect($retour);
        }

        $this->denyAccessUnlessGranted(CandidatureVoter::LIVRET_DEPOSER, $candidature);
        $estAuteur = $preuve->getDeposePar() !== null && $preuve->getDeposePar() === $this->getUser();
        if (!$estAuteur && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException("Seul l'auteur d'une preuve peut la retirer.");
        }

        $accompagnement->supprimerPreuve($preuve);
        $this->addFlash('success', 'Preuve retirée du livret.');

        return $this->redirect($retour);
    }

    private function servir(string $chemin, string $nomAffiche): BinaryFileResponse
    {
        $reponse = new BinaryFileResponse($chemin);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $nomAffiche);
        $reponse->setPrivate();
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->headers->addCacheControlDirective('no-store');

        return $reponse;
    }
}
