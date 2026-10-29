<?php

namespace App\Security;

/**
 * Validation des adresses de retour reçues dans la requête (« redirect »,
 * « _target_path ») : seul un chemin interne au site est accepté.
 *
 * Refusés, car un navigateur les interprète comme un autre domaine :
 * « //hote », « /\hote » (la barre inverse vaut une barre), les caractères de
 * contrôle (tabulation, retour à la ligne, ignorés par le navigateur) et les
 * formes encodées « /%2F hote », d'où le contrôle APRÈS décodage.
 */
final class CibleRedirection
{
    public static function sure(?string $cible): ?string
    {
        if ($cible === null || $cible === '') {
            return null;
        }

        $decodee = rawurldecode($cible);

        foreach ([$cible, $decodee] as $forme) {
            if (!str_starts_with($forme, '/')
                || str_starts_with($forme, '//')
                || str_contains($forme, '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $forme)) {
                return null;
            }
        }

        return $cible;
    }
}
