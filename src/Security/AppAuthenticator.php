<?php

namespace App\Security;

use App\Referentiel\Telephone;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class AppAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    private UrlGeneratorInterface $urlGenerator;
    private AuthorizationCheckerInterface $authorization;
    private RouterInterface $router;
    private EntityManagerInterface $entityManager;
    private LimiteurTentatives $limiteur;

    public function __construct(
        UrlGeneratorInterface $urlGenerator,
        AuthorizationCheckerInterface $authorization,
        RouterInterface $router,
        EntityManagerInterface $entityManager,
        LimiteurTentatives $limiteur
    ) {
        $this->urlGenerator = $urlGenerator;
        $this->authorization = $authorization;
        $this->router = $router;
        $this->entityManager = $entityManager;
        $this->limiteur = $limiteur;
    }

    public function supports(Request $request): bool
    {
        return $request->getPathInfo() === '/connexion' && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        $identifier = trim((string) $request->request->get('email', ''));
        $password = $request->request->get('password', '');
        $csrfToken = $request->request->get('_csrf_token');

        $request->getSession()->set(Security::LAST_USERNAME, $identifier);

        // Force brute : au-delà du seuil, le mot de passe n'est même pas vérifié.
        $attente = $this->limiteur->minutesDAttente(LimiteurTentatives::CONNEXION, $identifier, $request->getClientIp());
        if ($attente !== null) {
            throw new CustomUserMessageAuthenticationException(sprintf(
                'Trop de tentatives de connexion. Réessayez dans %d minute%s.',
                $attente,
                $attente > 1 ? 's' : ''
            ));
        }

        return new Passport(
            new UserBadge($identifier, function (string $identifier): User {
                $repository = $this->entityManager->getRepository(User::class);
                $user = $repository->findOneBy(['email' => strtolower($identifier)]);

                // Numéro au format international ; tapé sans indicatif, il est ivoirien.
                $telephone = Telephone::depuisIdentifiant($identifier);
                if (!$user && $telephone !== null) {
                    $user = $repository->findOneBy(['contact' => $telephone]);
                }

                if (!$user instanceof User) {
                    throw new \Symfony\Component\Security\Core\Exception\UserNotFoundException();
                }

                return $user;
            }),
            new PasswordCredentials($password),
            [
                new CsrfTokenBadge('authenticate', $csrfToken),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $this->limiteur->reinitialiser(
            LimiteurTentatives::CONNEXION,
            trim((string) $request->request->get('email', ''))
        );

        // 1. Vérifier le target_path envoyé par le formulaire
        if ($cible = CibleRedirection::sure($request->request->get('_target_path'))) {
            return new RedirectResponse($cible);
        }

        // 2. Vérifier redirect dans l'URL
        if ($cible = CibleRedirection::sure($request->query->get('redirect'))) {
            return new RedirectResponse($cible);
        }

        // 3. Vérifier targetPath stocké dans la session
        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        // 4. Redirection par défaut : l'aiguilleur oriente vers l'espace du rôle
        return new RedirectResponse($this->router->generate('app_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Le refus pour cause de limite atteinte ne prolonge pas le blocage.
        if (!$exception instanceof CustomUserMessageAuthenticationException) {
            $this->limiteur->enregistrerEchec(
                LimiteurTentatives::CONNEXION,
                trim((string) $request->request->get('email', '')),
                $request->getClientIp()
            );
        }

        return parent::onAuthenticationFailure($request, $exception);
    }

    protected function getLoginUrl(Request $request): string
    {
        $parametres = [];

        if ($request->query->get('profil') === 'professionnel') {
            $parametres['profil'] = 'professionnel';
        }

        if ($redirect = CibleRedirection::sure($request->query->get('redirect'))) {
            $parametres['redirect'] = $redirect;
        }

        return $this->urlGenerator->generate(self::LOGIN_ROUTE, $parametres);
    }
}
