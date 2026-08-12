import { createApp } from 'vue';
import CsvImportPreview from './components/CsvImportPreview.vue';

document.querySelectorAll('[data-csv-import-preview]').forEach((element) => {
    createApp(CsvImportPreview, {
        inputId: element.dataset.inputId,
    }).mount(element);
});
