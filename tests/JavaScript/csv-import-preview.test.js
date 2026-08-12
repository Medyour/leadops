import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import {
    analyzeCsv,
    analyzeCsvBytes,
    clearFileSelection,
    CSV_HEADER,
    CSV_LIMITS,
} from '../../resources/js/csv-import-parser.js';

const header = CSV_HEADER.join(',');

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

    return CSV_HEADER.map((column) => quote(values[column])).join(',');
}

function quote(value) {
    return /[",\r\n]/u.test(value) ? `"${value.replaceAll('"', '""')}"` : value;
}

test('accepte un BOM initial et refuse un BOM ailleurs', () => {
    assert.equal(analyzeCsv(`\uFEFF${header}\n${row()}`).headerValid, true);

    const lateBom = analyzeCsv(`${header}\n${row()}\n\uFEFF${row({ external_reference: 'csv-fictif-002' })}`);
    assert.equal(lateBom.syntaxValid, false);
    assert.match(lateBom.warnings.at(-1), /uniquement au début/u);
});

test('identifie un en-tête exact ou incorrect', () => {
    assert.equal(analyzeCsv(`${header}\n${row()}`).headerValid, true);

    const wrongHeader = [CSV_HEADER[1], CSV_HEADER[0], ...CSV_HEADER.slice(2)].join(',');
    const analysis = analyzeCsv(`${wrongHeader}\n${row()}`);
    assert.equal(analysis.headerValid, false);
    assert.match(analysis.warnings[0], /ordre attendu/u);
});

test('analyse les virgules, guillemets échappés et champs multilignes', () => {
    const analysis = analyzeCsv(`${header}\n${row({
        company: 'Atelier, Démo',
        message: 'Une demande "fictive"\nsur deux lignes',
    })}`);

    assert.equal(analysis.syntaxValid, true);
    assert.equal(analysis.rowCount, 1);
    assert.equal(analysis.preview[0][3], 'Atelier, Démo');
    assert.equal(analysis.preview[0][9], 'Une demande "fictive" sur deux lignes');
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

test('masque les valeurs sensibles dans l’aperçu', () => {
    const analysis = analyzeCsv(`${header}\n${row()}`);

    assert.equal(analysis.preview[0][4], 'a•••@example.test');
    assert.equal(analysis.preview[0][5], '••••05');
    assert.ok(analysis.preview[0][9].endsWith('…'));
    assert.ok(!analysis.preview[0].includes('aline@example.test'));
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

    for (const forbidden of ['fetch(', 'XMLHttpRequest', 'sendBeacon', 'localStorage', 'sessionStorage', 'console.', 'v-html']) {
        assert.equal(source.includes(forbidden), false, `${forbidden} ne doit pas être utilisé`);
    }
});
