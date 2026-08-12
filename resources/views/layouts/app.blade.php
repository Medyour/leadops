<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="LeadOps, démonstrateur local de traitement de leads fictifs.">
    <title>@yield('title', 'LeadOps')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-950 antialiased">
    <a href="#contenu" class="sr-only z-50 rounded bg-white px-4 py-2 text-sm font-semibold text-slate-950 focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:ring-2 focus:ring-blue-600">
        Aller au contenu
    </a>

    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
            <a href="{{ url('/') }}" class="text-lg font-black tracking-tight text-slate-950">LeadOps</a>
            @if (app()->environment(['local', 'testing']))
                <a href="{{ route('leads.create') }}" class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2">
                    Nouveau lead
                </a>
            @endif
        </div>
    </header>

    <main id="contenu" class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6 sm:py-12 lg:px-8">
        @if (session('status'))
            <div role="status" class="mb-8 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-950">
                {{ session('status') }}
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
