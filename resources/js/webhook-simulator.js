const WEBHOOK_PATH = '/api/local/webhooks/leads';

export const WEBHOOK_RESPONSE_MAX_BYTES = 32 * 1024;

const allowedLeadFields = [
    'source',
    'external_reference',
    'first_name',
    'last_name',
    'company',
    'email',
    'phone',
    'country',
    'service_interest',
    'company_size',
    'message',
    'consent',
    'received_at',
];

const requiredLeadFields = [
    'external_reference',
    'first_name',
    'last_name',
    'service_interest',
    'consent',
];

const displayedValidationPaths = new Set([
    'envelope',
    'lead',
    ...allowedLeadFields.map((field) => `lead.${field}`),
]);

const leadStatuses = new Set([
    'RECEIVED',
    'VALIDATING',
    'INVALID',
    'DUPLICATE',
    'QUALIFIED',
    'NEEDS_REVIEW',
    'ROUTED',
    'FAILED',
]);

export function makeExamplePayload(externalReference, receivedAt) {
    return {
        lead: {
            source: 'webhook',
            external_reference: externalReference,
            first_name: 'Prénom',
            last_name: 'Exemple',
            company: 'Atelier Exemple',
            email: 'webhook@example.test',
            phone: '+12025550142',
            country: 'FR',
            service_interest: 'automation',
            company_size: 12,
            message: 'Demande entièrement fictive créée pour tester le webhook local LeadOps.',
            consent: true,
            received_at: receivedAt,
        },
    };
}

export function formatExampleJson(externalReference, receivedAt) {
    return JSON.stringify(makeExamplePayload(externalReference, receivedAt), null, 2);
}

export function validateWebhookJson(rawJson) {
    let envelope;

    try {
        envelope = JSON.parse(rawJson);
    } catch {
        return {
            valid: false,
            value: null,
            errors: ['Le contenu doit être un document JSON valide.'],
        };
    }

    const paths = [];

    if (!isRecord(envelope) || !hasExactKeys(envelope, ['lead'])) {
        paths.push('envelope');
    }

    const lead = isRecord(envelope) && isRecord(envelope.lead) ? envelope.lead : null;

    if (!lead) {
        paths.push('lead');
    } else {
        for (const field of Object.keys(lead)) {
            if (!allowedLeadFields.includes(field)) {
                paths.push(`lead.${field}`);
            }
        }

        for (const field of requiredLeadFields) {
            if (!(field in lead)) {
                paths.push(`lead.${field}`);
            }
        }

        validateLeadShape(lead, paths);
    }

    const errors = [...new Set(paths)].sort();

    return {
        valid: errors.length === 0,
        value: errors.length === 0 ? envelope : null,
        errors,
    };
}

export function prepareWebhookRequest(endpoint, rawJson, origin, signal) {
    let currentOrigin;

    try {
        currentOrigin = new URL(origin);
    } catch {
        throw new TypeError('Destination webhook refusée.');
    }

    const target = new URL(endpoint, currentOrigin);

    if (
        endpoint !== WEBHOOK_PATH
        || currentOrigin.origin !== origin
        || target.origin !== currentOrigin.origin
        || target.pathname !== WEBHOOK_PATH
        || target.search !== ''
        || target.hash !== ''
        || target.username !== ''
        || target.password !== ''
        || !['http:', 'https:'].includes(target.protocol)
    ) {
        throw new TypeError('Destination webhook refusée.');
    }

    return {
        url: WEBHOOK_PATH,
        expectedUrl: target.href,
        options: {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: rawJson,
            credentials: 'omit',
            cache: 'no-store',
            redirect: 'error',
            referrerPolicy: 'no-referrer',
            mode: 'same-origin',
            signal,
        },
    };
}

export async function performWebhookRequest(
    request,
    fetchImplementation = globalThis.fetch,
    maxResponseBytes = WEBHOOK_RESPONSE_MAX_BYTES,
) {
    const response = await fetchImplementation(request.url, request.options);

    if (response.redirected === true || (response.url && response.url !== request.expectedUrl)) {
        return technicalResult(response.status);
    }

    if (response.status === 404) {
        return normalizeWebhookResponse(404, null);
    }

    const parsed = await readBoundedJsonResponse(response, maxResponseBytes);

    if (!parsed.valid) {
        return technicalResult(response.status);
    }

    return normalizeWebhookResponse(response.status, parsed.value);
}

export function startWebhookRequest({
    endpoint,
    rawJson,
    origin,
    timeoutMilliseconds = 10000,
    fetchImplementation = globalThis.fetch,
    setTimeoutImplementation = globalThis.setTimeout,
    clearTimeoutImplementation = globalThis.clearTimeout,
}) {
    const controller = new AbortController();
    let reason = 'network';
    let timerActive = true;

    const clearTimer = () => {
        if (timerActive) {
            timerActive = false;
            clearTimeoutImplementation(timer);
        }
    };

    const request = prepareWebhookRequest(endpoint, rawJson, origin, controller.signal);
    const timer = setTimeoutImplementation(() => {
        timerActive = false;
        reason = 'timeout';
        controller.abort();
    }, timeoutMilliseconds);

    const promise = performWebhookRequest(request, fetchImplementation)
        .catch((error) => normalizeRequestError(error, reason))
        .finally(clearTimer);

    return {
        promise,
        cancel() {
            if (reason === 'network') {
                reason = 'cancelled';
            }

            clearTimer();
            controller.abort();
        },
    };
}

export function createWebhookRequestCoordinator({
    endpoint,
    origin,
    timeoutMilliseconds = 10000,
    startRequestImplementation = startWebhookRequest,
    onSendingChange = () => {},
    onResult = () => {},
}) {
    let activeRequest = null;
    let disposed = false;
    let sending = false;

    const setSending = (value) => {
        sending = value;
        onSendingChange(value);
    };

    return {
        get sending() {
            return sending;
        },
        send(rawJson) {
            if (disposed || sending) {
                return false;
            }

            setSending(true);
            let request;

            try {
                request = startRequestImplementation({
                    endpoint,
                    rawJson,
                    origin,
                    timeoutMilliseconds,
                });
            } catch (error) {
                setSending(false);
                onResult(normalizeRequestError(error));

                return true;
            }

            activeRequest = request;
            request.promise.then(
                (normalized) => finishRequest(request, normalized),
                (error) => finishRequest(request, normalizeRequestError(error)),
            );

            return true;
        },
        cancel() {
            if (!activeRequest) {
                return false;
            }

            const request = activeRequest;
            activeRequest = null;
            setSending(false);
            request.cancel();

            return true;
        },
        dispose() {
            disposed = true;

            if (activeRequest) {
                const request = activeRequest;
                activeRequest = null;
                sending = false;
                request.cancel();
            }
        },
    };

    function finishRequest(request, normalized) {
        if (disposed || activeRequest !== request) {
            return;
        }

        activeRequest = null;
        setSending(false);
        onResult(normalized);
    }
}

export function normalizeWebhookResponse(status, body) {
    if (status === 201 && hasExactKeys(body, ['result', 'lead']) && body.result === 'created') {
        return normalizeSuccess('created', status, body.lead);
    }

    if (status === 200 && hasExactKeys(body, ['result', 'lead']) && body.result === 'already_received') {
        return normalizeSuccess('already_received', status, body.lead);
    }

    if (status === 422 && hasExactKeys(body, ['result', 'errors']) && body.result === 'invalid'
        && Array.isArray(body.errors) && body.errors.every((field) => typeof field === 'string')) {
        const fields = [...new Set(body.errors.filter((field) => displayedValidationPaths.has(field)))].sort();

        return {
            kind: 'invalid',
            statusCode: 422,
            title: 'Payload refusé',
            message: 'Laravel a refusé la structure ou les champs envoyés.',
            fields,
        };
    }

    if (status === 429 && hasExactKeys(body, ['result']) && body.result === 'rate_limited') {
        return {
            kind: 'rate_limited',
            statusCode: 429,
            title: 'Limite de débit atteinte',
            message: 'Patientez avant de tenter un nouvel envoi.',
        };
    }

    if (status === 404) {
        return {
            kind: 'unavailable',
            statusCode: 404,
            title: 'Webhook indisponible',
            message: 'Le webhook local n’est pas disponible dans cet environnement.',
        };
    }

    if (status >= 400 && hasExactKeys(body, ['result']) && body.result === 'unavailable') {
        return {
            kind: 'unavailable',
            statusCode: status,
            title: 'Webhook indisponible',
            message: 'Le webhook local ne peut pas traiter la requête actuellement.',
        };
    }

    return technicalResult(status);
}

export function normalizeRequestError(_error, reason = 'network') {
    if (reason === 'timeout') {
        return {
            kind: 'timeout',
            title: 'Délai dépassé',
            message: 'Le webhook local n’a pas répondu dans le délai prévu.',
        };
    }

    if (reason === 'cancelled') {
        return {
            kind: 'cancelled',
            title: 'Envoi annulé',
            message: 'La requête a été annulée sans afficher de réponse.',
        };
    }

    return technicalResult();
}

async function readBoundedJsonResponse(response, maxBytes) {
    const contentType = response.headers?.get?.('content-type');

    if (typeof contentType !== 'string' || !/^application\/(?:[a-z0-9.+-]+\+)?json(?:\s*;|$)/iu.test(contentType)) {
        return { valid: false, value: null };
    }

    const contentLength = response.headers?.get?.('content-length');

    if (contentLength !== null && contentLength !== undefined) {
        if (!/^\d+$/u.test(contentLength) || Number(contentLength) > maxBytes) {
            return { valid: false, value: null };
        }
    }

    const text = await readBoundedResponseText(response, maxBytes);

    if (text === null) {
        return { valid: false, value: null };
    }

    try {
        return { valid: true, value: JSON.parse(text) };
    } catch {
        return { valid: false, value: null };
    }
}

async function readBoundedResponseText(response, maxBytes) {
    if (!response.body?.getReader) {
        if (typeof response.text !== 'function') {
            return null;
        }

        const text = await response.text();
        return new TextEncoder().encode(text).byteLength <= maxBytes ? text : null;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8', { fatal: true });
    let size = 0;
    let text = '';

    while (true) {
        const { done, value } = await reader.read();

        if (done) {
            break;
        }

        if (!(value instanceof Uint8Array)) {
            await reader.cancel();

            return null;
        }

        size += value.byteLength;

        if (size > maxBytes) {
            await reader.cancel();

            return null;
        }

        try {
            text += decoder.decode(value, { stream: true });
        } catch {
            await reader.cancel();

            return null;
        }
    }

    try {
        return text + decoder.decode();
    } catch {
        return null;
    }
}

function validateLeadShape(lead, paths) {
    for (const field of ['external_reference', 'first_name', 'last_name', 'service_interest']) {
        if (typeof lead[field] !== 'string') {
            paths.push(`lead.${field}`);
        }
    }

    for (const field of ['source', 'company', 'email', 'phone', 'country', 'message', 'received_at']) {
        if (field in lead && lead[field] !== null && typeof lead[field] !== 'string') {
            paths.push(`lead.${field}`);
        }
    }

    if ('company_size' in lead && lead.company_size !== null && !Number.isInteger(lead.company_size)) {
        paths.push('lead.company_size');
    }

    if (typeof lead.consent !== 'boolean') {
        paths.push('lead.consent');
    }
}

function isRecord(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function hasExactKeys(record, expectedKeys) {
    if (!isRecord(record)) {
        return false;
    }

    const keys = Object.keys(record);
    return keys.length === expectedKeys.length && expectedKeys.every((key) => keys.includes(key));
}

function normalizeSuccess(kind, statusCode, lead) {
    if (!hasExactKeys(lead, ['id', 'status'])
        || !Number.isSafeInteger(lead.id) || lead.id < 1
        || typeof lead.status !== 'string' || !leadStatuses.has(lead.status)) {
        return technicalResult(statusCode);
    }

    const isCreated = kind === 'created';

    return {
        kind,
        statusCode,
        title: isCreated ? 'Lead créé' : 'Lead déjà reçu',
        message: isCreated
            ? 'Laravel a accepté le payload et créé la réception.'
            : 'Cette référence avait déjà été reçue. Aucun nouveau traitement n’a été lancé.',
        leadId: lead.id,
        leadStatus: lead.status,
    };
}

function technicalResult(statusCode = null) {
    return {
        kind: 'technical_error',
        ...(Number.isInteger(statusCode) ? { statusCode } : {}),
        title: 'Erreur technique',
        message: 'Le résultat n’a pas pu être confirmé. Vérifiez que le service local est disponible, puis réessayez.',
    };
}
