import { Controller } from '@hotwired/stimulus';

/*
 * Ruhige Bewegung in Bilderlisten:
 * - Blur-up: Das scharfe Bild blendet über die unscharfe Vorschau, sobald es geladen ist.
 * - Kacheln gleiten beim Scrollen sanft ins Bild (leicht gestaffelt).
 * Mit „Bewegung reduzieren“ passiert nichts davon; ohne JavaScript sind die Bilder sofort sichtbar.
 */
export default class extends Controller {
    connect() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }
        this.element.classList.add('has-motion');

        this.observer = new IntersectionObserver((entries) => {
            let batch = 0;
            for (const entry of entries) {
                if (!entry.isIntersecting) {
                    continue;
                }
                entry.target.style.setProperty('--delay', `${Math.min(batch++, 5) * 45}ms`);
                entry.target.classList.add('is-visible');
                this.observer.unobserve(entry.target);
            }
        }, { rootMargin: '0px 0px -5% 0px' });

        this.prepare(this.element);
        // „Mehr laden“ (Turbo-Frame) bringt neue Kacheln mit.
        this.mutations = new MutationObserver((records) => records.forEach((r) => r.addedNodes.forEach((node) => {
            if (node instanceof HTMLElement) {
                this.prepare(node);
            }
        })));
        this.mutations.observe(this.element, { childList: true, subtree: true });
    }

    disconnect() {
        this.observer?.disconnect();
        this.mutations?.disconnect();
    }

    prepare(root) {
        const tiles = root.matches?.('.tile') ? [root] : root.querySelectorAll('.tile');
        tiles.forEach((tile) => {
            if (tile.dataset.motion) {
                return;
            }
            tile.dataset.motion = '1';
            const img = tile.querySelector('.tile__img');
            const loaded = () => tile.classList.add('is-loaded');
            if (!img || (img.complete && img.naturalWidth > 0)) {
                loaded();
            } else {
                img.addEventListener('load', loaded, { once: true });
                img.addEventListener('error', loaded, { once: true });
            }
            this.observer.observe(tile);
        });
    }
}
