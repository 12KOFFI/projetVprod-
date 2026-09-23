<?php

namespace App\Service\Candidature;

use App\Entity\Candidature;
use App\Enum\StatutCandidature;
use App\Enum\StatutEtude;
use App\Enum\TypeFrais;
use App\Repository\HistoriqueStatutRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Construit la timeline « Suivi des étapes » présentée au candidat (F3.5).
 *
 * La vue ne décide de rien : elle reçoit des étapes déjà qualifiées (franchie,
 * courante, à venir, échec) et se contente de les mettre en forme.
 */
class SuiviParcours
{
    public const FRANCHIE = 'franchie';
    public const COURANTE = 'courante';
    public const A_VENIR = 'a_venir';
    public const ECHEC = 'echec';

    public function __construct(
        private readonly HistoriqueStatutRepository $historiqueRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CandidatureStatusResolver $resolver,
    ) {
    }

    /**
     * Libellés du parcours présenté au candidat. Ils décrivent des étapes, et
     * non des statuts : le badge de statut garde, lui, StatutCandidature::libelle().
     */
    private const LIBELLES = [
        StatutCandidature::PREINSCRIT->value => 'Préinscription',
        StatutCandidature::INSCRIT->value => 'Inscription',
        StatutCandidature::DOSSIER_RECEVABLE->value => 'Recevabilité',
        StatutCandidature::ELIGIBLE->value => 'Éligibilité',
        StatutCandidature::ADMISSIBLE->value => 'Admissibilité',
        StatutCandidature::ADMIS_DEFINITIF->value => 'Admission définitive',
    ];

    /**
     * Étapes du parcours, dans l'ordre chronologique :
     * Préinscription → Étude de dossier → Inscription → Recevabilité →
     * Éligibilité → Admissibilité → Admission définitive.
     *
     * « Étude de dossier » n'est pas un statut officiel : son état vient de
     * etu_statut, le dossier restant PREINSCRIT tant que les frais de dossier
     * ne sont pas réglés.
     *
     * @return list<array{statut: ?StatutCandidature, libelle: string, etat: string, description: string, date: ?\DateTimeInterface, action: ?array{url: string, libelle: string}}>
     */
    public function etapes(Candidature $candidature): array
    {
        $courant = $this->resolver->resolve($candidature);
        $dates = $this->datesParStatut($candidature);

        $etapes = [[
            'statut' => StatutCandidature::PREINSCRIT,
            'libelle' => self::LIBELLES[StatutCandidature::PREINSCRIT->value],
            'description' => 'Dossier soumis, numéro VAE attribué.',
            'etat' => self::FRANCHIE,
            'date' => $dates[StatutCandidature::PREINSCRIT->value] ?? null,
            'action' => null,
        ]];

        $etapes[] = $this->etapeEtude($candidature);

        // Étapes portées par le statut officiel, de l'inscription à l'admission.
        $ordre = array_values(array_filter(
            StatutCandidature::etapesParcours(),
            static fn (StatutCandidature $s): bool => $s !== StatutCandidature::PREINSCRIT
        ));
        $etats = $this->qualifierToutes($ordre, $courant);

        // Préinscrit accepté : l'inscription est la prochaine étape attendue.
        if ($this->resolver->estAccepteeEnAttentePaiement($candidature)) {
            $etats[0] = self::COURANTE;
        }

        foreach ($ordre as $index => $etape) {
            $etat = $etats[$index];
            $etapes[] = [
                'statut' => $etape,
                'libelle' => self::LIBELLES[$etape->value],
                'description' => $this->description($etape, $etat),
                'etat' => $etat,
                'date' => $dates[$etape->value] ?? null,
                // Le paiement n'est ouvert que sur cette fenêtre précise (étude
                // acceptée, dossier encore PREINSCRIT) : dès qu'il est confirmé,
                // le dossier passe à INSCRIT et cette condition redevient fausse
                // d'elle-même — inutile d'interroger le paiement séparément.
                'action' => $etape === StatutCandidature::INSCRIT && $this->resolver->estAccepteeEnAttentePaiement($candidature)
                    ? ['url' => $this->urlGenerator->generate('app_candidat_paiement_payer', ['type' => TypeFrais::DOSSIER->value]), 'libelle' => 'Payer']
                    : null,
            ];
        }

        // Un refus n'appartient pas au parcours nominal : il est ajouté à la
        // suite de la dernière étape franchie pour que le candidat comprenne où
        // sa démarche s'est arrêtée.
        if ($courant->estTerminal() && $courant !== StatutCandidature::ADMIS_DEFINITIF) {
            $etapes[] = [
                'statut' => $courant,
                'libelle' => $courant->libelle(),
                'description' => $this->description($courant, self::ECHEC),
                'etat' => self::ECHEC,
                'date' => $dates[$courant->value] ?? null,
                'action' => null,
            ];
        }

        return $etapes;
    }

    /**
     * Étape « Étude de dossier », qualifiée uniquement par etu_statut : la
     * décision d'étude ne se déduit jamais du statut global.
     *
     * @return array{statut: null, libelle: string, etat: string, description: string, date: ?\DateTimeInterface, action: null}
     */
    private function etapeEtude(Candidature $candidature): array
    {
        $resultat = $candidature->getResultatEtude() ?? StatutEtude::EN_ATTENTE;

        [$etat, $description] = match ($resultat) {
            StatutEtude::ACCEPTE => [self::FRANCHIE, 'Dossier accepté par le conseiller VAE.'],
            StatutEtude::REFUSE => [self::ECHEC, 'Dossier refusé : consultez le motif, complétez votre dossier, il sera étudié à nouveau.'],
            StatutEtude::EN_ATTENTE => [self::COURANTE, 'Un conseiller VAE de votre centre étudie votre dossier.'],
        };

        return [
            'statut' => null,
            'libelle' => 'Étude de dossier — ' . $resultat->libelleParcours(),
            'description' => $description,
            'etat' => $etat,
            'date' => $resultat === StatutEtude::EN_ATTENTE ? null : $candidature->getEtuDate(),
            'action' => null,
        ];
    }

    /**
     * Action attendue du candidat à ce stade, affichée en tête de son espace.
     */
    public function prochaineAction(Candidature $candidature): ?string
    {
        $statut = $this->resolver->resolve($candidature);

        if ($statut === StatutCandidature::PREINSCRIT) {
            return match ($candidature->getResultatEtude()) {
                StatutEtude::REFUSE => "L'étude de votre dossier n'a pas abouti : consultez le motif du conseiller VAE et complétez votre dossier.",
                StatutEtude::ACCEPTE => 'Votre dossier est accepté : réglez les frais de dossier pour finaliser votre inscription.',
                default => "Votre dossier est en attente d'étude par un conseiller VAE de votre centre.",
            };
        }

        return match ($statut) {
            StatutCandidature::INSCRIT => 'Votre inscription est finalisée. Un conseiller VAE doit à présent statuer sur la recevabilité de votre dossier.',
            StatutCandidature::DOSSIER_RECEVABLE => 'Votre dossier est recevable. Le jury central doit à présent statuer sur votre éligibilité.',
            StatutCandidature::ELIGIBLE => "Vous êtes éligible : vous pouvez choisir un accompagnateur, ou vous présenter directement à l'évaluation.",
            StatutCandidature::ADMISSIBLE => 'Vous êtes admissible. Le jury central doit statuer sur votre admission définitive.',
            StatutCandidature::ADMIS_DEFINITIF => 'Félicitations, votre certification est acquise.',
            default => null,
        };
    }

    /**
     * Qualifie chaque étape du parcours en avançant d'un cran l'étape
     * « en cours » : dès que l'action attendue à une étape est accomplie (le
     * candidat a déposé son dossier, le conseiller a rendu son avis…), cette
     * étape s'affiche comme terminée plutôt que comme encore active, et c'est
     * l'étape suivante — celle qui attend maintenant une action, de
     * l'administration ou du candidat — qui devient « en cours ».
     *
     * Un statut de refus (non recevable, non éligible…) n'appartient pas à ce
     * parcours nominal : aucune étape ne lui correspond exactement, si bien
     * qu'aucun décalage n'a lieu et que la dernière étape franchie reste
     * telle quelle — le refus est ajouté séparément par etapes().
     *
     * @param list<StatutCandidature> $ordre
     *
     * @return array<int, string>
     */
    private function qualifierToutes(array $ordre, StatutCandidature $courant): array
    {
        $etats = [];
        $indexCourant = null;

        foreach ($ordre as $index => $etape) {
            if ($etape === $courant) {
                $indexCourant = $index;
                $etats[$index] = self::FRANCHIE;

                continue;
            }

            // Les valeurs de l'énumération suivent l'ordre du parcours : une
            // étape de rang inférieur au statut courant a nécessairement été
            // franchie.
            $etats[$index] = $etape->value < $courant->value ? self::FRANCHIE : self::A_VENIR;
        }

        if ($indexCourant !== null && isset($etats[$indexCourant + 1])) {
            $etats[$indexCourant + 1] = self::COURANTE;
        }

        return $etats;
    }

    /**
     * @return array<int, \DateTimeInterface>
     */
    private function datesParStatut(Candidature $candidature): array
    {
        $dates = [];

        // Seules les vraies transitions datent une étape : une décision d'étude
        // est journalisée PREINSCRIT → PREINSCRIT et écraserait sinon la date
        // de dépôt (elle est datée à part, par etu_date).
        foreach ($this->historiqueRepository->findPourCandidature($candidature) as $ligne) {
            if ($ligne->getStatutAvant() === $ligne->getStatutApres()) {
                continue;
            }

            $dates[$ligne->getStatutApres()->value] = $ligne->getCreation();
        }

        return $dates;
    }

    /**
     * Une étape encore « à venir » n'a par définition connu aucune décision :
     * lui appliquer le texte de résultat (« vous a déclaré éligible »…) donne
     * au candidat l'impression erronée qu'une décision favorable est déjà
     * acquise. Elle reçoit donc une formulation au futur, propre à annoncer
     * ce qui se passera, jamais ce qui se serait déjà passé.
     */
    private function description(StatutCandidature $statut, string $etat): string
    {
        // L'inscription en cours attend une seule chose, précisément : le
        // paiement du candidat. À venir, elle reste hypothétique (l'étude n'a
        // pas encore été acceptée), d'où une formulation différente.
        if ($etat === self::COURANTE && $statut === StatutCandidature::INSCRIT) {
            return 'En attente de paiement';
        }

        // L'étape « en cours » attend encore sa décision : même formulation au
        // futur qu'une étape à venir.
        if ($etat === self::A_VENIR || $etat === self::COURANTE) {
            return match ($statut) {
                StatutCandidature::INSCRIT => 'Votre inscription sera finalisée après règlement des frais de dossier.',
                StatutCandidature::DOSSIER_RECEVABLE => 'Un conseiller VAE statuera sur la recevabilité de votre dossier.',
                StatutCandidature::ELIGIBLE => 'Le jury central statuera sur votre éligibilité.',
                StatutCandidature::ADMISSIBLE => 'Vous serez évalué devant le jury de votre centre.',
                StatutCandidature::ADMIS_DEFINITIF => 'Le jury central statuera sur votre admission définitive.',
                default => $statut->libelle(),
            };
        }

        return match ($statut) {
            StatutCandidature::PREINSCRIT => 'Dossier soumis, numéro VAE attribué.',
            StatutCandidature::INSCRIT => 'Frais de dossier réglés, fiche d\'inscription disponible.',
            StatutCandidature::DOSSIER_RECEVABLE => 'Le conseiller VAE a déclaré votre dossier recevable.',
            StatutCandidature::DOSSIER_NON_RECEVABLE => 'Le dossier n\'a pas été jugé recevable.',
            StatutCandidature::ELIGIBLE => 'Le jury central vous a déclaré éligible.',
            StatutCandidature::NON_ELIGIBLE => 'Le jury central ne vous a pas déclaré éligible.',
            StatutCandidature::ADMISSIBLE => 'Évaluation réussie devant le jury du centre.',
            StatutCandidature::NON_ADMISSIBLE => 'L\'évaluation devant le jury n\'a pas été concluante.',
            StatutCandidature::ADMIS_DEFINITIF => 'Admission définitive prononcée par le jury central.',
            StatutCandidature::NON_ADMIS_DEFINITIF => 'Le jury central n\'a pas prononcé l\'admission définitive.',
        };
    }
}
