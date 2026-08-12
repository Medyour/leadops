export const CSV_HEADER = Object.freeze([
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
]);

export const CSV_LIMITS = Object.freeze({
    maxBytes: 2 * 1024 * 1024,
    maxRows: 500,
    previewRows: 10,
});

const sensitiveColumns = new Set(['email', 'phone', 'message']);

export function decodeUtf8(buffer) {
    try {
        return new TextDecoder('utf-8', { fatal: true, ignoreBOM: true }).decode(buffer);
    } catch {
        throw new Error('Le fichier doit contenir uniquement du texte UTF-8 valide.');
    }
}

export function analyzeCsv(text, options = {}) {
    const limits = { ...CSV_LIMITS, ...options };
    const warnings = [];

    if (limits.sizeBytes > limits.maxBytes) {
        warnings.push('Le fichier dépasse la limite de 2 Mio.');
    }

    if (text === '') {
        return failedAnalysis(warnings, 'Le fichier est vide.');
    }

    if (/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/u.test(text)) {
        return failedAnalysis(warnings, 'Le fichier doit contenir uniquement du texte UTF-8 valide.');
    }

    if (text.startsWith('\uFEFF')) {
        text = text.slice(1);
    }

    if (text === '') {
        return failedAnalysis(warnings, 'Le fichier est vide.');
    }

    if (text.includes('\uFEFF')) {
        return failedAnalysis(warnings, 'Le BOM UTF-8 est autorisé uniquement au début du fichier.');
    }

    let records;

    try {
        records = parseCsvRecords(text);
    } catch (error) {
        return failedAnalysis(warnings, error.message);
    }

    if (records.length === 0) {
        return failedAnalysis(warnings, 'L’en-tête CSV est manquant.');
    }

    const header = records[0];
    const headerValid = arraysEqual(header, CSV_HEADER);

    if (!headerValid) {
        warnings.push(headerWarning(header));
    }

    const rows = records.slice(1);

    if (rows.length > limits.maxRows) {
        warnings.push(`Le fichier dépasse la limite de ${limits.maxRows} lignes de données.`);
    }

    const invalidColumnRows = rows
        .map((row, index) => (row.length === CSV_HEADER.length ? null : index + 2))
        .filter((line) => line !== null);

    if (invalidColumnRows.length > 0) {
        const displayedLines = invalidColumnRows.slice(0, 5).join(', ');
        const suffix = invalidColumnRows.length > 5 ? '…' : '';
        warnings.push(`Nombre de colonnes incorrect aux lignes ${displayedLines}${suffix}.`);
    }

    return {
        headerValid,
        headerMessage: headerValid
            ? 'L’en-tête correspond exactement au format attendu.'
            : 'L’en-tête ne correspond pas exactement au format attendu.',
        rowCount: rows.length,
        preview: rows.slice(0, limits.previewRows).map((row) => maskPreviewRow(row)),
        warnings,
        syntaxValid: true,
    };
}

export function analyzeCsvBytes(buffer, options = {}) {
    try {
        return analyzeCsv(decodeUtf8(buffer), options);
    } catch (error) {
        return failedAnalysis([], error.message);
    }
}

export function clearFileSelection(input) {
    input.value = '';
    input.focus();
}

function failedAnalysis(warnings, message) {
    return {
        headerValid: false,
        headerMessage: 'L’en-tête ne peut pas être vérifié.',
        rowCount: 0,
        preview: [],
        warnings: [...warnings, message],
        syntaxValid: false,
    };
}

function parseCsvRecords(contents) {
    const rows = [];
    let row = [];
    let field = '';
    let state = 'start';

    for (let index = 0; index < contents.length; index += 1) {
        const character = contents[index];

        if (state === 'quoted') {
            if (character === '"') {
                if (contents[index + 1] === '"') {
                    field += '"';
                    index += 1;
                } else {
                    state = 'after_quote';
                }
            } else {
                field += character;
            }

            continue;
        }

        if (state === 'after_quote') {
            if (character === ',') {
                row.push(field);
                field = '';
                state = 'start';
                continue;
            }

            if (character === '\r' || character === '\n') {
                rows.push([...row, field]);
                row = [];
                field = '';
                state = 'start';

                if (character === '\r' && contents[index + 1] === '\n') {
                    index += 1;
                }

                continue;
            }

            throw new Error('Le fichier contient une valeur citée mal formée.');
        }

        if (character === '"') {
            if (state !== 'start') {
                throw new Error('Le fichier contient un guillemet CSV mal placé.');
            }

            state = 'quoted';
            continue;
        }

        if (character === ',') {
            row.push(field);
            field = '';
            state = 'start';
            continue;
        }

        if (character === '\r' || character === '\n') {
            rows.push([...row, field]);
            row = [];
            field = '';
            state = 'start';

            if (character === '\r' && contents[index + 1] === '\n') {
                index += 1;
            }

            continue;
        }

        field += character;
        state = 'unquoted';
    }

    if (state === 'quoted') {
        throw new Error('Le fichier contient une valeur citée non terminée.');
    }

    if (state !== 'start' || row.length > 0 || field !== '') {
        rows.push([...row, field]);
    }

    return rows;
}

function headerWarning(header) {
    const duplicates = CSV_HEADER.filter((name) => header.filter((value) => value === name).length > 1);
    const missing = CSV_HEADER.filter((name) => !header.includes(name));
    const unexpected = header.filter((name) => !CSV_HEADER.includes(name));

    if (duplicates.length > 0) {
        return `En-tête dupliqué : ${[...new Set(duplicates)].join(', ')}.`;
    }

    if (missing.length > 0) {
        return `En-tête manquant : ${missing.join(', ')}.`;
    }

    if (unexpected.length > 0) {
        return 'Le fichier contient un ou plusieurs en-têtes inattendus.';
    }

    return 'Les en-têtes CSV ne sont pas dans l’ordre attendu.';
}

function maskPreviewRow(row) {
    return CSV_HEADER.map((column, index) => {
        const value = row[index] ?? '';
        return sensitiveColumns.has(column) ? maskSensitiveValue(column, value) : value;
    });
}

function maskSensitiveValue(column, value) {
    if (value === '') {
        return '';
    }

    if (column === 'email') {
        const separator = value.lastIndexOf('@');
        return separator > 0 ? `${value[0]}•••${value.slice(separator)}` : '•••';
    }

    if (column === 'phone') {
        const digits = value.replace(/\D/gu, '');
        return digits.length > 2 ? `••••${digits.slice(-2)}` : '••••';
    }

    const normalized = value.replace(/\s+/gu, ' ').trim();
    return normalized.length > 48 ? `${normalized.slice(0, 48)}…` : normalized;
}

function arraysEqual(left, right) {
    return left.length === right.length && left.every((value, index) => value === right[index]);
}
