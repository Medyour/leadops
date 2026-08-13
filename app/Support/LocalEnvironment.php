<?php

namespace App\Support;

use Illuminate\Support\Env;

final class LocalEnvironment
{
    /** @var list<string> */
    private const ALLOWED = ['local', 'testing'];

    public static function allowsMutations(): bool
    {
        $applicationEnvironment = app()->environment();
        $configuredEnvironment = (string) config('app.env');
        $runtimeEnvironment = Env::get('APP_ENV');

        return in_array($applicationEnvironment, self::ALLOWED, true)
            && in_array($configuredEnvironment, self::ALLOWED, true)
            && is_string($runtimeEnvironment)
            && in_array($runtimeEnvironment, self::ALLOWED, true);
    }
}
