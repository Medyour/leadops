# LeadOps — Consignes de contribution

## Architecture et interface

- Utiliser Laravel comme backend et conserver les conventions du framework.
- Construire la structure des pages et le rendu serveur avec Blade.
- Réserver Vue 3 aux interfaces qui nécessitent une interactivité côté client ; ne pas convertir les pages statiques en SPA.
- Utiliser Tailwind CSS pour le style et privilégier les classes utilitaires existantes.

## Qualité

- Ajouter ou adapter des tests automatisés pour toute fonctionnalité importante.
- Exécuter les tests Laravel, Laravel Pint et le build Vite avant de considérer une modification terminée.
- Garder les changements ciblés, lisibles et limités au besoin traité.

## Données et intégrations

- Ne jamais utiliser, stocker ou committer de données réelles, personnelles ou confidentielles.
- Utiliser uniquement des données fictives et anonymes dans les exemples, fixtures et tests.
- Ne connecter aucune API d'IA ou de CRM réelle dans le MVP ; utiliser des simulations ou adaptateurs locaux si nécessaire.
- Ne jamais committer de secret, jeton, clé, certificat ou fichier d'environnement local.

## Git

- Créer des commits ciblés avec des messages explicites décrivant précisément le changement.
- Ne pas mélanger refactorisation, mise en forme et fonctionnalité sans lien dans un même commit.
- Ne pas écraser les modifications locales existantes et ne pas réécrire l'historique partagé.
