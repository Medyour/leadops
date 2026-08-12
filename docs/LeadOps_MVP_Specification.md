# LeadOps MVP Specification

## Vision et objectif

LeadOps est un démonstrateur de portfolio montrant comment automatiser le
traitement de prospects B2B, depuis leur réception jusqu'à leur qualification,
leur routage et la gestion des exceptions. Il démontre l'automatisation de
processus, l'intégration via formulaire, CSV et webhook local, ainsi que la
fiabilité des données. Il ne constitue ni un CRM complet ni un produit
commercial.

## Cible et scénario métier

**Persona principal :** responsable opérations d'une agence B2B de 8 à 20
collaborateurs recevant de nombreux prospects.

**Scénario :**

1. réception d'un prospect ;
2. validation ;
3. normalisation ;
4. déduplication ;
5. scoring ;
6. classification ;
7. routage ;
8. transmission simulée ;
9. intervention humaine uniquement pour les exceptions.

## Périmètre MVP

### Inclus

- Formulaire de création de lead
- Import CSV
- Webhook JSON local
- Validation
- Normalisation
- Déduplication
- Scoring déterministe
- Classification HOT/WARM/LOW
- Routage déterministe
- Simulation de transmission
- Journal d'événements
- Retries techniques limités
- File des exceptions
- Dashboard, liste, détail et timeline

### Exclusions

- CRM complet
- Paiement
- Multi-tenant
- Téléphonie
- SMS / WhatsApp
- Rôles complexes
- Intégration CRM réelle
- API IA payante
- Marketing automation
- SaaS complet
- Application mobile
- Toute fonctionnalité non validée

## Parcours utilisateur

1. Réception du lead
2. Validation et normalisation
3. Recherche de doublons
4. Calcul du score
5. Classification
6. Routage
7. Transmission simulée
8. Journalisation

Exceptions : `INVALID`, `DUPLICATE`, `NEEDS_REVIEW`, `FAILED`.

## Données d'un prospect

### Données reçues

- source
- external_reference
- first_name
- last_name
- company
- email
- phone
- country
- service_interest
- company_size
- message
- consent
- received_at

`external_reference` est obligatoire pour les imports CSV et les webhooks. Lors
de l'affichage du formulaire, le serveur génère cette référence et l'inclut dans
un champ caché. Deux envois du même formulaire portent ainsi la même référence.
Une nouvelle ouverture du formulaire génère une nouvelle référence. Une
référence est donc toujours présente avant l'enregistrement.

### Données gérées ou calculées par le système

- id
- normalized_email
- normalized_phone
- score
- classification
- assigned_to
- status
- duplicate_of
- processing_attempts
- processed_at

## Pipeline et statuts

Parcours nominal :

`RECEIVED → VALIDATING → QUALIFIED → ROUTED`

États possibles :

- `RECEIVED`
- `VALIDATING`
- `INVALID`
- `DUPLICATE`
- `QUALIFIED`
- `NEEDS_REVIEW`
- `ROUTED`
- `FAILED`

`ROUTED` est atteint uniquement après une transmission simulée réussie. La
transmission est décrite dans le journal d'événements et ne nécessite pas un
statut supplémentaire.

Transitions autorisées :

- `RECEIVED → VALIDATING`
- `VALIDATING → INVALID | DUPLICATE | QUALIFIED | NEEDS_REVIEW | FAILED`
- `QUALIFIED → ROUTED | NEEDS_REVIEW | FAILED`
- `FAILED → VALIDATING` lors d'une reprise autorisée
- `NEEDS_REVIEW → VALIDATING` après correction

`INVALID`, `DUPLICATE` et `ROUTED` sont terminaux dans le MVP.

## Règles déterministes

### Validation

- source obligatoire et appartenant à `form`, `csv` ou `webhook`
- référence externe obligatoire une fois la réception préparée
- prénom obligatoire
- nom obligatoire
- consentement obligatoire et accepté (`true`)
- service d'intérêt obligatoire
- email valide ou téléphone valide obligatoire
- taille d'entreprise, lorsqu'elle est fournie, entière et positive

Une erreur certaine produit `INVALID`. Une valeur de service inconnue mais
syntaxiquement valide produit `NEEDS_REVIEW` afin de permettre une correction.

### Normalisation

- trim des champs textuels
- réduction des espaces internes répétés dans les noms, l'entreprise et le
  message
- email en minuscules
- téléphone converti dans une forme canonique internationale lorsque le pays et
  le numéro permettent de le faire, sinon conservation des chiffres et du `+`
  initial pour la validation
- chaîne vide convertie en `null` pour les champs facultatifs

### Idempotence et déduplication

L'idempotence d'entrée repose sur la paire unique
`source + external_reference`. Une réception répétée retourne le lead existant
et ne relance pas son traitement.

La déduplication métier recherche ensuite :

1. l'email normalisé ;
2. le téléphone normalisé.

Un unique lead antérieur correspondant produit `DUPLICATE` et renseigne
`duplicate_of`. Si l'email et le téléphone renvoient vers deux leads différents,
ou si plusieurs candidats incompatibles existent, le cas est ambigu et produit
`NEEDS_REVIEW`.

### Score

Base : 20 points.

- +10 si la taille d'entreprise est renseignée
- +10 si l'entreprise compte au moins 5 salariés
- +10 si l'email est valide
- +10 si le téléphone est valide
- +20 si le service d'intérêt appartient au catalogue reconnu
- +10 si le message normalisé contient au moins 80 caractères
- -5 si le domaine email appartient à la liste locale des domaines génériques
- -10 si l'entreprise ou le pays manque

La liste initiale des domaines génériques est : `gmail.com`, `outlook.com`,
`hotmail.com`, `yahoo.com` et `icloud.com`. Le catalogue des services reconnus
est : `automation`, `api`, `business_application`, `dashboard`, `other`.

Ces valeurs sont centralisées dans la configuration locale et couvertes par des
tests. Toute modification de la liste, du catalogue ou des seuils constitue une
modification explicite des règles métier.

Le score est borné entre 0 et 100.

Classification :

- `HOT` : 80 à 100
- `WARM` : 50 à 79
- `LOW` : 0 à 49

### Routage

- `automation` ou `api` → Sarah
- `business_application` ou `dashboard` → Yassine
- `other` → Emma
- catégorie inconnue → `NEEDS_REVIEW`

Les noms d'assignation sont des libellés fictifs de démonstration, pas des
comptes utilisateurs ni une intégration CRM.

## Retries et erreurs techniques

Une tentative initiale et au maximum deux retries techniques sont autorisés,
soit trois tentatives par cycle de traitement. `processing_attempts` compte les
tentatives du cycle courant, tentative initiale comprise. Une reprise manuelle
ouvre un nouveau cycle et remet ce compteur à zéro ; le journal conserve
l'historique de tous les cycles.

Les résultats métier (`INVALID`, `DUPLICATE`, `NEEDS_REVIEW`) ne sont pas
retentés automatiquement. Après épuisement des tentatives techniques, le lead
passe à `FAILED`. Une reprise manuelle de `FAILED` et un retraitement de
`NEEDS_REVIEW` après correction sont autorisés et journalisés.

## Écrans

- Dashboard
- Liste des leads
- Détail et timeline
- Nouveau lead
- Import CSV
- Simulateur webhook
- File des exceptions

## Modèle de données recommandé

Entités métier :

- Lead
- ProcessingEvent

Le modèle physique, les relations et les index sont définis dans le document
d'architecture du MVP.

## Sécurité et confidentialité

- uniquement des données fictives
- aucun client réel
- aucune donnée employeur
- aucune clé API
- aucun secret Git
- aucune intégration externe obligatoire
- endpoint webhook limité aux environnements local et de test dans le MVP

## Données de démonstration

Le jeu de données fictif comprend :

- lead valide
- lead faible priorité
- email invalide
- téléphone invalide
- doublons
- payload webhook invalide
- erreur simulée
- cas `NEEDS_REVIEW`

Tous les noms, entreprises, emails et téléphones sont fictifs.

## Critères d'acceptation

- réception via formulaire, CSV et webhook local
- validation correcte
- normalisation correcte
- déduplication correcte
- idempotence sur `source + external_reference`
- score reproductible
- classification correcte
- routage correct
- transmission simulée journalisée avant `ROUTED`
- retries limités à trois tentatives par cycle de traitement
- timeline complète
- aucun service externe requis

## Stratégie de tests

Tester :

- lead valide
- lead invalide
- consentement refusé
- email normalisé
- téléphone normalisé
- doublon email
- doublon téléphone
- doublon ambigu
- webhook répété et idempotence
- scoring et bornage
- classification
- routage
- fallback vers `NEEDS_REVIEW`
- `FAILED`
- retry
- import CSV
- transmission simulée

## Étapes d'implémentation recommandées

1. Contrat métier
2. Modèle de données
3. Réception et validation
4. Normalisation et déduplication
5. Scoring et classification
6. Routage et transmission simulée
7. Gestion des erreurs et retries
8. Webhook local
9. Import CSV
10. Interface utilisateur
11. Jeu de données de démonstration
12. Tests et validation finale portfolio

## IA

Décision validée : aucune IA dans le MVP.

Une éventuelle intégration IA reste une phase ultérieure et n'est pas
considérée comme développée.

## Points restant à décider

Aucune décision métier bloquante ne subsiste pour commencer le MVP. Un éventuel
déploiement public interactif nécessiterait une décision d'authentification et
sort du périmètre local défini ici.
