import { Controller } from '@hotwired/stimulus';

/*
 * Selfie-Vorschau und Verkleinerung vor dem Hochladen (lange Seite max. 1600 px, JPEG):
 * schneller, unter der Server-Grenze und es verlässt weniger vom Originalfoto das Gerät.
 * Kann der Browser das Format nicht lesen (z. B. HEIC), geht das Original unverändert hoch.
 */
const LONG_EDGE = 1600;

export default class extends Controller {
    static targets = ['input', 'preview', 'placeholder', 'submit'];

    preview() {
        const file = this.inputTarget.files[0];
        if (!file) return;
        if (this.url) URL.revokeObjectURL(this.url);
        this.url = URL.createObjectURL(file);
        this.previewTarget.src = this.url;
        this.previewTarget.hidden = false;
        this.placeholderTarget.hidden = true;
    }

    async prepare(event) {
        const file = this.inputTarget.files[0];
        if (!file || this.prepared || !('createImageBitmap' in window) || typeof DataTransfer === 'undefined') return;

        event.preventDefault();
        this.submitTarget.disabled = true;
        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const scale = Math.min(1, LONG_EDGE / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * scale);
            canvas.height = Math.round(bitmap.height * scale);
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.88));
            if (blob) {
                const transfer = new DataTransfer();
                transfer.items.add(new File([blob], 'selfie.jpg', { type: 'image/jpeg' }));
                this.inputTarget.files = transfer.files;
            }
        } catch {
            // Original hochladen
        }
        this.prepared = true;
        this.submitTarget.disabled = false;
        this.element.requestSubmit(this.submitTarget);
    }

    disconnect() {
        if (this.url) URL.revokeObjectURL(this.url);
    }
}
