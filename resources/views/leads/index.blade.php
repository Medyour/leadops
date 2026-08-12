@extends('layouts.app')

@section('title', 'Leads — LeadOps')

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-bold uppercase tracking-widest text-blue-700">Consultation</p>
            <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Tous les leads</h1>
            <p class="mt-2 text-slate-600">{{ $leads->total() }} résultat{{ $leads->total() > 1 ? 's' : '' }}</p>
        </div>
        @if (app()->environment(['local', 'testing']))
            <a href="{{ route('leads.create') }}" class="rounded-lg bg-blue-700 px-4 py-2.5 text-center text-sm font-bold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2">Nouveau lead</a>
        @endif
    </div>

    <div class="mt-8">@include('leads._filters', ['action' => route('leads.index')])</div>
    <div class="mt-6">@include('leads._table', ['caption' => 'Liste filtrée des leads', 'emptyTitle' => 'Aucun lead trouvé', 'emptyMessage' => 'Modifiez ou réinitialisez les filtres pour afficher des résultats.'])</div>
@endsection
