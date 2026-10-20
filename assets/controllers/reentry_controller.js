import { Controller } from '@hotwired/stimulus';

/*
 * Erster Start der Hochzeit vom Startbildschirm: Der Einmal-Code steht im Fragment der Startadresse (#t=…),
 * wird per POST gegen ein Gast-Cookie getauscht und sofort aus der Adresse entfernt.
 */
export default class extends Controller {
    static targets = ['error'];
    static values = { url: String, home: String };

    async connect() {
        const token = new URLSearchParams(window.location.hash.slice(1)).get('t');
        history.replaceState(null, '', window.location.pathname);
        if (!token) {
            window.location.replace(this.homeValue);
            return;
        }

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'X-Requested-With': 'wedora', Accept: 'application/json' },
                body: new URLSearchParams({ token }),
                credentials: 'same-origin',
            });
            if (response.ok) {
                const data = await response.json();
                window.location.replace(data.redirect || this.homeValue);
                return;
            }
        } catch {
            // Offline o. Ä.: Hinweis anzeigen.
        }
        this.errorTarget.hidden = false;
    }
}
