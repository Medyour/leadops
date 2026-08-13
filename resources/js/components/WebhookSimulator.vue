<script setup>
import { nextTick, onBeforeUnmount, ref } from 'vue';
import {
    createWebhookRequestCoordinator,
    formatExampleJson,
    normalizeRequestError,
    validateWebhookJson,
} from '../webhook-simulator.js';

const props = defineProps({
    endpoint: {
        type: String,
        required: true,
    },
});

const requestTimeoutMilliseconds = 10000;
let exampleSequence = 0;

const initialJson = createExampleJson();
const editor = ref(initialJson);
const editorErrors = ref([]);
const sending = ref(false);
const result = ref(null);
const editorElement = ref(null);
const resultElement = ref(null);
const requestCoordinator = createWebhookRequestCoordinator({
    endpoint: props.endpoint,
    origin: globalThis.location.origin,
    timeoutMilliseconds: requestTimeoutMilliseconds,
    onSendingChange(value) {
        sending.value = value;
    },
    onResult(normalized) {
        result.value = normalized;
        focusResult();
    },
});

function createExampleJson() {
    exampleSequence += 1;
    const now = new Date();
    const uniquePart = globalThis.crypto?.randomUUID?.()
        ?? `${now.getTime()}-${exampleSequence}`;

    return formatExampleJson(
        `webhook-example-${uniquePart}`,
        now.toISOString().replace(/\.\d{3}Z$/u, 'Z'),
    );
}

function loadNewExample() {
    const cancelled = requestCoordinator.cancel();
    editor.value = createExampleJson();
    clearFeedback(cancelled);
    focusEditor();
}

function resetEditor() {
    const cancelled = requestCoordinator.cancel();
    editor.value = initialJson;
    clearFeedback(cancelled);
    focusEditor();
}

async function send() {
    if (sending.value) {
        return;
    }

    const validation = validateWebhookJson(editor.value);
    editorErrors.value = validation.errors;
    result.value = null;

    if (!validation.valid) {
        await focusEditor();
        return;
    }

    requestCoordinator.send(editor.value);
}

function clearFeedback(cancelled = false) {
    editorErrors.value = [];
    result.value = cancelled ? normalizeRequestError(null, 'cancelled') : null;
}

async function focusEditor() {
    await nextTick();
    editorElement.value?.focus();
}

async function focusResult() {
    await nextTick();
    resultElement.value?.focus();
}

onBeforeUnmount(() => {
    requestCoordinator.dispose();
});
</script>

<template>
    <div class="grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1.25fr)_minmax(18rem,0.75fr)]">
        <section aria-labelledby="webhook-editor-title" :aria-busy="sending" class="min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 id="webhook-editor-title" class="text-xl font-black text-slate-950">Payload JSON fictif</h2>
                    <p id="webhook-editor-help" class="mt-2 text-sm leading-6 text-slate-600">
                        L’enveloppe doit contenir uniquement l’objet <code class="rounded bg-slate-100 px-1 py-0.5 font-mono text-xs">lead</code>. La vérification locale porte sur la syntaxe et la structure générale seulement.
                    </p>
                </div>
                <span class="inline-flex w-fit shrink-0 rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-900">Données fictives</span>
            </div>

            <label for="webhook-payload" class="mt-6 block text-sm font-bold text-slate-950">Contenu JSON à envoyer</label>
            <textarea
                id="webhook-payload"
                ref="editorElement"
                v-model="editor"
                rows="21"
                spellcheck="false"
                autocapitalize="off"
                autocomplete="off"
                aria-describedby="webhook-editor-help webhook-validation-authority webhook-editor-errors"
                :aria-invalid="editorErrors.length > 0 ? 'true' : undefined"
                :disabled="sending"
                class="mt-2 block w-full min-w-0 resize-y rounded-xl border bg-slate-950 p-4 font-mono text-xs leading-6 text-slate-100 shadow-inner outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-500 disabled:cursor-wait disabled:opacity-75 sm:text-sm"
            ></textarea>

            <p id="webhook-validation-authority" class="mt-3 text-sm leading-6 text-slate-600">
                Laravel reste l’unique autorité de validation et peut refuser un contenu accepté par cette prévalidation.
            </p>

            <div v-if="editorErrors.length" id="webhook-editor-errors" role="alert" class="mt-4 rounded-xl border border-red-300 bg-red-50 p-4 text-red-950">
                <p class="font-bold">Corrigez le JSON avant l’envoi.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    <li v-for="error in editorErrors" :key="error">{{ error }}</li>
                </ul>
            </div>
            <span v-else id="webhook-editor-errors" class="sr-only">Aucune erreur locale détectée.</span>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                <button type="button" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-800 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2" @click="loadNewExample">
                    Nouvel exemple fictif
                </button>
                <button type="button" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-800 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2" @click="resetEditor">
                    Réinitialiser
                </button>
                <button type="button" :disabled="sending" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-blue-700 px-5 py-2 text-sm font-bold text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 disabled:cursor-wait disabled:bg-blue-400 sm:ml-auto" @click="send">
                    {{ sending ? 'Envoi en cours…' : 'Envoyer' }}
                </button>
            </div>

            <p class="sr-only" role="status" aria-live="polite" aria-atomic="true">
                {{ sending ? 'Envoi du payload au webhook local en cours.' : 'Aucun envoi en cours.' }}
            </p>
        </section>

        <aside class="min-w-0 space-y-5">
            <section aria-labelledby="webhook-destination-title" class="rounded-2xl border border-slate-200 bg-slate-100 p-5 sm:p-6">
                <h2 id="webhook-destination-title" class="text-lg font-black text-slate-950">Destination verrouillée</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">Fournie par Laravel, limitée à l’origine courante et non modifiable.</p>
                <code class="mt-4 block overflow-x-auto break-all rounded-xl bg-slate-950 p-3 text-xs leading-5 text-slate-100">{{ endpoint }}</code>
                <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm">
                    <dt class="font-bold text-slate-700">Méthode</dt><dd class="font-mono text-slate-950">POST</dd>
                    <dt class="font-bold text-slate-700">Cookies</dt><dd class="text-slate-950">Aucun</dd>
                    <dt class="font-bold text-slate-700">Délai</dt><dd class="text-slate-950">10 secondes</dd>
                </dl>
            </section>

            <section
                ref="resultElement"
                tabindex="-1"
                aria-labelledby="webhook-result-title"
                aria-live="polite"
                aria-atomic="true"
                :aria-busy="sending"
                class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm outline-none focus:ring-2 focus:ring-blue-600 sm:p-6"
            >
                <h2 id="webhook-result-title" class="text-lg font-black text-slate-950">Résultat assaini</h2>

                <p v-if="sending" class="mt-4 text-sm font-semibold text-blue-800">Requête en cours…</p>
                <p v-else-if="!result" class="mt-3 text-sm leading-6 text-slate-600">Aucun payload envoyé. Le simulateur n’envoie rien automatiquement.</p>
                <div v-else class="mt-4">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-500">
                        {{ result.statusCode ? `HTTP ${result.statusCode}` : 'Requête interrompue' }}
                    </p>
                    <h3 class="mt-1 text-base font-black text-slate-950">{{ result.title }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-700">{{ result.message }}</p>

                    <dl v-if="result.leadId || result.leadStatus" class="mt-4 grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 border-t border-slate-200 pt-4 text-sm">
                        <template v-if="result.leadId">
                            <dt class="font-bold text-slate-600">Identifiant</dt><dd>{{ result.leadId }}</dd>
                        </template>
                        <template v-if="result.leadStatus">
                            <dt class="font-bold text-slate-600">Statut</dt><dd>{{ result.leadStatus }}</dd>
                        </template>
                    </dl>

                    <div v-if="result.kind === 'invalid'" class="mt-4 border-t border-slate-200 pt-4">
                        <p class="text-sm font-bold text-slate-800">Chemins de champs signalés</p>
                        <ul v-if="result.fields.length" class="mt-2 list-disc space-y-1 pl-5 font-mono text-xs text-slate-700">
                            <li v-for="field in result.fields" :key="field">{{ field }}</li>
                        </ul>
                        <p v-else class="mt-2 text-sm text-slate-600">Aucun chemin de champ autorisé n’a été fourni.</p>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</template>
