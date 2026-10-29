<?php

namespace App\Controller;

use App\Referentiel\Telephone;
use App\Entity\User;
use App\Form\UserType;
use App\Security\AppAuthenticator;
use App\Security\CibleRedirection;
use App\Security\LimiteurTentatives;
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
    private const RESET_VERIFIE_LE = 'forgot_password_verifie_le';
    /** Délai pour choisir le nouveau mot de passe une fois l'identité vérifiée. */
    private const RESET_DUREE_SECONDES = 900;

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
        $form = $this->createForm(UserType::class, $user, ['is_register' => true, 'attestation' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = strtolower(trim((string) $user->getEmail())) ?: null;
            // Numéros déjà au format international (TelephoneType).
            $contact = $user->getContact();
            $contact2 = $user->getContact2();
            $repository = $entityManager->getRepository(User::class);

            if ($contact === null) {
                $form->get('contact')->addError(new FormError('Saisissez votre numéro de téléphone.'));
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

    /**
     * Réinitialisation du mot de passe en libre-service, réservée aux candidats.
     *
     * Faute de canal hors bande (SMS, lien par e-mail), l'identité est vérifiée
     * par des informations du compte : identifiant, date de naissance et quatre
     * derniers chiffres du téléphone. La date de naissance est indispensable :
     * quand l'identifiant saisi est le numéro de téléphone, ses quatre derniers
     * chiffres ne prouvent rien. Les essais sont limités (LimiteurTentatives).
     *
     * Les comptes du personnel en sont exclus : leur mot de passe est
     * réinitialisé par l'administrateur (PersonnelController), sans quoi un
     * compte administrateur tomberait avec le seul numéro de son titulaire.
     */
    #[Route('/mot-de-passe-oublie', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher, LimiteurTentatives $limiteur): Response
    {
        $session = $request->getSession();
        $verificationValide = (int) $session->get(self::RESET_STEP, 1) === 2
            && $session->get(self::RESET_USER)
            && time() - (int) $session->get(self::RESET_VERIFIE_LE, 0) <= self::RESET_DUREE_SECONDES;

        if ($request->isMethod('GET')) {
            if ($verificationValide) {
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
            $ip = $request->getClientIp();

            $attente = $limiteur->minutesDAttente(LimiteurTentatives::REINITIALISATION, $identifier, $ip);
            if ($attente !== null) {
                $this->addFlash('error', sprintf('Trop de tentatives. Réessayez dans %d minute%s.', $attente, $attente > 1 ? 's' : ''));
                return $this->render('security/forgot_password.html.twig', ['step' => 1]);
            }

            $digits = preg_replace('/\D+/', '', (string) $request->request->get('last_four_digits'));
            $dateNaissance = $this->lireDate((string) $request->request->get('date_naissance'));
            $repository = $entityManager->getRepository(User::class);
            $user = $identifier !== '' ? $repository->findOneBy(['email' => strtolower($identifier)]) : null;
            $telephone = Telephone::depuisIdentifiant($identifier);
            if (!$user && $telephone !== null) {
                $user = $repository->findOneBy(['contact' => $telephone]);
            }

            if (!$user || !$this->identiteVerifiee($user, $digits, $dateNaissance)) {
                $limiteur->enregistrerEchec(LimiteurTentatives::REINITIALISATION, $identifier, $ip);
                $this->addFlash('error', 'Les informations fournies ne permettent pas de vérifier ce compte.');
                return $this->render('security/forgot_password.html.twig', ['step' => 1]);
            }

            $limiteur->reinitialiser(LimiteurTentatives::REINITIALISATION, $identifier);
            // Nouvel identifiant de session : la vérification ne profite pas à
            // une session qui aurait été fixée par un tiers.
            $session->migrate(true);
            $session->set(self::RESET_STEP, 2);
            $session->set(self::RESET_USER, $user->getId());
            $session->set(self::RESET_IDENTIFIER, $user->getEmail() ?: $user->getContact());
            $session->set(self::RESET_VERIFIE_LE, time());
            return $this->render('security/forgot_password.html.twig', [
                'step' => 2,
                'identifier' => $user->getEmail() ?: $user->getContact(),
            ]);
        }

        if (!$this->isCsrfTokenValid('forgot_password_reset', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'La demande est invalide. Veuillez recommencer.');
            return $this->redirectToRoute('app_forgot_password');
        }

        if (!$verificationValide) {
            $this->clearResetSession($session);
            $this->addFlash('error', 'La vérification a expiré. Veuillez recommencer.');
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
        $session->remove(self::RESET_VERIFIE_LE);
    }

    private function safeTarget(?string $target): ?string
    {
        return CibleRedirection::sure($target);
    }

    /**
     * Seul un compte de candidat actif, dont la date de naissance et les quatre
     * derniers chiffres du téléphone correspondent, peut être réinitialisé ici.
     */
    private function identiteVerifiee(User $user, string $digits, ?\DateTimeInterface $dateNaissance): bool
    {
        if (array_diff($user->getRoles(), [User::ROLE_CANDIDAT, 'ROLE_USER']) !== [] || !$user->isActif()) {
            return false;
        }

        $contact = preg_replace('/\D+/', '', (string) $user->getContact());
        $suffixe = strlen($contact) >= 4 ? substr($contact, -4) : '';
        $naissance = $user->getDatenaissance();

        return $suffixe !== '' && strlen($digits) === 4 && hash_equals($suffixe, $digits)
            && $naissance !== null && $dateNaissance !== null
            && $naissance->format('Y-m-d') === $dateNaissance->format('Y-m-d');
    }

    /**
     * Champ date du navigateur (aaaa-mm-jj) ou saisie libre jj/mm/aaaa.
     */
    private function lireDate(string $saisie): ?\DateTimeInterface
    {
        $saisie = trim($saisie);

        foreach (['Y-m-d', 'd/m/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $saisie);
            if ($date !== false && $date->format($format) === $saisie) {
                return $date;
            }
        }

        return null;
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
