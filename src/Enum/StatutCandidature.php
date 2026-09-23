<?php

namespace App\Enum;

/**
 * Machine à états du dossier de candidature VAE.
 *
 * Les valeurs sont volontairement espacées (dizaines) pour permettre
 * l'insertion d'états intermédiaires sans migration de données.
 *
 * Statut global du dossier. Il n'est stocké nulle part : il se calcule à la
 * demande par App\Service\Candidature\CandidatureStatusResolver, à partir des
 * décisions métier portées par Candidature (etu_statut, rec_statut,
 * elig_statut, resultat, admis) et du règlement des frais de dossier. Les
 * décisions s'enregistrent via TransitionCandidature::appliquer(), qui vérifie
 * ici que la décision est permise à l'étape atteinte.
 *
 * L'étude du dossier ne crée pas de statut : un dossier accepté reste
 * PREINSCRIT (etu_statut = ACCEPTE) jusqu'au paiement confirmé des frais de
 * dossier, qui le rend INSCRIT. Seul un dossier INSCRIT entre en recevabilité.
 *
 * Référence : specifications_vae.txt, section 6 « Points techniques à prévoir ».
 */
enum StatutCandidature: int
{
    case PREINSCRIT             = 10;
    case INSCRIT                = 30;
    case DOSSIER_NON_RECEVABLE  = 40;
    case DOSSIER_RECEVABLE      = 41;
    case NON_ELIGIBLE           = 50;
    case ELIGIBLE                = 51;
    case NON_ADMISSIBLE          = 70;
    case ADMISSIBLE              = 71;
    case NON_ADMIS_DEFINITIF     = 80;
    case ADMIS_DEFINITIF         = 81;

    public function libelle(): string
    {
        return match ($this) {
            self::PREINSCRIT             => 'Préinscrit',
            self::INSCRIT                => 'Inscrit',
            self::DOSSIER_NON_RECEVABLE  => 'Dossier non recevable',
            self::DOSSIER_RECEVABLE      => 'Dossier recevable',
            self::NON_ELIGIBLE           => 'Non éligible',
            self::ELIGIBLE                => 'Éligible',
            self::NON_ADMISSIBLE          => 'Non admissible',
            self::ADMISSIBLE              => 'Admissible',
            self::NON_ADMIS_DEFINITIF     => 'Non admis',
            self::ADMIS_DEFINITIF         => 'Admis définitif',
        };
    }

    /**
     * Couleur sémantique Tailwind du badge, conformément à frontend-design.md :
     * green = validé, blue = intermédiaire positif, amber = en cours,
     * red = refusé, slate = neutre/attente.
     */
    public function couleur(): string
    {
        return match ($this) {
            self::PREINSCRIT             => 'slate',
            self::INSCRIT                => 'indigo',
            self::DOSSIER_NON_RECEVABLE,
            self::NON_ELIGIBLE,
            self::NON_ADMISSIBLE,
            self::NON_ADMIS_DEFINITIF     => 'red',
            self::DOSSIER_RECEVABLE,
            self::ELIGIBLE                => 'blue',
            self::ADMISSIBLE              => 'blue',
            self::ADMIS_DEFINITIF         => 'green',
        };
    }

    public function estTerminal(): bool
    {
        return in_array($this, [
            self::DOSSIER_NON_RECEVABLE,
            self::NON_ELIGIBLE,
            self::NON_ADMISSIBLE,
            self::NON_ADMIS_DEFINITIF,
            self::ADMIS_DEFINITIF,
        ], true);
    }

    /**
     * Transitions autorisées depuis cet état.
     *
     * @return list<self>
     */
    public function transitionsAutorisees(): array
    {
        return match ($this) {
            // Garde complémentaire dans TransitionCandidature : l'étude doit
            // avoir été acceptée (etu_statut) pour franchir ce passage.
            self::PREINSCRIT              => [self::INSCRIT],
            self::INSCRIT                 => [self::DOSSIER_RECEVABLE, self::DOSSIER_NON_RECEVABLE],
            self::DOSSIER_RECEVABLE       => [self::ELIGIBLE, self::NON_ELIGIBLE],
            self::ELIGIBLE                 => [self::ADMISSIBLE, self::NON_ADMISSIBLE],
            self::ADMISSIBLE               => [self::ADMIS_DEFINITIF, self::NON_ADMIS_DEFINITIF],
            default                        => [],
        };
    }

    public function peutAllerVers(self $cible): bool
    {
        return in_array($cible, $this->transitionsAutorisees(), true);
    }

    /**
     * Étapes affichées au candidat dans le menu « Suivi des étapes ».
     * L'ordre correspond au parcours chronologique de la spécification.
     *
     * @return list<self>
     */
    public static function etapesParcours(): array
    {
        return [
            self::PREINSCRIT,
            self::INSCRIT,
            self::DOSSIER_RECEVABLE,
            self::ELIGIBLE,
            self::ADMISSIBLE,
            self::ADMIS_DEFINITIF,
        ];
    }
}
