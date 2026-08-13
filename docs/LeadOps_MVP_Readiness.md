# LeadOps MVP — Readiness finale

## Conclusion

Le MVP LeadOps est prêt pour une démonstration locale de portfolio avec des données exclusivement fictives. Le périmètre fonctionnel décrit par la spécification et l’architecture est terminé, reproductible et couvert par des tests.

Cette conclusion ne constitue pas une autorisation de déploiement public interactif. Hors `local` et `testing`, l’application doit rester strictement en lecture seule. Toute mutation publique exige au préalable une authentification, des autorisations explicites et un moteur SQL serveur.

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
- absence volontaire de HSTS avant HTTPS définitif et de CSP avant stabilisation de l’hébergement ;
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
- Les fichiers suivis ne contiennent ni `.env` local, base SQLite, log, certificat, clé privée, jeton, dossier `vendor`, `node_modules` ou build Vite.
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

La queue `database` et les verrous locaux conviennent au MVP. Leur comportement sous plusieurs workers et plusieurs hôtes n’est pas certifié. Les expirations de verrous devront être revues avec les délais et l’infrastructure du déploiement réel.

### Absence d’outbox

La transmission est locale et simulée. Il n’existe pas d’outbox transactionnelle pour garantir une future émission externe après commit. Une intégration CRM réelle exigerait une outbox, un consommateur idempotent, une stratégie de rejeu et une supervision.

### Authentification absente

Le MVP n’a ni comptes opérateurs, ni rôles, ni politiques d’autorisation métier. Cette limite est acceptable uniquement parce que toutes les mutations sont locales. Les lectures publiques éventuelles ne doivent contenir que le jeu fictif fourni.

## Prérequis avant un futur déploiement public

Avant toute exposition publique, même en lecture seule :

1. choisir le domaine, l’hébergement HTTPS et la terminaison TLS ;
2. configurer `APP_ENV=production`, `APP_DEBUG=false`, une clé forte et des secrets hors Git ;
3. définir les proxies de confiance, cookies `Secure`, domaine de session et stockage de session adapté ;
4. concevoir puis tester une CSP compatible avec les assets réellement hébergés ;
5. activer HSTS uniquement après validation de HTTPS sur le domaine et les sous-domaines concernés ;
6. mettre en place journalisation centralisée avec redaction, alertes, sauvegardes et politique de rétention ;
7. exécuter les tests de charge, concurrence, restauration et migration sur l’infrastructure cible ;
8. réévaluer les dépendances et les en-têtes au moment du déploiement.

Avant toute mutation publique, il faut en plus :

1. une authentification robuste et des autorisations par action ;
2. un moteur SQL serveur, par exemple PostgreSQL ou MySQL, et des tests de verrouillage multiprocessus ;
3. une protection contre les abus adaptée à l’identité authentifiée et aux proxies réels ;
4. des protections de rejeu et une idempotence définies pour chaque intégration ;
5. une outbox transactionnelle avant toute transmission externe ;
6. une politique de données réelles, consentement, minimisation, suppression et audit d’accès.

## Confirmation du périmètre

Le dépôt n’utilise aucune donnée personnelle ou client réelle. Il n’appelle aucune API d’IA, aucun CRM, aucun fournisseur de messagerie et aucun service de transmission réel. Aucun domaine, hébergement ou service externe n’a été créé pendant le durcissement final.
