import { Controller } from '@hotwired/stimulus';

/* Text aus einem Feld in die Zwischenablage kopieren; der Knopf bestätigt kurz „Kopiert!“. */
export default class extends Controller {
    static targets = ['source', 'button'];

    async copy() {
        const text = this.sourceTarget.value;
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            // Ohne Clipboard-API (z. B. ohne HTTPS): markieren und klassisch kopieren.
            this.sourceTarget.select();
            document.execCommand('copy');
        }

        const label = this.buttonTarget.textContent;
        this.buttonTarget.textContent = 'Kopiert!';
        setTimeout(() => { this.buttonTarget.textContent = label; }, 1800);
    }
}
