<?php

namespace App\Service\Candidature;

use App\Entity\Candidature;
use App\Entity\Centre;
use App\Entity\Metier;
use App\Repository\CentreMetierRepository;
use App\Repository\CentreRepository;
use App\Repository\CertificationRepository;
use App\Repository\MetierRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Construit les listes déroulantes du formulaire de dépôt.
 *
 * Les trois champs se déterminent en cascade : le centre ouvre des métiers, et
 * le couple (centre, métier) des certifications. Comme les listes sont peuplées
 * en JavaScript, le serveur doit reconstituer à la soumission l'ensemble des
 * choix valides — sans quoi Symfony rejetterait une valeur pourtant légitime,
 * absente de la liste initialement vide.
 *
 * Ce service existe pour que cette résolution ne soit pas réécrite dans chacun
 * des quatre écrans qui servent ce formulaire (espace candidat, accueil,
 * reprise, conseiller), conformément à la règle anti-duplication D.2.
 */
class OptionsFormulaireDepot
{
    /** Préfixe des données postées par CandidatureDepotType. */
    private const RACINE_FORMULAIRE = 'candidature_depot';

    public function __construct(
        private readonly CentreRepository $centres,
        private readonly MetierRepository $metiers,
        private readonly CentreMetierRepository $offres,
        private readonly CertificationRepository $certifications,
    ) {
    }

    /**
     * Options d'un dépôt initial : tout part de ce que le formulaire a posté.
     *
     * @return array{metiers_disponibles: Metier[], certifications_disponibles: \App\Entity\Certification[]}
     */
    public function pourDepot(Request $request): array
    {
        $centre = $this->centrePoste($request);
        $metier = $this->metierPoste($request);

        return $this->listes($centre, $metier);
    }

    /**
     * Options d'une modification : les valeurs postées priment, celles du
     * dossier servent de repli au premier affichage.
     *
     * @return array{centre_impose?: ?Centre, metiers_disponibles: Metier[], certifications_disponibles: \App\Entity\Certification[]}
     */
    public function pourModification(Candidature $candidature, Request $request, bool $centreImpose = false): array
    {
        $centre = $this->centrePoste($request) ?? $candidature->getCentre();
        $metier = $this->metierPoste($request) ?? $candidature->getMetier();

        $options = $this->listes($centre, $metier);

        if ($centreImpose) {
            $options['centre_impose'] = $candidature->getCentre();
        }

        return $options;
    }

    /**
     * Options d'un dépôt assisté : le centre est celui de l'agent et ne peut
     * pas être négocié (règle métier R3.7).
     *
     * @return array{centre_impose: Centre, metiers_disponibles: Metier[], certifications_disponibles: \App\Entity\Certification[]}
     */
    public function pourDepotAssiste(Centre $centre, Request $request): array
    {
        return ['centre_impose' => $centre] + $this->listes($centre, $this->metierPoste($request));
    }

    /**
     * @return array{metiers_disponibles: Metier[], certifications_disponibles: \App\Entity\Certification[]}
     */
    private function listes(?Centre $centre, ?Metier $metier): array
    {
        if ($centre === null) {
            return ['metiers_disponibles' => [], 'certifications_disponibles' => []];
        }

        $metiersOuverts = $this->offres->findMetiersOuverts($centre);

        // Un métier hors de l'offre du centre ne doit pas ouvrir ses
        // certifications : le triplet serait invalide, et la contrainte
        // CertificationOfferte le rejetterait de toute façon.
        $certifications = $metier !== null && in_array($metier, $metiersOuverts, true)
            ? $this->certifications->findOffertesPour($centre, $metier)
            : [];

        return [
            'metiers_disponibles' => $metiersOuverts,
            'certifications_disponibles' => $certifications,
        ];
    }

    private function centrePoste(Request $request): ?Centre
    {
        $id = (int) ($request->request->all(self::RACINE_FORMULAIRE)['centre'] ?? 0);

        return $id > 0 ? $this->centres->find($id) : null;
    }

    private function metierPoste(Request $request): ?Metier
    {
        $id = (int) ($request->request->all(self::RACINE_FORMULAIRE)['metier'] ?? 0);

        return $id > 0 ? $this->metiers->find($id) : null;
    }
}
