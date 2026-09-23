<?php

namespace App\Exception;

use App\Enum\TypeImportJury;

/**
 * Le fichier soumis ne peut pas être exploité (module M6).
 *
 * Ces exceptions interrompent l'import avant toute écriture : elles signalent
 * un fichier structurellement inutilisable, par opposition aux lignes en erreur
 * qui, elles, sont consignées dans le rapport sans arrêter le traitement.
 */
class ImportInvalideException extends VaeException
{
    public static function feuilleAbsente(TypeImportJury $type): self
    {
        return new self(sprintf(
            'Le classeur ne contient pas de feuille « %s ». Utilisez le modèle fourni.',
            $type->feuille()
        ));
    }

    /**
     * @param list<string> $attendues
     * @param list<string> $trouvees
     */
    public static function enteteNonConforme(array $attendues, array $trouvees): self
    {
        return new self(sprintf(
            'L\'en-tête du fichier ne correspond pas au modèle. Attendu : %s. Trouvé : %s.',
            implode(', ', $attendues),
            $trouvees === [] ? '(vide)' : implode(', ', $trouvees)
        ));
    }

    public static function fichierVide(): self
    {
        return new self('Le fichier ne contient aucune ligne à traiter sous l\'en-tête.');
    }

    public static function illisible(): self
    {
        return new self(
            'Le fichier n\'a pas pu être lu. Vérifiez qu\'il s\'agit bien d\'un classeur '
            . 'Excel (.xlsx ou .xls) non protégé par mot de passe.'
        );
    }

    public static function tropDErreurs(int $pourcentage, int $seuil): self
    {
        return new self(sprintf(
            'Import annulé : %d %% des lignes sont en erreur (seuil admis : %d %%). '
            . 'Aucune décision n\'a été appliquée. Corrigez le fichier et recommencez.',
            $pourcentage,
            $seuil
        ));
    }

    public static function stockageImpossible(): self
    {
        return new self('Le fichier n\'a pas pu être conservé. Contactez l\'administrateur technique.');
    }
}
