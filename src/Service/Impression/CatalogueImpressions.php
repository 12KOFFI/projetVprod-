<?php

namespace App\Service\Impression;

use App\Entity\User;
use App\Enum\StatutCandidature;
use App\Security\Role;

/**
 * Catalogue des éditions offertes à un utilisateur.
 *
 * C'est ICI, et non dans le gabarit, que se décide ce qu'un rôle a le droit
 * d'imprimer : Twig ne fait aucun filtrage de sécurité (règle D.1 de
 * final.txt). Le contrôleur revérifie le droit avant de produire un document,
 * de sorte qu'une URL forgée ne contourne pas le catalogue.
 */
class CatalogueImpressions
{
    /** Listes de dossiers exportables, désignées par leur clé d'URL. */
    public const LISTES = [
        'inscrits' => [
            'libelle' => 'Candidats inscrits',
            'description' => "Dossiers dont les frais ont été réglés, en attente de décision de recevabilité.",
            'icone' => 'user-check',
            'ton' => 'indigo',
            'statuts' => [StatutCandidature::INSCRIT],
        ],
        'recevables' => [
            'libelle' => 'Candidats recevables',
            'description' => 'Dossiers déclarés recevables, y compris ceux ayant poursuivi le parcours.',
            'icone' => 'circle-check',
            'ton' => 'green',
            'statuts' => [
                StatutCandidature::DOSSIER_RECEVABLE,
                StatutCandidature::ELIGIBLE,
                StatutCandidature::NON_ELIGIBLE,
                StatutCandidature::ADMISSIBLE,
                StatutCandidature::NON_ADMISSIBLE,
                StatutCandidature::NON_ADMIS_DEFINITIF,
                StatutCandidature::ADMIS_DEFINITIF,
            ],
        ],
        'eligibles' => [
            'libelle' => 'Candidats éligibles',
            'description' => "Dossiers déclarés éligibles par le jury central, et la suite de leur parcours.",
            'icone' => 'clipboard-check',
            'ton' => 'green',
            'statuts' => [
                StatutCandidature::ELIGIBLE,
                StatutCandidature::ADMISSIBLE,
                StatutCandidature::NON_ADMISSIBLE,
                StatutCandidature::NON_ADMIS_DEFINITIF,
                StatutCandidature::ADMIS_DEFINITIF,
            ],
        ],
        'admis' => [
            'libelle' => 'Admis définitifs',
            'description' => "Dossiers admis définitivement par le jury central d'admission.",
            'icone' => 'award',
            'ton' => 'green',
            'statuts' => [StatutCandidature::ADMIS_DEFINITIF],
        ],
    ];

    /**
     * Listes auxquelles ce rôle a droit, dans l'ordre du parcours.
     *
     * @return array<string, array{libelle: string, description: string, icone: string, ton: string, statuts: list<StatutCandidature>}>
     */
    public function listesPour(User $utilisateur): array
    {
        $roles = $utilisateur->getRoles();
        $estAdmin = in_array(Role::ADMIN, $roles, true);
        $estConseiller = $estAdmin || in_array(Role::CONSEILLER, $roles, true);
        $estAgent = $estConseiller || in_array(Role::AGENT_ACCUEIL, $roles, true);

        $autorisees = [];

        if ($estAgent) {
            $autorisees[] = 'inscrits';
        }

        if ($estConseiller) {
            $autorisees[] = 'recevables';
            $autorisees[] = 'eligibles';
        }

        // L'admission définitive relève du jury central : seule
        // l'administration en édite la liste.
        if ($estAdmin) {
            $autorisees[] = 'admis';
        }

        return array_intersect_key(self::LISTES, array_flip($autorisees));
    }

    public function peutExporter(User $utilisateur, string $liste): bool
    {
        return array_key_exists($liste, $this->listesPour($utilisateur));
    }

    /**
     * Le personnel recherche la fiche d'un dossier par son numéro ; le candidat
     * imprime la sienne depuis sa propre carte, sans saisie.
     */
    public function peutRechercherParNumero(User $utilisateur): bool
    {
        $roles = $utilisateur->getRoles();

        return in_array(Role::ADMIN, $roles, true)
            || in_array(Role::CONSEILLER, $roles, true)
            || in_array(Role::AGENT_ACCUEIL, $roles, true);
    }

    /**
     * Seule l'administration choisit le centre d'un export : pour les autres
     * rôles, il est imposé par le compte et le sélecteur n'est pas affiché.
     */
    public function peutChoisirLeCentre(User $utilisateur): bool
    {
        return in_array(Role::ADMIN, $utilisateur->getRoles(), true);
    }

    public function peutImprimerLesStatistiques(User $utilisateur): bool
    {
        $roles = $utilisateur->getRoles();

        return in_array(Role::ADMIN, $roles, true) || in_array(Role::CONSEILLER, $roles, true);
    }
}
