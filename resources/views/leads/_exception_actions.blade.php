@if ($errors->has('action'))
    <div id="exception-action" role="alert" tabindex="-1" autofocus class="mt-6 rounded-xl border border-red-300 bg-red-50 p-4 text-sm font-semibold text-red-950">
        {{ $errors->first('action') }}
    </div>
@endif

@if (app()->environment(['local', 'testing']) && $lead->status === \App\Domain\LeadProcessing\Enums\LeadStatus::NeedsReview)
    <section aria-labelledby="review-title" class="mt-8 rounded-2xl border border-amber-300 bg-amber-50 p-5 shadow-sm sm:p-6">
        <div class="max-w-3xl">
            <p class="text-sm font-bold uppercase tracking-widest text-amber-800">Intervention humaine</p>
            <h2 id="review-title" class="mt-2 text-2xl font-black text-slate-950">Corriger les informations à examiner</h2>
            <p class="mt-2 leading-7 text-slate-700">
                Enregistrez d’abord les corrections utiles. Le lead restera à examiner et aucun traitement ne démarrera tant que vous n’utiliserez pas l’action de retraitement séparée.
            </p>
        </div>

        @if ($errors->any() && ! $errors->has('action'))
            <div role="alert" aria-labelledby="review-errors-title" tabindex="-1" autofocus class="mt-6 rounded-xl border border-red-300 bg-red-50 p-4 text-red-950">
                <h3 id="review-errors-title" class="font-bold">Le formulaire contient des erreurs.</h3>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    @foreach ($errors->messages() as $field => $messages)
                        @foreach ($messages as $error)
                            <li><a href="#{{ $field }}" class="font-semibold underline underline-offset-2">{{ $error }}</a></li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('leads.review.update', $lead) }}" class="mt-6 rounded-xl border border-amber-200 bg-white p-4 sm:p-6">
            @csrf
            @method('PATCH')

            <div class="grid gap-6 sm:grid-cols-2">
                @foreach ([
                    ['first_name', 'Prénom', 'text', 100, true],
                    ['last_name', 'Nom', 'text', 100, true],
                    ['company', 'Entreprise', 'text', 150, false],
                    ['email', 'Adresse e-mail', 'email', 254, false],
                    ['phone', 'Téléphone', 'tel', 50, false],
                    ['country', 'Pays (code à deux lettres)', 'text', 2, false],
                    ['service_interest', 'Service recherché', 'text', 50, true],
                ] as [$name, $label, $type, $maxlength, $required])
                    <x-form-field :name="$name" :label="$label" :required="$required">
                        <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" maxlength="{{ $maxlength }}" @required($required)
                            value="{{ old($name, $lead->{$name}) }}" @class(['form-control', 'form-control-error' => $errors->has($name)])
                            @if ($errors->has($name)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>
                    </x-form-field>
                @endforeach

                <x-form-field name="company_size" label="Taille de l’entreprise" help="Nombre entier positif.">
                    <input id="company_size" name="company_size" type="number" min="1" inputmode="numeric"
                        value="{{ old('company_size', $lead->company_size) }}" @class(['form-control', 'form-control-error' => $errors->has('company_size')])
                        @if ($errors->has('company_size')) aria-invalid="true" aria-describedby="company_size-help company_size-error" @else aria-describedby="company_size-help" @endif>
                </x-form-field>
            </div>

            <div class="mt-6">
                <x-form-field name="message" label="Message" help="5 000 caractères maximum.">
                    <textarea id="message" name="message" rows="5" maxlength="5000" @class(['form-control resize-y', 'form-control-error' => $errors->has('message')])
                        @if ($errors->has('message')) aria-invalid="true" aria-describedby="message-help message-error" @else aria-describedby="message-help" @endif>{{ old('message', $lead->message) }}</textarea>
                </x-form-field>
            </div>

            <div @class(['mt-6 rounded-xl border p-4', 'border-red-300 bg-red-50' => $errors->has('consent'), 'border-slate-200 bg-slate-50' => ! $errors->has('consent')])>
                <input type="hidden" name="consent" value="0">
                <div class="flex items-start gap-3">
                    <input id="consent" name="consent" type="checkbox" value="1" @checked(old('consent', $lead->consent))
                        class="mt-1 h-5 w-5 shrink-0 rounded border-slate-400 text-blue-700 focus:ring-blue-600"
                        @if ($errors->has('consent')) aria-invalid="true" aria-describedby="consent-error" @endif>
                    <label for="consent" class="text-sm font-semibold leading-6 text-slate-800">Consentement au traitement des données fictives</label>
                </div>
                @error('consent')
                    <p id="consent-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-6 flex justify-end">
                <button type="submit" class="w-full rounded-lg bg-amber-700 px-5 py-3 text-sm font-bold text-white hover:bg-amber-800 focus:outline-none focus:ring-2 focus:ring-amber-600 focus:ring-offset-2 sm:w-auto">
                    Enregistrer les corrections
                </button>
            </div>
        </form>

        <div class="mt-6 rounded-xl border border-blue-200 bg-white p-4 sm:flex sm:items-center sm:justify-between sm:gap-6 sm:p-6">
            <div>
                <h3 class="font-black text-slate-950">Relancer le pipeline complet</h3>
                <p class="mt-1 text-sm leading-6 text-slate-700">Cette action ouvre un nouveau cycle, efface les anciens résultats dérivés et recalcule validation, doublons, score, classification et routage.</p>
            </div>
            <form method="POST" action="{{ route('leads.reprocess', $lead) }}" class="mt-4 shrink-0 sm:mt-0">
                @csrf
                <button type="submit" class="w-full rounded-lg bg-blue-700 px-5 py-3 text-sm font-bold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 sm:w-auto">
                    Retraiter le lead
                </button>
            </form>
        </div>
    </section>
@endif

@if (app()->environment(['local', 'testing']) && $lead->status === \App\Domain\LeadProcessing\Enums\LeadStatus::Failed)
    <section aria-labelledby="retry-title" class="mt-8 rounded-2xl border border-red-200 bg-red-50 p-5 shadow-sm sm:flex sm:items-center sm:justify-between sm:gap-8 sm:p-6">
        <div>
            <p class="text-sm font-bold uppercase tracking-widest text-red-800">Échec technique</p>
            <h2 id="retry-title" class="mt-2 text-xl font-black text-slate-950">Ouvrir un nouveau cycle de traitement</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-700">La reprise remet le compteur à zéro et autorise à nouveau une tentative initiale et deux reprises automatiques. L’historique actuel sera conservé.</p>
        </div>
        <form method="POST" action="{{ route('leads.retry', $lead) }}" class="mt-5 shrink-0 sm:mt-0">
            @csrf
            <button type="submit" class="w-full rounded-lg bg-red-700 px-5 py-3 text-sm font-bold text-white hover:bg-red-800 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-offset-2 sm:w-auto">
                Relancer le traitement
            </button>
        </form>
    </section>
@endif
