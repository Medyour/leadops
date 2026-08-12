@extends('layouts.app')

@section('title', 'Import CSV — LeadOps')

@section('content')
    <div class="mx-auto max-w-5xl">
        <div class="mb-8">
            <p class="text-sm font-bold uppercase tracking-widest text-blue-700">Réception par lot</p>
            <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Importer des leads fictifs</h1>
            <p class="mt-3 max-w-3xl text-base leading-7 text-slate-600">
                Import local uniquement. L’upload HTTP peut utiliser un fichier temporaire géré par PHP ; LeadOps n’enregistre aucune copie applicative ou permanente.
            </p>
        </div>

        @if ($errors->any())
            <div role="alert" aria-labelledby="csv-error-title" class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 text-red-950">
                <h2 id="csv-error-title" class="font-bold">L’import n’a pas démarré.</h2>
                <ul id="csv-file-errors" class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1.3fr)_minmax(18rem,0.7fr)]">
            <form method="POST" action="{{ route('imports.csv.store') }}" enctype="multipart/form-data" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
                @csrf
                <label for="csv_file" class="block text-base font-bold text-slate-950">Fichier CSV</label>
                <p id="csv-file-help" class="mt-1 text-sm leading-6 text-slate-600">UTF-8, virgule, 2 Mio maximum et 500 lignes de données maximum.</p>
                <input id="csv_file" name="csv_file" type="file" accept=".csv,text/csv" required aria-describedby="csv-file-help{{ $errors->has('csv_file') ? ' csv-file-errors' : '' }}"
                    @if ($errors->has('csv_file')) aria-invalid="true" @endif
                    class="mt-4 block w-full min-w-0 rounded-xl border border-slate-300 bg-slate-50 px-3 py-3 text-sm text-slate-800 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-100 file:px-3 file:py-2 file:font-bold file:text-blue-900 hover:file:bg-blue-200 focus:outline-none focus:ring-2 focus:ring-blue-600">
                <button type="submit" class="mt-6 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-blue-700 px-5 py-3 text-sm font-bold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 sm:w-auto">
                    Lancer l’import
                </button>
            </form>

            <aside aria-labelledby="csv-format-title" class="rounded-2xl border border-slate-200 bg-slate-100 p-5 sm:p-6">
                <h2 id="csv-format-title" class="text-lg font-black text-slate-950">Format attendu</h2>
                <ul class="mt-3 space-y-1 text-sm leading-6 text-slate-700">
                    <li>Encodage UTF-8, BOM facultatif uniquement au début</li>
                    <li>Séparateur virgule</li>
                    <li>Valeurs citées selon le format CSV</li>
                    <li>En-têtes strictement ordonnés</li>
                </ul>
                <a href="{{ asset('examples/leads-import-example.csv') }}" download class="mt-5 inline-flex min-h-11 items-center rounded-lg border border-blue-300 bg-white px-4 py-2 text-sm font-bold text-blue-800 hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-600">
                    Télécharger l’exemple CSV
                </a>
            </aside>
        </div>

        <section aria-labelledby="csv-header-title" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 id="csv-header-title" class="text-lg font-black text-slate-950">En-tête exact</h2>
            <p class="mt-2 text-sm text-slate-600">Copiez ces colonnes sans les renommer ni les réordonner.</p>
            <code class="mt-4 block overflow-x-auto rounded-xl bg-slate-950 p-4 text-xs leading-6 text-slate-100">{{ implode(',', $header) }}</code>
        </section>

        @if ($summary)
            <section aria-labelledby="import-summary-title" class="mt-8">
                <h2 id="import-summary-title" class="text-2xl font-black tracking-tight text-slate-950">Résumé de l’import</h2>
                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    @foreach ([
                        ['created', 'Créés', 'border-emerald-200 bg-emerald-50 text-emerald-950'],
                        ['already_received', 'Déjà reçus', 'border-blue-200 bg-blue-50 text-blue-950'],
                        ['invalid', 'Invalides', 'border-red-200 bg-red-50 text-red-950'],
                    ] as [$key, $label, $classes])
                        <div class="rounded-xl border p-4 {{ $classes }}">
                            <p class="text-sm font-bold">{{ $label }}</p>
                            <p class="mt-1 text-3xl font-black">{{ $summary[$key] }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                            <caption class="sr-only">Résultat non sensible de chaque ligne importée</caption>
                            <thead class="bg-slate-100 text-slate-700">
                                <tr>
                                    <th scope="col" class="px-4 py-3 font-bold">Ligne</th>
                                    <th scope="col" class="px-4 py-3 font-bold">Référence</th>
                                    <th scope="col" class="px-4 py-3 font-bold">Résultat</th>
                                    <th scope="col" class="px-4 py-3 font-bold">Champs concernés</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                @forelse ($summary['results'] as $result)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-3 font-semibold">{{ $result['line'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 font-mono text-xs">{{ $result['external_reference'] ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 font-bold">
                                            {{ match ($result['status']) {
                                                'created' => 'Créé',
                                                'already_received' => 'Déjà reçu',
                                                default => 'Invalide',
                                            } }}
                                        </td>
                                        <td class="px-4 py-3 text-slate-600">{{ $result['fields'] ? implode(', ', $result['fields']) : '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-4 py-6 text-center text-slate-600">Aucune ligne de données.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        @endif
    </div>
@endsection
