<?php

namespace App\Repository;

use App\Entity\Centre;
use App\Entity\Metier;
use App\Entity\User;
use App\Security\Role;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 * @implements PasswordUpgraderInterface<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function save(User $user, bool $flush = false): void
    {
        $this->getEntityManager()->persist($user);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->save($user, true);
    }

    /**
     * Recherche par email ou par numéro de contact normalisé.
     * Reprend la logique déjà appliquée par AppAuthenticator.
     */
    public function findOneByIdentifiant(string $identifiant): ?User
    {
        $identifiant = trim($identifiant);

        $user = $this->findOneBy(['email' => strtolower($identifiant)]);
        if ($user !== null) {
            return $user;
        }

        $contact = preg_replace('/\D+/', '', $identifiant);

        return $contact !== '' ? $this->findOneBy(['contact' => $contact]) : null;
    }

    /**
     * Membres du personnel d'un centre portant le rôle demandé.
     *
     * Les rôles étant stockés en JSON, le filtrage fin est fait en PHP :
     * les effectifs par centre sont faibles (quelques dizaines), ce qui rend
     * une requête JSON_CONTAINS non portable inutile ici.
     *
     * @return User[]
     */
    public function findPersonnelParCentre(Centre $centre, string $role): array
    {
        $utilisateurs = $this->createQueryBuilder('u')
            ->andWhere('u.centre = :centre')
            ->andWhere('u.actif = true')
            ->setParameter('centre', $centre)
            ->orderBy('u.nom', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $utilisateurs,
            static fn (User $u): bool => in_array($role, $u->getRoles(), true)
        ));
    }

    /**
     * Conseillers VAE d'un centre, pour l'écran de charge de l'agent
     * d'accueil (spec 5.2).
     *
     * @return User[]
     */
    public function findConseillersParCentre(Centre $centre): array
    {
        return $this->findPersonnelParCentre($centre, Role::CONSEILLER);
    }

    /**
     * Accompagnateurs actifs d'un centre pour un métier donné (spec 5.4).
     *
     * @return User[]
     */
    public function findAccompagnateursParCentreEtMetier(Centre $centre, Metier $metier): array
    {
        $utilisateurs = $this->createQueryBuilder('u')
            ->andWhere('u.centre = :centre')
            ->andWhere('u.metier = :metier')
            ->andWhere('u.actif = true')
            ->setParameter('centre', $centre)
            ->setParameter('metier', $metier)
            ->orderBy('u.nom', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $utilisateurs,
            static fn (User $u): bool => in_array(Role::ACCOMPAGNATEUR, $u->getRoles(), true)
        ));
    }

    public function remove(User $user, bool $flush = false): void
    {
        $this->getEntityManager()->remove($user);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Liste du personnel de l'écran E2.7.
     *
     * Le filtre par rôle s'appuie sur une comparaison de chaîne sur la colonne
     * JSON : JSON_CONTAINS n'est pas portable via DQL, et les rôles sont des
     * chaînes préfixées « ROLE_ » suffisamment discriminantes pour un LIKE.
     * Les candidats sont exclus : cet écran ne gère que les comptes internes.
     */
    public function queryListePersonnel(?string $role = null, ?Centre $centre = null, ?string $recherche = null): Query
    {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.centre', 'c')->addSelect('c')
            ->leftJoin('u.metier', 'm')->addSelect('m')
            ->andWhere('u.roles NOT LIKE :seulCandidat')
            ->setParameter('seulCandidat', '["' . Role::CANDIDAT . '"]')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenoms', 'ASC');

        if ($role !== null && $role !== '') {
            $qb->andWhere('u.roles LIKE :role')->setParameter('role', '%"' . $role . '"%');
        }

        if ($centre !== null) {
            $qb->andWhere('u.centre = :centre')->setParameter('centre', $centre);
        }

        if ($recherche !== null && $recherche !== '') {
            $qb->andWhere('u.nom LIKE :recherche OR u.prenoms LIKE :recherche OR u.email LIKE :recherche OR u.contact LIKE :recherche')
                ->setParameter('recherche', '%' . $recherche . '%');
        }

        return $qb->getQuery();
    }

    /**
     * Unicité applicative de l'email (validation V2.5) : l'identifiant de
     * connexion doit rester unique même si la colonne est nullable.
     */
    public function emailDejaUtilise(string $email, ?int $exclureId = null): bool
    {
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('LOWER(u.email) = :email')
            ->setParameter('email', mb_strtolower(trim($email)));

        if ($exclureId !== null) {
            $qb->andWhere('u.id != :id')->setParameter('id', $exclureId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Dépendance bloquante de R2.5 : un centre auquel du personnel est rattaché
     * n'est pas supprimable.
     */
    public function countParCentre(Centre $centre): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.centre = :centre')
            ->setParameter('centre', $centre)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Dépendance bloquante de R2.4 : un métier servant de rattachement à un
     * accompagnateur ne peut pas être supprimé.
     */
    public function countParMetier(Metier $metier): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.metier = :metier')
            ->setParameter('metier', $metier)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
