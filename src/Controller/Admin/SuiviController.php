<?php

namespace App\Controller\Admin;

use App\Entity\Centre;
use App\Repository\CandidatureRepository;
use App\Repository\CentreRepository;
use App\Service\Candidature\CandidatureExporter;
use App\Service\Impression\CatalogueImpressions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Listes nationales des candidats ayant franchi une étape de décision.
 *
 * L'administrateur voit l'ensemble des centres : c'est le seul rôle dont le
 * périmètre n'est pas restreint, et le centre n'est ici qu'un filtre.
 *
 * Le suivi chiffré qui vivait sur ce contrôleur est désormais réparti entre le
 * tableau de bord (entonnoir) et l'écran Indicateurs (tableau croisé).
 */
#[Route('/admin', name: 'app_admin_suivi_')]
#[IsGranted('ROLE_ADMIN')]
class SuiviController extends AbstractController
{
    public function __construct(
        private readonly CandidatureRepository $candidatures,
        private readonly CentreRepository $centres,
        private readonly CandidatureExporter $exporter,
    ) {
    }

    /**
     * Candidats ayant franchi l'étape de recevabilité.
     *
     * Les dossiers qui ont poursuivi le parcours en font partie : ils ont tous
     * été déclarés recevables à un moment donné.
     */
    #[Route('/recevables', name: 'recevables', methods: ['GET'])]
    public function recevables(Request $request): Response
    {
        return $this->liste($request, 'recevables');
    }

    /**
     * Candidats déclarés éligibles par le jury central, même logique cumulative.
     */
    #[Route('/eligibles', name: 'eligibles', methods: ['GET'])]
    public function eligibles(Request $request): Response
    {
        return $this->liste($request, 'eligibles');
    }

    /**
     * Rendu commun aux deux listes : même gabarit, seuls le titre, l'icône et
     * les statuts visés changent. La clé provient du code, jamais de la requête.
     */
    private function liste(Request $request, string $cle): Response
    {
        $definition = CatalogueImpressions::LISTES[$cle];
        $centre = $this->centreFiltre($request);

        $candidatures = $this->candidatures->findParStatuts($definition['statuts'], $centre);

        $export = $this->exporterSiDemande($request, $candidatures, $centre, $definition['libelle'], $cle);

        if ($export !== null) {
            return $export;
        }

        return $this->render('admin/suivi/liste.html.twig', [
            'candidatures' => $candidatures,
            'centres' => $this->centres->findTousTries(),
            'filtre_centre' => $centre,
            'titre' => $definition['libelle'],
            'description' => $definition['description'],
            'icone' => $definition['icone'],
            'route' => 'app_admin_suivi_' . $cle,
        ]);
    }

    /**
     * @param \App\Entity\Candidature[] $candidatures
     */
    private function exporterSiDemande(
        Request $request,
        array $candidatures,
        ?Centre $centre,
        string $titre,
        string $cle,
    ): ?Response {
        $format = (string) $request->query->get('export', '');
        $horodatage = (new \DateTime())->format('Ymd-Hi');
        $perimetre = $centre?->getNom() ?? 'Tous les centres';

        return match ($format) {
            'excel' => $this->exporter->versExcel($candidatures, sprintf('candidats-%s-%s.xlsx', $cle, $horodatage)),
            'pdf' => $this->exporter->versPdf($candidatures, $titre, $perimetre),
            default => null,
        };
    }

    private function centreFiltre(Request $request): ?Centre
    {
        $id = $request->query->getInt('centre');

        return $id > 0 ? $this->centres->find($id) : null;
    }
}
