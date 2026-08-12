<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Webhook JSON local

Le webhook `POST /api/local/webhooks/leads` est enregistré uniquement avec
`APP_ENV=local` ou `APP_ENV=testing`. Il attend `Content-Type: application/json`
et une enveloppe contenant exactement une clé `lead` :

```json
{
  "lead": {
    "external_reference": "webhook-2026-0001",
    "first_name": "Prénom Fictif",
    "last_name": "Nom Fictif",
    "company": "Atelier Imaginaire",
    "email": "lead@example.test",
    "phone": "+12025550123",
    "country": "FR",
    "service_interest": "automation",
    "company_size": 12,
    "message": "Message entièrement fictif.",
    "consent": true,
    "received_at": "2026-08-12T10:15:30Z"
  }
}
```

`external_reference`, les noms, le service, le consentement booléen à `true`
et au moins un contact valide sont obligatoires. `company`, `email`, `phone`,
`country`, `company_size`, `message` et `received_at` sont facultatifs. Une date
fournie doit respecter strictement le format UTC `YYYY-MM-DDTHH:MM:SSZ`. Un
champ `source` chaîne est toléré dans `lead` pour les émetteurs génériques mais
est toujours ignoré : le serveur persiste `webhook`.

Le débit est limité par client à 30 requêtes par minute par défaut. La valeur se
configure avec `LEADOPS_LOCAL_WEBHOOK_RATE_LIMIT` et reste bornée entre 1 et 600
requêtes par minute ; la clé de débit est un HMAC de l'adresse réseau et ne
conserve jamais l'adresse brute. La route n'utilise ni session, ni CSRF, ni
authentification fictive. Les réponses ne contiennent que `result`, puis
l'identifiant et le statut du lead en cas de succès.
