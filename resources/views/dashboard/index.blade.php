@extends('layouts.app')

@section('title', 'Tableau de bord — LeadOps')

@section('content')
    <div class="max-w-3xl">
        <p class="text-sm font-bold uppercase tracking-widest text-blue-700">Vue opérationnelle</p>
        <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Tableau de bord</h1>
        <p class="mt-3 text-slate-600">Suivez le volume, le traitement et les exceptions de tous les leads fictifs.</p>
    </div>

    <section aria-labelledby="overview-title" class="mt-8">
        <h2 id="overview-title" class="text-xl font-black text-slate-950">Vue d’ensemble</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <x-metric-card label="Total des leads" :value="$totalLeads" :href="route('leads.index')" description="Tous les leads reçus" />
            <x-metric-card label="Exceptions" :value="$totalExceptions" :href="route('exceptions.index')" description="Invalides, doublons, à examiner ou en échec" />
        </div>
    </section>

    @php
        $groups = [
            ['title' => 'Par statut', 'filter' => 'status', 'counts' => $statusCounts, 'labels' => [
                'RECEIVED' => 'Reçus', 'VALIDATING' => 'En validation', 'INVALID' => 'Invalides', 'DUPLICATE' => 'Doublons',
                'QUALIFIED' => 'Qualifiés', 'NEEDS_REVIEW' => 'À examiner', 'ROUTED' => 'Routés', 'FAILED' => 'Échecs',
            ]],
            ['title' => 'Par classification', 'filter' => 'classification', 'counts' => $classificationCounts, 'labels' => [
                'HOT' => 'HOT', 'WARM' => 'WARM', 'LOW' => 'LOW',
            ]],
            ['title' => 'Par source', 'filter' => 'source', 'counts' => $sourceCounts, 'labels' => [
                'form' => 'Formulaire', 'csv' => 'Import CSV', 'webhook' => 'Webhook local',
            ]],
        ];
    @endphp

    @foreach ($groups as $group)
        <section aria-labelledby="group-{{ $group['filter'] }}" class="mt-10">
            <h2 id="group-{{ $group['filter'] }}" class="text-xl font-black text-slate-950">{{ $group['title'] }}</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($group['counts'] as $value => $count)
                    <x-metric-card
                        :label="$group['labels'][$value]"
                        :value="$count"
                        :href="route('leads.index', [$group['filter'] => $value])"
                    />
                @endforeach
            </div>
        </section>
    @endforeach
@endsection
