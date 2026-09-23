<?php

namespace App\Controller\Admin;

use App\Entity\Centre;
use App\Entity\User;
use App\Form\UserType;
use App\Repository\CentreRepository;
use App\Repository\UserRepository;
use App\Security\Role;
use App\Service\Referentiel\GestionCompte;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Écrans E2.7 et E2.8 : comptes du personnel (F2.7).
 *
 * Un compte n'est jamais supprimé : il est désactivé, la traçabilité des
 * dossiers traités primant sur le ménage de la liste. Le mot de passe initial
 * est généré par GestionCompte et affiché une seule fois (R2.9).
 */
#[Route('/admin/utilisateurs', name: 'app_admin_personnel_')]
#[IsGranted('ROLE_ADMIN')]
class PersonnelController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $repository,
        private readonly CentreRepository $centreRepository,
        private readonly GestionCompte $gestionCompte,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, PaginatorInterface $paginator): Response
    {
        $recherche = trim((string) $request->query->get('q', ''));
        $filtreRole = $this->roleFiltre($request);
        $filtreCentre = $this->centreFiltre($request);

        return $this->render('admin/personnel/index.html.twig', [
            'pagination' => $paginator->paginate(
                $this->repository->queryListePersonnel($filtreRole, $filtreCentre, $recherche),
                $request->query->getInt('page', 1),
                25
            ),
            'recherche' => $recherche,
            'roles' => $this->rolesAssignables(),
            'centres' => $this->centreRepository->findTousTries(),
            'filtre_role' => $filtreRole,
            'filtre_centre' => $filtreCentre,
        ]);
    }

    #[Route('/nouveau', name: 'nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request): Response
    {
        $utilisateur = new User();
        $form = $this->createForm(UserType::class, $utilisateur, ['is_admin_creation' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->repository->emailDejaUtilise((string) $utilisateur->getEmail())) {
                $form->get('email')->addError(new FormError('Cette adresse est déjà utilisée par un autre compte.'));
            } else {
                $motDePasse = $this->gestionCompte->creerPersonnel(
                    $utilisateur,
                    (string) $form->get('role')->getData()
                );

                $this->addFlash('success', sprintf(
                    'Compte créé pour %s. Mot de passe initial : <strong class="font-mono">%s</strong> — '
                    . 'communiquez-le hors bande, il ne sera plus affiché. Son changement sera demandé '
                    . 'à la première connexion.',
                    htmlspecialchars($utilisateur->getNomComplet(), \ENT_QUOTES),
                    htmlspecialchars($motDePasse, \ENT_QUOTES)
                ));

                return $this->redirectToRoute('app_admin_personnel_index');
            }
        }

        return $this->render('admin/personnel/form.html.twig', [
            'form' => $form->createView(),
            'utilisateur' => $utilisateur,
            'creation' => true,
        ]);
    }

    #[Route('/{id}/modifier', name: 'modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, User $utilisateur): Response
    {
        $form = $this->createForm(UserType::class, $utilisateur, ['is_admin_creation' => true]);
        $form->get('role')->setData(Role::principal($utilisateur));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->repository->emailDejaUtilise((string) $utilisateur->getEmail(), $utilisateur->getId())) {
                $form->get('email')->addError(new FormError('Cette adresse est déjà utilisée par un autre compte.'));
            } else {
                $this->gestionCompte->mettreAJourPersonnel(
                    $utilisateur,
                    (string) $form->get('role')->getData()
                );

                $this->addFlash('success', 'Le compte a été mis à jour.');

                return $this->redirectToRoute('app_admin_personnel_index');
            }
        }

        return $this->render('admin/personnel/form.html.twig', [
            'form' => $form->createView(),
            'utilisateur' => $utilisateur,
            'creation' => false,
        ]);
    }

    #[Route('/{id}/reinitialiser-mot-de-passe', name: 'reinitialiser', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reinitialiserMotDePasse(Request $request, User $utilisateur): Response
    {
        if (!$this->isCsrfTokenValid('reinitialiser_' . $utilisateur->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_admin_personnel_index');
        }

        $motDePasse = $this->gestionCompte->reinitialiserMotDePasse($utilisateur);

        $this->addFlash('success', sprintf(
            'Nouveau mot de passe de %s : <strong class="font-mono">%s</strong> — communiquez-le hors '
            . 'bande, il ne sera plus affiché.',
            htmlspecialchars($utilisateur->getNomComplet(), \ENT_QUOTES),
            htmlspecialchars($motDePasse, \ENT_QUOTES)
        ));

        return $this->redirectToRoute('app_admin_personnel_index');
    }

    #[Route('/{id}/basculer-activation', name: 'basculer_activation', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function basculerActivation(Request $request, User $utilisateur): Response
    {
        if (!$this->isCsrfTokenValid('activation_' . $utilisateur->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Action expirée, veuillez réessayer.');

            return $this->redirectToRoute('app_admin_personnel_index');
        }

        /** @var User $auteur */
        $auteur = $this->getUser();

        // Lève SuppressionInterditeException si l'administrateur tente de se
        // désactiver lui-même ; l'ExceptionSubscriber la rend en message flash.
        $this->gestionCompte->basculerActivation($utilisateur, $auteur);

        $this->addFlash('success', sprintf(
            'Le compte de %s est désormais %s.',
            $utilisateur->getNomComplet(),
            $utilisateur->isActif() ? 'actif' : 'désactivé'
        ));

        return $this->redirectToRoute('app_admin_personnel_index');
    }

    private function roleFiltre(Request $request): ?string
    {
        $role = (string) $request->query->get('role', '');

        return array_key_exists($role, $this->rolesAssignables()) ? $role : null;
    }

    private function centreFiltre(Request $request): ?Centre
    {
        $id = $request->query->getInt('centre');

        return $id > 0 ? $this->centreRepository->find($id) : null;
    }

    /**
     * Rôles proposés à l'administration : les candidats ne sont pas gérés depuis
     * cet écran, et ROLE_JURY reste sans fonctionnalité (décision P4 de final.txt).
     *
     * @return array<string, string>
     */
    private function rolesAssignables(): array
    {
        $libelles = Role::libelles();
        unset($libelles[Role::CANDIDAT]);

        return $libelles;
    }
}
