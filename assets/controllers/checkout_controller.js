import { Controller } from '@hotwired/stimulus';

/* Shows the server's own totals for the current checkout choices, using the same quote as placing the order. */
export default class extends Controller {
    static targets = ['subtotal', 'shipping', 'discount', 'total', 'messages'];
    static values = { url: String };

    connect() {
        this.refresh();
    }

    async refresh() {
        const field = (name) => this.element.querySelector(`[name$="[${name}]"]`)?.value ?? '';
        const body = new URLSearchParams({
            addressId: field('address'),
            shippingMethod: field('shippingMethod'),
            paymentMethod: field('paymentMethod'),
            voucherCode: field('discountCode'),
        });

        const response = await fetch(this.urlValue, { method: 'POST', body });
        if (!response.ok) {
            return;
        }

        const quote = await response.json();
        const peso = (value) => '₱' + Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        this.subtotalTarget.textContent = peso(quote.subtotal);
        this.shippingTarget.textContent = peso(quote.shippingFee);
        this.discountTarget.textContent = peso(quote.discount);
        this.totalTarget.textContent = peso(quote.total);
        this.messagesTarget.textContent = quote.messages.join(' ');
    }
}
