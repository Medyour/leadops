const WEBHOOK_PATH = '/api/local/webhooks/leads';

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
    const target = new URL(endpoint, origin);
    const expectedOrigin = new URL(origin).origin;

    if (
        target.origin !== expectedOrigin
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
        url: target.href,
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

export async function performWebhookRequest(request, fetchImplementation = globalThis.fetch) {
    const response = await fetchImplementation(request.url, request.options);
    let body = null;

    try {
        body = await response.json();
    } catch {
        // A non-JSON response is intentionally reduced to its HTTP status.
    }

    return normalizeWebhookResponse(response.status, body);
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
    const request = prepareWebhookRequest(endpoint, rawJson, origin, controller.signal);
    const timer = setTimeoutImplementation(() => {
        reason = 'timeout';
        controller.abort();
    }, timeoutMilliseconds);

    const promise = performWebhookRequest(request, fetchImplementation)
        .catch((error) => normalizeRequestError(error, reason))
        .finally(() => clearTimeoutImplementation(timer));

    return {
        promise,
        cancel() {
            reason = 'cancelled';
            controller.abort();
        },
    };
}

export function normalizeWebhookResponse(status, body) {
    if (status === 201 && body?.result === 'created') {
        return successResult('created', status, body.lead);
    }

    if (status === 200 && body?.result === 'already_received') {
        return successResult('already_received', status, body.lead);
    }

    if (status === 422 && body?.result === 'invalid') {
        const fields = Array.isArray(body.errors)
            ? [...new Set(body.errors.filter((field) => typeof field === 'string' && displayedValidationPaths.has(field)))].sort()
            : [];

        return {
            kind: 'invalid',
            statusCode: 422,
            title: 'Payload refusé',
            message: 'Laravel a refusé la structure ou les champs envoyés.',
            fields,
        };
    }

    if (status === 429) {
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

    if (status >= 400 && body?.result === 'unavailable') {
        return {
            kind: 'unavailable',
            statusCode: status,
            title: 'Webhook indisponible',
            message: 'Le webhook local ne peut pas traiter la requête actuellement.',
        };
    }

    return technicalResult(status);
}

export function normalizeRequestError(error, reason = 'network') {
    if (reason === 'timeout') {
        return {
            kind: 'timeout',
            title: 'Délai dépassé',
            message: 'Le webhook local n’a pas répondu dans le délai prévu.',
        };
    }

    if (reason === 'cancelled' || error?.name === 'AbortError') {
        return {
            kind: 'cancelled',
            title: 'Envoi annulé',
            message: 'La requête a été annulée sans afficher de réponse.',
        };
    }

    return technicalResult();
}

function validateLeadShape(lead, paths) {
    validateOptionalString(lead, 'source', 20, paths);
    validateRequiredString(lead, 'external_reference', 100, paths, /^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/u);
    validateRequiredString(lead, 'first_name', 100, paths);
    validateRequiredString(lead, 'last_name', 100, paths);
    validateOptionalString(lead, 'company', 150, paths);
    validateOptionalString(lead, 'email', 254, paths);
    validateOptionalString(lead, 'phone', 50, paths);
    validateOptionalString(lead, 'country', 2, paths, /^[A-Z]{2}$/u);
    validateRequiredString(lead, 'service_interest', 50, paths, /^[a-z][a-z0-9_]*$/u);
    validateOptionalString(lead, 'message', 5000, paths);

    if ((lead.email === null || lead.email === '' || lead.email === undefined)
        && (lead.phone === null || lead.phone === '' || lead.phone === undefined)) {
        paths.push('lead.email', 'lead.phone');
    }

    if (lead.email != null && (typeof lead.email !== 'string' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/u.test(lead.email))) {
        paths.push('lead.email');
    }

    if (lead.phone != null && (typeof lead.phone !== 'string' || !/^\+?[0-9 .()\-]{7,50}$/u.test(lead.phone))) {
        paths.push('lead.phone');
    }

    if ('company_size' in lead && lead.company_size !== null
        && (!Number.isInteger(lead.company_size) || lead.company_size < 1 || lead.company_size > 4294967295)) {
        paths.push('lead.company_size');
    }

    if (lead.consent !== true) {
        paths.push('lead.consent');
    }

    if ('received_at' in lead && !isValidUtcDate(lead.received_at)) {
        paths.push('lead.received_at');
    }
}

function validateRequiredString(record, field, maxLength, paths, pattern = null) {
    if (!(field in record) || typeof record[field] !== 'string' || record[field].length === 0
        || record[field].length > maxLength || (pattern && !pattern.test(record[field]))) {
        paths.push(`lead.${field}`);
    }
}

function validateOptionalString(record, field, maxLength, paths, pattern = null) {
    if (!(field in record) || record[field] === null) {
        return;
    }

    if (typeof record[field] !== 'string' || record[field].length > maxLength || (pattern && !pattern.test(record[field]))) {
        paths.push(`lead.${field}`);
    }
}

function isValidUtcDate(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/u.test(value)) {
        return false;
    }

    const date = new Date(value);
    return !Number.isNaN(date.valueOf()) && date.toISOString().replace('.000Z', 'Z') === value;
}

function isRecord(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function hasExactKeys(record, expectedKeys) {
    const keys = Object.keys(record);
    return keys.length === expectedKeys.length && expectedKeys.every((key) => keys.includes(key));
}

function successResult(kind, statusCode, lead) {
    const isCreated = kind === 'created';
    const result = {
        kind,
        statusCode,
        title: isCreated ? 'Lead créé' : 'Lead déjà reçu',
        message: isCreated
            ? 'Laravel a accepté le payload et créé la réception.'
            : 'Cette référence avait déjà été reçue. Aucun nouveau traitement n’a été lancé.',
    };

    if (Number.isSafeInteger(lead?.id) && lead.id > 0) {
        result.leadId = lead.id;
    }

    if (typeof lead?.status === 'string' && leadStatuses.has(lead.status)) {
        result.leadStatus = lead.status;
    }

    return result;
}

function technicalResult(statusCode = null) {
    return {
        kind: 'technical_error',
        ...(Number.isInteger(statusCode) ? { statusCode } : {}),
        title: 'Erreur technique',
        message: 'Le résultat n’a pas pu être confirmé. Vérifiez que le service local est disponible, puis réessayez.',
    };
}
