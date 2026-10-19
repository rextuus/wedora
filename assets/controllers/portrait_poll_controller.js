import { Controller } from '@hotwired/stimulus';

/*
 * Lädt den umgebenden Turbo-Frame neu, solange das Porträt gezeichnet wird.
 * Ist die Zeichnung fertig, enthält die neue Antwort diesen Controller nicht mehr.
 */
export default class extends Controller {
    static values = { url: String, interval: { type: Number, default: 4000 } };

    connect() {
        this.timer = setTimeout(() => {
            const frame = this.element.closest('turbo-frame');
            if (frame) frame.src = `${this.urlValue}?t=${Date.now()}`;
        }, this.intervalValue);
    }

    disconnect() {
        clearTimeout(this.timer);
    }
}
