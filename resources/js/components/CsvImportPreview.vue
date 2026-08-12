<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { analyzeCsvBytes, canReadCsvFile, clearFileSelection } from '../csv-import-parser.js';

const props = defineProps({
    inputId: {
        type: String,
        required: true,
    },
    header: {
        type: Array,
        required: true,
    },
    maxBytes: {
        type: Number,
        required: true,
    },
    maxRows: {
        type: Number,
        required: true,
    },
});

const file = ref(null);
const analysis = ref(null);
const reading = ref(false);
const readSequence = ref(0);
let input;

const formattedSize = computed(() => {
    if (!file.value) {
        return '';
    }

    if (file.value.size < 1024) {
        return `${file.value.size} octets`;
    }

    return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 }).format(file.value.size / 1024)} Kio`;
});

const liveStatus = computed(() => {
    if (reading.value) {
        return 'Lecture locale du fichier en cours.';
    }

    if (!file.value || !analysis.value) {
        return 'Aucun fichier sélectionné pour la prévisualisation.';
    }

    const warningCount = analysis.value.warnings.length;
    const rowLabel = analysis.value.rowCount === 1 ? 'ligne détectée' : 'lignes détectées';
    return `${file.value.name}, ${analysis.value.rowCount} ${rowLabel}, ${warningCount} avertissement${warningCount > 1 ? 's' : ''}.`;
});

async function handleSelection(event) {
    const selectedFile = event.target.files?.[0] ?? null;
    const sequence = readSequence.value + 1;
    readSequence.value = sequence;
    file.value = selectedFile;
    analysis.value = null;

    if (!selectedFile) {
        reading.value = false;
        return;
    }

    reading.value = true;

    if (!canReadCsvFile(selectedFile.size, props.maxBytes)) {
        analysis.value = {
            headerValid: false,
            headerMessage: 'L’en-tête n’a pas été lu.',
            rowCount: 0,
            preview: [],
            syntaxValid: false,
            warnings: ['Le fichier dépasse la limite de 2 Mio. Son contenu n’a pas été lu dans le navigateur.'],
        };
        reading.value = false;
        return;
    }

    try {
        const buffer = await selectedFile.arrayBuffer();
        if (sequence === readSequence.value) {
            analysis.value = analyzeCsvBytes(buffer, {
                header: props.header,
                maxBytes: props.maxBytes,
                maxRows: props.maxRows,
                sizeBytes: selectedFile.size,
            });
        }
    } catch {
        if (sequence === readSequence.value) {
            analysis.value = {
                headerValid: false,
                headerMessage: 'L’en-tête ne peut pas être vérifié.',
                rowCount: 0,
                preview: [],
                syntaxValid: false,
                warnings: ['Le navigateur n’a pas pu lire ce fichier localement.'],
            };
        }
    } finally {
        if (sequence === readSequence.value) {
            reading.value = false;
        }
    }
}

function clearSelection() {
    readSequence.value += 1;
    file.value = null;
    analysis.value = null;
    reading.value = false;
    clearFileSelection(input);
}

onMounted(() => {
    input = document.getElementById(props.inputId);
    input?.addEventListener('change', handleSelection);
});

onBeforeUnmount(() => {
    input?.removeEventListener('change', handleSelection);
});
</script>

<template>
    <section aria-labelledby="csv-preview-title" class="min-w-0 rounded-2xl border border-blue-200 bg-blue-50 p-4 sm:p-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 id="csv-preview-title" class="text-base font-black text-slate-950">Prévisualisation locale</h2>
                <p class="mt-1 text-sm leading-6 text-slate-700">
                    Cette vérification est informative. Laravel contrôlera intégralement le fichier lors de l’import.
                </p>
            </div>
            <button
                v-if="file"
                type="button"
                class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-800 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2"
                @click="clearSelection"
            >
                Effacer la sélection
            </button>
        </div>

        <p class="sr-only" aria-live="polite" aria-atomic="true">{{ liveStatus }}</p>

        <p v-if="reading" role="status" class="mt-4 text-sm font-semibold text-blue-900">Lecture locale en cours…</p>

        <div v-else-if="file && analysis" class="mt-5 space-y-5">
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div class="min-w-0 rounded-xl border border-blue-200 bg-white p-3">
                    <dt class="font-bold text-slate-600">Fichier</dt>
                    <dd class="mt-1 break-words font-semibold text-slate-950">{{ file.name }}</dd>
                    <dd class="mt-1 text-slate-600">{{ formattedSize }}</dd>
                </div>
                <div class="rounded-xl border border-blue-200 bg-white p-3">
                    <dt class="font-bold text-slate-600">En-tête</dt>
                    <dd class="mt-1 font-semibold" :class="analysis.headerValid ? 'text-emerald-800' : 'text-amber-900'">
                        {{ analysis.headerMessage }}
                    </dd>
                </div>
                <div class="rounded-xl border border-blue-200 bg-white p-3">
                    <dt class="font-bold text-slate-600">Lignes détectées</dt>
                    <dd class="mt-1 text-2xl font-black text-slate-950">{{ analysis.rowCount }}</dd>
                </div>
            </dl>

            <div v-if="analysis.warnings.length" role="alert" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950">
                <h3 class="font-bold">Points à vérifier avant l’import</h3>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm leading-6">
                    <li v-for="warning in analysis.warnings" :key="warning">{{ warning }}</li>
                </ul>
            </div>

            <div v-if="analysis.preview.length" class="overflow-hidden rounded-xl border border-slate-300 bg-white">
                <div class="overflow-x-auto" tabindex="0" aria-label="Aperçu du fichier CSV, défilement horizontal possible">
                    <table class="min-w-max divide-y divide-slate-200 text-left text-xs">
                        <caption class="caption-top px-4 py-3 text-left text-sm font-bold text-slate-950">
                            10 premières lignes au maximum — e-mail, téléphone et message sont masqués ou tronqués
                        </caption>
                        <thead class="bg-slate-100 text-slate-700">
                            <tr>
                                <th v-for="column in header" :key="column" scope="col" class="whitespace-nowrap px-3 py-3 font-bold">
                                    {{ column }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr v-for="(row, rowIndex) in analysis.preview" :key="rowIndex">
                                <td v-for="(value, columnIndex) in row" :key="columnIndex" class="max-w-64 whitespace-pre-wrap break-words px-3 py-3 align-top text-slate-700">
                                    {{ value || '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</template>
