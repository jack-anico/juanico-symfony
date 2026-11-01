import { Controller } from '@hotwired/stimulus';

/* Opens and closes the mobile menu drawer. */
export default class extends Controller {
    static targets = ['panel'];

    open() {
        this.panelTarget.classList.remove('hidden');
    }

    close() {
        this.panelTarget.classList.add('hidden');
    }
}
