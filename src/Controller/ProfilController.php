<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserProfileType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Modification par un utilisateur connecté de ses propres informations
 * personnelles, quel que soit son rôle (candidat, agent d'accueil,
 * conseiller, accompagnateur, administrateur) : d'où un préfixe de route
 * neutre plutôt qu'un rattachement à un espace de rôle particulier, sur le
 * même principe que /tableau-de-bord.
 */
#[Route('/mon-profil', name: 'app_profil_')]
#[IsGranted('ROLE_USER')]
class ProfilController extends AbstractController
{
    #[Route('', name: 'modifier', methods: ['GET', 'POST'])]
    public function modifier(
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        /** @var User $utilisateur */
        $utilisateur = $this->getUser();

        $form = $this->createForm(UserProfileType::class, $utilisateur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($userRepository->emailDejaUtilise((string) $utilisateur->getEmail(), $utilisateur->getId())) {
                $form->get('email')->addError(new FormError('Cette adresse est déjà utilisée par un autre compte.'));
            } else {
                // Champ laissé vide : le mot de passe actuel est conservé.
                $nouveauMotDePasse = (string) $form->get('nouveauMotDePasse')->getData();

                if ($nouveauMotDePasse !== '') {
                    $utilisateur->setPassword($passwordHasher->hashPassword($utilisateur, $nouveauMotDePasse));
                    $utilisateur->setDoitChangerMotDePasse(false);
                }

                $entityManager->flush();

                $this->addFlash('success', $nouveauMotDePasse !== ''
                    ? 'Vos modifications ont été enregistrées, y compris votre nouveau mot de passe.'
                    : 'Vos modifications ont été enregistrées.');

                return $this->redirectToRoute('app_profil_modifier');
            }
        }

        return $this->render('profil/modifier.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
