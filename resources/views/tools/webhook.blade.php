@extends('layouts.app')

@section('title', 'Simulateur webhook — LeadOps')

@section('content')
    <div class="mx-auto max-w-5xl">
        <div class="mb-8">
            <p class="text-sm font-bold uppercase tracking-widest text-blue-700">Outil local</p>
            <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Simuler la réception d’un webhook</h1>
            <p class="mt-3 max-w-3xl text-base leading-7 text-slate-600">
                Testez le webhook LeadOps avec des données exclusivement fictives. La prévalidation dans le navigateur est indicative&nbsp;: Laravel reste l’unique autorité de validation.
            </p>
        </div>

        <div
            data-webhook-simulator
            data-endpoint="{{ $webhookUrl }}"
        >
            <noscript>
                <p role="alert" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm font-semibold text-amber-950">
                    JavaScript doit être activé pour utiliser ce simulateur local.
                </p>
            </noscript>
        </div>
    </div>
@endsection
