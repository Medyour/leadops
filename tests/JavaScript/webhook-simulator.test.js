import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import {
    formatExampleJson,
    makeExamplePayload,
    normalizeRequestError,
    normalizeWebhookResponse,
    performWebhookRequest,
    prepareWebhookRequest,
    startWebhookRequest,
    validateWebhookJson,
} from '../../resources/js/webhook-simulator.js';

const origin = 'http://leadops.test';
const endpoint = `${origin}/api/local/webhooks/leads`;
const validJson = formatExampleJson('webhook-example-test-001', '2026-08-13T10:15:30Z');

test('génère un payload valide, fictif et renouvelable', () => {
    const first = makeExamplePayload('webhook-example-unique-001', '2026-08-13T10:15:30Z');
    const second = makeExamplePayload('webhook-example-unique-002', '2026-08-13T10:16:30Z');

    assert.equal(validateWebhookJson(JSON.stringify(first)).valid, true);
    assert.equal(first.lead.email.endsWith('@example.test'), true);
    assert.equal(first.lead.phone, '+12025550142');
    assert.match(first.lead.received_at, /Z$/u);
    assert.notEqual(first.lead.external_reference, second.lead.external_reference);
});

test('refuse un JSON invalide avant toute préparation réseau', () => {
    const analysis = validateWebhookJson('{"lead":');

    assert.equal(analysis.valid, false);
    assert.equal(analysis.value, null);
    assert.deepEqual(analysis.errors, ['Le contenu doit être un document JSON valide.']);
});

test('signale une enveloppe ou des champs généraux incorrects', () => {
    const unexpectedEnvelope = validateWebhookJson(JSON.stringify({ lead: {}, metadata: {} }));
    assert.ok(unexpectedEnvelope.errors.includes('envelope'));

    const wrongFields = validateWebhookJson(JSON.stringify({
        lead: {
            external_reference: ' espaces ',
            first_name: 'Exemple',
            last_name: 'Fictif',
            service_interest: 'Automation API',
            consent: false,
            secret: '<script>alert(1)</script>',
        },
    }));

    for (const path of ['lead.external_reference', 'lead.service_interest', 'lead.consent', 'lead.secret', 'lead.email', 'lead.phone']) {
        assert.ok(wrongFields.errors.includes(path));
    }
});

test('prépare uniquement un POST JSON stateless vers le webhook local exact', () => {
    const signal = new AbortController().signal;
    const request = prepareWebhookRequest(endpoint, validJson, origin, signal);

    assert.equal(request.url, endpoint);
    assert.deepEqual(request.options.headers, {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    });
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.credentials, 'omit');
    assert.equal(request.options.redirect, 'error');
    assert.equal(request.options.mode, 'same-origin');
    assert.equal(request.options.body, validJson);
    assert.equal('csrf' in request.options, false);

    for (const forbidden of [
        'https://external.example/api/local/webhooks/leads',
        `${origin}/api/local/webhooks/leads?next=https://external.example`,
        `${origin}/api/other`,
    ]) {
        assert.throws(() => prepareWebhookRequest(forbidden, validJson, origin, signal), /refusée/u);
    }
});

test('normalise strictement les réponses 201, 200, 422, 429, 404 et techniques', () => {
    assert.deepEqual(
        normalizeWebhookResponse(201, { result: 'created', lead: { id: 7, status: 'RECEIVED', email: 'secret@example.test' }, trace: 'private' }),
        {
            kind: 'created',
            statusCode: 201,
            title: 'Lead créé',
            message: 'Laravel a accepté le payload et créé la réception.',
            leadId: 7,
            leadStatus: 'RECEIVED',
        },
    );

    assert.equal(normalizeWebhookResponse(200, { result: 'already_received', lead: { id: 7, status: 'RECEIVED' } }).kind, 'already_received');

    const invalid = normalizeWebhookResponse(422, {
        result: 'invalid',
        errors: ['lead.email', '<img src=x onerror=alert(1)>', 'trace.private', 'lead.email'],
        exception: '<script>private</script>',
    });
    assert.deepEqual(invalid.fields, ['lead.email']);
    assert.equal(JSON.stringify(invalid).includes('<script>'), false);
    assert.equal(normalizeWebhookResponse(429, { result: 'rate_limited', headers: 'secret' }).kind, 'rate_limited');
    assert.equal(normalizeWebhookResponse(404, '<html>arbitrary</html>').kind, 'unavailable');
    assert.equal(normalizeWebhookResponse(500, { result: 'unavailable', trace: 'secret' }).kind, 'unavailable');
    assert.equal(normalizeWebhookResponse(201, { result: 'unexpected', response: 'arbitrary' }).kind, 'technical_error');
});

test('filtre aussi la réponse réellement lue avant de la retourner', async () => {
    const request = prepareWebhookRequest(endpoint, validJson, origin, new AbortController().signal);
    const result = await performWebhookRequest(request, async () => ({
        status: 201,
        async json() {
            return {
                result: 'created',
                lead: { id: 12, status: 'ROUTED', message: '<script>alert(1)</script>' },
                debug: { exception: 'private' },
            };
        },
    }));

    assert.deepEqual(Object.keys(result).sort(), ['kind', 'leadId', 'leadStatus', 'message', 'statusCode', 'title']);
    assert.equal(JSON.stringify(result).includes('<script>'), false);
});

test('gère le délai dépassé et l’annulation avec AbortController', async () => {
    const abortingFetch = (_url, options) => new Promise((_resolve, reject) => {
        const rejectAbort = () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
        if (options.signal.aborted) {
            rejectAbort();
        } else {
            options.signal.addEventListener('abort', rejectAbort, { once: true });
        }
    });

    const timedOut = startWebhookRequest({
        endpoint,
        rawJson: validJson,
        origin,
        fetchImplementation: abortingFetch,
        setTimeoutImplementation(callback) {
            callback();
            return 1;
        },
        clearTimeoutImplementation() {},
    });
    assert.equal((await timedOut.promise).kind, 'timeout');

    const cancelled = startWebhookRequest({
        endpoint,
        rawJson: validJson,
        origin,
        fetchImplementation: abortingFetch,
    });
    cancelled.cancel();
    assert.equal((await cancelled.promise).kind, 'cancelled');
    assert.equal(normalizeRequestError(new Error('network')).kind, 'technical_error');
});

test('le composant bloque les envois concurrents et n’envoie rien au montage', async () => {
    const component = await readFile(
        new URL('../../resources/js/components/WebhookSimulator.vue', import.meta.url),
        'utf8',
    );

    assert.match(component, /async function send\(\) \{\s+if \(sending\.value\) \{\s+return;/u);
    assert.match(component, /:disabled="sending"/u);
    assert.match(component, /@click="send"/u);
    assert.equal(component.includes('onMounted'), false);
    assert.equal(component.includes('send();'), false);
    assert.match(component, /currentRequest\.cancel\(\)/u);
});

test('le simulateur n’utilise ni rendu HTML, stockage, cookie ou console', async () => {
    const sources = await Promise.all([
        readFile(new URL('../../resources/js/app.js', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/js/components/WebhookSimulator.vue', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/js/webhook-simulator.js', import.meta.url), 'utf8'),
    ]);
    const source = sources.join('\n');

    for (const forbidden of [
        'v-html',
        'localStorage',
        'sessionStorage',
        'indexedDB',
        'document.cookie',
        'console.',
        'XMLHttpRequest',
        'sendBeacon',
    ]) {
        assert.equal(source.includes(forbidden), false, `${forbidden} ne doit pas être utilisé`);
    }
});
