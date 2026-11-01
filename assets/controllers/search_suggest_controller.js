import { Controller } from '@hotwired/stimulus';

/* Live suggestions under the search box. The search still works as a plain form without JavaScript. */
export default class extends Controller {
    static targets = ['input', 'panel'];
    static values = { url: String };

    disconnect() {
        clearTimeout(this.timer);
        this.request?.abort();
        this.close();
    }

    type() {
        clearTimeout(this.timer);
        this.request?.abort();

        const q = this.inputTarget.value.trim();
        if (q.length < 2) {
            this.close();
            return;
        }

        this.timer = setTimeout(() => this.load(q), 200);
    }

    async load(q) {
        this.request = new AbortController();
        try {
            const response = await fetch(`${this.urlValue}?q=${encodeURIComponent(q)}`, { signal: this.request.signal });
            if (!response.ok) {
                this.close();
                return;
            }
            this.panelTarget.innerHTML = await response.text();
            this.options.forEach((option, index) => { option.id = `${this.panelTarget.id}-${index}`; });
            this.active = -1;
            this.open();
        } catch (error) {
            // A failed or replaced request only loses the dropdown: Enter still searches
            if ('AbortError' !== error.name) {
                this.close();
            }
        }
    }

    key(event) {
        if ('Escape' === event.key) {
            this.close();
            return;
        }
        if (this.panelTarget.classList.contains('hidden')) {
            return;
        }

        if ('ArrowDown' === event.key || 'ArrowUp' === event.key) {
            event.preventDefault();
            const count = this.options.length;
            if (count) {
                const step = 'ArrowDown' === event.key ? 1 : -1;
                this.highlight((this.active + step + count + (this.active < 0 && step < 0 ? 1 : 0)) % count);
            }
        } else if ('Enter' === event.key && this.active >= 0) {
            event.preventDefault();
            this.options[this.active].click();
        }
    }

    clickOutside(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }

    highlight(index) {
        this.options.forEach((option, i) => option.setAttribute('aria-selected', String(i === index)));
        this.active = index;
        this.options[index].scrollIntoView({ block: 'nearest' });
        this.inputTarget.setAttribute('aria-activedescendant', this.options[index].id);
    }

    open() {
        this.panelTarget.classList.remove('hidden');
        this.inputTarget.setAttribute('aria-expanded', 'true');
    }

    close() {
        this.panelTarget.classList.add('hidden');
        this.panelTarget.innerHTML = '';
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.removeAttribute('aria-activedescendant');
        this.active = -1;
    }

    get options() {
        return [...this.panelTarget.querySelectorAll('[role=option]')];
    }
}
