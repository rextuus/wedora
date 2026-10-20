import { Controller } from '@hotwired/stimulus';

/*
 * Hinweis „Auf den Startbildschirm legen“. Android/Chrome: eigener Knopf (beforeinstallprompt), die App
 * teilt sich dort die Cookies mit dem Browser. iPhone/iPad: Web-Apps vom Home-Bildschirm haben einen
 * eigenen Cookie-Speicher – auf Knopfdruck holen wir einen Einmal-Link und setzen ihn als Adresse, die
 * Safari beim „Zum Home-Bildschirm“ übernimmt. Nach ein paar Minuten wird die Adresse zurückgesetzt,
 * damit der Link nicht versehentlich geteilt wird. In der installierten App ist der Hinweis unsichtbar.
 */
const DISMISSED = 'wedora-install-hint';
const RESET_AFTER_MS = 5 * 60 * 1000;

export default class extends Controller {
    static targets = ['install', 'ios', 'iosReady', 'other'];
    static values = { link: String };

    connect() {
        if (this.standalone() || this.dismissed()) return;

        this.onPrompt = (event) => {
            event.preventDefault();
            this.deferred = event;
            this.show('install');
        };
        window.addEventListener('beforeinstallprompt', this.onPrompt);

        if (/iPhone|iPad|iPod/.test(navigator.userAgent)) {
            this.show('ios');
        } else {
            this.fallback = setTimeout(() => this.deferred || this.show('other'), 1500);
        }
    }

    disconnect() {
        window.removeEventListener('beforeinstallprompt', this.onPrompt);
        clearTimeout(this.fallback);
        this.restoreUrl();
    }

    async install() {
        if (!this.deferred) return;
        this.deferred.prompt();
        const choice = await this.deferred.userChoice;
        this.deferred = null;
        if (choice.outcome === 'accepted') this.dismiss();
    }

    async prepareIos(event) {
        event.currentTarget.disabled = true;
        try {
            const response = await fetch(this.linkValue, {
                method: 'POST',
                headers: { 'X-Requested-With': 'wedora', Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error(String(response.status));
            const data = await response.json();
            this.originalUrl = window.location.pathname + window.location.search;
            history.replaceState(history.state, '', data.url);
            this.resetTimer = setTimeout(() => this.restoreUrl(), RESET_AFTER_MS);
            this.show('iosReady');
        } catch {
            event.currentTarget.disabled = false;
        }
    }

    dismiss() {
        try { localStorage.setItem(DISMISSED, '1'); } catch { /* privates Fenster */ }
        this.element.hidden = true;
        this.restoreUrl();
    }

    show(target) {
        ['install', 'ios', 'iosReady', 'other'].forEach((name) => {
            const has = this[`has${name[0].toUpperCase()}${name.slice(1)}Target`];
            if (has) this[`${name}Target`].hidden = name !== target;
        });
        this.element.hidden = false;
    }

    restoreUrl() {
        clearTimeout(this.resetTimer);
        if (this.originalUrl) {
            history.replaceState(history.state, '', this.originalUrl);
            this.originalUrl = null;
        }
    }

    standalone() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    }

    dismissed() {
        try { return localStorage.getItem(DISMISSED) === '1'; } catch { return false; }
    }
}
