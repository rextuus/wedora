import { Controller } from '@hotwired/stimulus';

/*
 * Glücksrad: fragt den Server nach der gezogenen Aufgabe (JSON), dreht das Rad, bis dieses Feld oben unter dem
 * Zeiger steht, und öffnet dann die Aufgabe. Klappt etwas nicht, wird das Formular normal abgeschickt
 * (der Server leitet dann direkt zur Aufgabe weiter).
 */
const TURNS = 5;

export default class extends Controller {
    static targets = ['disc', 'button', 'result'];

    async spin(event) {
        if (this.spinning) {
            event.preventDefault();
            return;
        }
        if (!window.fetch) return;

        event.preventDefault();
        this.spinning = true;
        this.buttonTarget.disabled = true;

        let challenge;
        try {
            const response = await fetch(this.element.action, {
                method: 'POST',
                body: new FormData(this.element),
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (response.status === 409) {
                window.location.reload();
                return;
            }
            if (!response.ok) throw new Error(String(response.status));
            ({ challenge } = await response.json());
        } catch {
            this.element.submit();
            return;
        }

        const labels = [...this.discTarget.querySelectorAll('[data-id]')];
        let matches = labels.filter((label) => label.dataset.id === challenge.id);
        if (matches.length === 0) {
            // Mehr Aufgaben als Felder: ein Feld bekommt die gezogene Aufgabe.
            const label = labels[Math.floor(Math.random() * labels.length)];
            label.dataset.id = challenge.id;
            label.firstElementChild.textContent = challenge.title;
            matches = [label];
        }
        const index = labels.indexOf(matches[Math.floor(Math.random() * matches.length)]);
        const step = 360 / labels.length;
        const jitter = (Math.random() - 0.5) * step * 0.6;
        const target = TURNS * 360 - (index + 0.5) * step + jitter;

        await this.rotate(target);
        this.resultTarget.textContent = `Gezogen: ${challenge.title}`;
        setTimeout(() => window.location.assign(challenge.url), 1200);
    }

    rotate(degrees) {
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const disc = this.discTarget;

        return new Promise((resolve) => {
            const done = () => resolve();
            disc.addEventListener('transitionend', done, { once: true });
            // Falls kein transitionend kommt (z. B. Tab im Hintergrund).
            setTimeout(done, reduced ? 400 : 4600);
            disc.style.transitionDuration = reduced ? '.2s' : '4.2s';
            disc.style.transform = `rotate(${degrees}deg)`;
        });
    }
}
