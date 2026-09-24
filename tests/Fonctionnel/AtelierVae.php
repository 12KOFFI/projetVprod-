<?php

namespace App\Tests\Fonctionnel;

use App\Entity\Candidature;
use App\Entity\Centre;
use App\Entity\CentreMetier;
use App\Entity\DirectionRegionale;
use App\Entity\Filiere;
use App\Entity\Localite;
use App\Entity\Metier;
use App\Entity\Paiement;
use App\Entity\User;
use App\Enum\MoyenPaiement;
use App\Enum\StatutPaiement;
use App\Enum\TypeFrais;
use App\Security\Role;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données des tests fonctionnels et de bout en bout.
 *
 * Tout ce qui est créé ici porte le préfixe PREFIXE dans son libellé, son
 * numéro ou son adresse électronique. C'est ce préfixe qui rend le nettoyage
 * sûr : la base de test contient aussi le référentiel réel (30 métiers, 20
 * centres, les comptes @daip.ci), que les tests ne doivent jamais toucher.
 */
final class AtelierVae
{
    public const PREFIXE = 'TSTE2E';
    public const MOT_DE_PASSE = 'MotDePasseTest2026!';

    private Connection $connexion;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        $this->connexion = $this->entityManager->getConnection();
    }

    /**
     * Deux centres, pour éprouver le cloisonnement : un conseiller ne voit que
     * les dossiers du sien (R4.2).
     *
     * @return array{centre: Centre, centreAutre: Centre, metier: Metier}
     */
    public function referentiel(): array
    {
        $direction = (new DirectionRegionale())->setLibelle(self::PREFIXE . ' Direction');
        $this->entityManager->persist($direction);

        $localite = (new Localite())
            ->setLibelle(self::PREFIXE . ' Localite')
            ->setDirectionRegionale($direction);
        $this->entityManager->persist($localite);

        $centre = (new Centre())
            ->setNom(self::PREFIXE . ' Centre A')
            ->setType('etablissement')
            ->setLocalite($localite);
        $this->entityManager->persist($centre);

        $centreAutre = (new Centre())
            ->setNom(self::PREFIXE . ' Centre B')
            ->setType('etablissement')
            ->setLocalite($localite);
        $this->entityManager->persist($centreAutre);

        $filiere = (new Filiere())->setLibelle(self::PREFIXE . ' Filiere');
        $this->entityManager->persist($filiere);

        $metier = (new Metier())
            ->setLibelle(self::PREFIXE . ' Metier')
            ->setFiliere($filiere)
            ->setStatut('actif');
        $this->entityManager->persist($metier);

        // Sans cette ligne d'offre, aucun dépôt n'est possible sur ce couple (R3.2).
        $offre = (new CentreMetier())
            ->setCentre($centre)
            ->setMetier($metier)
            ->setNbrplace(10);
        $this->entityManager->persist($offre);

        $this->entityManager->flush();

        return ['centre' => $centre, 'centreAutre' => $centreAutre, 'metier' => $metier];
    }

    public function utilisateur(
        string $cle,
        string $role,
        ?Centre $centre = null,
        ?Metier $metier = null,
        string $nom = 'TESTNOM',
        string $prenoms = 'Prenom',
    ): User {
        $utilisateur = (new User())
            ->setEmail(sprintf('%s.%s@test.local', strtolower(self::PREFIXE), $cle))
            ->setNom($nom)
            ->setPrenoms($prenoms)
            ->setCentre($centre)
            ->setMetier($metier)
            ->setActif(true)
            ->setDoitChangerMotDePasse(false);

        $utilisateur->setRoles([$role]);
        $utilisateur->setPassword($this->hasher->hashPassword($utilisateur, self::MOT_DE_PASSE));

        $this->entityManager->persist($utilisateur);
        $this->entityManager->flush();

        return $utilisateur;
    }

    /**
     * Candidature posée directement dans l'état voulu, sans passer par
     * TransitionCandidature : il s'agit de fabriquer un point de départ, pas de
     * rejouer le parcours. C'est la convention déjà retenue par
     * ChargerJeuDemoCommand.
     */
    public function candidature(
        User $candidat,
        Centre $centre,
        Metier $metier,
        ?int $etu = null,
        ?int $rec = null,
        ?int $elig = null,
        ?int $resultat = null,
        ?int $admis = null,
        bool $fraisDossierRegles = false,
        ?User $conseiller = null,
    ): Candidature {
        $candidature = (new Candidature())
            ->setNumero($this->numeroLibre())
            ->setUser($candidat)
            ->setCentre($centre)
            ->setMetier($metier)
            ->setNbAnneesExperience(8)
            ->setEtuStatut($etu)
            ->setRecStatut($rec)
            ->setEligStatut($elig)
            ->setResultat($resultat)
            ->setAdmis($admis);

        if ($conseiller !== null) {
            $candidature->setConseiller($conseiller);
        }

        $this->entityManager->persist($candidature);
        $this->entityManager->flush();

        if ($fraisDossierRegles) {
            $this->paiementReussi($candidature, TypeFrais::DOSSIER);
        }

        return $candidature;
    }

    public function paiementReussi(Candidature $candidature, TypeFrais $type): Paiement
    {
        $paiement = (new Paiement())
            ->setCandidature($candidature)
            ->setUser($candidature->getUser())
            ->setType($type)
            ->setMontant('10000.00')
            ->setStatut(StatutPaiement::REUSSI)
            ->setMoyenPaiement(MoyenPaiement::MOBILE_MONEY)
            ->setReferencePaiement(sprintf('%s-%s', (string) $candidature->getNumero(), $type->value))
            ->setDatePaiement(new \DateTime());

        $this->entityManager->persist($paiement);
        $this->entityManager->flush();

        return $paiement;
    }

    /**
     * Numéro hors de l'espace de numérotation réel (VAE{YY}{NNN}) : les tests
     * ne doivent pas consommer une séquence que l'application attribuerait.
     */
    public function numeroLibre(): string
    {
        static $sequence = 0;

        return sprintf('%s%04d', self::PREFIXE, ++$sequence);
    }

    /**
     * Supprime tout ce que les tests ont écrit, dans l'ordre inverse des
     * dépendances. Les contraintes de clé étrangère refusent toute autre
     * séquence, et le référentiel réel reste intact.
     */
    public function purger(): void
    {
        $prefixe = self::PREFIXE . '%';
        $emailPrefixe = strtolower(self::PREFIXE) . '.%';

        $this->connexion->executeStatement(
            'DELETE t FROM transaction_paiement t
             JOIN paiement p ON p.id = t.paiement_id
             JOIN candidature c ON c.id = p.candidature_id
             WHERE c.numero LIKE ?',
            [$prefixe]
        );
        $this->connexion->executeStatement(
            'DELETE p FROM paiement p JOIN candidature c ON c.id = p.candidature_id WHERE c.numero LIKE ?',
            [$prefixe]
        );
        $this->connexion->executeStatement(
            'DELETE h FROM historique_statut h JOIN candidature c ON c.id = h.candidature_id WHERE c.numero LIKE ?',
            [$prefixe]
        );
        // Les dossiers déposés par le parcours réel portent un numéro VAE
        // normal : on les rattrape par leur candidat, tous préfixés.
        $this->connexion->executeStatement(
            'DELETE h FROM historique_statut h
             JOIN candidature c ON c.id = h.candidature_id
             JOIN user u ON u.id = c.user_id
             WHERE u.email LIKE ?',
            [$emailPrefixe]
        );
        $this->connexion->executeStatement(
            'DELETE p FROM paiement p
             JOIN candidature c ON c.id = p.candidature_id
             JOIN user u ON u.id = c.user_id
             WHERE u.email LIKE ?',
            [$emailPrefixe]
        );
        $this->connexion->executeStatement('DELETE FROM candidature WHERE numero LIKE ?', [$prefixe]);
        $this->connexion->executeStatement(
            'DELETE c FROM candidature c JOIN user u ON u.id = c.user_id WHERE u.email LIKE ?',
            [$emailPrefixe]
        );

        $this->connexion->executeStatement('DELETE FROM historique_statut WHERE auteur_id IN (SELECT id FROM user WHERE email LIKE ?)', [$emailPrefixe]);
        $this->connexion->executeStatement('DELETE FROM import_jury WHERE auteur_id IN (SELECT id FROM user WHERE email LIKE ?)', [$emailPrefixe]);
        $this->connexion->executeStatement('DELETE FROM user WHERE email LIKE ?', [$emailPrefixe]);

        $this->connexion->executeStatement(
            'DELETE cmc FROM centre_metier_certification cmc
             JOIN centre_metier cm ON cm.id = cmc.centre_metier_id
             JOIN centre ce ON ce.id = cm.centre_id
             WHERE ce.nom LIKE ?',
            [$prefixe]
        );
        $this->connexion->executeStatement(
            'DELETE cm FROM centre_metier cm JOIN centre ce ON ce.id = cm.centre_id WHERE ce.nom LIKE ?',
            [$prefixe]
        );
        $this->connexion->executeStatement('DELETE FROM certification WHERE libelle LIKE ?', [$prefixe]);
        $this->connexion->executeStatement('DELETE FROM metier WHERE libelle LIKE ?', [$prefixe]);
        $this->connexion->executeStatement('DELETE FROM filiere WHERE libelle LIKE ?', [$prefixe]);
        $this->connexion->executeStatement('DELETE FROM centre WHERE nom LIKE ?', [$prefixe]);
        $this->connexion->executeStatement('DELETE FROM localite WHERE libelle LIKE ?', [$prefixe]);
        $this->connexion->executeStatement('DELETE FROM direction_regionale WHERE libelle LIKE ?', [$prefixe]);

        $this->entityManager->clear();
    }

    public function roleCandidat(): string
    {
        return Role::CANDIDAT;
    }
}
