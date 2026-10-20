import { Controller } from '@hotwired/stimulus';

/*
 * Gästewand: Porträt antippen → groß im Popup (<dialog>) mit Namen. Das Bild wird aus der Kachel übernommen,
 * es wird also nichts nachgeladen. Schließt per Knopf, Escape oder Tippen neben das Fenster.
 */
export default class extends Controller {
    static targets = ['dialog', 'art', 'name'];

    open(event) {
        const art = event.currentTarget.querySelector('[data-art]');
        this.artTarget.innerHTML = art ? art.innerHTML : '';
        this.nameTarget.textContent = event.params.name ?? '';
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    backdrop(event) {
        if (event.target === this.dialogTarget) {
            this.close();
        }
    }
}
