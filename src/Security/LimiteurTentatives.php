<?php

namespace App\Security;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Limite les tentatives d'authentification ratées (connexion, vérification
 * d'identité avant réinitialisation du mot de passe).
 *
 * Fenêtre fixe stockée dans le cache applicatif (fichiers sous var/cache) :
 * aucune dépendance supplémentaire, fonctionne sur un hébergement mutualisé.
 *
 * Deux compteurs par action :
 *   - par identifiant visé, bas : protège un compte précis contre la force brute ;
 *   - par adresse IP, haut : beaucoup d'utilisateurs mobiles partagent la même
 *     IP publique (NAT des opérateurs), un seuil bas les bloquerait tous.
 */
class LimiteurTentatives
{
    public const CONNEXION = 'connexion';
    public const REINITIALISATION = 'reinitialisation';

    /** action => [essais par identifiant, essais par IP, fenêtre en secondes] */
    private const REGLES = [
        self::CONNEXION => [5, 50, 900],
        self::REINITIALISATION => [5, 20, 3600],
    ];

    public function __construct(private readonly CacheItemPoolInterface $cacheApp)
    {
    }

    /**
     * Nombre de minutes avant de pouvoir réessayer, ou null si l'essai est permis.
     */
    public function minutesDAttente(string $action, ?string $identifiant, ?string $ip): ?int
    {
        [$maxIdentifiant, $maxIp] = self::REGLES[$action];
        $attente = null;

        foreach ([[$this->cle($action, 'id', $identifiant), $maxIdentifiant], [$this->cle($action, 'ip', $ip), $maxIp]] as [$cle, $max]) {
            if ($cle === null) {
                continue;
            }
            $etat = $this->lire($cle);
            if ($etat !== null && $etat['essais'] >= $max) {
                $minutes = max(1, (int) ceil(($etat['fin'] - time()) / 60));
                $attente = max($attente ?? 0, $minutes);
            }
        }

        return $attente;
    }

    public function enregistrerEchec(string $action, ?string $identifiant, ?string $ip): void
    {
        foreach ([$this->cle($action, 'id', $identifiant), $this->cle($action, 'ip', $ip)] as $cle) {
            if ($cle === null) {
                continue;
            }
            $etat = $this->lire($cle) ?? ['essais' => 0, 'fin' => time() + self::REGLES[$action][2]];
            ++$etat['essais'];

            $element = $this->cacheApp->getItem($cle);
            $element->set($etat);
            $element->expiresAt((new \DateTimeImmutable())->setTimestamp($etat['fin']));
            $this->cacheApp->save($element);
        }
    }

    /**
     * Après un succès, seul le compteur de l'identifiant est remis à zéro :
     * celui de l'IP continue de freiner une attaque répartie sur plusieurs comptes.
     */
    public function reinitialiser(string $action, ?string $identifiant): void
    {
        $cle = $this->cle($action, 'id', $identifiant);
        if ($cle !== null) {
            $this->cacheApp->deleteItem($cle);
        }
    }

    /**
     * @return array{essais: int, fin: int}|null
     */
    private function lire(string $cle): ?array
    {
        $element = $this->cacheApp->getItem($cle);
        $etat = $element->isHit() ? $element->get() : null;

        return is_array($etat) && ($etat['fin'] ?? 0) > time() ? $etat : null;
    }

    private function cle(string $action, string $portee, ?string $valeur): ?string
    {
        $valeur = mb_strtolower(trim((string) $valeur));

        // Empreinte : la clé de cache ne doit contenir ni caractère réservé, ni
        // donnée personnelle en clair.
        return $valeur === '' ? null : sprintf('limiteur_%s_%s_%s', $action, $portee, hash('sha256', $valeur));
    }
}
