# Scénarios de démonstration LeadOps

Ce jeu de démonstration est entièrement fictif, déterministe et local. Il ne constitue pas une preuve de résultat client et ne contient aucune donnée réelle.

| Référence | Source | Contacts | Statut | Résultat visible | Objectif |
|---|---|---|---|---|---|
| `demo-hot-001` | formulaire | e-mail et téléphone valides | `ROUTED` / `HOT` | Sarah, score 90 | Montrer le parcours nominal complet jusqu’à la transmission simulée. |
| `demo-warm-001` | CSV | e-mail et téléphone valides | `ROUTED` / `WARM` | Yassine, score 70 | Montrer une qualification minorée par l’absence de pays. |
| `demo-low-001` | webhook | e-mail valide, sans téléphone | `ROUTED` / `LOW` | Emma, score 40 | Montrer un lead valide sans entreprise, pays ni taille. |
| `demo-invalid-001` | formulaire | e-mail invalide, sans téléphone | `INVALID` | motif `contact.invalid` | Montrer le rejet métier sans autre contact valide. |
| `demo-invalid-phone-001` | CSV | sans e-mail, téléphone invalide | `INVALID` | motif `contact.invalid` | Montrer le rejet métier de la forme normalisée trop longue du numéro fictif réservé `+1 202-555-0199` muni d’une extension fictive. |
| `demo-duplicate-001` | webhook | e-mail et téléphone de `demo-hot-001` | `DUPLICATE` | lien vers `demo-hot-001` | Montrer une correspondance unique reliée à son origine. |
| `demo-review-001` | formulaire | e-mail de `demo-hot-001`, téléphone de `demo-warm-001` | `NEEDS_REVIEW` | motif et identifiants candidats | Montrer une ambiguïté modifiable puis retraitable dans l’interface locale. |
| `demo-failed-001` | webhook | e-mail et téléphone valides | `FAILED` | trois tentatives et cycle épuisé | Montrer un incident technique relançable dans l’interface locale. |

Chaque lead possède une timeline cohérente. Les trois leads routés exposent réception, démarrage, normalisation, validation, déduplication, score, classification, décision de routage, qualification, transmission simulée puis état `ROUTED`. La transmission réussie précède toujours l’événement terminal. Les timelines ne contiennent ni e-mail, ni téléphone, ni message, ni payload.

Le fichier `tests/Fixtures/invalid-webhook-payload.json` représente séparément une requête webhook invalide. Il est testé comme une réponse `422` sans création de lead, d’événement ou de job ; il ne fait donc pas partie des huit leads persistants.

## Lancer le seeder seul

Sur une base locale de démonstration déjà migrée :

```shell
php artisan db:seed --class=LeadDemoSeeder
```

La commande peut être répétée : le manifeste de huit paires `source + external_reference`, leurs relations, leurs événements, leurs valeurs et leurs horodatages restent identiques. Le seeder ne distribue aucun job. Une collision portant la même référence avec une autre source est conservée ; seules les lignes reconnues comme provenant de l’ancienne version du seeder sont migrées vers la source actuelle.

`php artisan migrate:fresh --seed` détruit intégralement la base ciblée avant de la reconstruire. Cette commande doit uniquement être utilisée sur une base locale jetable, jamais sur la base locale habituelle ni sur une base contenant des données à conserver.
