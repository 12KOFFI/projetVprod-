# PROJET VAE — DAIP 2026

Application de gestion de la **Validation des Acquis de l'Expérience (VAE)** :
dépôt et suivi des candidatures, affectation aux centres et aux jurys, gestion
des paiements et production des indicateurs.

---

## Stack technique

| Composant      | Version / outil                          |
| -------------- | ---------------------------------------- |
| Framework      | Symfony 6.2                              |
| PHP            | 8.0.2 minimum (8.1 en production)        |
| Base de donnees| MySQL / MariaDB (Doctrine ORM 2)         |
| Templates      | Twig 3                                   |
| Assets         | Webpack Encore + Tailwind CSS            |
| Exports        | DomPDF, PhpSpreadsheet, PhpWord          |

## Profils utilisateurs

`ROLE_CANDIDAT`, `ROLE_CONSEILLER`, `ROLE_ACCOMPAGNATEUR`, `ROLE_JURY`,
`ROLE_AGENT_ACCEUIL`, `ROLE_ADMIN`.

## Principaux domaines fonctionnels

- **Candidatures** — dépôt, suivi de statut et historisation (`HistoriqueStatut`)
- **Espace candidat** — parcours dédié aux candidats
- **Conseiller / Administration** — instruction des dossiers et pilotage
- **Référentiels** — centres, certifications, filières, métiers, localités,
  directions régionales
- **Jurys** — import et affectation (`ImportJury`)
- **Paiements** — paiements et transactions
- **Indicateurs & impressions** — statistiques et documents imprimables

---

## Développement local

Prérequis : PHP 8.1, Composer, Node.js, MySQL.

```bash
composer install
npm install
npm run dev

# Configuration locale (non versionnée)
cp .env.dist .env.local   # puis renseigner DATABASE_URL, APP_SECRET, MAILER_DSN

php bin/console doctrine:migrations:migrate
symfony server:start
```

---

## Déploiement en production (hébergement mutualisé)

> Le serveur de production ne dispose **ni de Composer, ni de Node, ni de la
> console Symfony** : uniquement PHP 8.1 et Apache (cPanel). Le dépôt est donc
> conçu pour être déployé **tel quel**, sans aucune commande à exécuter.

### Ce que le dépôt embarque volontairement

| Dossier         | Pourquoi il est versionné                                      |
| --------------- | -------------------------------------------------------------- |
| `vendor/`       | `composer install` est impossible en production                  |
| `public/build/` | `npm run build` est impossible en production                     |
| `var/`          | Seule la structure des dossiers (`.gitkeep`). Le cache compilé   |
|                 | n'est **jamais** versionné : il contient des chemins absolus     |
|                 | Windows et doit être régénéré par le serveur                     |

### Architecture web

Le `.htaccess` à la racine redirige en interne tout le trafic vers `public/`,
qui est la véritable racine web de Symfony. Aucune condition « fichier
existant » n'y figure : les fichiers sensibles restés à la racine
(`.env.local`, `config/`, `src/`, `vendor/`, `var/`) sont donc **inaccessibles
depuis le web**, même si la racine du domaine pointe sur le dossier du projet.

### Procédure

1. **Récupérer le code** sur le serveur (clone Git depuis cPanel, ou archive).

2. **Créer le fichier de configuration.** Copier `.env.dist` en **`.env.local`**
   à la racine, puis y renseigner les vraies valeurs :

   ```dotenv
   APP_SECRET=<valeur aleatoire de 32 caracteres hexadecimaux>
   DATABASE_URL="mysql://<utilisateur>:<mot_de_passe>@127.0.0.1:3306/daip_vae?serverVersion=8.0.32&charset=utf8mb4"
   MAILER_DSN=<smtp reel>
   ```

   > ⚠️ **Étape obligatoire.** `.env` et `.env.local` ne sont pas versionnés :
   > après un clone, seul `.env.dist` est présent et ne contient que des valeurs
   > d'exemple. Sans `.env.local`, l'application démarre sur une base inexistante.
   >
   > ⚠️ Sur cPanel, les noms de base et d'utilisateur sont **préfixés par le
   > login cPanel** (ex. `monlogin_daip_vae`). Vérifier les noms exacts dans
   > *Bases de données MySQL*, ainsi que la valeur de `serverVersion` dans
   > phpMyAdmin.

3. **Droits d'écriture sur `var/`** (`755` ou `775`). Symfony y recompile son
   cache automatiquement à la première visite, puisqu'aucune commande
   `cache:clear` ne peut être lancée.

4. **Importer la base de données** via phpMyAdmin (les migrations Doctrine ne
   peuvent pas être exécutées sans console).

### Barre de debug

`.env.dist` est livré en `APP_ENV=dev`, ce qui affiche le Web Profiler en bas de
chaque page — utile pour diagnostiquer la mise en route sans accès console.

> ⚠️ **À désactiver une fois le site validé.** En mode dev, n'importe quel
> visiteur peut consulter les requêtes SQL, la configuration complète, les
> variables d'environnement (dont le mot de passe de la base) et les traces
> d'erreur détaillées. Basculer alors `.env.local` en :
>
> ```dotenv
> APP_ENV=prod
> APP_DEBUG=0
> ```

### Après une mise à jour du code

Les dépendances et les assets étant versionnés, une mise à jour se résume à
récupérer le code, puis à **vider le dossier `var/cache/`** pour que Symfony
reconstruise son cache à la visite suivante.
