@if ($leads->isEmpty())
    <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
        <h2 class="text-lg font-black text-slate-950">{{ $emptyTitle }}</h2>
        <p class="mt-2 text-sm text-slate-600">{{ $emptyMessage }}</p>
    </div>
@else
    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full table-fixed divide-y divide-slate-200 text-left text-sm sm:min-w-[56rem] sm:table-auto">
            <caption class="sr-only">{{ $caption }}</caption>
            <thead class="bg-slate-100 text-xs font-bold uppercase tracking-wide text-slate-600">
                <tr>
                    <th scope="col" class="px-4 py-3">Lead</th>
                    <th scope="col" class="w-32 px-4 py-3 sm:w-auto">Statut</th>
                    <th scope="col" class="hidden px-4 py-3 sm:table-cell">Classification</th>
                    <th scope="col" class="hidden px-4 py-3 sm:table-cell">Source</th>
                    <th scope="col" class="hidden px-4 py-3 sm:table-cell">Assignation</th>
                    <th scope="col" class="hidden px-4 py-3 sm:table-cell">Reçu le</th>
                    <th scope="col" class="hidden px-4 py-3 sm:table-cell"><span class="sr-only">Action</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($leads as $lead)
                    <tr class="align-top hover:bg-slate-50">
                        <th scope="row" class="max-w-64 px-4 py-4 font-semibold text-slate-950">
                            <a href="{{ route('leads.show', $lead) }}" class="break-words hover:text-blue-700 hover:underline focus:outline-none focus:ring-2 focus:ring-blue-600">{{ $lead->first_name }} {{ $lead->last_name }}</a>
                            <span class="mt-1 block break-words text-xs font-normal text-slate-500">{{ $lead->company ?: 'Entreprise non renseignée' }} · {{ $lead->external_reference }}</span>
                        </th>
                        <td class="whitespace-nowrap px-4 py-4"><x-status-badge :status="$lead->status" /></td>
                        <td class="hidden whitespace-nowrap px-4 py-4 sm:table-cell"><x-classification-badge :classification="$lead->classification" /></td>
                        <td class="hidden whitespace-nowrap px-4 py-4 text-slate-700 sm:table-cell">{{ ucfirst($lead->source->value) }}</td>
                        <td class="hidden whitespace-nowrap px-4 py-4 text-slate-700 sm:table-cell">{{ $lead->assigned_to ?: 'Non assigné' }}</td>
                        <td class="hidden whitespace-nowrap px-4 py-4 text-slate-700 sm:table-cell">{{ $lead->received_at->format('d/m/Y H:i') }}</td>
                        <td class="hidden whitespace-nowrap px-4 py-4 text-right sm:table-cell"><a href="{{ route('leads.show', $lead) }}" class="font-bold text-blue-700 hover:underline focus:outline-none focus:ring-2 focus:ring-blue-600">Voir le détail</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $leads->links() }}</div>
@endif
