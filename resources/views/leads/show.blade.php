@extends('layouts.app')

@section('title', 'Lead #'.$lead->id.' — LeadOps')

@section('content')
    <div class="flex flex-col gap-5 border-b border-slate-200 pb-8 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="text-sm font-bold uppercase tracking-widest text-blue-700">Lead #{{ $lead->id }}</p>
            <h1 class="mt-2 break-words text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">
                {{ $lead->first_name }} {{ $lead->last_name }}
            </h1>
            <p class="mt-2 text-slate-600">
                Reçu le {{ $lead->received_at->format('d/m/Y à H:i') }} via
                {{ match ($lead->source->value) {
                    'form' => 'formulaire',
                    'csv' => 'import CSV',
                    'webhook' => 'webhook local',
                } }}.
            </p>
        </div>
        <x-status-badge :status="$lead->status" class="shrink-0" />
    </div>

    @if (in_array($lead->status, [\App\Domain\LeadProcessing\Enums\LeadStatus::Received, \App\Domain\LeadProcessing\Enums\LeadStatus::Validating, \App\Domain\LeadProcessing\Enums\LeadStatus::Qualified], true))
        <div class="mt-6 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">
            <p class="font-bold">Traitement en attente ou en cours</p>
            <p class="mt-1">Le score, la classification ou l’assignation peuvent encore évoluer.</p>
        </div>
    @endif

    @if ($lead->duplicateOf)
        <div class="mt-6 rounded-xl border border-violet-200 bg-violet-50 p-4 text-sm text-violet-950">
            <p class="font-bold">Doublon détecté</p>
            <p class="mt-1">
                Ce lead correspond au lead
                <a href="{{ route('leads.show', $lead->duplicateOf) }}" class="font-bold underline decoration-2 underline-offset-2">#{{ $lead->duplicateOf->id }}</a>
                (<x-status-badge :status="$lead->duplicateOf->status" />). Aucun contenu du lead d’origine n’est affiché ici.
            </p>
        </div>
    @endif

    @include('leads._exception_actions')

    <section aria-labelledby="summary-title" class="mt-8">
        <h2 id="summary-title" class="text-xl font-black text-slate-950">Synthèse du traitement</h2>
        <dl class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <dt class="text-sm font-semibold text-slate-600">Statut</dt>
                <dd class="mt-2"><x-status-badge :status="$lead->status" /></dd>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <dt class="text-sm font-semibold text-slate-600">Score</dt>
                <dd class="mt-1 text-2xl font-black text-slate-950">{{ $lead->score ?? '—' }}<span class="text-sm font-semibold text-slate-500"> / 100</span></dd>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <dt class="text-sm font-semibold text-slate-600">Classification</dt>
                <dd class="mt-2"><x-classification-badge :classification="$lead->classification" /></dd>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <dt class="text-sm font-semibold text-slate-600">Assignation</dt>
                <dd class="mt-1 break-words text-lg font-black text-slate-950">{{ $lead->assigned_to ?? 'En attente' }}</dd>
            </div>
        </dl>
    </section>

    <div class="mt-8 grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(20rem,0.8fr)]">
        <section aria-labelledby="business-title" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 id="business-title" class="text-xl font-black text-slate-950">Données métier</h2>
            <dl class="mt-5 grid gap-x-6 gap-y-5 sm:grid-cols-2">
                @foreach ([
                    'Entreprise' => $lead->company,
                    'Service recherché' => str_replace('_', ' ', $lead->service_interest),
                    'Taille de l’entreprise' => $lead->company_size,
                    'Pays' => $lead->country,
                    'Adresse e-mail' => $lead->email,
                    'Téléphone' => $lead->phone,
                ] as $label => $value)
                    <div class="min-w-0">
                        <dt class="text-sm font-semibold text-slate-600">{{ $label }}</dt>
                        <dd class="mt-1 break-words font-medium text-slate-950">{{ filled($value) ? $value : 'Non renseigné' }}</dd>
                    </div>
                @endforeach
                <div class="sm:col-span-2">
                    <dt class="text-sm font-semibold text-slate-600">Message</dt>
                    <dd class="mt-1 whitespace-pre-wrap break-words font-medium text-slate-950">{{ $lead->message ?: 'Non renseigné' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-sm font-semibold text-slate-600">Consentement</dt>
                    <dd class="mt-1 font-medium text-slate-950">{{ $lead->consent ? 'Accepté' : 'Non accepté' }}</dd>
                </div>
            </dl>
        </section>

        <section aria-labelledby="timeline-title" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h2 id="timeline-title" class="text-xl font-black text-slate-950">Timeline</h2>
            @if ($lead->processingEvents->isEmpty())
                <p class="mt-4 text-sm text-slate-600">Aucun événement journalisé.</p>
            @else
                <ol class="relative mt-6 space-y-8 before:absolute before:bottom-2 before:left-[5px] before:top-2 before:w-px before:bg-slate-200">
                    @foreach ($lead->processingEvents as $event)
                        <x-timeline-event :event="$event" />
                    @endforeach
                </ol>
            @endif
        </section>
    </div>
@endsection
