# LeadOps

LeadOps est un démonstrateur local de traitement de leads B2B fictifs. Il illustre un pipeline déterministe allant de la réception à la validation, la normalisation, la déduplication, le score, la classification, le routage et la transmission simulée, avec une timeline explicable et une file d’exceptions.

Ce dépôt est un MVP de portfolio, pas un CRM ni un service prêt à recevoir des données réelles.

[Démonstration en ligne](https://leadops.mkirchel.com) — accès public strictement en lecture seule, avec des données fictives.

## Technologies

- PHP 8.3 et Laravel 13 ;
- Blade pour le rendu serveur ;
- Vue 3 pour les deux îlots interactifs uniquement ;
- Tailwind CSS 4 et Vite 8 ;
- SQLite pour la démonstration locale ;
- queue Laravel avec le pilote `database` ;
- PHPUnit et tests JavaScript natifs Node.js.

## Fonctionnalités

- réception par formulaire, import CSV et webhook JSON local ;
- validation serveur, normalisation et déduplication ;
- score reproductible, classes `HOT`, `WARM` et `LOW` ;
- routage déterministe et transmission locale simulée ;
- traitement asynchrone, idempotence et trois tentatives maximum par cycle ;
- dashboard, listes filtrées et paginées, détail et timeline ;
- correction des cas `NEEDS_REVIEW` et reprise des cas `FAILED`, uniquement en environnement local ou de test ;
- huit scénarios de démonstration fictifs et reproductibles.

## Installation locale

Prérequis : PHP 8.3, Composer 2, Node.js 20.19 ou plus récent dans la branche 20.x, et npm.

```shell
composer install
php -r "file_exists('.env') || copy('.env.example', '.env');"
php artisan key:generate
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate --seed
npm ci
npm run build
php artisan serve
```

L’application est alors disponible à l’adresse affichée par Laravel, généralement `http://127.0.0.1:8000`.

## Lancer la queue

Dans un second terminal :

```shell
php artisan queue:work database --queue=lead-processing --tries=3
```

Le job `ProcessLead` impose également trois tentatives par cycle et des délais de reprise déterministes.

## Charger les huit scénarios fictifs

Sur une base déjà migrée :

```shell
php artisan db:seed --class=LeadDemoSeeder
```

Le seeder est idempotent. Pour reconstruire une base SQLite jetable depuis zéro :

```shell
php artisan migrate:fresh --seed
```

`migrate:fresh` détruit toutes les tables de la base ciblée. Ne l’utilisez que sur une base locale jetable.

Les scénarios couvrent trois leads routés (`HOT`, `WARM`, `LOW`), deux invalides, un doublon, un cas à examiner et un échec technique. Leur détail se trouve dans [docs/LeadOps_Demo_Scenarios.md](docs/LeadOps_Demo_Scenarios.md).

## Tests et contrôles qualité

```shell
composer validate --strict
composer audit --locked --no-interaction
vendor/bin/pint --test
php artisan test
npm audit --audit-level=low
npm run test:js
npm run build
```

La CI GitHub exécute également une reconstruction et un double chargement du seeder sur une base SQLite jetable.

## Déploiement public en lecture seule

Le déploiement public validé au 10 octobre 2026 utilise DigitalOcean App Platform en région `LON1`, une instance `apps-s-1vcpu-0.5gb` (1 conteneur, 5 USD/mois) et le commit `b15146f` de `main`. L’auto-déploiement est désactivé. La configuration non secrète est décrite dans [`.do/app.yaml`](.do/app.yaml), où `region: lon` désigne `LON1`.

Le buildpack PHP natif utilise les assets Vite préconstruits et versionnés et reconstruit une base SQLite déterministe : **8 leads, 68 événements et 0 job**. Le service reste strictement en lecture seule, sans service de base de données, worker, volume ni Redis. Le readiness check HTTP `GET /health`, sur le port interne `8080`, renvoie `204`. Les outils `/tools/webhook`, `/imports/csv` et `/leads/create` renvoient `404` en production.

Le domaine principal est `https://leadops.mkirchel.com`, avec HTTPS valide et certificat TLS géré par DigitalOcean. Cloudflare gère le DNS : CNAME `leadops` vers `leadops-88t64.ondigitalocean.app`, en mode DNS-only (proxy désactivé). DNSSEC reste volontairement désactivé en raison de la limitation actuelle d’App Platform. L’ancien domaine redirige en `301` vers le domaine principal en conservant les chemins, notamment `/leads`. `${APP_URL}` reste liée au domaine principal. Le dashboard, Leads et Exceptions sont validés ; le détail des contrôles figure dans le runbook et le rapport de readiness.

`APP_KEY` est chiffrée et limitée au runtime ; sa valeur ne doit jamais être documentée, affichée ou versionnée. Les procédures de déploiement manuel, de rollback et de vérification après déploiement sont décrites dans le [runbook DigitalOcean](docs/DigitalOcean_Deployment_Runbook.md). La fusion d’une PR ou un push sur `main` ne déclenche pas de déploiement.

## Architecture de sécurité

- toutes les routes web non sûres sont refusées hors `local` et `testing` ;
- les outils locaux possèdent une garde d’environnement exécutée à chaque requête, même si un cache de routes local est réutilisé par erreur ;
- la version non locale expose uniquement les parcours de lecture ;
- CSRF sur toutes les mutations avec session ; webhook local sans session mais strictement limité en environnement et en débit ;
- limites de débit indexées par un HMAC fondé sur `APP_KEY`, sans conserver l’adresse réseau brute ;
- validation serveur et listes de champs explicites avant toute persistance ;
- contrainte SQL unique pour l’idempotence, transactions et verrous applicatifs pour les transitions ;
- événements réduits à une liste blanche de métadonnées, sans payload, e-mail, téléphone ni message ;
- erreurs HTTP génériques et logs applicatifs sans valeurs de ligne importée ;
- échappement Blade/Vue par défaut, sans rendu HTML arbitraire ;
- sessions chiffrées et réponses dynamiques marquées `no-store` ;
- en-têtes `nosniff`, anti-framing, politique de référent et permissions navigateur minimales.

DigitalOcean assure la terminaison HTTPS de la démonstration publique. L’application n’ajoute actuellement ni HSTS ni CSP ; ces politiques restent à évaluer avant toute extension du périmètre.

## Limites connues

- SQLite ne fournit pas la preuve d’un verrouillage de ligne multiprocessus équivalente à celle d’un moteur SQL serveur ;
- la queue et les verrous partagent l’infrastructure locale, adaptée à une démonstration mono-opérateur ;
- aucune outbox transactionnelle ne couvre une future transmission externe ;
- aucune authentification métier n’est incluse ; les mutations doivent rester désactivées hors environnement local ou de test ;
- l’hébergement public se limite au jeu fictif en lecture seule ; aucune politique de rétention avancée ni intégration externe n’est fournie ;
- le simulateur webhook nécessite JavaScript, tandis que les écrans Blade principaux et l’import conservent leur parcours serveur sans JavaScript.

Avant toute mutation publique, LeadOps devra disposer d’une authentification, d’autorisations explicites, d’un moteur SQL serveur et d’une stratégie de déploiement sécurisée.

## Données et intégrations

LeadOps utilise exclusivement des identités, entreprises, domaines et numéros fictifs. Aucune donnée personnelle ou client réelle n’est fournie ou attendue. Le MVP n’appelle aucune API d’IA, aucun CRM et aucun service de transmission réel ; l’adaptateur de transmission est entièrement local et simulé.

Les décisions détaillées sont documentées dans [l’architecture du MVP](docs/LeadOps_MVP_Architecture.md) et l’état final dans [le rapport de readiness](docs/LeadOps_MVP_Readiness.md).
