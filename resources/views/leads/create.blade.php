@extends('layouts.app')

@section('title', 'Nouveau lead — LeadOps')

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="mb-8">
            <p class="text-sm font-bold uppercase tracking-widest text-blue-700">Réception manuelle</p>
            <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Créer un lead fictif</h1>
            <p class="mt-3 max-w-2xl text-base leading-7 text-slate-600">
                Utilisez uniquement des données inventées. Le traitement démarre en arrière-plan après réception.
            </p>
        </div>

        @if ($errors->any())
            <div role="alert" aria-labelledby="error-summary-title" class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 text-red-950">
                <h2 id="error-summary-title" class="font-bold">Le formulaire contient des erreurs.</h2>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    @foreach ($errors->messages() as $field => $messages)
                        @foreach ($messages as $error)
                            <li><a href="#{{ $field }}" class="font-semibold underline underline-offset-2">{{ $error }}</a></li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('leads.store') }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
            @csrf
            <input type="hidden" name="external_reference" value="{{ old('external_reference', $externalReference) }}">

            <p class="mb-6 text-sm text-slate-600"><span class="font-bold text-red-700">*</span> Champs obligatoires</p>

            <div class="grid gap-6 sm:grid-cols-2">
                <x-form-field name="first_name" label="Prénom" :required="true">
                    <input id="first_name" name="first_name" type="text" maxlength="100" required autocomplete="given-name"
                        value="{{ old('first_name') }}" @class(['form-control', 'form-control-error' => $errors->has('first_name')])
                        @if ($errors->has('first_name')) aria-invalid="true" aria-describedby="first_name-error" @endif>
                </x-form-field>

                <x-form-field name="last_name" label="Nom" :required="true">
                    <input id="last_name" name="last_name" type="text" maxlength="100" required autocomplete="family-name"
                        value="{{ old('last_name') }}" @class(['form-control', 'form-control-error' => $errors->has('last_name')])
                        @if ($errors->has('last_name')) aria-invalid="true" aria-describedby="last_name-error" @endif>
                </x-form-field>

                <x-form-field name="company" label="Entreprise">
                    <input id="company" name="company" type="text" maxlength="150" autocomplete="organization"
                        value="{{ old('company') }}" @class(['form-control', 'form-control-error' => $errors->has('company')])
                        @if ($errors->has('company')) aria-invalid="true" aria-describedby="company-error" @endif>
                </x-form-field>

                <x-form-field name="company_size" label="Taille de l’entreprise" help="Nombre entier positif.">
                    <input id="company_size" name="company_size" type="number" min="1" inputmode="numeric"
                        value="{{ old('company_size') }}" @class(['form-control', 'form-control-error' => $errors->has('company_size')])
                        @if ($errors->has('company_size')) aria-invalid="true" aria-describedby="company_size-help company_size-error" @else aria-describedby="company_size-help" @endif>
                </x-form-field>

                <x-form-field name="email" label="Adresse e-mail" help="Un e-mail valide ou un téléphone est requis.">
                    <input id="email" name="email" type="email" maxlength="254" autocomplete="email"
                        value="{{ old('email') }}" @class(['form-control', 'form-control-error' => $errors->has('email')])
                        @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-help email-error" @else aria-describedby="email-help" @endif>
                </x-form-field>

                <x-form-field name="phone" label="Téléphone" help="Un téléphone valide ou un e-mail est requis.">
                    <input id="phone" name="phone" type="tel" maxlength="50" autocomplete="tel"
                        value="{{ old('phone') }}" @class(['form-control', 'form-control-error' => $errors->has('phone')])
                        @if ($errors->has('phone')) aria-invalid="true" aria-describedby="phone-help phone-error" @else aria-describedby="phone-help" @endif>
                </x-form-field>

                <x-form-field name="country" label="Pays" help="Code pays sur deux lettres, par exemple FR.">
                    <input id="country" name="country" type="text" maxlength="2" autocomplete="country" autocapitalize="characters"
                        value="{{ old('country') }}" @class(['form-control uppercase', 'form-control-error' => $errors->has('country')])
                        @if ($errors->has('country')) aria-invalid="true" aria-describedby="country-help country-error" @else aria-describedby="country-help" @endif>
                </x-form-field>

                <x-form-field name="service_interest" label="Service recherché" :required="true">
                    <select id="service_interest" name="service_interest" required @class(['form-control', 'form-control-error' => $errors->has('service_interest')])
                        @if ($errors->has('service_interest')) aria-invalid="true" aria-describedby="service_interest-error" @endif>
                        <option value="">Sélectionner un service</option>
                        @foreach ($services as $service)
                            <option value="{{ $service->value }}" @selected(old('service_interest') === $service->value)>
                                {{ match ($service->value) {
                                    'automation' => 'Automatisation',
                                    'api' => 'API',
                                    'business_application' => 'Application métier',
                                    'dashboard' => 'Tableau de bord',
                                    'other' => 'Autre',
                                } }}
                            </option>
                        @endforeach
                    </select>
                </x-form-field>
            </div>

            <div class="mt-6">
                <x-form-field name="message" label="Message" help="5 000 caractères maximum.">
                    <textarea id="message" name="message" rows="6" maxlength="5000" @class(['form-control resize-y', 'form-control-error' => $errors->has('message')])
                        @if ($errors->has('message')) aria-invalid="true" aria-describedby="message-help message-error" @else aria-describedby="message-help" @endif>{{ old('message') }}</textarea>
                </x-form-field>
            </div>

            <div @class(['mt-6 rounded-xl border p-4', 'border-red-300 bg-red-50' => $errors->has('consent'), 'border-slate-200 bg-slate-50' => ! $errors->has('consent')])>
                <div class="flex items-start gap-3">
                    <input id="consent" name="consent" type="checkbox" value="1" required @checked(old('consent'))
                        class="mt-1 h-5 w-5 shrink-0 rounded border-slate-400 text-blue-700 focus:ring-blue-600"
                        @if ($errors->has('consent')) aria-invalid="true" aria-describedby="consent-error" @endif>
                    <label for="consent" class="text-sm font-semibold leading-6 text-slate-800">
                        Je confirme que ces données sont fictives et j’accepte leur traitement dans cette démonstration. <span class="text-red-700" aria-hidden="true">*</span>
                        <span class="sr-only">(obligatoire)</span>
                    </label>
                </div>
                @error('consent')
                    <p id="consent-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-8 flex justify-end">
                <button type="submit" class="w-full rounded-lg bg-blue-700 px-5 py-3 text-sm font-bold text-white shadow-sm hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 sm:w-auto">
                    Recevoir le lead
                </button>
            </div>
        </form>
    </div>
@endsection
