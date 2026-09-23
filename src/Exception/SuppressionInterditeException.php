<?php

namespace App\Exception;

/**
 * Levée quand un élément de référentiel est encore utilisé ailleurs : le message
 * énumère les dépendances bloquantes afin que l'administrateur sache quoi
 * traiter avant de réessayer (règles métier R2.1 à R2.5, R2.8).
 */
class SuppressionInterditeException extends VaeException
{
    /**
     * @param array<string, int> $blocages libellé de la dépendance => nombre
     */
    public static function pour(string $element, array $blocages): self
    {
        $details = [];

        foreach ($blocages as $libelle => $nombre) {
            $details[] = sprintf('%d %s', $nombre, $libelle);
        }

        // Formulation sans accord de participe : l'élément peut être masculin
        // (un centre, un métier) comme féminin (une localité, une filière).
        return new self(sprintf(
            'Suppression impossible : %s compte encore %s.',
            $element,
            implode(', ', $details)
        ));
    }

    public static function avecMotif(string $motif): self
    {
        return new self($motif);
    }
}
