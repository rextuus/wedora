import { Controller } from '@hotwired/stimulus';

/*
 * Fortsetzbarer Upload in Stücken (Gegenstück zu UploadController.php).
 * - höchstens zwei Dateien gleichzeitig
 * - nach Verbindungsabbruch automatisch weiter ab dem Stand des Servers (HEAD)
 * - verständliche Fehlermeldungen vom Server, manuelles Wiederholen
 * - Videos: Anzeige „wird verarbeitet“, bis der Worker fertig ist
 */
const CONCURRENCY = 2;
const AUTO_RETRIES = 4;
const POLL_MS = 4000;

export default class extends Controller {
    static targets = ['input', 'list', 'template', 'summary', 'caption'];
    static values = { endpoint: String, mineEndpoint: String, module: String, challenge: String };

    connect() {
        this.queue = [];
        this.active = 0;
        this.pending = new Map(); // Medien-ID → Listeneintrag (Verarbeitung läuft)
    }

    disconnect() {
        clearTimeout(this.pollTimer);
        clearInterval(this.clock);
    }

    choose() {
        const visibility = this.element.querySelector('input[name="visibility"]:checked')?.value ?? 'all_guests';
        const caption = this.hasCaptionTarget ? this.captionTarget.value.trim() : '';
        for (const file of this.inputTarget.files) {
            const item = this.createItem(file, visibility);
            item.caption = caption;
            this.queue.push(item);
        }
        this.inputTarget.value = '';
        this.pump();
    }

    retry(event) {
        const item = event.target.closest('[data-upload-item]').uploadItem;
        item.attempts = 0;
        this.setState(item, 'waiting', 'Wartet …');
        this.queue.push(item);
        this.pump();
    }

    async cancel(event) {
        const node = event.target.closest('[data-upload-item]');
        const item = node.uploadItem;
        item.cancelled = true;
        item.xhr?.abort();
        if (item.id && item.state !== 'done') {
            await this.request('DELETE', `${this.endpointValue}/${item.id}`).catch(() => {});
        }
        node.remove();
        this.updateSummary();
    }

    createItem(file, visibility) {
        const node = this.templateTarget.content.firstElementChild.cloneNode(true);
        node.querySelector('[data-role=name]').textContent = file.name;
        node.querySelector('[data-role=size]').textContent = formatBytes(file.size);
        this.listTarget.prepend(node);

        const item = {
            node,
            file,
            visibility,
            type: file.type.startsWith('video/') ? 'video' : 'photo',
            id: null,
            offset: 0,
            chunkSize: 8 * 1024 * 1024,
            attempts: 0,
            state: 'waiting',
        };
        node.uploadItem = item;
        this.setState(item, 'waiting', 'Wartet …');

        return item;
    }

    pump() {
        while (this.active < CONCURRENCY && this.queue.length > 0) {
            const item = this.queue.shift();
            if (item.cancelled) {
                continue;
            }
            this.active++;
            this.upload(item).finally(() => {
                this.active--;
                this.pump();
            });
        }
        this.updateSummary();
    }

    async upload(item) {
        try {
            if (!item.id) {
                const response = await this.request('POST', this.endpointValue, JSON.stringify({
                    type: item.type,
                    size: item.file.size,
                    visibility: item.visibility,
                    module: this.moduleValue || 'gallery',
                    challenge: this.challengeValue || null,
                    caption: item.caption || null,
                }), { 'Content-Type': 'application/json' });
                const data = await response.json();
                if (!response.ok) {
                    return this.fail(item, data.error ?? 'Upload nicht möglich.', response.status >= 500 || response.status === 429);
                }
                item.id = data.id;
                item.chunkSize = data.chunkSize;
            } else {
                // Fortsetzen: Stand beim Server erfragen.
                const head = await this.request('HEAD', `${this.endpointValue}/${item.id}`);
                if (head.status === 404) {
                    item.id = null;
                    item.offset = 0;
                    return this.upload(item);
                }
                item.offset = Number(head.headers.get('Upload-Offset') ?? 0);
            }

            while (!item.cancelled) {
                if (item.state !== 'uploading') {
                    this.setState(item, 'uploading', 'Wird hochgeladen …');
                }
                const chunk = item.file.slice(item.offset, item.offset + item.chunkSize);
                const result = await this.sendChunk(item, chunk);

                if (result.status === 204) {
                    item.offset = Number(result.headers['upload-offset']);
                    item.attempts = 0;
                    continue;
                }
                if (result.status === 409) {
                    item.offset = Number(result.body.offset ?? 0);
                    continue;
                }
                if (result.status === 201) {
                    return this.finished(item, result.body.media);
                }
                return this.fail(item, result.body.error ?? 'Upload fehlgeschlagen.', result.status >= 500);
            }
        } catch (error) {
            if (item.cancelled) {
                return;
            }
            // Netzwerkfehler: automatisch mit wachsender Pause erneut versuchen.
            if (item.attempts < AUTO_RETRIES) {
                item.attempts++;
                this.setState(item, 'retrying', `Verbindung unterbrochen – neuer Versuch ${item.attempts}/${AUTO_RETRIES} …`);
                await sleep(1000 * 2 ** item.attempts);
                return this.upload(item);
            }
            return this.fail(item, 'Keine Verbindung. Deine Datei bleibt ausgewählt – versuche es gleich noch einmal.', true);
        }
    }

    sendChunk(item, chunk) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            item.xhr = xhr;
            xhr.open('PATCH', `${this.endpointValue}/${item.id}`);
            xhr.setRequestHeader('Upload-Offset', String(item.offset));
            xhr.setRequestHeader('Content-Type', 'application/offset+octet-stream');
            xhr.setRequestHeader('X-Requested-With', 'wedora');
            xhr.upload.onprogress = (event) => {
                if (event.lengthComputable) {
                    this.progress(item, item.offset + event.loaded);
                }
            };
            // Letztes Stück ist beim Server: er prüft und speichert jetzt die ganze Datei.
            xhr.upload.onload = () => {
                if (item.offset + chunk.size >= item.file.size) {
                    this.setState(item, 'finalizing', item.type === 'video'
                        ? 'Hochgeladen – Video wird geprüft …'
                        : 'Hochgeladen – wird gespeichert …');
                }
            };
            xhr.onload = () => {
                let body = {};
                try { body = xhr.responseText ? JSON.parse(xhr.responseText) : {}; } catch { body = {}; }
                resolve({
                    status: xhr.status,
                    body,
                    headers: { 'upload-offset': xhr.getResponseHeader('Upload-Offset') },
                });
            };
            xhr.onerror = () => reject(new Error('network'));
            xhr.onabort = () => reject(new Error('abort'));
            xhr.send(chunk);
        });
    }

    finished(item, media) {
        this.progress(item, item.file.size);
        if (media.processing === 'processing') {
            item.processingSince = Date.now();
            this.applyEstimate(item, media.estimatedSeconds);
            this.setState(item, 'processing', this.processingText(item));
            this.pending.set(media.id, item);
            this.schedulePoll();
            this.startClock();
        } else if (media.processing === 'failed') {
            this.setState(item, 'error', media.error ?? 'Verarbeitung fehlgeschlagen.');
        } else {
            this.done(item, media);
        }
    }

    done(item, media) {
        item.node.classList.remove('has-estimate');
        this.setState(item, 'done', media.publication === 'pending' ? 'Gespeichert – wird nach Freigabe sichtbar' : 'Gespeichert');
    }

    fail(item, message, retryable) {
        this.setState(item, 'error', message);
        item.node.querySelector('[data-role=retry]').hidden = !retryable;
    }

    // Prognose aus den bisherigen Umwandlungen (Server); null = noch zu wenig Erfahrungswerte.
    applyEstimate(item, remainingSeconds) {
        if (typeof remainingSeconds !== 'number') {
            item.estimatedReadyAt = null;
            return;
        }
        item.estimatedReadyAt = Date.now() + remainingSeconds * 1000;
        item.estimateStartedAt ??= Date.now();
    }

    processingText(item) {
        if (!item.estimatedReadyAt) {
            const seconds = Math.floor((Date.now() - item.processingSince) / 1000);
            return `Video wird umgewandelt (${formatDuration(seconds)}) – das dauert meist ein bis zwei Minuten. Du kannst schon weitermachen.`;
        }

        const remaining = Math.ceil((item.estimatedReadyAt - Date.now()) / 1000);
        const total = Math.max(1, item.estimatedReadyAt - item.estimateStartedAt);
        const percent = Math.min(95, Math.round(((Date.now() - item.estimateStartedAt) / total) * 100));
        const bar = item.node.querySelector('progress');
        bar.value = percent;
        bar.textContent = `${percent} %`;
        item.node.classList.add('has-estimate');

        return remaining > 0
            ? `Video wird umgewandelt – noch etwa ${formatDuration(remaining)}. Du kannst schon weitermachen.`
            : 'Video wird umgewandelt – dauert etwas länger als üblich, gleich fertig …';
    }

    // Laufende Zeitanzeige, damit klar ist, dass noch etwas passiert.
    startClock() {
        if (this.clock) {
            return;
        }
        this.clock = setInterval(() => {
            if (this.pending.size === 0) {
                clearInterval(this.clock);
                this.clock = null;
                return;
            }
            for (const item of this.pending.values()) {
                item.node.querySelector('[data-role=status]').textContent = this.processingText(item);
            }
        }, 1000);
    }

    schedulePoll() {
        clearTimeout(this.pollTimer);
        if (this.pending.size === 0) {
            return;
        }
        this.pollTimer = setTimeout(async () => {
            try {
                const response = await this.request('GET', this.mineEndpointValue);
                const { items } = await response.json();
                for (const media of items) {
                    const item = this.pending.get(media.id);
                    if (!item) {
                        continue;
                    }
                    if (media.processing === 'processing') {
                        // Schätzung nachführen (z. B. wenn die Warteschlange kürzer wird).
                        this.applyEstimate(item, media.estimatedSeconds);
                        continue;
                    }
                    this.pending.delete(media.id);
                    media.processing === 'ready' ? this.done(item, media) : this.setState(item, 'error', media.error);
                }
            } catch {
                // Nächster Versuch beim nächsten Intervall.
            }
            this.schedulePoll();
        }, POLL_MS);
    }

    progress(item, loaded) {
        const percent = Math.min(100, Math.round((loaded / Math.max(1, item.file.size)) * 100));
        const bar = item.node.querySelector('progress');
        bar.value = percent;
        bar.textContent = `${percent} %`;
    }

    setState(item, state, text) {
        item.state = state;
        item.node.dataset.state = state;
        item.node.querySelector('[data-role=status]').textContent = text;
        item.node.querySelector('[data-role=retry]').hidden = true;
        item.node.querySelector('[data-role=cancel]').hidden = ['done', 'processing', 'finalizing'].includes(state);
        item.node.setAttribute('aria-busy', String(['uploading', 'finalizing', 'processing', 'retrying'].includes(state)));
        this.updateSummary();
    }

    updateSummary() {
        if (!this.hasSummaryTarget) {
            return;
        }
        const items = [...this.listTarget.querySelectorAll('[data-upload-item]')].map((n) => n.uploadItem);
        const open = items.filter((i) => ['waiting', 'uploading', 'retrying'].includes(i.state)).length;
        this.summaryTarget.textContent = open > 0
            ? `${open} ${open === 1 ? 'Datei wird' : 'Dateien werden'} hochgeladen – bitte die Seite geöffnet lassen.`
            : '';
    }

    request(method, url, body = null, headers = {}) {
        return fetch(url, {
            method,
            body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'wedora', Accept: 'application/json', ...headers },
        });
    }
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function formatDuration(seconds) {
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

function formatBytes(bytes) {
    return bytes >= 1024 ** 3
        ? `${(bytes / 1024 ** 3).toFixed(1).replace('.', ',')} GB`
        : `${Math.max(1, Math.round(bytes / 1024 ** 2))} MB`;
}
