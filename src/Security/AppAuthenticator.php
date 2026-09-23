<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
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

    public function __construct(
        UrlGeneratorInterface $urlGenerator,
        AuthorizationCheckerInterface $authorization,
        RouterInterface $router,
        EntityManagerInterface $entityManager
    ) {
        $this->urlGenerator = $urlGenerator;
        $this->authorization = $authorization;
        $this->router = $router;
        $this->entityManager = $entityManager;
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

        return new Passport(
            new UserBadge($identifier, function (string $identifier): User {
                $repository = $this->entityManager->getRepository(User::class);
                $user = $repository->findOneBy(['email' => strtolower($identifier)]);

                if (!$user) {
                    $user = $repository->findOneBy(['contact' => preg_replace('/\D+/', '', $identifier)]);
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
        // 1. Vérifier le target_path envoyé par le formulaire
        $targetPathPost = $request->request->get('_target_path');
        if ($targetPathPost && $this->isSafeTarget($targetPathPost)) {
            return new RedirectResponse(urldecode($targetPathPost));
        }

        // 2. Vérifier redirect dans l'URL
        $redirect = $request->query->get('redirect');
        if ($redirect && $this->isSafeTarget($redirect)) {
            return new RedirectResponse($redirect);
        }

        // 3. Vérifier targetPath stocké dans la session
        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        // 4. Redirection par défaut : l'aiguilleur oriente vers l'espace du rôle
        return new RedirectResponse($this->router->generate('app_dashboard'));
    }

    protected function getLoginUrl(Request $request): string
    {
        $parametres = [];

        if ($request->query->get('profil') === 'professionnel') {
            $parametres['profil'] = 'professionnel';
        }

        $redirect = $request->query->get('redirect');

        if ($redirect && $this->isSafeTarget($redirect)) {
            $parametres['redirect'] = $redirect;
        }

        return $this->urlGenerator->generate(self::LOGIN_ROUTE, $parametres);
    }

    private function isSafeTarget(string $target): bool
    {
        return str_starts_with($target, '/') && !str_starts_with($target, '//');
    }
}
