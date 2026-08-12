@extends('layouts.app')

@section('title', 'Exceptions — LeadOps')

@section('content')
    <div class="max-w-3xl">
        <p class="text-sm font-bold uppercase tracking-widest text-amber-700">Intervention requise</p>
        <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">File des exceptions</h1>
        <p class="mt-3 text-slate-600">{{ $leads->total() }} lead{{ $leads->total() > 1 ? 's' : '' }} invalide{{ $leads->total() > 1 ? 's' : '' }}, en doublon, à examiner ou en échec.</p>
    </div>

    <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Signification des statuts d’exception">
        <div class="rounded-xl border border-red-200 bg-red-50 p-3"><x-status-badge status="INVALID" /><p class="mt-2 text-xs text-red-950">Données métier invalides.</p></div>
        <div class="rounded-xl border border-violet-200 bg-violet-50 p-3"><x-status-badge status="DUPLICATE" /><p class="mt-2 text-xs text-violet-950">Correspondance avec un lead antérieur.</p></div>
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3"><x-status-badge status="NEEDS_REVIEW" /><p class="mt-2 text-xs text-amber-950">Décision humaine nécessaire.</p></div>
        <div class="rounded-xl border border-red-200 bg-white p-3"><x-status-badge status="FAILED" /><p class="mt-2 text-xs text-red-950">Échec technique du traitement.</p></div>
    </div>

    <div class="mt-8">@include('leads._filters', ['action' => route('exceptions.index')])</div>
    <div class="mt-6">@include('leads._table', ['caption' => 'File filtrée des exceptions', 'emptyTitle' => 'Aucune exception trouvée', 'emptyMessage' => 'Aucun lead du périmètre des exceptions ne correspond aux filtres.'])</div>
@endsection
