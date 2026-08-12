<form method="GET" action="{{ $action }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="sm:col-span-2 lg:col-span-1">
            <label for="search" class="block text-sm font-bold text-slate-700">Recherche</label>
            <input id="search" name="search" type="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="Référence, nom, entreprise ou e-mail" class="form-control mt-1">
        </div>
        <div>
            <label for="status" class="block text-sm font-bold text-slate-700">Statut</label>
            <select id="status" name="status" class="form-control mt-1">
                <option value="">Tous les statuts</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ str_replace('_', ' ', $status->value) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="classification" class="block text-sm font-bold text-slate-700">Classification</label>
            <select id="classification" name="classification" class="form-control mt-1">
                <option value="">Toutes les classifications</option>
                @foreach ($classifications as $classification)
                    <option value="{{ $classification->value }}" @selected(($filters['classification'] ?? '') === $classification->value)>{{ $classification->value }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="source" class="block text-sm font-bold text-slate-700">Source</label>
            <select id="source" name="source" class="form-control mt-1">
                <option value="">Toutes les sources</option>
                @foreach ($sources as $source)
                    <option value="{{ $source->value }}" @selected(($filters['source'] ?? '') === $source->value)>{{ ucfirst($source->value) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="assigned_to" class="block text-sm font-bold text-slate-700">Assigné à</label>
            <select id="assigned_to" name="assigned_to" class="form-control mt-1">
                <option value="">Toutes les assignations</option>
                @foreach ($assignees as $assignee)
                    <option value="{{ $assignee }}" @selected(($filters['assigned_to'] ?? '') === $assignee)>{{ $assignee }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="mt-5 flex flex-wrap gap-3">
        <button type="submit" class="rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2">Filtrer</button>
        <a href="{{ $action }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2">Réinitialiser</a>
    </div>
</form>
