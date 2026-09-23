<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Security\AppAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

class SecurityController extends AbstractController
{
    private const RESET_STEP = 'forgot_password_step';
    private const RESET_USER = 'forgot_password_user_id';
    private const RESET_IDENTIFIER = 'forgot_password_identifier';

    #[Route('/connexion', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToSafeTarget($request->query->get('redirect'));
        }

        // Même page pour tous : seuls les textes changent selon le profil choisi
        // dans le menu « Mon espace » (candidat par défaut).
        $profil = $request->query->get('profil') === 'professionnel' ? 'professionnel' : 'candidat';

        return $this->render('security/login.html.twig', [
            'profil' => $profil,
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'redirect' => $this->safeTarget($request->query->get('redirect')),
        ]);
    }

    #[Route('/inscription', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        UserAuthenticatorInterface $authenticator,
        AppAuthenticator $formAuthenticator
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToSafeTarget($request->query->get('redirect'));
        }

        $user = (new User())->setNationalite("COTE D'IVOIRE");
        $form = $this->createForm(UserType::class, $user, ['is_register' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = strtolower(trim((string) $user->getEmail())) ?: null;
            $contact = preg_replace('/\D+/', '', (string) $user->getContact()) ?: null;
            $contact2 = preg_replace('/\D+/', '', (string) $user->getContact2()) ?: null;
            $repository = $entityManager->getRepository(User::class);

            if ($contact === null) {
                $form->get('contact')->addError(new FormError('Saisissez votre numéro de téléphone : 10 chiffres.'));
            } elseif ($contact2 !== null && $contact2 === $contact) {
                $form->get('contact2')->addError(new FormError('Le 2e contact doit être différent du premier.'));
            } elseif (($email !== null && $repository->findOneBy(['email' => $email])) || ($contact !== null && $repository->findOneBy(['contact' => $contact]))) {
                $this->addFlash('error', 'Cet email ou ce numéro de téléphone est déjà utilisé.');
            } else {
                $user->setEmail($email);
                $user->setContact($contact);
                $user->setContact2($contact2);
                $user->setRoles([User::ROLE_CANDIDAT]);
                $user->setPassword($passwordHasher->hashPassword($user, (string) $form->get('password')->getData()));
                $entityManager->persist($user);
                $entityManager->flush();
                $authenticator->authenticateUser($user, $formAuthenticator, $request);

                $this->addFlash('success', 'Votre compte a été créé avec succès.');
                return $this->redirectToSafeTarget($request->request->get('redirect'));
            }
        }

        return $this->render('security/register.html.twig', [
            'form' => $form->createView(),
            'redirect' => $this->safeTarget($request->query->get('redirect')),
        ]);
    }

    #[Route('/mot-de-passe-oublie', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): Response
    {
        $session = $request->getSession();
        $step = (int) $session->get(self::RESET_STEP, 1);

        if ($request->isMethod('GET')) {
            if ($step === 2 && $session->get(self::RESET_USER)) {
                return $this->render('security/forgot_password.html.twig', [
                    'step' => 2,
                    'identifier' => $session->get(self::RESET_IDENTIFIER, ''),
                ]);
            }

            $this->clearResetSession($session);
            return $this->render('security/forgot_password.html.twig', ['step' => 1]);
        }

        if ($request->request->has('verify_user')) {
            if (!$this->isCsrfTokenValid('forgot_password_verify', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'La demande est invalide. Veuillez recommencer.');
                return $this->redirectToRoute('app_forgot_password');
            }

            $identifier = trim((string) $request->request->get('identifier'));
            $digits = preg_replace('/\D+/', '', (string) $request->request->get('last_four_digits'));
            $repository = $entityManager->getRepository(User::class);
            $user = $repository->findOneBy(['email' => strtolower($identifier)]);
            if (!$user && $digits === (string) $request->request->get('last_four_digits')) {
                $user = $repository->findOneBy(['contact' => preg_replace('/\D+/', '', $identifier)]);
            }

            $contact = $user?->getContact();
            $normalizedContact = $contact !== null ? preg_replace('/\D+/', '', $contact) : '';
            $contactSuffix = strlen($normalizedContact) >= 4 ? substr($normalizedContact, -4) : '';

            if (!$user || $contactSuffix === '' || !hash_equals($contactSuffix, $digits)) {
                $this->addFlash('error', 'Les informations fournies ne permettent pas de vérifier ce compte.');
                return $this->render('security/forgot_password.html.twig', ['step' => 1]);
            }

            $session->set(self::RESET_STEP, 2);
            $session->set(self::RESET_USER, $user->getId());
            $session->set(self::RESET_IDENTIFIER, $user->getEmail() ?: $user->getContact());
            return $this->render('security/forgot_password.html.twig', [
                'step' => 2,
                'identifier' => $user->getEmail() ?: $user->getContact(),
            ]);
        }

        if (!$this->isCsrfTokenValid('forgot_password_reset', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'La demande est invalide. Veuillez recommencer.');
            return $this->redirectToRoute('app_forgot_password');
        }

        $user = $entityManager->getRepository(User::class)->find($session->get(self::RESET_USER));
        $newPassword = (string) $request->request->get('new_password');
        $confirmation = (string) $request->request->get('confirm_password');
        if (!$user || strlen($newPassword) < 8 || !hash_equals($newPassword, $confirmation)) {
            $this->addFlash('error', 'Le mot de passe doit contenir au moins 8 caractères et les deux saisies doivent correspondre.');
            return $this->render('security/forgot_password.html.twig', [
                'step' => 2,
                'identifier' => $session->get(self::RESET_IDENTIFIER, ''),
            ]);
        }

        $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
        $entityManager->flush();
        $this->clearResetSession($session);
        $this->addFlash('success', 'Votre mot de passe a été réinitialisé avec succès. Veuillez vous connecter.');
        return $this->redirectToRoute('app_login');
    }

    #[Route('/deconnexion', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method is intercepted by the firewall logout handler.');
    }

    private function clearResetSession(\Symfony\Component\HttpFoundation\Session\SessionInterface $session): void
    {
        $session->remove(self::RESET_STEP);
        $session->remove(self::RESET_USER);
        $session->remove(self::RESET_IDENTIFIER);
    }

    private function safeTarget(?string $target): ?string
    {
        return $target && str_starts_with($target, '/') && !str_starts_with($target, '//') ? $target : null;
    }

    /**
     * Après connexion ou inscription, l'utilisateur est authentifié :
     * le repli est son espace de travail, jamais la page de connexion.
     */
    private function redirectToSafeTarget(?string $target): Response
    {
        return $this->redirect($this->safeTarget($target) ?: $this->generateUrl('app_dashboard'));
    }
}
