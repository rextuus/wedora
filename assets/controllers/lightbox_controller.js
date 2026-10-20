import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';

/*
 * Vollbild-Ansicht für Fotos und Videos mit Wischen (Scroll-Snap), Pfeiltasten und Esc.
 * Progressive Verbesserung: Jede Kachel ist ein normaler Link zur Detailseite; mit JavaScript
 * öffnet sie stattdessen diese Ansicht. Die Zurück-Taste des Handys schließt sie wieder.
 */
export default class extends Controller {
    static targets = ['tile'];
    static values = { token: String };

    connect() {
        this.onKey = this.onKey.bind(this);
        this.onPop = this.onPop.bind(this);
        this.onBeforeCache = this.onBeforeCache.bind(this);
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
        this.close(false);
    }

    /*
     * Turbo speichert beim Zurück eine Momentaufnahme der Seite und rendert sie wieder. Eine gerade
     * ausblendende Vollbild-Ansicht darf darin nicht landen – sonst bleibt eine tote Kopie stehen,
     * deren Schließen-Knopf nichts mehr tut.
     */
    onBeforeCache() {
        this.close(false);
        document.querySelectorAll('body > .lightbox').forEach((element) => element.remove());
        document.body.classList.remove('has-lightbox');
    }

    open(event) {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1) {
            return; // Neuer Tab: normaler Link.
        }
        event.preventDefault();
        this.items = this.tileTargets.map((tile) => ({ ...tile.dataset, href: tile.getAttribute('href') }));
        this.trigger = event.currentTarget;
        this.build();
        this.show(this.tileTargets.indexOf(event.currentTarget));
        this.animateOpen(event.currentTarget);
        history.pushState({ lightbox: true }, '');
        window.addEventListener('popstate', this.onPop);
        document.addEventListener('keydown', this.onKey);
    }

    build() {
        const root = document.createElement('div');
        root.className = 'lightbox';
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-label', 'Fotoansicht');
        root.innerHTML = `
            <div class="lightbox__bar">
                <span class="lightbox__count" aria-live="polite"></span>
                <button type="button" class="lightbox__close" aria-label="Schließen">${icon('close')}</button>
            </div>
            <button type="button" class="lightbox__nav lightbox__nav--prev" aria-label="Vorheriges">${icon('prev')}</button>
            <div class="lightbox__track" tabindex="0" aria-label="Fotos und Videos – wischen oder Pfeiltasten zum Blättern"></div>
            <button type="button" class="lightbox__nav lightbox__nav--next" aria-label="Nächstes">${icon('next')}</button>
            <div class="lightbox__meta">
                <div class="lightbox__text"><p class="lightbox__who"></p><p class="lightbox__when"></p><p class="lightbox__caption"></p></div>
                <div class="lightbox__actions">
                    <button type="button" class="lightbox__like" aria-pressed="false"></button>
                    <a class="lightbox__details">Details</a>
                </div>
            </div>`;

        this.track = root.querySelector('.lightbox__track');
        this.items.forEach((item, index) => {
            const slide = document.createElement('div');
            slide.className = 'lightbox__slide';
            slide.dataset.index = String(index);
            this.track.append(slide);
        });

        root.querySelector('.lightbox__close').addEventListener('click', () => history.back());
        root.querySelector('.lightbox__nav--prev').addEventListener('click', () => this.go(this.index - 1));
        root.querySelector('.lightbox__nav--next').addEventListener('click', () => this.go(this.index + 1));
        root.querySelector('.lightbox__like').addEventListener('click', () => this.like());
        // Zur Detailseite erst nach dem Schließen, damit der Verlaufseintrag dieser Ansicht nicht übrig bleibt.
        root.querySelector('.lightbox__details').addEventListener('click', (event) => {
            event.preventDefault();
            this.pendingVisit = event.currentTarget.href;
            history.back();
        });
        this.track.addEventListener('scroll', () => {
            clearTimeout(this.scrollTimer);
            this.scrollTimer = setTimeout(() => this.settle(), 60);
        }, { passive: true });

        document.body.append(root);
        document.body.classList.add('has-lightbox');
        this.root = root;
    }

    /*
     * Öffnen: Das Vorschaubild wächst von der Kachel auf seine Vollbildgröße, der Hintergrund blendet ein.
     */
    animateOpen(tile) {
        const thumb = tile.querySelector('.tile__img');
        if (!thumb || !thumb.naturalWidth || matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        const from = thumb.getBoundingClientRect();
        const scale = Math.min(window.innerWidth / thumb.naturalWidth, window.innerHeight / thumb.naturalHeight);
        const width = thumb.naturalWidth * scale;
        const height = thumb.naturalHeight * scale;
        const to = { left: (window.innerWidth - width) / 2, top: (window.innerHeight - height) / 2, width, height };

        const ghost = thumb.cloneNode();
        ghost.className = 'lightbox__ghost';
        Object.assign(ghost.style, { left: `${to.left}px`, top: `${to.top}px`, width: `${to.width}px`, height: `${to.height}px` });
        this.root.append(ghost);
        this.track.style.opacity = '0';

        const dx = from.left - to.left;
        const dy = from.top - to.top;
        const options = { duration: 320, easing: 'cubic-bezier(.2,.7,.2,1)' };
        this.root.animate([{ backgroundColor: 'rgba(0,0,0,0)' }, { backgroundColor: 'rgba(0,0,0,1)' }], options);
        this.root.querySelectorAll('.lightbox__bar, .lightbox__meta').forEach((el) => el.animate([{ opacity: 0 }, { opacity: 1 }], { ...options, delay: 120, fill: 'backwards' }));
        ghost.animate([
            { transform: `translate(${dx}px, ${dy}px) scale(${from.width / to.width}, ${from.height / to.height})`, borderRadius: '2px' },
            { transform: 'none', borderRadius: '0' },
        ], options).finished.then(() => {
            this.track.style.opacity = '';
            ghost.remove();
        });
    }

    show(index) {
        this.index = Math.max(0, Math.min(index, this.items.length - 1));
        this.track.scrollTo({ left: this.index * this.track.clientWidth, behavior: 'instant' });
        this.render();
        this.root.querySelector('.lightbox__close').focus();
    }

    go(index) {
        if (index < 0 || index >= this.items.length) {
            return;
        }
        this.track.scrollTo({ left: index * this.track.clientWidth, behavior: 'smooth' });
    }

    settle() {
        const index = Math.round(this.track.scrollLeft / Math.max(1, this.track.clientWidth));
        if (index !== this.index) {
            this.index = index;
            this.render();
        }
    }

    // Aktuelles Element plus Nachbarn laden, Meta-Leiste aktualisieren, andere Videos anhalten.
    render() {
        [this.index - 1, this.index, this.index + 1].forEach((i) => this.load(i));
        this.track.querySelectorAll('video').forEach((video) => {
            if (Number(video.closest('.lightbox__slide').dataset.index) !== this.index) {
                video.pause();
            }
        });

        const item = this.items[this.index];
        const root = this.root;
        root.querySelector('.lightbox__count').textContent = `${this.index + 1} / ${this.items.length}`;
        root.querySelector('.lightbox__who').textContent = item.uploader ?? '';
        root.querySelector('.lightbox__when').textContent = item.when ?? '';
        root.querySelector('.lightbox__caption').textContent = item.caption ?? '';
        root.querySelector('.lightbox__details').href = item.href;
        root.querySelector('.lightbox__nav--prev').hidden = this.index === 0;
        root.querySelector('.lightbox__nav--next').hidden = this.index === this.items.length - 1;
        this.renderLike(item);
    }

    load(index) {
        const item = this.items[index];
        const slide = this.track?.children[index];
        if (!item || !slide || slide.childElementCount > 0) {
            return;
        }
        if (item.video) {
            const video = document.createElement('video');
            video.controls = true;
            video.playsInline = true;
            video.preload = 'metadata';
            video.poster = item.src;
            video.src = item.video;
            slide.append(video);
        } else {
            const img = document.createElement('img');
            img.src = item.src;
            img.alt = item.alt ?? '';
            img.decoding = 'async';
            slide.append(img);
        }
    }

    renderLike(item) {
        const button = this.root.querySelector('.lightbox__like');
        button.hidden = !item.likeUrl;
        const liked = item.liked === '1';
        button.classList.toggle('is-liked', liked);
        button.setAttribute('aria-pressed', String(liked));
        button.setAttribute('aria-label', `${liked ? 'Gefällt mir nicht mehr' : 'Gefällt mir'} (${item.likes ?? 0})`);
        button.innerHTML = `${icon(liked ? 'heart-filled' : 'heart')}<span>${item.likes ?? 0}</span>`;
    }

    async like() {
        const item = this.items[this.index];
        const body = new FormData();
        body.append('_token', this.tokenValue);
        try {
            const response = await fetch(item.likeUrl, { method: 'POST', body, headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            const data = await response.json();
            item.liked = data.liked ? '1' : '0';
            item.likes = String(data.count);
            // Kachel im Raster aktuell halten.
            const tile = this.tileTargets[this.index];
            tile.dataset.liked = item.liked;
            tile.dataset.likes = item.likes;
            this.renderLike(item);
        } catch {
            // Offline: Like bleibt unverändert.
        }
    }

    onKey(event) {
        if (event.key === 'Escape') {
            history.back();
        } else if (event.key === 'ArrowRight') {
            this.go(this.index + 1);
        } else if (event.key === 'ArrowLeft') {
            this.go(this.index - 1);
        }
    }

    onPop() {
        const target = this.pendingVisit;
        this.pendingVisit = null;
        this.close(!target);
        if (target) {
            visit(target);
        }
    }

    close(restoreFocus) {
        if (!this.root) {
            return;
        }
        const root = this.root;
        root.querySelectorAll('video').forEach((video) => video.pause());
        this.root = null;
        if (matchMedia('(prefers-reduced-motion: reduce)').matches) {
            root.remove();
        } else {
            root.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 180, easing: 'ease-out' }).finished.then(() => root.remove());
        }
        document.body.classList.remove('has-lightbox');
        document.removeEventListener('keydown', this.onKey);
        window.removeEventListener('popstate', this.onPop);
        if (restoreFocus) {
            this.tileTargets[this.index]?.focus();
        }
    }
}

function icon(name) {
    const paths = {
        close: '<path d="M6 6l12 12M18 6 6 18"/>',
        prev: '<path d="M15 5 8 12l7 7"/>',
        next: '<path d="m9 5 7 7-7 7"/>',
        heart: '<path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/>',
        'heart-filled': '<path fill="currentColor" d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/>',
    };
    return `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name]}</svg>`;
}
