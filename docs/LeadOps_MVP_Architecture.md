# LeadOps MVP Architecture

## 1. Objet et conclusion de faisabilité

Ce document traduit la spécification du MVP LeadOps en une architecture prête
à être implémentée, sans développer de fonctionnalité.

Le MVP est techniquement faisable avec le socle actuel : Laravel 13 et PHP 8.3
pour le backend, Blade pour le rendu serveur, Tailwind CSS 4 pour le style,
Vite 8 pour les assets, la base relationnelle configurée par Laravel et la file
de jobs Laravel pour le traitement avec retries.

Vue 3 n'est pas encore une dépendance du projet. Son ajout sera nécessaire au
moment d'implémenter les deux îlots interactifs retenus, mais aucune dépendance
n'est ajoutée pendant cette phase d'architecture.

Après les clarifications apportées à la spécification, aucune contradiction ni
décision métier bloquante ne subsiste pour un MVP local. Les contraintes à
respecter sont les suivantes :

- application mono-opérateur et mono-tenant ;
- données exclusivement fictives ;
- aucun appel sortant vers une IA, un CRM ou un service de transmission ;
- webhook disponible uniquement en environnement `local` ou `testing` ;
- migrations compatibles avec SQLite pour la démonstration locale ;
- interface rendue principalement avec Blade, sans SPA.

## 2. Principes d'architecture

### 2.1 Découpage logique

```text
Formulaire ─┐
CSV ────────┼─> Contrôleurs + Form Requests ─> Ingestion idempotente
Webhook ────┘                                      │
                                                   v
                                         Job ProcessLead
                                                   │
                    ┌──────────────────────────────┼──────────────────────┐
                    v                              v                      v
             Normalisation                  Qualification          Journalisation
             Validation                     Déduplication          à chaque étape
                                            Score / Classe
                                            Routage
                                            Transmission simulée
                    └──────────────────────────────┬──────────────────────┘
                                                   v
                                   Blade + îlots Vue 3 ciblés
```

Les contrôleurs restent minces. Ils valident le format d'entrée, appellent le
service d'ingestion et retournent une réponse HTTP. La logique métier réside
dans des classes de domaine déterministes, indépendantes du transport HTTP.

### 2.2 Laravel

Laravel porte :

- les routes HTTP ;
- les contrôleurs et Form Requests ;
- les modèles Eloquent et migrations ;
- les transactions et contraintes de base de données ;
- le job de traitement et ses retries ;
- la validation, les limites de débit, CSRF et autorisations d'accès local ;
- les factories, seeders et tests PHPUnit.

La connexion `database` de la queue est adaptée au MVP : la migration des jobs
est déjà présente et le script de développement existant démarre un worker. Le
traitement est asynchrone après la persistance idempotente du lead.

### 2.3 Blade

Blade fournit la structure de toutes les pages : layout, navigation, titres,
formulaires, tableaux, cartes de métriques, badges de statut, messages flash,
pagination et timeline.

Organisation recommandée :

```text
resources/views/
├── layouts/app.blade.php
├── components/
│   ├── status-badge.blade.php
│   ├── classification-badge.blade.php
│   ├── metric-card.blade.php
│   ├── form-field.blade.php
│   └── timeline-event.blade.php
├── dashboard/index.blade.php
├── leads/index.blade.php
├── leads/create.blade.php
├── leads/show.blade.php
├── imports/create.blade.php
├── tools/webhook.blade.php
└── exceptions/index.blade.php
```

### 2.4 Vue 3

Vue 3 est limité à deux îlots montés depuis `resources/js/app.js` :

- prévisualisation et validation ergonomique du CSV avant envoi ;
- éditeur JSON du simulateur webhook et affichage de la réponse.

Le dashboard, les listes, les formulaires standard, le détail et la timeline
restent en Blade. Il n'y a ni Vue Router, ni store global, ni API parallèle pour
recréer une SPA. Les données initiales des îlots sont passées par attributs
`data-*` ou par un petit objet JSON explicitement sérialisé.

### 2.5 Tailwind CSS

Tailwind est utilisé via les classes utilitaires existantes. Les composants
Blade encapsulent les combinaisons répétées. Aucun système de composants CSS
concurrent n'est introduit.

Les états doivent rester compréhensibles sans dépendre uniquement de la
couleur : libellé textuel, icône décorative éventuelle et contraste conforme.

## 3. Pages, routes et parcours

### 3.1 Routes web

| Méthode | URI | Nom | Contrôleur | Résultat |
|---|---|---|---|---|
| GET | `/` | `dashboard` | `DashboardController@index` | Dashboard |
| GET | `/leads` | `leads.index` | `LeadController@index` | Liste filtrable et paginée |
| GET | `/leads/create` | `leads.create` | `LeadController@create` | Formulaire |
| POST | `/leads` | `leads.store` | `LeadController@store` | Création idempotente, puis redirection |
| GET | `/leads/{lead}` | `leads.show` | `LeadController@show` | Détail et timeline |
| PATCH | `/leads/{lead}/review` | `leads.review.update` | `LeadReviewController@update` | Correction d'un cas à revoir |
| POST | `/leads/{lead}/reprocess` | `leads.reprocess` | `LeadReprocessController@store` | Retraitement après correction |
| POST | `/leads/{lead}/retry` | `leads.retry` | `LeadRetryController@store` | Reprise manuelle d'un échec |
| GET | `/imports/csv` | `imports.csv.create` | `CsvImportController@create` | Écran d'import |
| POST | `/imports/csv` | `imports.csv.store` | `CsvImportController@store` | Import et résumé |
| GET | `/tools/webhook` | `tools.webhook` | `WebhookSimulatorController@show` | Simulateur local |
| GET | `/exceptions` | `exceptions.index` | `ExceptionController@index` | File des exceptions |

Les filtres de la liste utilisent la query string : `status`,
`classification`, `source`, `assigned_to` et `search`. La file des exceptions
est une vue filtrée sur `INVALID`, `DUPLICATE`, `NEEDS_REVIEW` et `FAILED`, pas
une nouvelle entité.

### 3.2 Route JSON locale

| Méthode | URI | Nom | Contrôleur | Réponse |
|---|---|---|---|---|
| POST | `/api/local/webhooks/leads` | `local.webhooks.leads.store` | `LocalWebhookController@store` | JSON `201`, `200` idempotent ou `422` |

La route sans session est enregistrée uniquement en environnement `local` ou
`testing`, avec une limite de débit dédiée. Elle n'est pas une API publique,
n'utilise pas CSRF et n'appelle aucun service externe.

### 3.3 Parcours nominal

1. Le contrôleur valide l'enveloppe reçue.
2. Le formulaire fournit la référence générée par le serveur lors de son
   affichage ; `LeadIngestionService` exige cette référence et crée le lead en
   `RECEIVED`.
3. La contrainte unique garantit l'idempotence sous concurrence.
4. `ProcessLead` est distribué après validation de la transaction.
5. Le job passe le lead à `VALIDATING`, exécute les règles et journalise chaque
   décision.
6. Un lead qualifié reçoit son score, sa classification et son assignation.
7. l'adaptateur local simule la transmission.
8. Le lead passe à `ROUTED`, `processed_at` est renseigné et la timeline expose
   la totalité du parcours.

### 3.4 Parcours d'exception

- `INVALID` : consultation de la cause ; état terminal dans le MVP.
- `DUPLICATE` : consultation du lead d'origine ; état terminal dans le MVP.
- `NEEDS_REVIEW` : correction depuis le détail, puis retraitement explicite.
- `FAILED` : consultation de l'erreur technique, puis retry manuel explicite.

Le contrôleur refuse une correction ou une action de reprise incompatible avec
le statut courant et retourne `409 Conflict` pour le JSON ou un message de
validation pour le web.

## 4. Modèle de données

### 4.1 Table `leads`

| Colonne | Type logique | Null | Rôle |
|---|---|---:|---|
| `id` | bigint | non | Identifiant interne |
| `source` | varchar(20) | non | `form`, `csv`, `webhook` |
| `external_reference` | varchar(100) | non | Clé idempotente de la source |
| `first_name` | varchar(100) | non | Valeur normalisée |
| `last_name` | varchar(100) | non | Valeur normalisée |
| `company` | varchar(150) | oui | Entreprise fictive |
| `email` | varchar(254) | oui | Valeur reçue nettoyée |
| `phone` | varchar(50) | oui | Valeur reçue nettoyée |
| `country` | char(2) | oui | Code ISO 3166-1 alpha-2 |
| `service_interest` | varchar(50) | non | Valeur reçue/corrigée |
| `company_size` | unsigned integer | oui | Nombre de salariés |
| `message` | text | oui | Message fictif |
| `consent` | boolean | non | Doit être vrai |
| `received_at` | timestamp | non | Horodatage fourni ou serveur |
| `normalized_email` | varchar(254) | oui | Clé de rapprochement |
| `normalized_phone` | varchar(32) | oui | Clé de rapprochement |
| `score` | unsigned tiny integer | oui | 0 à 100 après qualification |
| `classification` | varchar(10) | oui | `HOT`, `WARM`, `LOW` |
| `assigned_to` | varchar(50) | oui | Libellé fictif d'assignation |
| `status` | varchar(20) | non | Statut du pipeline |
| `duplicate_of` | foreign bigint | oui | Référence vers `leads.id` |
| `processing_attempts` | unsigned tiny integer | non | Tentatives du cycle courant, de 0 à 3 |
| `processed_at` | timestamp | oui | Fin nominale du traitement |
| `created_at` | timestamp | non | Laravel |
| `updated_at` | timestamp | non | Laravel |

Contraintes et index :

- unique composite sur `source, external_reference` ;
- index sur `normalized_email` ;
- index sur `normalized_phone` ;
- index composite sur `status, received_at` ;
- index composite sur `classification, received_at` ;
- index composite sur `assigned_to, status` ;
- clé étrangère `duplicate_of` vers `leads.id`, suppression restreinte ;
- contrainte applicative et testée pour `score` entre 0 et 100.

Les statuts et classifications sont stockés en chaînes lisibles et convertis en
enums PHP. Cette approche reste portable entre SQLite et un moteur SQL serveur.

### 4.2 Table `processing_events`

| Colonne | Type logique | Null | Rôle |
|---|---|---:|---|
| `id` | bigint | non | Identifiant |
| `lead_id` | foreign bigint | non | Lead concerné |
| `type` | varchar(50) | non | Nature stable de l'événement |
| `from_status` | varchar(20) | oui | Statut précédent |
| `to_status` | varchar(20) | oui | Nouveau statut |
| `attempt` | unsigned tiny integer | oui | Tentative concernée |
| `context` | JSON/text | oui | Métadonnées non sensibles et bornées |
| `created_at` | timestamp | non | Ordre de la timeline |

Index :

- index composite sur `lead_id, created_at` ;
- index sur `type` ;
- clé étrangère vers `leads.id`, suppression en cascade uniquement pour les
  données fictives de démonstration.

Les types d'événements prévus sont des constantes stables, par exemple
`lead.received`, `validation.failed`, `lead.normalized`, `duplicate.detected`,
`score.calculated`, `lead.classified`, `lead.routed`,
`transmission.succeeded`, `processing.retry_scheduled`,
`processing.failed` et `review.requested`.

Le contexte ne contient jamais le payload complet, l'email, le téléphone ou le
message. Il conserve seulement les codes de règle, scores, catégories,
identifiants internes et informations nécessaires à l'explication du parcours.

### 4.3 Relations Eloquent

- `Lead hasMany ProcessingEvent`, ordonnés par `created_at`, puis `id` ;
- `Lead belongsTo Lead` via `duplicate_of` sous le nom `duplicateOf` ;
- `Lead hasMany Lead` sous le nom `duplicates`.

Il n'y a pas de table d'assignés, d'import ou de tenant. Sarah, Yassine et Emma
sont des libellés de routage configurés. Le résumé d'un import est calculé à la
requête et affiché après traitement des lignes.

## 5. Statuts et transitions

### 5.1 Enum de statut

`LeadStatus` est un enum PHP adossé à une chaîne : `RECEIVED`, `VALIDATING`,
`INVALID`, `DUPLICATE`, `QUALIFIED`, `NEEDS_REVIEW`, `ROUTED`, `FAILED`.

### 5.2 Matrice de transition

| Depuis | Vers | Déclencheur |
|---|---|---|
| `RECEIVED` | `VALIDATING` | Début d'une tentative |
| `VALIDATING` | `INVALID` | Validation métier certaine en échec |
| `VALIDATING` | `DUPLICATE` | Correspondance unique |
| `VALIDATING` | `NEEDS_REVIEW` | Ambiguïté ou catégorie inconnue |
| `VALIDATING` | `QUALIFIED` | Validation et déduplication réussies |
| `VALIDATING` | `FAILED` | Échec technique après retries |
| `QUALIFIED` | `ROUTED` | Routage et transmission simulée réussis |
| `QUALIFIED` | `NEEDS_REVIEW` | Routage impossible sans décision humaine |
| `QUALIFIED` | `FAILED` | Échec technique après retries |
| `NEEDS_REVIEW` | `VALIDATING` | Correction enregistrée et retraitement |
| `FAILED` | `VALIDATING` | Retry manuel |

`LeadStatusTransition` est l'unique service autorisé à changer `status`. Il
vérifie la matrice, met à jour le lead et écrit l'événement correspondant dans
la même transaction.

`processed_at` n'est rempli que lors du passage à `ROUTED`. Il est remis à
`null` avant un retraitement autorisé. Le score, la classification,
l'assignation et `duplicate_of` sont recalculés de façon contrôlée afin qu'un
retraitement ne conserve pas une décision obsolète.

## 6. Moteur déterministe de qualification, score et routage

### 6.1 Organisation des classes

```text
app/Domain/LeadProcessing/
├── Enums/
│   ├── LeadStatus.php
│   ├── LeadClassification.php
│   ├── LeadSource.php
│   └── ServiceInterest.php
├── Data/LeadProcessingResult.php
├── LeadNormalizer.php
├── LeadBusinessValidator.php
├── DuplicateDetector.php
├── LeadScorer.php
├── LeadClassifier.php
├── LeadRouter.php
├── LeadStatusTransition.php
└── Contracts/LeadTransmitter.php

app/Services/Leads/
├── LeadIngestionService.php
├── LeadProcessor.php
├── ProcessingEventRecorder.php
└── SimulatedLeadTransmitter.php

app/Jobs/ProcessLead.php
config/leadops.php
```

Les classes de calcul reçoivent des valeurs et retournent des résultats sans
accès HTTP, horloge implicite ni appel réseau. Elles sont donc simples à tester
et produisent le même résultat pour la même entrée et la même configuration.

### 6.2 Configuration métier

`config/leadops.php` contient uniquement des valeurs non secrètes :

- base et pondérations du score ;
- seuil de longueur du message ;
- domaines génériques ;
- catalogue de services ;
- seuils de classification ;
- table de routage ;
- nombre maximal de tentatives et délais de retry ;
- activation du webhook local.

La configuration n'est pas modifiable depuis l'interface. Une évolution exige
une modification versionnée et des tests adaptés.

### 6.3 Ordre d'exécution

Pour chaque tentative, `LeadProcessor` :

1. verrouille le lead et vérifie qu'une transition est autorisée ;
2. incrémente `processing_attempts` ;
3. normalise les valeurs ;
4. applique la validation métier ;
5. recherche les doublons en excluant le lead courant ;
6. calcule le score et le détail des règles appliquées ;
7. détermine la classification ;
8. détermine l'assignation ;
9. passe à `QUALIFIED` ;
10. appelle `LeadTransmitter`, implémenté localement ;
11. passe à `ROUTED` et renseigne `processed_at`.

Les sorties métier attendues changent le statut sans lever d'exception. Seules
les erreurs techniques transitoires déclenchent le mécanisme de retry.

### 6.4 Déduplication concurrente

La contrainte unique traite l'idempotence d'entrée. La déduplication email et
téléphone est exécutée avec des requêtes indexées. Les candidats sont triés par
`received_at`, puis `id`, et seuls les leads antérieurs non eux-mêmes marqués
`DUPLICATE` servent d'origine.

Si un seul identifiant d'origine est trouvé, le lead devient `DUPLICATE`. Si les
clés pointent vers plusieurs origines, le lead devient `NEEDS_REVIEW`. Cette
règle évite qu'un ordre de requête change le résultat.

### 6.5 Score explicable

`LeadScorer` retourne le total borné et une liste de codes, par exemple
`company_size_present:+10` ou `generic_email_domain:-5`. L'événement de score
stocke ces codes, jamais les données personnelles. Le détail rend le résultat
reproductible et affichable dans la timeline.

### 6.6 Routage et transmission

`LeadRouter` retourne un libellé d'assignation ou une décision de revue. Il ne
charge aucun utilisateur et n'effectue aucun appel externe.

`SimulatedLeadTransmitter` implémente `LeadTransmitter`. Par défaut, il retourne
un succès local avec une référence fictive. En test uniquement, un faux peut
produire une erreur technique contrôlée afin de vérifier retries et `FAILED`.

### 6.7 Retries

`ProcessLead` définit explicitement `$tries = 3` et autorise donc trois
exécutions par cycle avec deux délais courts et déterminés. Cette limite portée
par le job prévaut sur l'option `--tries=1` du worker de développement existant.
Une erreur technique en `VALIDATING` relance la qualification ; une erreur
après `QUALIFIED` reprend au routage et à la transmission sans modifier le
score. Les erreurs métier ne sont pas retentées. Le hook d'échec final enregistre
`processing.failed` et place le lead en `FAILED`.

Le retry manuel distribue une nouvelle exécution seulement depuis `FAILED`. Il
est journalisé séparément et remet `processing_attempts` à zéro. La limite
automatique reste de trois tentatives par cycle ; le nouveau cycle manuel est
identifiable dans les événements, sans effacer l'historique.

## 7. Services, contrôleurs et validations

### 7.1 Contrôleurs

- `DashboardController` : agrégats par statut, classe et source ;
- `LeadController` : liste, création, détail ;
- `LeadReviewController` : champs corrigeables d'un `NEEDS_REVIEW` ;
- `LeadReprocessController` : retraitement après correction ;
- `LeadRetryController` : reprise d'un `FAILED` ;
- `CsvImportController` : validation du fichier, ingestion ligne par ligne et
  résumé ;
- `WebhookSimulatorController` : page Blade hébergeant l'îlot Vue ;
- `LocalWebhookController` : réception JSON locale ;
- `ExceptionController` : liste filtrée des exceptions.

Les contrôleurs ne calculent ni score, ni doublon, ni routage.

### 7.2 Form Requests

- `StoreLeadRequest`
- `ImportLeadsCsvRequest`
- `StoreLocalWebhookLeadRequest`
- `UpdateLeadReviewRequest`
- `ReprocessLeadRequest`
- `RetryLeadRequest`

La validation HTTP couvre types, tailles maximales, champs requis et format de
l'enveloppe. La validation métier partagée reste dans
`LeadBusinessValidator`, afin que formulaire, CSV, webhook et retraitement
suivent exactement les mêmes règles.

`StoreLeadRequest` exige que la référence cachée du formulaire soit un UUID. Le
contrôleur `create` la génère ; il n'en régénère jamais une lors du `POST`, afin
qu'une répétition de la même soumission reste idempotente.

Depuis `NEEDS_REVIEW`, les champs métier reçus peuvent être corrigés, mais
`source` et `external_reference` restent immuables afin de préserver la clé
d'idempotence.

Limites recommandées :

- champs texte selon les tailles du schéma ;
- message limité à 5 000 caractères ;
- CSV limité à 2 Mio et 500 lignes ;
- en-tête CSV strict et documenté ;
- JSON limité par le serveur web et par la validation Laravel ;
- dates converties dans le fuseau UTC pour le stockage.

Une ligne CSV invalide n'annule pas les autres lignes. Le résumé distingue
créés, déjà reçus, invalides et erreurs de fichier. Chaque ligne passe par
`LeadIngestionService`, garantissant les mêmes règles d'idempotence.

### 7.3 Autorisations

Le MVP ne crée pas de rôles complexes ni de comptes métier. Son modèle
d'autorisation est explicitement local :

- pages et mutations disponibles dans l'environnement local de démonstration ;
- webhook et simulateur enregistrés uniquement en `local`/`testing` ;
- protection CSRF sur toutes les mutations avec session web ;
- webhook sans session ni CSRF, mais absent hors `local`/`testing` et limité en
  débit ;
- limite de débit sur formulaire, import, retry et webhook ;
- règles d'état dans les Form Requests ou services pour les actions de revue ;
- route model binding et refus des transitions non autorisées.

Un déploiement public interactif imposerait une authentification avant
d'activer les mutations. Cette décision est hors périmètre du MVP local ; les
routes de mutation doivent rester désactivées dans un tel contexte.

## 8. Factories, seeders et données fictives

### 8.1 Factory

`LeadFactory` génère exclusivement des identités réservées aux domaines
d'exemple (`example.test`), des entreprises inventées et des numéros non réels.
Elle fournit des états nommés :

- `valid()` ;
- `lowPriority()` ;
- `invalidEmail()` ;
- `invalidPhone()` ;
- `duplicateOf(Lead $lead)` ;
- `needsReview()` ;
- `failed()` ;
- `routed()`.

`ProcessingEventFactory` génère des événements cohérents avec le lead associé et
un contexte sans données brutes.

### 8.2 Seeders

`LeadDemoSeeder` crée un petit scénario stable couvrant :

- un lead `HOT` routé vers Sarah ;
- un lead `WARM` routé vers Yassine ;
- un lead `LOW` routé vers Emma ;
- un lead invalide ;
- un doublon avec son origine ;
- un cas ambigu `NEEDS_REVIEW` ;
- un échec technique simulé ;
- une timeline complète.

Les seeders peuvent utiliser une graine fixe pour conserver des captures
d'écran reproductibles. Aucun nom, domaine, numéro ou contenu issu d'une
personne, d'un client ou d'un employeur réel n'est autorisé.

## 9. Sécurité et confidentialité

- validation côté serveur obligatoire, même lorsqu'un îlot Vue prévalide ;
- échappement Blade par défaut et absence de rendu HTML du message ;
- listes `$fillable` ou objets de données explicites contre l'assignation de
  masse ;
- transactions autour des créations, transitions et événements ;
- contrainte unique comme autorité finale d'idempotence ;
- verrou de ligne pendant une tentative pour éviter deux traitements concurrents ;
- upload CSV contrôlé par taille, extension, type et structure réelle ;
- aucune conservation du fichier CSV après traitement ;
- logs et événements sans payload brut, email, téléphone ou message ;
- message d'erreur technique tronqué et nettoyé avant persistance ;
- aucune donnée saisie envoyée à un service externe ;
- secrets uniquement dans l'environnement local, jamais dans Git ;
- `.env` exclu du versionnement et valeurs d'exemple non sensibles ;
- endpoint local absent des environnements non autorisés ;
- réponses webhook sans trace interne ni stack trace.

Les données étant fictives, la suppression fonctionnelle et une politique de
rétention avancée ne font pas partie du MVP. Les commandes de remise à zéro de
la base locale suffisent pour la démonstration.

## 10. Stratégie de tests

### 10.1 Tests unitaires

- normalisation de chaque champ et idempotence de la normalisation ;
- validation des contacts, du consentement et du catalogue ;
- détection d'un doublon email, téléphone, double correspondance et ambiguïté ;
- chaque règle positive et négative du score ;
- bornage à 0 et 100 ;
- seuils `LOW`, `WARM`, `HOT` aux bornes ;
- chaque branche de routage et le fallback ;
- matrice complète des transitions autorisées et refusées.

### 10.2 Tests fonctionnels Laravel

- affichage de chaque page et pagination ;
- création par formulaire ;
- validation et protection CSRF ;
- webhook `201`, répétition `200`, erreur `422` et indisponibilité hors local ;
- import CSV valide, partiellement invalide, trop volumineux et mal structuré ;
- filtres dashboard, liste et exceptions ;
- détail et ordre de la timeline ;
- correction/retraitement uniquement depuis `NEEDS_REVIEW` ;
- retry uniquement depuis `FAILED` ;
- absence de données sensibles dans les événements et logs applicatifs.

### 10.3 Tests d'intégration

- contrainte unique sous deux réceptions identiques ;
- job distribué après commit ;
- pipeline complet jusqu'à `ROUTED` ;
- transmission simulée avant la transition terminale ;
- deux retries maximum après la tentative initiale ;
- passage à `FAILED` après épuisement ;
- cohérence entre état du lead et événements après rollback ;
- requêtes de déduplication avec SQLite.

### 10.4 Tests frontend

Les îlots Vue restent suffisamment petits pour privilégier :

- tests des fonctions pures de prévalidation CSV/JSON ;
- un test de montage par composant si l'outillage retenu le permet ;
- tests fonctionnels Laravel comme autorité sur la validation finale.

Aucune bibliothèque de test frontend n'est ajoutée pendant la phase
d'architecture. Son besoin sera évalué lors de l'étape Vue, sans affaiblir les
tests serveur.

### 10.5 Contrôles de fin d'étape

À chaque étape implémentée :

1. `php artisan test`
2. `vendor/bin/pint --test`
3. `npm run build`

Les tests ciblés sont exécutés pendant le développement, puis la suite complète
avant chaque Pull Request de fonctionnalité.

## 11. Découpage recommandé de l'implémentation

Chaque étape doit rester petite, testable et former un commit ciblé.

1. **Contrats métier** — enums, configuration, matrice de transition et tests
   unitaires.
2. **Persistance** — migrations `leads` et `processing_events`, modèles,
   relations et tests de schéma.
3. **Données fictives** — factories et premiers scénarios stables.
4. **Journal et transitions** — recorder, service de transition et tests
   transactionnels.
5. **Ingestion commune** — service idempotent et référence générée à l'affichage
   du formulaire.
6. **Normalisation et validation** — règles partagées et tests de cas limites.
7. **Déduplication** — index, détecteur, ambiguïtés et concurrence.
8. **Score et classification** — détail explicable, bornage et seuils.
9. **Routage et transmission simulée** — contrat, faux local et parcours nominal.
10. **Job et retries** — orchestration, backoff, `FAILED` et reprise manuelle.
11. **Formulaire et détail Blade** — création, statut et timeline.
12. **Liste, dashboard et exceptions Blade** — filtres, agrégats et pagination.
13. **Webhook local** — contrôleur JSON, idempotence et limites de débit.
14. **Import CSV** — validation, traitement ligne par ligne et résumé.
15. **Îlots Vue 3** — dépendance Vue approuvée, prévisualisation CSV et simulateur
    JSON, sans SPA.
16. **Seeder de démonstration** — tous les scénarios fictifs et reproductibles.
17. **Durcissement final** — sécurité, confidentialité, tests complets, Pint et
    build Vite.

## 12. Décisions techniques consolidées

- deux entités métier seulement : `Lead` et `ProcessingEvent` ;
- traitement asynchrone par job Laravel avec queue `database` ;
- idempotence garantie par `source + external_reference` et contrainte SQL ;
- référence générée côté serveur pour le formulaire ;
- `ROUTED` signifie que la transmission simulée a réussi ;
- aucune transition ou règle `REJECTED`, statut retiré car non défini ;
- trois tentatives techniques automatiques par cycle de traitement ;
- règles de score centralisées, versionnées et explicables ;
- assignés représentés par des libellés fictifs, sans table utilisateur métier ;
- Blade pour toutes les pages, Vue 3 pour deux îlots seulement ;
- webhook strictement local ;
- SQLite comme cible locale initiale, sans SQL spécifique au moteur ;
- aucune intégration externe et aucune donnée réelle.

## 13. Points restant à décider

Aucun point ne bloque le développement du MVP local décrit ici.

Avant un éventuel déploiement public interactif, il faudrait décider du mode
d'authentification et du moteur de base de données cible. Ce sont des décisions
de déploiement ultérieures, pas des fonctionnalités du MVP local.
