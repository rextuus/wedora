import { Controller } from '@hotwired/stimulus';

/*
 * „Zu viel am Handy?“: Die kleine Figur ist zunächst versteckt und taucht erst auf, wenn die Bedingung erfüllt ist:
 *  - „time“: nach `threshold` Minuten sichtbarer Zeit in der App. Gezählt wird nur hier im Browser
 *    (localStorage), an den Server geht nichts.
 *  - „activity“: wenn der Gast in der letzten Stunde mindestens `threshold` Beiträge hochgeladen hat
 *    (`recent` zählt der Server).
 * Sie bleibt (auch über Seitenwechsel), bis man sie antippt; dann kommt der Hinweis als Popup, die Figur
 * verschwindet und taucht frühestens nach einer Stunde wieder auf.
 */
const TICK_SECONDS = 15;
const PAUSE_MS = 60 * 60 * 1000;

export default class extends Controller {
    static targets = ['dialog', 'trigger'];
    static values = { mode: String, threshold: Number, recent: Number, key: String };

    connect() {
        if (this.modeValue === 'off' || this.thresholdValue <= 0) {
            return;
        }

        const state = this.read();
        if (state.visible) {
            this.reveal(state);
        } else if (this.modeValue === 'activity' && this.recentValue >= this.thresholdValue) {
            this.reveal(state);
        } else if (this.modeValue === 'time') {
            this.timer = setInterval(() => this.tick(), TICK_SECONDS * 1000);
        }
    }

    disconnect() {
        clearInterval(this.timer);
    }

    open() {
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    /** Hinweis gelesen: Figur weg, Zeit von vorn, eine Stunde Ruhe. */
    closed() {
        const state = this.read();
        if (state.visible) {
            this.write({ seconds: 0, shownAt: Date.now(), visible: false });
        }
        this.triggerTarget.hidden = true;
    }

    backdrop(event) {
        if (event.target === this.dialogTarget) {
            this.close();
        }
    }

    tick() {
        if (document.visibilityState !== 'visible') {
            return;
        }

        const state = this.read();
        state.seconds = (state.seconds ?? 0) + TICK_SECONDS;
        this.write(state);
        if (state.seconds >= this.thresholdValue * 60) {
            this.reveal(state);
        }
    }

    reveal(state) {
        if (!state.visible && state.shownAt && Date.now() - state.shownAt < PAUSE_MS) {
            return;
        }

        clearInterval(this.timer);
        this.write({ ...state, visible: true });
        this.triggerTarget.hidden = false;
    }

    read() {
        try {
            return JSON.parse(localStorage.getItem(this.keyValue) ?? '{}') ?? {};
        } catch {
            return {};
        }
    }

    write(state) {
        try {
            localStorage.setItem(this.keyValue, JSON.stringify(state));
        } catch {
            // Ohne Speicher (privater Modus) bleibt die Figur eben bis zum nächsten Seitenwechsel.
        }
    }
}
