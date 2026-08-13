import { createApp } from 'vue';
import CsvImportPreview from './components/CsvImportPreview.vue';
import WebhookSimulator from './components/WebhookSimulator.vue';

document.querySelectorAll('[data-csv-import-preview]').forEach((element) => {
    try {
        const header = JSON.parse(element.dataset.header ?? '[]');
        const maxBytes = Number(element.dataset.maxBytes);
        const maxRows = Number(element.dataset.maxRows);

        if (!element.dataset.inputId || !Array.isArray(header) || header.length === 0) {
            return;
        }

        createApp(CsvImportPreview, {
            inputId: element.dataset.inputId,
            header,
            maxBytes,
            maxRows,
        }).mount(element);
    } catch {
        // Progressive enhancement: the native Blade form remains available.
    }
});

document.querySelectorAll('[data-webhook-simulator]').forEach((element) => {
    const endpoint = element.dataset.endpoint;

    if (!endpoint) {
        return;
    }

    createApp(WebhookSimulator, { endpoint }).mount(element);
});
