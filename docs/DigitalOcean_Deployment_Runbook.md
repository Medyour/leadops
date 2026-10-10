# Déploiement DigitalOcean App Platform — lecture seule

## État public validé au 10 octobre 2026

Les informations suivantes ont été confirmées par l’opérateur. Elles décrivent le déploiement existant ; la présente mise à jour documentaire ne modifie pas DigitalOcean et ne déclenche aucun déploiement.

| Réglage | État validé |
|---|---|
| URL | [Démonstration en ligne](https://leadops-88t64.ondigitalocean.app) |
| Plateforme / région | DigitalOcean App Platform / `LON1` (`region: lon` dans l’App Spec) |
| Instance / coût | `apps-s-1vcpu-0.5gb`, 1 conteneur, 5 USD/mois |
| Source / déploiement | `main`, auto-déploiement désactivé (`deploy_on_push: false`) |
| Commit actuellement déployé | `b15146f` |
| Mode | strictement en lecture seule |
| SQLite déterministe | 8 leads, 68 événements, 0 job |
| Readiness check HTTP | `GET /health`, port interne `8080`, réponse `204` |
| Outils locaux | `/tools/webhook`, `/imports/csv`, `/leads/create` : `404` en production |
| Ressources supplémentaires | aucun service de base de données, worker, volume ou Redis |

`APP_KEY` est chiffrée et limitée au runtime. Ne jamais documenter, afficher, exporter ou versionner sa valeur, même dans une capture, un log, une PR ou une copie de l’App Spec. Ne jamais versionner une base SQLite ni un fichier d’environnement local.

Le slug régional `lon` suit la [correspondance officielle des régions App Platform](https://docs.digitalocean.com/products/app-platform/how-to/enable-vpc/) ; aucune connexion VPC n’est ajoutée.

## Architecture retenue

Le déploiement cible une seule ressource **Web Service** DigitalOcean App Platform avec le buildpack PHP natif, sur la pile Ubuntu 22. Le modèle [`.do/app.yaml`](../.do/app.yaml) utilise le dépôt GitHub privé `Medyour/leadops`, la branche `main`, désactive `deploy_on_push` et sélectionne une seule instance `apps-s-1vcpu-0.5gb`.

Le buildpack PHP DigitalOcean reconnaît Laravel via `composer.json`, utilise actuellement le buildpack PHP Heroku v281 et prend en charge PHP 8.1 à 8.5 sur Ubuntu 22, dont PHP 8.3.0 à 8.3.30. Le dépôt contraint la production à `~8.3.0`. L’exemple Laravel officiel utilise `heroku-php-apache2 public/`, qui reste la commande de démarrage retenue. Aucun Dockerfile n’est nécessaire. Références : [buildpack PHP](https://docs.digitalocean.com/products/app-platform/reference/buildpacks/php/), [exemple Laravel](https://docs.digitalocean.com/products/app-platform/getting-started/sample-apps/laravel/) et [commandes de build et de démarrage](https://docs.digitalocean.com/products/app-platform/how-to/build-run-commands/).

Le script `scripts/prepare-production.sh` :

1. installe les dépendances Composer sans paquets de développement ;
2. vérifie que le manifeste Vite préconstruit et versionné est présent ;
3. recrée `database/demo.sqlite` par migrations fraîches et `LeadDemoSeeder` uniquement ;
4. vérifie exactement 8 leads, 68 événements et 0 job, puis supprime tout journal SQLite résiduel ;
5. rend le fichier SQLite non inscriptible et prépare les caches de routes et de vues.

`environment_slug: php` garantit le buildpack PHP, mais la documentation DigitalOcean ne garantit pas la présence simultanée de Node/npm dans ce runtime. Le build DigitalOcean ne les invoque donc pas. La CI utilise Node 20.20.2 pour construire Vite dans un répertoire de contrôle isolé, sans écraser les assets versionnés sous `public/build`, puis la préparation de production contrôle la présence de leur manifeste. Références : [buildpack PHP](https://docs.digitalocean.com/products/app-platform/reference/buildpacks/php/) et [buildpack Node.js](https://docs.digitalocean.com/products/app-platform/reference/buildpacks/nodejs/).

La base fait partie de l’image construite. Elle est volontairement reconstructible et jetable. Au runtime, `DB_READ_ONLY=true` active `PRAGMA query_only=ON` sur chaque connexion SQLite. Les routes non sûres restent en plus refusées hors `local` et `testing`.

Le filesystem App Platform est éphémère, limité et sans volumes persistants. Une nouvelle instance ou un redéploiement supprime toute modification locale. Ce comportement est compatible uniquement parce qu’aucune donnée runtime importante n’est créée et que la base de démonstration est reconstruite à chaque build. Référence : [stockage App Platform](https://docs.digitalocean.com/products/app-platform/how-to/store-data/).

## Variables à configurer

Le modèle App Spec contient les valeurs non secrètes. Avant le premier déploiement, ajouter manuellement au composant web :

| Variable | Portée | Chiffrée | Valeur |
|---|---|---:|---|
| `APP_KEY` | Run time uniquement | oui | secret déjà configuré ; valeur jamais affichée ni consignée |

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

Le service existe déjà. Cette procédure est conservée pour une éventuelle recréation autorisée ; elle ne doit pas être exécutée pour une mise à jour documentaire.

1. Dans DigitalOcean, choisir **Create > App Platform**.
2. Autoriser l’accès en lecture au dépôt GitHub privé strictement nécessaire, puis sélectionner `Medyour/leadops` et `main`.
3. Désactiver le déploiement automatique sur push.
4. Importer les réglages de `.do/app.yaml` ou les reproduire dans l’interface.
5. Vérifier qu’il existe exactement un Web Service, aucun worker, job, volume, Redis ou base gérée.
6. Vérifier le buildpack PHP, la pile Ubuntu 22, le port HTTP `8080`, la commande de build et la commande de démarrage.
7. Sélectionner la région `LON1` (`lon` dans l’App Spec) et une instance `apps-s-1vcpu-0.5gb`, quantité `1`.
8. Ajouter `APP_KEY` comme variable chiffrée de portée runtime avant le lancement.
9. Vérifier le récapitulatif et le coût, puis déclencher le premier déploiement manuellement.

Le plan `apps-s-1vcpu-0.5gb` fournit actuellement 1 vCPU partagé, 512 Mio de RAM et 50 Gio de transfert pour 5 USD par mois. Les services sont facturés au tarif mensuel, au prorata de la seconde ; le transfert sortant au-delà de l’allocation est facturé séparément. Référence : [tarification App Platform](https://docs.digitalocean.com/products/app-platform/details/pricing/).

## URL temporaire et HTTPS

L’URL publique validée est [https://leadops-88t64.ondigitalocean.app](https://leadops-88t64.ondigitalocean.app). Aucun domaine personnalisé n’est nécessaire. `${APP_URL}` reste la variable liée à l’URL primaire fournie par DigitalOcean. Référence : [gestion des domaines](https://docs.digitalocean.com/products/app-platform/how-to/manage-domains/).

App Platform redirige HTTP vers HTTPS et termine TLS en bordure. Laravel accepte uniquement `X-Forwarded-Proto` depuis le proxy configuré ; les valeurs transmises de host, port et adresse cliente ne sont pas approuvées. Les cookies sont `Secure`, `HttpOnly` et `SameSite=Lax`. HSTS et CSP ne sont pas ajoutés par l’application ; une politique CSP doit être conçue après stabilisation des assets. Références : [limites HTTPS/HSTS](https://docs.digitalocean.com/products/app-platform/details/limits/) et [fonctionnalités HTTPS](https://docs.digitalocean.com/products/app-platform/details/features/).

## Health check

Le service utilise `GET /health` sur le port `8080`. Cette route Laravel dédiée renvoie `204 No Content`, reste hors du groupe de middlewares web, n’ouvre aucune session, ne consulte pas SQLite et ne révèle aucun contenu métier. Elle est incluse dans le cache de routes. Le health check laisse 10 secondes au démarrage, s’exécute toutes les 10 secondes et échoue après cinq erreurs consécutives. Référence : [health checks App Platform](https://docs.digitalocean.com/products/app-platform/how-to/manage-health-checks/).

## Contrôles après déploiement

1. Ouvrir l’URL `ondigitalocean.app` et vérifier le dashboard, la liste, le détail et la timeline.
2. Vérifier `GET /health` : statut `204`, corps vide.
3. Vérifier les redirections HTTP vers HTTPS et les URLs générées en `https://`.
4. Vérifier les en-têtes `Cache-Control`, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` et l’absence volontaire de CSP/HSTS applicatifs.
5. Vérifier en `GET` que `/tools/webhook`, `/imports/csv` et `/leads/create` renvoient `404`. Le refus des méthodes `POST`, `PUT`, `PATCH` et `DELETE` doit être couvert par les tests locaux et la CI (`404` pour les routes explicitement fermées, `405` lorsqu’aucune route ne correspond à la méthode) ; ne pas envoyer de requête de mutation à la démonstration publique pour cet audit.
6. Vérifier dans les logs de build : 8 leads, 68 événements et 0 job.
7. Vérifier l’absence de worker et que les logs applicatifs apparaissent dans les logs runtime DigitalOcean, pas dans un fichier persistant.
8. Vérifier que ni `database/*.sqlite`, ni `.env`, ni clé ou jeton ne sont suivis par Git.

Consigner la date, l’URL, le commit affiché par le déploiement **Live app**, les statuts HTTP et les compteurs, sans contenu de secret. Un `204` sur `/health` valide la disponibilité HTTP, pas SQLite ni les parcours métier : les autres contrôles restent nécessaires. Les GET de contrôle peuvent être effectués sans authentification avec `curl` :

```shell
curl --silent --show-error --output /dev/null --write-out '%{http_code}\n' https://leadops-88t64.ondigitalocean.app/health
curl --silent --show-error --output /dev/null --write-out '%{http_code}\n' https://leadops-88t64.ondigitalocean.app/tools/webhook
curl --silent --show-error --output /dev/null --write-out '%{http_code}\n' https://leadops-88t64.ondigitalocean.app/imports/csv
curl --silent --show-error --output /dev/null --write-out '%{http_code}\n' https://leadops-88t64.ondigitalocean.app/leads/create
```

Ces commandes utilisent `/dev/null` sous un shell POSIX ; sous Windows PowerShell, appeler `curl.exe` et remplacer `/dev/null` par `NUL`. Résultats attendus : `204`, `404`, `404`, `404`.

## Déploiement manuel des versions suivantes

Procédure réservée à une intervention explicitement autorisée. Une PR, sa fusion ou un push ne déploie rien puisque l’auto-déploiement est désactivé.

1. Faire valider et fusionner la version destinée à la production sur `main`, avec les contrôles qualité réussis. Partir d’une copie locale propre et synchronisée, puis relever le SHA complet de `origin/main`.
2. Noter le commit et le déploiement actuellement sains (référence validée : `b15146f`) pour un éventuel rollback.
3. Dans App Platform, ouvrir l’application existante. Vérifier `main`, l’auto-déploiement désactivé, `LON1`, une seule instance et les réglages non secrets de `.do/app.yaml`, dont `/health` et `8080`. Conserver le secret runtime existant sans afficher sa valeur. Ne pas importer aveuglément cette spécification partielle : elle omet volontairement le secret.
4. Après autorisation, choisir **Actions > Deploy**. Cette action récupère le dernier code de la branche source ; elle ne sélectionne pas le SHA de cette PR. Éviter un nouveau push sur `main` pendant l’intervention et contrôler le SHA effectivement pris en charge.
5. Suivre le build et l’état du déploiement dans **Activity**. Vérifier les compteurs 8/68/0 dans les logs de build et attendre le statut **Live app**.
6. Comparer le commit de **Live app** au SHA prévu, puis exécuter tous les contrôles après déploiement. Si le commit ou les invariants diffèrent, ne pas déclarer la version validée et appliquer la procédure de rollback si nécessaire.

Référence : [gestion des déploiements DigitalOcean](https://docs.digitalocean.com/products/app-platform/how-to/manage-deployments/).

## Rollback

Procédure à exécuter uniquement lors d’une intervention autorisée ; aucun rollback n’est lancé par cet audit.

1. Dans **Activity**, identifier le dernier déploiement réussi et validé, à partir de son commit (référence actuelle : `b15146f`). App Platform propose les dix derniers déploiements réussis ; la cible doit être dans la même région avec les mêmes ressources et configuration de base de données.
2. Choisir **Rollback** à côté de ce déploiement. Vérifier la comparaison du code et des réglages non secrets : `LON1`, une instance, lecture seule, `/health`, `8080`, aucun service de données ni worker. Ne pas afficher ou copier les valeurs secrètes.
3. Garder **Automatically Deploy Code Changes** désactivé, puis confirmer le rollback après autorisation.
4. Attendre **Live app**, vérifier le commit restauré et répéter les contrôles après déploiement. Consigner les résultats sans secret.

Le rollback restaure le code, la configuration et l’App Spec du déploiement choisi, sans réécrire l’historique Git. Ici, la SQLite fictive est incluse dans l’image restaurée ; aucune donnée runtime ni base gérée ne nécessite de restauration. Si le déploiement de référence n’est plus disponible, arrêter et préparer une version de restauration revue sur `main` avant tout déploiement manuel autorisé. Référence : [rollback DigitalOcean](https://docs.digitalocean.com/products/app-platform/how-to/manage-deployments/#roll-back-to-a-previous-deployment).

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
