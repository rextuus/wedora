import { Controller } from '@hotwired/stimulus';

/*
 * Lazy Turbo-Frame früher laden: Turbo lädt erst, wenn der Frame sichtbar ist (am Ende der Galerie entsteht
 * dann eine Pause). Hier startet das Laden schon etwa anderthalb Bildschirmhöhen vorher.
 */
export default class extends Controller {
    connect() {
        if (!('IntersectionObserver' in window)) return;
        this.observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    this.element.loading = 'eager';
                    this.disconnect();
                }
            },
            { rootMargin: '0px 0px 150% 0px' },
        );
        this.observer.observe(this.element);
    }

    disconnect() {
        this.observer?.disconnect();
        this.observer = null;
    }
}
