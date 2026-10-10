# LeadOps MVP — Readiness finale

## Conclusion

Le MVP LeadOps dispose d’une démonstration locale et d’un déploiement public validé, strictement en lecture seule, avec des données exclusivement fictives. Le périmètre fonctionnel décrit par la spécification et l’architecture est terminé, reproductible et couvert par des tests.

Cette conclusion ne constitue pas une autorisation de déploiement public interactif. Hors `local` et `testing`, l’application doit rester strictement en lecture seule. Toute mutation publique exige au préalable une authentification, des autorisations explicites et un moteur SQL serveur.

## Déploiement public validé — 10 octobre 2026

État confirmé par l’opérateur, distinct des preuves locales historiques ci-dessous :

| Élément | État validé |
|---|---|
| Démonstration principale | [https://leadops.mkirchel.com](https://leadops.mkirchel.com) |
| HTTPS / TLS | valide ; certificat géré par DigitalOcean |
| DNS Cloudflare | CNAME `leadops` vers `leadops-88t64.ondigitalocean.app`, DNS-only, proxy désactivé |
| DNSSEC | volontairement désactivé en raison de la limitation actuelle d’App Platform |
| Ancien domaine | `https://leadops-88t64.ondigitalocean.app` : `301` vers le domaine principal, chemins conservés, dont `/leads` |
| Parcours vérifiés | dashboard, Leads et Exceptions fonctionnels |
| Hébergement | DigitalOcean App Platform, région `LON1` |
| Capacité / coût | `apps-s-1vcpu-0.5gb`, 1 conteneur, 5 USD/mois |
| Source | `main`, auto-déploiement désactivé |
| Commit déployé | `b15146f` |
| Mode / données | lecture seule ; SQLite déterministe, 8 leads, 68 événements, 0 job |
| Readiness HTTP | `/health`, port interne `8080`, réponse `204` |
| Routes locales fermées | `/tools/webhook`, `/imports/csv`, `/leads/create` : `404` en production |
| Infrastructure | aucun service de base de données, worker, volume ou Redis |
| Secret applicatif | `APP_KEY` chiffrée, limitée au runtime ; valeur jamais documentée, affichée ni versionnée |

La spécification [`.do/app.yaml`](../.do/app.yaml) décrit les réglages non secrets, avec `region: lon` pour `LON1`. Le [runbook DigitalOcean](DigitalOcean_Deployment_Runbook.md) décrit le déploiement manuel, le rollback et les vérifications après déploiement. La mise à jour documentaire ne modifie pas l’application, ne change pas DigitalOcean et ne déclenche aucun déploiement.

Le domaine personnalisé est déclaré `PRIMARY`, sans `zone` puisque Cloudflare gère le DNS. `${APP_URL}` reste liée au domaine principal. La redirection de `${STARTER_DOMAIN}` utilise HTTPS et `301`, sans `uri` pour conserver le chemin ; le domaine principal transmet le chemin complet au composant `web`. Cette représentation suit la [gestion officielle des domaines](https://docs.digitalocean.com/products/app-platform/how-to/manage-domains/#use-subdomain-routing) et la [référence App Spec](https://docs.digitalocean.com/products/app-platform/reference/app-spec/). Elle n’a pas été appliquée à la plateforme.

## Critères du MVP validés

- réception par formulaire, CSV et webhook JSON local ;
- validation serveur commune et règles métier déterministes ;
- normalisation des champs de contact et des textes ;
- idempotence par la paire unique `source + external_reference` ;
- déduplication par e-mail et téléphone, y compris les ambiguïtés ;
- score borné, classification `HOT`, `WARM` ou `LOW` et routage explicable ;
- transmission entièrement simulée avant le passage à `ROUTED` ;
- journal d’événements ordonné et filtré ;
- trois tentatives techniques maximum par cycle ;
- correction locale des cas `NEEDS_REVIEW` et reprise locale des cas `FAILED` ;
- dashboard, listes filtrées et paginées, détail et timeline ;
- huit scénarios fictifs, stables et rechargeables sans doublon.

## Fonctionnalités terminées

Le backend Laravel porte la validation, l’ingestion transactionnelle, les transitions, la queue et les agrégats. Blade rend tous les parcours principaux. Vue 3 reste limité à la prévisualisation CSV et au simulateur webhook, sans routeur ni état global de SPA.

Le durcissement final ajoute ou confirme :

- refus global de toute méthode web non sûre hors `local` et `testing` ;
- garde locale exécutée à chaque requête sur les formulaires, imports, actions d’exception, simulateur et webhook ;
- vérification concordante de l’environnement applicatif, de la configuration et de la variable d’exécution ;
- échec fermé même lorsqu’un cache de configuration et de routes créé en local est réutilisé sous un runtime `production` ;
- chiffrement des sessions activé par défaut ;
- réponses dynamiques non stockables par les caches ;
- en-têtes anti-framing, `nosniff`, politique de référent, isolation de fenêtre et permissions navigateur minimales ;
- absence de HSTS et de CSP applicatifs ; HTTPS est assuré par DigitalOcean pour la démonstration validée ;
- audits Composer et npm ainsi qu’un double chargement du seeder dans la CI.

## Risques trouvés et corrections

### Cache de routes local réutilisé hors environnement autorisé

Une route conditionnellement enregistrée pouvait rester présente si un cache construit en local était réutilisé ailleurs. Le webhook JSON ne possédait alors pas de deuxième garde d’environnement au niveau de la requête.

Les routes locales utilisent désormais un middleware dédié. La décision exige que l’environnement détecté par Laravel, `config('app.env')` et la valeur runtime `APP_ENV` appartiennent tous à `local` ou `testing`. Un test construit les caches en local, les exécute avec `APP_ENV=production` et confirme neuf réponses `404` pour les outils et mutations sensibles.

### Lecture seule non locale dépendante d’une liste de noms de routes

La garde web ciblait une liste de routes. Elle couvre maintenant toute méthode HTTP non sûre dans le groupe web, ce qui protège aussi une future mutation oubliée dans la liste.

### Route de stockage inscriptible hors du groupe web

Le disque local Laravel conservait l’option `serve` du squelette, qui enregistrait automatiquement une route `PUT /storage/{path}` hors du groupe web. Cette fonctionnalité n’est pas utilisée par LeadOps et contredisait la lecture seule stricte hors environnement local. Le service HTTP du disque privé est désormais désactivé ; les téléchargements d’exemple restent des fichiers publics statiques et les tests confirment l’absence des routes Laravel de lecture et d’écriture du stockage.

### Sessions et cache navigateur

Le modèle de session était non chiffré par défaut et les réponses métier ne forçaient pas `no-store`. Les sessions sont maintenant chiffrées par défaut et toutes les réponses dynamiques utilisent `Cache-Control: no-store, private`.

### En-têtes HTTP

Les réponses ajoutent :

- `Cross-Origin-Opener-Policy: same-origin` ;
- `Permissions-Policy: camera=(), geolocation=(), microphone=()` ;
- `Referrer-Policy: strict-origin-when-cross-origin` ;
- `X-Content-Type-Options: nosniff` ;
- `X-Frame-Options: DENY` ;
- `X-Permitted-Cross-Domain-Policies: none`.

`X-Powered-By` est retiré. Les mêmes protections sont appliquées aux réponses d’erreur Laravel. HSTS et CSP ne sont pas activés à ce stade.

### Documentation et CI

Le README Laravel générique a été remplacé. La CI contrôle désormais les audits de dépendances et la reproductibilité des migrations et du seeder, sans service externe supplémentaire ni nouvelle dépendance.

## Confidentialité et absence de fuite

L’audit a porté sur les réponses HTTP, Blade, Vue, les événements, les logs, les erreurs, les sessions et les fichiers Git.

- Blade et Vue utilisent l’échappement texte ; aucun `v-html`, rendu Blade brut ou `innerHTML` métier n’est utilisé.
- Les événements acceptent uniquement une liste blanche de clés et ne stockent ni payload, e-mail, téléphone ni message.
- Les exceptions techniques persistées sont réduites à des codes stables ; les contrôleurs sensibles renvoient des messages génériques.
- Les erreurs d’import journalisent uniquement le numéro de ligne.
- Le webhook ne renvoie que le résultat, l’identifiant interne et le statut autorisé.
- Les valeurs de correction ne sont pas recopiées dans les erreurs d’action ; les sessions persistantes sont chiffrées.
- Le CSV uploadé n’est jamais copié dans un stockage applicatif permanent.
- Les fichiers suivis ne contiennent ni `.env` local, base SQLite, log, certificat, clé privée, jeton, dossier `vendor` ou `node_modules`. Les assets Vite préconstruits sous `public/build` sont volontairement versionnés pour le buildpack PHP ; le build de contrôle sous `public/build-ci` reste ignoré.
- Les données versionnées utilisent uniquement `example.test`, des noms inventés et des numéros fictifs.

L’affichage de détail montre volontairement les données fictives du démonstrateur. Une future utilisation de données réelles imposerait une nouvelle analyse de minimisation, d’autorisation, de rétention et d’accès.

## Preuves de validation

Validation locale finale du 13 août 2026 :

| Contrôle | Résultat |
|---|---|
| `composer validate --strict` | valide |
| `composer audit --locked --no-interaction` | 0 vulnérabilité |
| `npm audit --audit-level=low` | 0 vulnérabilité |
| migrations SQLite temporaires | réussies |
| `LeadDemoSeeder` exécuté deux fois | reproductible, 8 leads |
| `vendor/bin/pint --test` | réussi |
| suite Laravel | 181 tests, 3 129 assertions |
| tests JavaScript | 31 réussis |
| build Vite | réussi en 609 ms |
| bundle CSS principal | 68,30 kB, 13,64 kB gzip |
| bundle JavaScript principal | 85,18 kB, 32,41 kB gzip |
| routes et caches | `local`/`testing` autorisés ; `production`/`staging` en lecture seule ; aucune route de stockage inscriptible |
| cache local sous runtime production | 9 outils ou mutations sensibles refusés en `404` |
| audit de fichiers et secrets | aucun fichier ou secret interdit détecté |
| contrôle visuel à 320 px | aucun débordement de page sur les parcours contrôlés |
| contrôle visuel à 1 440 px | grilles, filtres, table et détail cohérents |
| sémantique accessible | un `h1` et un `main`, navigation nommée, aucun contrôle sans libellé ni identifiant dupliqué |
| fallback sans JavaScript | contenu et formulaires Blade présents dans le HTML serveur ; message `noscript` du simulateur présent |

Le build conserve l’avertissement facultatif relatif à `fontaine`. Cette dépendance n’est pas installée uniquement pour supprimer cet avertissement.

## Audit documentaire et contrôles du 10 octobre 2026

Départ depuis `main` propre, synchronisée avec `origin/main` au commit `b15146f`. Périmètre : README, runbook, présent rapport et ajout de la région `lon` dans l’App Spec ; aucun code applicatif ou asset de production modifié.

| Contrôle exécuté | Résultat |
|---|---|
| suite Laravel | 186 tests réussis, 3 168 assertions |
| Laravel Pint (`--test`) | réussi |
| tests JavaScript | 31 réussis |
| build Vite isolé (`VITE_BUILD_DIRECTORY=build-ci`) | réussi ; assets versionnés inchangés |
| `composer validate --strict` | valide |
| GET public `/health` | `204` |
| GET publics `/`, `/leads`, `/exceptions`, `/leads/1` | `200` |
| GET publics `/tools/webhook`, `/imports/csv`, `/leads/create` | `404` |
| audit des fichiers suivis et motifs de secrets | aucun secret ni fichier `.env`, SQLite ou clé/certificat détecté |
| exclusions Git | `.env` et `database/demo.sqlite` ignorés |
| `git diff --check` | réussi |

Les contrôles HTTP sont uniquement des GET ; aucune mutation publique n’a été envoyée. Le commit déployé, la capacité, le coût et les compteurs de la base en production proviennent des confirmations de l’opérateur, sans accès à la console DigitalOcean ni extraction de la base. Les tests locaux couvrent la reconstruction déterministe 8/68/0 et le refus des écritures. Les audits de dépendances et contrôles visuels du 13 août restent des preuves historiques, sans nouvelle exécution revendiquée ici. Aucun déploiement ni rollback n’a été déclenché.

## Alignement du domaine définitif — 10 octobre 2026

Départ depuis `main` propre et synchronisée avec `origin/main` au commit `ab84efc`. La branche ciblée aligne le README, le runbook, ce rapport et les domaines/ingress de `.do/app.yaml`. Le test de configuration de production est adapté pour vérifier le domaine primaire, l’absence de `zone`, `${APP_URL}`, la redirection HTTPS `301` ciblée sur `${STARTER_DOMAIN}` sans remplacement du chemin et le routage vers `web`. Aucun code applicatif ni asset de production n’est modifié.

| Contrôle exécuté | Résultat |
|---|---|
| suite Laravel | 187 tests réussis, 3 183 assertions |
| Laravel Pint (`--test`) | réussi |
| tests JavaScript | 31 réussis |
| build Vite isolé (`VITE_BUILD_DIRECTORY=build-ci`) | réussi ; assets versionnés inchangés |
| `composer validate --strict` | valide |
| HTTPS public | vérification TLS par curl réussie, sans désactiver la validation du certificat |
| GET `https://leadops.mkirchel.com/health` | `204` |
| GET `/`, `/leads`, `/exceptions`, `/leads/1` sur le domaine principal | `200` |
| GET `/tools/webhook`, `/imports/csv`, `/leads/create` sur le domaine principal | `404` |
| GET ancien domaine `/`, sans suivre la redirection | `301`, destination `https://leadops.mkirchel.com/` |
| GET ancien domaine `/leads`, sans suivre la redirection | `301`, destination `https://leadops.mkirchel.com/leads` |
| résolution DNS CNAME | `leadops.mkirchel.com` vers `leadops-88t64.ondigitalocean.app` |
| `git diff --check` | réussi |

Les contrôles publics utilisent uniquement GET et la résolution DNS. Les réglages DNS-only/proxy désactivé, DNSSEC désactivé, la gestion du certificat et l’auto-déploiement désactivé restent des confirmations de l’opérateur, sans inspection des consoles. La spécification est vérifiée par les tests locaux et comparée à la documentation officielle ; aucune validation distante de l’App Spec ni application à DigitalOcean n’a été effectuée. Aucun changement Cloudflare ou DigitalOcean, déploiement, rollback ou fusion n’a été déclenché.

Les validations locales utilisent PHP 8.3.31 et Node.js 24.19.0 ; la CI reste configurée avec Node.js 20.20.2. Le build conserve l’avertissement connu relatif à la dépendance facultative `fontaine`.

## Performance

- les listes sélectionnent un ensemble borné de colonnes et paginent à 20 éléments ;
- les filtres sont validés avant la requête ;
- le détail charge explicitement la timeline et le doublon d’origine ;
- les tests de requêtes bornent les sélections des listes, du détail et du dashboard ;
- les agrégats sont exécutés côté SQL ;
- les recherches échappent les jokers SQL ;
- le CSV est limité à 2 Mio et 500 lignes, sans conservation du fichier ;
- les bundles Vite restent modestes et aucun nouvel asset ou framework n’a été ajouté.

## Limites restantes

### SQLite et verrouillage multiprocessus

SQLite est adapté à une démonstration mono-opérateur, mais `SELECT ... FOR UPDATE` n’y fournit pas la preuve d’un verrou de ligne équivalente à celle d’un moteur SQL serveur. Les contraintes uniques, transactions et verrous de cache sont testés, mais la concurrence multiprocessus réelle doit être revalidée sur le moteur cible.

### Queue et verrous locaux

La queue `database` et les verrous locaux conviennent aux parcours locaux du MVP. Leur comportement sous plusieurs workers et plusieurs hôtes n’est pas certifié. Le déploiement public utilise la connexion `discard` (pilote `null`) sans worker et ne doit créer aucun job. Les expirations de verrous devront être revues avant une future infrastructure de traitement public.

### Absence d’outbox

La transmission est locale et simulée. Il n’existe pas d’outbox transactionnelle pour garantir une future émission externe après commit. Une intégration CRM réelle exigerait une outbox, un consommateur idempotent, une stratégie de rejeu et une supervision.

### Authentification absente

Le MVP n’a ni comptes opérateurs, ni rôles, ni politiques d’autorisation métier. Cette limite est acceptable uniquement parce que toutes les mutations sont locales. Les lectures publiques contiennent exclusivement le jeu fictif fourni.

## Limites du déploiement public et évolution du périmètre

Le déploiement public validé dispose de HTTPS, d’un environnement `production` sans debug, de proxies configurés, de sessions et cache `array`, de logs `stderr` et d’une base fictive reconstruite à chaque build. Il reste limité à une instance de 512 Mio, sans haute disponibilité ni persistance de données utilisateur. `/health` ne teste pas SQLite ; les vérifications métier du runbook restent nécessaires.

Une CSP, une politique HSTS adaptée aux domaines, les tests de charge et de concurrence et une stratégie de sauvegarde/rétention pour d’éventuelles données persistantes restent à traiter avant une extension du périmètre. Ces points ne sont pas présentés comme validés par cette démonstration. Les dépendances et en-têtes doivent être réévalués à chaque intervention.

Avant toute mutation publique, il faut en plus :

1. une authentification robuste et des autorisations par action ;
2. un moteur SQL serveur, par exemple PostgreSQL ou MySQL, et des tests de verrouillage multiprocessus ;
3. une protection contre les abus adaptée à l’identité authentifiée et aux proxies réels ;
4. des protections de rejeu et une idempotence définies pour chaque intégration ;
5. une outbox transactionnelle avant toute transmission externe ;
6. une politique de données réelles, consentement, minimisation, suppression et audit d’accès.

## Confirmation du périmètre

Le dépôt n’utilise aucune donnée personnelle ou client réelle. Il n’appelle aucune API d’IA, aucun CRM, aucun fournisseur de messagerie et aucun service de transmission réel. L’hébergement public DigitalOcean est confirmé ; aucun service externe, domaine ou déploiement supplémentaire n’est créé par cette mise à jour documentaire.
