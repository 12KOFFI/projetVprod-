<?php

namespace App\Tests\Fonctionnel;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base commune aux suites fonctionnelles : un client HTTP, l'atelier de
 * données, et surtout la garantie que la base de test retrouve son état
 * d'origine après chaque test.
 */
abstract class SocleFonctionnel extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;
    protected AtelierVae $atelier;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $conteneur = static::getContainer();

        $this->entityManager = $conteneur->get(EntityManagerInterface::class);
        $this->atelier = new AtelierVae(
            $this->entityManager,
            $conteneur->get(UserPasswordHasherInterface::class)
        );

        // Nettoyage en entrée aussi : un test interrompu ne doit pas empoisonner
        // la session suivante.
        $this->atelier->purger();
    }

    protected function tearDown(): void
    {
        $this->atelier->purger();

        parent::tearDown();
    }
}
