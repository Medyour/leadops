<!DOCTYPE html>
<html lang="fr" class="overflow-x-hidden">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="LeadOps, démonstrateur local de traitement de leads fictifs.">
    <title>@yield('title', 'LeadOps')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen overflow-x-hidden bg-slate-50 text-slate-950 antialiased">
    <a href="#contenu" class="sr-only z-50 rounded bg-white px-4 py-2 text-sm font-semibold text-slate-950 focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:ring-2 focus:ring-blue-600">
        Aller au contenu
    </a>

    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl flex-col items-start gap-4 px-4 py-4 sm:px-6 md:flex-row md:items-center md:justify-between lg:px-8">
            <a href="{{ route('dashboard') }}" class="text-lg font-black tracking-tight text-slate-950">LeadOps</a>
            <nav aria-label="Navigation principale" class="w-full md:w-auto">
                <ul class="flex flex-wrap items-center gap-1 text-sm font-bold">
                    @foreach ([route('dashboard') => ['Tableau de bord', 'dashboard'], route('leads.index') => ['Leads', 'leads.*'], route('exceptions.index') => ['Exceptions', 'exceptions.*']] as $href => [$label, $pattern])
                        <li><a href="{{ $href }}" @if(request()->routeIs($pattern)) aria-current="page" @endif class="block rounded-lg px-3 py-2 {{ request()->routeIs($pattern) ? 'bg-blue-50 text-blue-800' : 'text-slate-700 hover:bg-slate-100' }} focus:outline-none focus:ring-2 focus:ring-blue-600">{{ $label }}</a></li>
                    @endforeach
                    @if (app()->environment(['local', 'testing']))
                        <li><a href="{{ route('imports.csv.create') }}" @if(request()->routeIs('imports.csv.*')) aria-current="page" @endif class="block rounded-lg px-3 py-2 {{ request()->routeIs('imports.csv.*') ? 'bg-blue-50 text-blue-800' : 'text-slate-700 hover:bg-slate-100' }} focus:outline-none focus:ring-2 focus:ring-blue-600">Import CSV</a></li>
                        @if (Route::has('tools.webhook'))
                            <li><a href="{{ route('tools.webhook') }}" @if(request()->routeIs('tools.webhook')) aria-current="page" @endif class="block rounded-lg px-3 py-2 {{ request()->routeIs('tools.webhook') ? 'bg-blue-50 text-blue-800' : 'text-slate-700 hover:bg-slate-100' }} focus:outline-none focus:ring-2 focus:ring-blue-600">Simulateur webhook</a></li>
                        @endif
                        <li><a href="{{ route('leads.create') }}" class="block rounded-lg bg-blue-700 px-3 py-2 text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2">Nouveau lead</a></li>
                    @endif
                </ul>
            </nav>
        </div>
    </header>

    <main id="contenu" class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6 sm:py-12 lg:px-8">
        @if (session('status'))
            <div role="status" aria-atomic="true" class="mb-8 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-950">
                {{ session('status') }}
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
