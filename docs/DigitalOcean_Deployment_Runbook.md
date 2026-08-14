# Déploiement DigitalOcean App Platform — lecture seule

## Architecture retenue

Le déploiement cible une seule ressource **Web Service** DigitalOcean App Platform avec le buildpack PHP natif, sur la pile Ubuntu 22. Le modèle [`.do/app.yaml`](../.do/app.yaml) utilise le dépôt GitHub privé `Medyour/leadops`, la branche `main`, désactive `deploy_on_push` et sélectionne une seule instance `apps-s-1vcpu-0.5gb`.

Le buildpack PHP DigitalOcean reconnaît Laravel via `composer.json`, utilise actuellement le buildpack PHP Heroku v281 et prend en charge PHP 8.1 à 8.5 sur Ubuntu 22, dont PHP 8.3.0 à 8.3.30. Le dépôt contraint la production à `~8.3.0`. L’exemple Laravel officiel utilise `heroku-php-apache2 public/`, qui reste la commande de démarrage retenue. Aucun Dockerfile n’est nécessaire. Références : [buildpack PHP](https://docs.digitalocean.com/products/app-platform/reference/buildpacks/php/), [exemple Laravel](https://docs.digitalocean.com/products/app-platform/getting-started/sample-apps/laravel/) et [commandes de build et de démarrage](https://docs.digitalocean.com/products/app-platform/how-to/build-run-commands/).

Le script `scripts/prepare-production.sh` :

1. installe les dépendances Composer sans paquets de développement ;
2. vérifie que le manifeste Vite préconstruit et versionné est présent ;
3. recrée `database/demo.sqlite` par migrations fraîches et `LeadDemoSeeder` uniquement ;
4. vérifie exactement 8 leads, 68 événements et 0 job, puis supprime tout journal SQLite résiduel ;
5. rend le fichier SQLite non inscriptible et prépare les caches de routes et de vues.

`environment_slug: php` garantit le buildpack PHP, mais la documentation DigitalOcean ne garantit pas la présence simultanée de Node/npm dans ce runtime. Le build DigitalOcean ne les invoque donc pas. La CI utilise Node 20.20.2 pour construire Vite, vérifie que les fichiers versionnés sous `public/build` sont strictement à jour, puis la préparation de production contrôle leur présence. Références : [buildpack PHP](https://docs.digitalocean.com/products/app-platform/reference/buildpacks/php/) et [buildpack Node.js](https://docs.digitalocean.com/products/app-platform/reference/buildpacks/nodejs/).

La base fait partie de l’image construite. Elle est volontairement reconstructible et jetable. Au runtime, `DB_READ_ONLY=true` active `PRAGMA query_only=ON` sur chaque connexion SQLite. Les routes non sûres restent en plus refusées hors `local` et `testing`.

Le filesystem App Platform est éphémère, limité et sans volumes persistants. Une nouvelle instance ou un redéploiement supprime toute modification locale. Ce comportement est compatible uniquement parce qu’aucune donnée runtime importante n’est créée et que la base de démonstration est reconstruite à chaque build. Référence : [stockage App Platform](https://docs.digitalocean.com/products/app-platform/how-to/store-data/).

## Variables à configurer

Le modèle App Spec contient les valeurs non secrètes. Avant le premier déploiement, ajouter manuellement au composant web :

| Variable | Portée | Chiffrée | Valeur |
|---|---|---:|---|
| `APP_KEY` | Run time | oui | sortie de `php artisan key:generate --show`, générée hors Git |

Les variables importantes déjà décrites par le modèle sont :

| Variable | Valeur |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `${APP_URL}`, URL primaire fournie par DigitalOcean |
| `DB_CONNECTION` | `sqlite` |
| `DB_DATABASE` | `database/demo.sqlite` |
| `DB_READ_ONLY` | `true` |
| `SESSION_DRIVER` | `array` |
| `CACHE_STORE` | `array` |
| `QUEUE_CONNECTION` | `discard` (connexion Laravel utilisant le pilote `null`) |
| `QUEUE_FAILED_DRIVER` | `null` |
| `LOG_CHANNEL` | `stderr` |
| `MAIL_MAILER` | `array` |
| `SESSION_SECURE_COOKIE` | `true` |
| `SESSION_HTTP_ONLY` | `true` |
| `SESSION_SAME_SITE` | `lax` |
| `TRUSTED_PROXIES` | `*` (seul `X-Forwarded-Proto` est accepté) |

`APP_KEY` ne figure volontairement pas dans le fichier App Spec, même sous forme de valeur factice déployable. DigitalOcean permet de chiffrer les variables sensibles et fournit `${APP_URL}` comme variable liée à l’URL primaire. Référence : [variables d’environnement et secrets](https://docs.digitalocean.com/products/app-platform/how-to/use-environment-variables/).

## Création manuelle du service

Cette procédure décrit les actions à effectuer ; elle ne les automatise pas.

1. Dans DigitalOcean, choisir **Create > App Platform**.
2. Autoriser l’accès en lecture au dépôt GitHub privé strictement nécessaire, puis sélectionner `Medyour/leadops` et `main`.
3. Désactiver le déploiement automatique sur push.
4. Importer les réglages de `.do/app.yaml` ou les reproduire dans l’interface.
5. Vérifier qu’il existe exactement un Web Service, aucun worker, job, volume, Redis ou base gérée.
6. Vérifier le buildpack PHP, la pile Ubuntu 22, le port HTTP `8080`, la commande de build et la commande de démarrage.
7. Sélectionner une instance `apps-s-1vcpu-0.5gb`, quantité `1`.
8. Ajouter `APP_KEY` comme variable chiffrée de portée runtime avant le lancement.
9. Vérifier le récapitulatif et le coût, puis déclencher le premier déploiement manuellement.

Le plan `apps-s-1vcpu-0.5gb` fournit actuellement 1 vCPU partagé, 512 Mio de RAM et 50 Gio de transfert pour 5 USD par mois. Les services sont facturés au tarif mensuel, au prorata de la seconde ; le transfert sortant au-delà de l’allocation est facturé séparément. Référence : [tarification App Platform](https://docs.digitalocean.com/products/app-platform/details/pricing/).

## URL temporaire et HTTPS

Après un déploiement réussi, DigitalOcean attribue une URL `https://<nom>.ondigitalocean.app`. Utiliser cette URL technique avant tout sous-domaine personnalisé. Référence : [gestion des domaines](https://docs.digitalocean.com/products/app-platform/how-to/manage-domains/).

App Platform redirige HTTP vers HTTPS et termine TLS en bordure. Laravel accepte uniquement `X-Forwarded-Proto` depuis le proxy configuré ; les valeurs transmises de host, port et adresse cliente ne sont pas approuvées. Les cookies sont `Secure`, `HttpOnly` et `SameSite=Lax`. HSTS et CSP ne sont pas ajoutés par l’application ; une politique CSP doit être conçue après stabilisation des assets. Références : [limites HTTPS/HSTS](https://docs.digitalocean.com/products/app-platform/details/limits/) et [fonctionnalités HTTPS](https://docs.digitalocean.com/products/app-platform/details/features/).

## Health check

Le service utilise `GET /health` sur le port `8080`. Cette route Laravel dédiée renvoie `204 No Content`, reste hors du groupe de middlewares web, n’ouvre aucune session, ne consulte pas SQLite et ne révèle aucun contenu métier. Elle est incluse dans le cache de routes. Le health check laisse 10 secondes au démarrage, s’exécute toutes les 10 secondes et échoue après cinq erreurs consécutives. Référence : [health checks App Platform](https://docs.digitalocean.com/products/app-platform/how-to/manage-health-checks/).

## Contrôles après déploiement

1. Ouvrir l’URL `ondigitalocean.app` et vérifier le dashboard, la liste, le détail et la timeline.
2. Vérifier `GET /health` : statut `204`, corps vide.
3. Vérifier les redirections HTTP vers HTTPS et les URLs générées en `https://`.
4. Vérifier les en-têtes `Cache-Control`, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` et l’absence volontaire de CSP/HSTS applicatifs.
5. Vérifier que les routes locales renvoient `404` et que toutes les méthodes `POST`, `PUT`, `PATCH` et `DELETE` restent refusées (`404` pour les routes explicitement fermées, `405` lorsqu’aucune route ne correspond à la méthode).
6. Vérifier dans les logs de build : 8 leads, 68 événements et 0 job.
7. Vérifier l’absence de worker et que les logs applicatifs apparaissent dans les logs runtime DigitalOcean, pas dans un fichier persistant.
8. Vérifier que ni `database/*.sqlite`, ni `.env`, ni clé ou jeton ne sont suivis par Git.

## Rollback

App Platform conserve les déploiements réussis récents et permet de redéployer une version antérieure depuis l’onglet **Deployments**. Sélectionner le dernier déploiement connu comme sain, lancer le rollback, puis répéter les contrôles ci-dessus. La base SQLite contenue dans l’image du déploiement restauré correspond à cette version ; aucune restauration de données runtime n’est requise. DigitalOcean annonce la restauration possible vers l’un des dix derniers déploiements réussis dans les [fonctionnalités App Platform](https://docs.digitalocean.com/products/app-platform/details/features/).

## Connexion future du sous-domaine

Ne configurer le sous-domaine qu’après validation complète de l’URL technique.

1. Ajouter le sous-domaine dans l’onglet **Networking** de l’application.
2. Choisir la gestion DNS appropriée et créer le CNAME indiqué par DigitalOcean auprès du fournisseur DNS.
3. Attendre la validation DNS et l’émission automatique du certificat TLS.
4. Faire du sous-domaine le domaine primaire, vérifier que `${APP_URL}` est mis à jour, puis redéployer si nécessaire.
5. Répéter les contrôles HTTPS, cookies, assets et liens générés avant d’envisager HSTS ou une CSP.

Aucun changement DNS n’est requis ni autorisé dans la préparation actuelle.

## Limites et coûts

- Coût cible actuel : environ 5 USD/mois, hors dépassement éventuel de transfert.
- Une seule instance de 512 Mio : pas de haute disponibilité et capacité limitée.
- Filesystem éphémère : aucune sauvegarde ou donnée utilisateur persistante.
- Base SQLite strictement fictive et immuable : tout changement exige un nouveau build.
- Sessions et cache en mémoire de processus : aucune donnée importante ne doit en dépendre.
- Queue `discard` utilisant le pilote Laravel `null`, sans worker : tout job distribué par erreur est écarté et les parcours publics ne doivent en distribuer aucun.
- Aucun e-mail, CRM, IA ou service externe réel.
- L’offre à 5 USD n’autorise pas la mise à l’échelle manuelle ou automatique ; changer de capacité change le coût.

## Suppression du service

1. Noter l’URL et le dernier commit uniquement si un rapport de démonstration doit être conservé.
2. Retirer d’abord tout domaine personnalisé éventuel afin qu’il ne reste pas attaché à l’application.
3. Dans **Settings**, utiliser **Destroy**, saisir le nom de l’application et confirmer.
4. Vérifier que le Web Service n’apparaît plus, qu’aucune ressource associée n’existe et que la facturation s’arrête.
5. Retirer l’autorisation GitHub accordée à DigitalOcean si elle n’est plus utile.

La suppression détruit l’application et son filesystem éphémère. Aucun volume ni service de base de données n’existe dans cette architecture. Référence : [suppression d’une application](https://docs.digitalocean.com/products/app-platform/getting-started/quickstart/#destroy-an-app).
