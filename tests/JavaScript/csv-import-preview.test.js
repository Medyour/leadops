import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import {
    analyzeCsv,
    analyzeCsvBytes,
    canReadCsvFile,
    clearFileSelection,
    DEFAULT_CSV_HEADER,
    CSV_LIMITS,
} from '../../resources/js/csv-import-parser.js';

const header = DEFAULT_CSV_HEADER.join(',');

function row(overrides = {}) {
    const values = {
        external_reference: 'csv-fictif-001',
        first_name: 'Aline',
        last_name: 'Exemple',
        company: 'Atelier Fictif',
        email: 'aline@example.test',
        phone: '+33102030405',
        country: 'FR',
        service_interest: 'automation',
        company_size: '8',
        message: 'Message entièrement fictif pour tester la prévisualisation locale.',
        consent: 'true',
        received_at: '2026-08-12T10:00:00Z',
        ...overrides,
    };

    return DEFAULT_CSV_HEADER.map((column) => quote(values[column])).join(',');
}

function quote(value) {
    return /[",\r\n]/u.test(value) ? `"${value.replaceAll('"', '""')}"` : value;
}

test('accepte un BOM initial et refuse un BOM ailleurs', () => {
    assert.equal(analyzeCsv(`\uFEFF${header}\n${row()}`).headerValid, true);
    assert.equal(
        analyzeCsvBytes(new TextEncoder().encode(`\uFEFF${header}\n${row()}`)).headerValid,
        true,
    );

    const lateBom = analyzeCsv(`${header}\n${row()}\n\uFEFF${row({ external_reference: 'csv-fictif-002' })}`);
    assert.equal(lateBom.syntaxValid, false);
    assert.match(lateBom.warnings.at(-1), /uniquement au début/u);

    const lateBomBytes = analyzeCsvBytes(
        new TextEncoder().encode(`${header}\n${row()}\n\uFEFF${row({ external_reference: 'csv-fictif-002' })}`),
    );
    assert.equal(lateBomBytes.syntaxValid, false);
    assert.match(lateBomBytes.warnings.at(-1), /uniquement au début/u);
});

test('identifie un en-tête exact ou incorrect', () => {
    assert.equal(analyzeCsv(`${header}\n${row()}`).headerValid, true);

    const wrongHeader = [DEFAULT_CSV_HEADER[1], DEFAULT_CSV_HEADER[0], ...DEFAULT_CSV_HEADER.slice(2)].join(',');
    const analysis = analyzeCsv(`${wrongHeader}\n${row()}`);
    assert.equal(analysis.headerValid, false);
    assert.match(analysis.warnings[0], /ordre attendu/u);
    assert.deepEqual(analysis.preview, []);
});

test('analyse CRLF, LF, CR, virgules, guillemets échappés et champs multilignes', () => {
    for (const lineEnding of ['\r\n', '\n', '\r']) {
        const analysis = analyzeCsv(`${header}${lineEnding}${row({
            company: 'Atelier, Démo',
            message: `Une demande "fictive"${lineEnding}sur deux lignes`,
        })}`);

        assert.equal(analysis.syntaxValid, true);
        assert.equal(analysis.rowCount, 1);
        assert.equal(analysis.preview[0][3], 'Atelier, Démo');
        assert.match(analysis.preview[0][9], /^\[message masqué/u);
    }
});

test('signale un fichier vide, un UTF-8 invalide et une syntaxe incorrecte', () => {
    assert.match(analyzeCsv('').warnings[0], /vide/u);
    assert.match(analyzeCsv(`${header}\n"non terminé`).warnings[0], /non terminée/u);

    const invalidUtf8 = analyzeCsvBytes(Uint8Array.from([0xc3, 0x28]));
    assert.match(invalidUtf8.warnings[0], /UTF-8 valide/u);
});

test('signale les limites de taille et de lignes sans bloquer le flux HTML', () => {
    const rows = Array.from({ length: CSV_LIMITS.maxRows + 1 }, (_, index) => row({
        external_reference: `csv-fictif-${String(index).padStart(3, '0')}`,
    }));
    const analysis = analyzeCsv(`${header}\n${rows.join('\n')}`, { sizeBytes: CSV_LIMITS.maxBytes + 1 });

    assert.equal(analysis.rowCount, 501);
    assert.equal(analysis.preview.length, 10);
    assert.ok(analysis.warnings.some((warning) => warning.includes('2 Mio')));
    assert.ok(analysis.warnings.some((warning) => warning.includes('500 lignes')));
});

test('contrôle la taille avant que le composant lise le contenu', async () => {
    assert.equal(canReadCsvFile(CSV_LIMITS.maxBytes), true);
    assert.equal(canReadCsvFile(CSV_LIMITS.maxBytes + 1), false);

    const component = await readFile(
        new URL('../../resources/js/components/CsvImportPreview.vue', import.meta.url),
        'utf8',
    );
    assert.ok(
        component.indexOf('if (!canReadCsvFile(selectedFile.size, props.maxBytes))')
            < component.indexOf('const buffer = await selectedFile.arrayBuffer()'),
    );
});

test('compte les lignes vides et la dernière ligne comme le parseur Laravel', () => {
    const withoutFinalNewline = analyzeCsv(`${header}\n${row()}`);
    const withFinalNewline = analyzeCsv(`${header}\n${row()}\n`);
    const withBlankRecord = analyzeCsv(`${header}\n${row()}\n\n`);

    assert.equal(withoutFinalNewline.rowCount, 1);
    assert.equal(withFinalNewline.rowCount, 1);
    assert.equal(withBlankRecord.rowCount, 2);
    assert.match(withBlankRecord.warnings.at(-1), /colonnes incorrect/u);
    assert.ok(withBlankRecord.preview[1].every((value) => value === 'Donnée non affichée'));
});

test('utilise le contrat d’en-tête fourni par Laravel', () => {
    const serverHeader = [...DEFAULT_CSV_HEADER];
    assert.equal(analyzeCsv(`${serverHeader.join(',')}\n${row()}`, { header: serverHeader }).headerValid, true);

    const changedServerHeader = [...serverHeader.slice(0, -1), 'server_only_column'];
    assert.equal(analyzeCsv(`${header}\n${row()}`, { header: changedServerHeader }).headerValid, false);
});

test('masque les valeurs sensibles dans l’aperçu', () => {
    const analysis = analyzeCsv(`${header}\n${row()}`);

    assert.equal(analysis.preview[0][4], '••••••');
    assert.equal(analysis.preview[0][5], '••••••');
    assert.match(analysis.preview[0][9], /^\[message masqué — \d+ caractères\]$/u);
    assert.ok(!analysis.preview[0].includes('aline@example.test'));
    assert.ok(!analysis.preview[0].some((value) => value.includes('Message entièrement fictif')));
});

test('efface la sélection et rend le focus au champ fichier', () => {
    let focused = false;
    const input = {
        value: 'C:\\fakepath\\leads.csv',
        focus() {
            focused = true;
        },
    };

    clearFileSelection(input);

    assert.equal(input.value, '');
    assert.equal(focused, true);
});

test('le code de prévisualisation ne contient ni réseau, stockage, console ou v-html', async () => {
    const sources = await Promise.all([
        readFile(new URL('../../resources/js/app.js', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/js/components/CsvImportPreview.vue', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/js/csv-import-parser.js', import.meta.url), 'utf8'),
    ]);
    const source = sources.join('\n');

    for (const forbidden of [
        'fetch(',
        'XMLHttpRequest',
        'sendBeacon',
        'localStorage',
        'sessionStorage',
        'indexedDB',
        'document.cookie',
        'console.',
        'v-html',
    ]) {
        assert.equal(source.includes(forbidden), false, `${forbidden} ne doit pas être utilisé`);
    }
});

test('la CI exécute les tests JavaScript avant le build Vite', async () => {
    const workflow = await readFile(
        new URL('../../.github/workflows/quality-checks.yml', import.meta.url),
        'utf8',
    );

    assert.ok(workflow.includes('run: npm run test:js'));
    assert.ok(workflow.indexOf('run: npm run test:js') < workflow.indexOf('run: npm run build'));
});
