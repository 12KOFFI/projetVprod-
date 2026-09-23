<?php

namespace App\Security;

use App\Entity\User;

/**
 * Vocabulaire centralisé des rôles de la plateforme VAE.
 *
 * Les 5 rôles métier proviennent de specifications_vae.txt, section 2.
 * Les chaînes reprennent exactement celles déjà déclarées dans la
 * role_hierarchy de config/packages/security.yaml — y compris
 * ROLE_AGENT_ACCEUIL, dont l'orthographe historique est conservée pour
 * ne pas invalider les rôles déjà stockés en base.
 */
final class Role
{
    public const CANDIDAT       = 'ROLE_CANDIDAT';
    public const AGENT_ACCUEIL  = 'ROLE_AGENT_ACCEUIL';
    public const CONSEILLER     = 'ROLE_CONSEILLER';
    public const ACCOMPAGNATEUR = 'ROLE_ACCOMPAGNATEUR';
    public const ADMIN          = 'ROLE_ADMIN';

    /**
     * Ordre de priorité décroissant utilisé pour déterminer le rôle
     * principal d'un utilisateur (règle métier R1.4).
     *
     * @var list<string>
     */
    private const PRIORITE = [
        self::ADMIN,
        self::CONSEILLER,
        self::ACCOMPAGNATEUR,
        self::AGENT_ACCUEIL,
        self::CANDIDAT,
    ];

    /**
     * Rôles qui imposent un rattachement à un centre (règle métier R1.5).
     *
     * @var list<string>
     */
    private const RATTACHES_CENTRE = [
        self::AGENT_ACCUEIL,
        self::CONSEILLER,
        self::ACCOMPAGNATEUR,
    ];

    /**
     * @return array<string, string> role => libellé lisible
     */
    public static function libelles(): array
    {
        return [
            self::CANDIDAT       => 'Candidat',
            self::AGENT_ACCUEIL  => 'Agent d\'accueil',
            self::CONSEILLER     => 'Conseiller VAE',
            self::ACCOMPAGNATEUR => 'Accompagnateur',
            self::ADMIN          => 'Administrateur',
        ];
    }

    public static function libelle(string $role): string
    {
        return self::libelles()[$role] ?? $role;
    }

    /**
     * Rôle principal d'un utilisateur, ou null si aucun rôle métier reconnu.
     */
    public static function principal(User $user): ?string
    {
        $roles = $user->getRoles();

        foreach (self::PRIORITE as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return null;
    }

    public static function exigeCentre(string $role): bool
    {
        return in_array($role, self::RATTACHES_CENTRE, true);
    }

    public static function exigeMetier(string $role): bool
    {
        return $role === self::ACCOMPAGNATEUR;
    }

    /**
     * Route de l'espace de travail correspondant au rôle principal.
     */
    public static function routeEspace(string $role): ?string
    {
        return match ($role) {
            self::ADMIN          => 'app_admin_dashboard',
            self::CONSEILLER     => 'app_conseiller_dashboard',
            self::ACCOMPAGNATEUR => 'app_accompagnement_dashboard',
            self::AGENT_ACCUEIL  => 'app_accueil_dashboard',
            self::CANDIDAT       => 'app_candidat_dashboard',
            default              => null,
        };
    }
}
