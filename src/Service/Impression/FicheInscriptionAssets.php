<?php

namespace App\Service\Impression;

use App\Entity\Candidature;
use App\Service\PdfGenerator;

/**
 * Encode en base64 le logo et la photo d'identité injectés dans la fiche
 * d'inscription imprimée (F5.8, imprimable aussi bien depuis l'espace
 * candidat que depuis les impressions du personnel).
 *
 * Dompdf ne sait lire ces images que sur le système de fichiers, jamais via
 * l'URL publique qui les sert normalement — d'où l'encodage préalable.
 */
class FicheInscriptionAssets
{
    public function __construct(
        private readonly PdfGenerator $pdf,
        private readonly string $dossierMedia,
        private readonly string $dossierImage,
    ) {
    }

    public function logo(): ?string
    {
        $chemin = rtrim($this->dossierImage, '/\\') . \DIRECTORY_SEPARATOR . 'favicon.png';

        return is_file($chemin) ? $this->pdf->imageToBase64($chemin) : null;
    }

    public function photoCandidat(Candidature $candidature): ?string
    {
        if ($candidature->getFphoto() === null) {
            return null;
        }

        $chemin = rtrim($this->dossierMedia, '/\\') . \DIRECTORY_SEPARATOR
            . $candidature->getNumero() . \DIRECTORY_SEPARATOR . $candidature->getFphoto();

        return is_file($chemin) ? $this->pdf->imageToBase64($chemin) : null;
    }
}
