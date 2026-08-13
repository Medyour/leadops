import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import {
    createWebhookRequestCoordinator,
    formatExampleJson,
    makeExamplePayload,
    normalizeRequestError,
    normalizeWebhookResponse,
    performWebhookRequest,
    prepareWebhookRequest,
    startWebhookRequest,
    validateWebhookJson,
    WEBHOOK_RESPONSE_MAX_BYTES,
} from '../../resources/js/webhook-simulator.js';

const origin = 'http://leadops.test';
const endpoint = '/api/local/webhooks/leads';
const validJson = formatExampleJson('webhook-example-test-001', '2026-08-13T10:15:30Z');

test('génère un payload valide, fictif et renouvelable', () => {
    const first = makeExamplePayload('webhook-example-unique-001', '2026-08-13T10:15:30Z');
    const second = makeExamplePayload('webhook-example-unique-002', '2026-08-13T10:16:30Z');

    assert.equal(validateWebhookJson(JSON.stringify(first)).valid, true);
    assert.equal(first.lead.email.endsWith('@example.test'), true);
    assert.equal(first.lead.phone, '+12025550142');
    assert.match(first.lead.phone, /^\+120255501(?:0[0-9]|[1-9][0-9])$/u);
    assert.match(first.lead.received_at, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/u);
    assert.notEqual(first.lead.external_reference, second.lead.external_reference);

    const serialized = JSON.stringify(first);
    assert.equal(serialized.includes('example.test'), true);
    assert.equal(serialized.includes('gmail.com'), false);
});

test('refuse un JSON invalide sans démarrer de requête', () => {
    const analysis = validateWebhookJson('{"lead":');
    let starts = 0;
    const coordinator = createWebhookRequestCoordinator({
        endpoint,
        origin,
        startRequestImplementation() {
            starts += 1;
        },
    });

    assert.equal(analysis.valid, false);
    assert.equal(analysis.value, null);
    assert.deepEqual(analysis.errors, ['Le contenu doit être un document JSON valide.']);
    assert.equal(starts, 0);
});

test('refuse localement les primitives, tableaux et enveloppes incorrectes', () => {
    for (const rawJson of ['null', '[]', '"lead"', '42', 'true', '{"lead":[]}']) {
        const analysis = validateWebhookJson(rawJson);
        assert.equal(analysis.valid, false, rawJson);
        assert.ok(analysis.errors.includes('envelope') || analysis.errors.includes('lead'));
    }

    const unexpectedEnvelope = validateWebhookJson(JSON.stringify({ lead: {}, metadata: {} }));
    assert.ok(unexpectedEnvelope.errors.includes('envelope'));

    const wrongFields = validateWebhookJson(JSON.stringify({
        lead: {
            external_reference: ['wrong type'],
            first_name: 'Exemple',
            last_name: 'Fictif',
            email: { value: 'wrong type' },
            service_interest: 42,
            company_size: '12',
            consent: 1,
            secret: '<script>alert(1)</script>',
        },
    }));

    for (const path of ['lead.external_reference', 'lead.service_interest', 'lead.company_size', 'lead.consent', 'lead.secret', 'lead.email']) {
        assert.ok(wrongFields.errors.includes(path));
    }
});

test('laisse Laravel décider seul des règles métier', () => {
    const structurallyValidButBusinessInvalid = makeExamplePayload(
        'référence que Laravel refusera',
        'date non UTC',
    );
    structurallyValidButBusinessInvalid.lead.email = 'adresse invalide';
    structurallyValidButBusinessInvalid.lead.phone = null;
    structurallyValidButBusinessInvalid.lead.company_size = -1;
    structurallyValidButBusinessInvalid.lead.consent = false;

    assert.equal(validateWebhookJson(JSON.stringify(structurallyValidButBusinessInvalid)).valid, true);
});

test('prépare uniquement un POST JSON stateless vers le chemin relatif exact', () => {
    const signal = new AbortController().signal;
    const request = prepareWebhookRequest(endpoint, validJson, origin, signal);

    assert.equal(request.url, endpoint);
    assert.equal(request.expectedUrl, `${origin}${endpoint}`);
    assert.deepEqual(request.options.headers, {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    });
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.credentials, 'omit');
    assert.equal(request.options.redirect, 'error');
    assert.equal(request.options.mode, 'same-origin');
    assert.equal(request.options.cache, 'no-store');
    assert.equal(request.options.referrerPolicy, 'no-referrer');
    assert.equal(request.options.body, validJson);
    assert.deepEqual(Object.keys(request.options.headers).sort(), ['Accept', 'Content-Type']);
    assert.equal('csrf' in request.options, false);
});

test('rejette toute URL externe, absolue, modifiée ou avec un autre schéma', () => {
    const signal = new AbortController().signal;

    for (const forbidden of [
        `${origin}${endpoint}`,
        `https://leadops.test${endpoint}`,
        `https://external.example${endpoint}`,
        `//external.example${endpoint}`,
        `javascript:${endpoint}`,
        `${endpoint}?next=https://external.example`,
        `${endpoint}#fragment`,
        '/api/local/webhooks/other',
        '/%61pi/local/webhooks/leads',
    ]) {
        assert.throws(() => prepareWebhookRequest(forbidden, validJson, origin, signal), /refusée/u, forbidden);
    }

    for (const forbiddenOrigin of ['file:///tmp/index.html', 'https://user:pass@leadops.test', 'not a URL']) {
        assert.throws(() => prepareWebhookRequest(endpoint, validJson, forbiddenOrigin, signal), /refusée/u);
    }
});

test('refuse les redirections dans la requête et dans la réponse', async () => {
    const request = prepareWebhookRequest(endpoint, validJson, origin, new AbortController().signal);
    assert.equal(request.options.redirect, 'error');

    const result = await performWebhookRequest(request, async () => ({
        status: 302,
        redirected: true,
        url: 'https://external.example/redirected',
    }));

    assert.equal(result.kind, 'technical_error');
    assert.equal(JSON.stringify(result).includes('external.example'), false);
});

test('normalise strictement les réponses 201, 200, 422, 429, 404 et indisponibles', () => {
    assert.deepEqual(
        normalizeWebhookResponse(201, { result: 'created', lead: { id: 7, status: 'RECEIVED' } }),
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
    });
    assert.deepEqual(invalid.fields, ['lead.email']);
    assert.equal(JSON.stringify(invalid).includes('<img'), false);
    assert.equal(normalizeWebhookResponse(429, { result: 'rate_limited' }).kind, 'rate_limited');
    assert.equal(normalizeWebhookResponse(404, null).kind, 'unavailable');
    assert.equal(normalizeWebhookResponse(500, { result: 'unavailable' }).kind, 'unavailable');
});

test('rejette les clés inattendues et toutes les valeurs affichables non autorisées', () => {
    const malicious = '<script>globalThis.compromised=true</script>';
    const responses = [
        normalizeWebhookResponse(201, { result: 'created', lead: { id: 7, status: 'RECEIVED', message: malicious } }),
        normalizeWebhookResponse(201, { result: 'created', lead: { id: malicious, status: malicious } }),
        normalizeWebhookResponse(200, { result: 'already_received', lead: { id: 7, status: 'UNKNOWN' } }),
        normalizeWebhookResponse(422, { result: 'invalid', errors: ['lead.email'], exception: malicious }),
        normalizeWebhookResponse(429, { result: 'rate_limited', headers: malicious }),
        normalizeWebhookResponse(500, { result: 'unavailable', trace: malicious }),
    ];

    assert.ok(responses.every((response) => response.kind === 'technical_error'));
    assert.ok(responses.every((response) => !JSON.stringify(response).includes(malicious)));
});

test('lit une réponse JSON bornée puis élimine le corps brut et les clés sensibles', async () => {
    const request = prepareWebhookRequest(endpoint, validJson, origin, new AbortController().signal);
    const result = await performWebhookRequest(request, async () => jsonResponse(201, {
        result: 'created',
        lead: { id: 12, status: 'ROUTED' },
    }));

    assert.deepEqual(Object.keys(result).sort(), ['kind', 'leadId', 'leadStatus', 'message', 'statusCode', 'title']);
    assert.equal(JSON.stringify(result).includes(validJson), false);
});

test('transforme les corps non JSON, mal formés, trop grands ou inattendus en erreur générique', async () => {
    const request = prepareWebhookRequest(endpoint, validJson, origin, new AbortController().signal);
    const cases = [
        new Response('<html>trace privée</html>', { status: 500, headers: { 'Content-Type': 'text/html' } }),
        new Response('{"result":', { status: 500, headers: { 'Content-Type': 'application/json' } }),
        new Response(JSON.stringify({ result: 'created', lead: { id: 1, status: 'RECEIVED' }, exception: 'privée' }), {
            status: 201,
            headers: { 'Content-Type': 'application/json' },
        }),
        new Response(JSON.stringify({ result: 'created', lead: { id: 1, status: 'RECEIVED' } }), {
            status: 201,
            headers: { 'Content-Type': 'application/json', 'Content-Length': String(WEBHOOK_RESPONSE_MAX_BYTES + 1) },
        }),
        new Response(JSON.stringify({ result: 'created', padding: 'x'.repeat(200) }), {
            status: 201,
            headers: { 'Content-Type': 'application/json' },
        }),
    ];

    for (const [index, response] of cases.entries()) {
        const maxBytes = index === cases.length - 1 ? 32 : WEBHOOK_RESPONSE_MAX_BYTES;
        const result = await performWebhookRequest(request, async () => response, maxBytes);
        assert.equal(result.kind, 'technical_error');
        assert.equal(JSON.stringify(result).includes('privée'), false);
        assert.equal(JSON.stringify(result).includes('trace'), false);
    }
});

test('distingue erreur réseau, délai dépassé et annulation sans détail technique', async () => {
    const network = startWebhookRequest({
        endpoint,
        rawJson: validJson,
        origin,
        fetchImplementation: async () => {
            throw new Error('private network detail');
        },
    });
    const networkResult = await network.promise;
    assert.equal(networkResult.kind, 'technical_error');
    assert.equal(JSON.stringify(networkResult).includes('private network detail'), false);

    const abortingFetch = (_url, options) => new Promise((_resolve, reject) => {
        const rejectAbort = () => reject(Object.assign(new Error('private abort detail'), { name: 'AbortError' }));
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
    assert.equal(normalizeRequestError(new Error('private')).kind, 'technical_error');
});

test('utilise un délai exact de 10 secondes et nettoie les temporisateurs', async () => {
    let timeoutMilliseconds;
    let timeoutCallback;
    const cleared = [];
    let capturedSignal;

    const request = startWebhookRequest({
        endpoint,
        rawJson: validJson,
        origin,
        fetchImplementation: (_url, options) => {
            capturedSignal = options.signal;
            return new Promise(() => {});
        },
        setTimeoutImplementation(callback, milliseconds) {
            timeoutCallback = callback;
            timeoutMilliseconds = milliseconds;
            return 42;
        },
        clearTimeoutImplementation(timer) {
            cleared.push(timer);
        },
    });

    assert.equal(timeoutMilliseconds, 10000);
    assert.equal(capturedSignal.aborted, false);
    request.cancel();
    assert.equal(capturedSignal.aborted, true);
    assert.deepEqual(cleared, [42]);
    assert.equal(typeof timeoutCallback, 'function');

    const completedClears = [];
    const completed = startWebhookRequest({
        endpoint,
        rawJson: validJson,
        origin,
        fetchImplementation: async () => jsonResponse(201, { result: 'created', lead: { id: 1, status: 'RECEIVED' } }),
        setTimeoutImplementation() {
            return 84;
        },
        clearTimeoutImplementation(timer) {
            completedClears.push(timer);
        },
    });
    await completed.promise;
    assert.deepEqual(completedClears, [84]);
});

test('ne démarre rien à la création et bloque les doubles envois concurrents', async () => {
    const requests = [];
    const sendingStates = [];
    const results = [];
    const coordinator = createWebhookRequestCoordinator({
        endpoint,
        origin,
        startRequestImplementation() {
            const completion = deferred();
            const request = { promise: completion.promise, cancel() {}, completion };
            requests.push(request);
            return request;
        },
        onSendingChange(value) {
            sendingStates.push(value);
        },
        onResult(value) {
            results.push(value);
        },
    });

    assert.equal(requests.length, 0);
    assert.equal(coordinator.send(validJson), true);
    assert.equal(coordinator.send(validJson), false);
    assert.equal(requests.length, 1);
    assert.equal(coordinator.sending, true);

    requests[0].completion.resolve({ kind: 'created' });
    await flushPromises();
    assert.deepEqual(sendingStates, [true, false]);
    assert.deepEqual(results, [{ kind: 'created' }]);
});

test('annule avant réinitialisation ou nouvel exemple et ignore toute réponse tardive', async () => {
    const requests = [];
    const results = [];
    const coordinator = createWebhookRequestCoordinator({
        endpoint,
        origin,
        startRequestImplementation() {
            const completion = deferred();
            const request = {
                promise: completion.promise,
                cancelled: false,
                cancel() {
                    this.cancelled = true;
                },
                completion,
            };
            requests.push(request);
            return request;
        },
        onResult(value) {
            results.push(value);
        },
    });

    coordinator.send(validJson);
    assert.equal(coordinator.cancel(), true);
    assert.equal(requests[0].cancelled, true);
    assert.equal(coordinator.sending, false);

    coordinator.send(validJson.replace('001', '002'));
    requests[0].completion.resolve({ kind: 'created', leadId: 1 });
    await flushPromises();
    assert.deepEqual(results, []);

    requests[1].completion.resolve({ kind: 'created', leadId: 2 });
    await flushPromises();
    assert.deepEqual(results, [{ kind: 'created', leadId: 2 }]);
});

test('le démontage annule la requête et empêche toute mise à jour tardive', async () => {
    const completion = deferred();
    const results = [];
    let cancelled = false;
    const coordinator = createWebhookRequestCoordinator({
        endpoint,
        origin,
        startRequestImplementation() {
            return {
                promise: completion.promise,
                cancel() {
                    cancelled = true;
                },
            };
        },
        onResult(value) {
            results.push(value);
        },
    });

    coordinator.send(validJson);
    coordinator.dispose();
    assert.equal(cancelled, true);
    completion.resolve({ kind: 'created' });
    await flushPromises();
    assert.deepEqual(results, []);
    assert.equal(coordinator.send(validJson), false);

    const component = await readFile(
        new URL('../../resources/js/components/WebhookSimulator.vue', import.meta.url),
        'utf8',
    );
    assert.match(component, /onBeforeUnmount\(\(\) => \{\s+requestCoordinator\.dispose\(\);/u);
});

test('le composant n’envoie rien au montage et relie annulation, focus et état occupé', async () => {
    const component = await readFile(
        new URL('../../resources/js/components/WebhookSimulator.vue', import.meta.url),
        'utf8',
    );

    assert.equal(component.includes('onMounted'), false);
    assert.equal(component.includes('send();'), false);
    assert.match(component, /@click="send"/u);
    assert.match(component, /const cancelled = requestCoordinator\.cancel\(\);\s+editor\.value = createExampleJson\(\);\s+clearFeedback\(cancelled\);/u);
    assert.match(component, /const cancelled = requestCoordinator\.cancel\(\);\s+editor\.value = initialJson;\s+clearFeedback\(cancelled\);/u);
    assert.match(component, /normalizeRequestError\(null, 'cancelled'\)/u);
    assert.match(component, /:disabled="sending"/u);
    assert.match(component, /:aria-busy="sending"/u);
    assert.match(component, /aria-live="polite"/u);
    assert.match(component, /resultElement\.value\?\.focus\(\)/u);
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

test('le script npm conserve les tests CSV et webhook sous le runner Node de la CI', async () => {
    const packageJson = JSON.parse(await readFile(new URL('../../package.json', import.meta.url), 'utf8'));
    const workflow = await readFile(new URL('../../.github/workflows/quality-checks.yml', import.meta.url), 'utf8');

    assert.match(packageJson.scripts['test:js'], /csv-import-preview\.test\.js/u);
    assert.match(packageJson.scripts['test:js'], /webhook-simulator\.test\.js/u);
    assert.ok(workflow.includes('run: npm run test:js'));
    assert.ok(workflow.indexOf('run: npm run test:js') < workflow.indexOf('run: npm run build'));
});

function jsonResponse(status, body) {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });

    return { promise, resolve, reject };
}

async function flushPromises() {
    await Promise.resolve();
    await Promise.resolve();
}
