<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Vitrine publique de la plateforme VAE.
 *
 * Accessible sans authentification : c'est le point d'entrée du maître
 * artisan qui découvre le dispositif. Un utilisateur déjà connecté est
 * renvoyé vers son espace de travail plutôt que vers la page de présentation.
 */
class HomeController extends AbstractController
{
    /**
     * Documents officiels de la session, déposés dans public/document/.
     * Chemins relatifs à la racine web, tels qu'attendus par asset().
     */
    private const DOCUMENTS = [
        'liste' => 'document/LISTE DES METIERS PAR ETABLISSEMENT.docx',
    ];

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Un lien vers un PDF absent mènerait à une erreur 404 : le gabarit
        // affiche le bouton comme indisponible tant que le fichier n'est pas déposé.
        $racineWeb = $this->getParameter('kernel.project_dir') . '/public/';
        $documents = [];
        foreach (self::DOCUMENTS as $cle => $chemin) {
            $documents[$cle] = [
                'chemin' => $chemin,
                'disponible' => is_file($racineWeb . $chemin),
            ];
        }

        return $this->render('public/home.html.twig', [
            'documents' => $documents,
        ]);
    }

    /**
     * Deroulement detaille de la session, extrait de la page d'accueil.
     *
     * Contrairement a l'accueil, cette page ne redirige pas l'utilisateur
     * connecte : c'est une page d'information que le candidat doit pouvoir
     * consulter a tout moment de son parcours.
     */
    #[Route('/deroulement-session-2026', name: 'app_session_deroulement', methods: ['GET'])]
    public function deroulement(): Response
    {
        return $this->render('public/session.html.twig');
    }
}
