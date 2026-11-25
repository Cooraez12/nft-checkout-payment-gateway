/**
 * Classic (shortcode) checkout flow for NFT Checkout.
 *
 * Place Order is submitted to WooCommerce's own checkout endpoint, so every
 * WooCommerce validation and hook runs server-side. On success RT has
 * e-mailed the voucher, and this script replaces the checkout form with an
 * "order received" panel built from the gateway's response.
 *
 * The block checkout does not use this file: it goes through the Store API
 * (NFT-11, see rt-nft-checkout-blocks.js).
 *
 * NFT-10: this script never rewrites billing fields. Validation of names,
 * addresses and postcodes is the server's job.
 */
(function ($, window, document) {
    'use strict';

    if (window.NFTCheckoutInitialized) {
        return;
    }

    window.NFTCheckoutInitialized = true;

    const CHECK_ICON =
        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M5 12.5l4.5 4.5L19 7.5"></path>' +
        '</svg>';

    const NFTCheckout = {

        PAYMENT_METHOD: rtnfch_params.payment_method,

        state: {
            submitting: false,
            orderId: null,
            button: null,
            buttonText: ''
        },

        /* =========================================================
         * INIT
         * ========================================================= */

        init: function () {

            this.bindClassicCheckout();
        },

        /* =========================================================
         * CLASSIC CHECKOUT
         * ========================================================= */

        bindClassicCheckout: function () {

            const self = this;

            $('form.checkout')
                .off('checkout_place_order_' + self.PAYMENT_METHOD)
                .on(
                    'checkout_place_order_' + self.PAYMENT_METHOD,
                    function () {

                        const $form = $(this);

                        if (self.state.submitting) {
                            return false;
                        }

                        self.clearCheckoutErrors();

                        // Start custom flow
                        self.handleClassicCheckout($form);

                        // STOP WooCommerce default flow
                        return false;
                    }
                );
        },

        handleClassicCheckout: function ($form) {

            const self = this;

            self.state.submitting = true;

            self.state.button = $form
                .find('button[name="woocommerce_checkout_place_order"]');

            self.state.buttonText = self.state.button.text();

            self.state.button
                .prop('disabled', true)
                .addClass('loading')
                .text('Processing...');

            $.ajax({

                type: 'POST',

                url: wc_checkout_params.checkout_url,

                data: $form.serialize(),

                dataType: 'json',

                success: function (response) {

                    self.handleResponse(response);
                },

                error: function () {

                    self.showCheckoutError(
                        'There was an error processing your order.'
                    );

                    self.reset();
                }
            });
        },

        /* =========================================================
         * RESPONSE HANDLER
         * ========================================================= */

        handleResponse: function (response) {

            const self = this;

            try {

                if (typeof response === 'string') {

                    try {
                        response = JSON.parse(response);
                    } catch (e) {

                        self.showCheckoutError('Invalid server response.');

                        self.reset();

                        return;
                    }
                }

                const success =
                    response?.result === 'success' ||
                    response?.success === true;

                const redirect =
                    response.redirect ||
                    response.data?.redirect ||
                    null;

                const errorMessage =
                    response?.message ||
                    response?.messages ||
                    response?.data?.message ||
                    response?.data?.messages ||
                    response?.data?.error ||
                    response?.error ||
                    'Your order could not be placed. Please try again.';

                self.state.orderId =
                    response.order_id ||
                    response.data?.order_id ||
                    null;

                // =====================================================
                // FAILURE
                // =====================================================
                if (!success) {

                    setTimeout(function () {

                        self.showCheckoutError(errorMessage);

                        // force scroll after Woo rerender
                        const $notice = $('.woocommerce-notices-wrapper');

                        if ($notice.length) {
                            $('html, body').animate({
                                scrollTop: $notice.offset().top - 80
                            }, 300);
                        }

                    }, 50);

                    setTimeout(function () {
                        self.reset();
                    }, 400);

                    return;
                }

                // =====================================================
                // SUCCESS — the voucher email is on its way
                // =====================================================
                const orderReceived = response.data?.order_received;

                if (orderReceived) {

                    self.showOrderReceived(orderReceived);

                    self.reset();

                    return;
                }

                if (redirect && typeof redirect === 'string' && redirect.length > 5) {

                    window.location.href = redirect;

                    self.reset(true);

                    return;
                }

                self.showCheckoutError('Your order could not be placed. Please try again.');

                self.reset();

            } catch (e) {

                self.showCheckoutError('Unexpected checkout error.');

                self.reset();
            }
        },

        /* =========================================================
         * ORDER RECEIVED
         * ========================================================= */

        showOrderReceived: function (details) {

            const text = function (value) {
                return document.createTextNode(value);
            };

            const $summary = $('<div>', { 'class': 'rt-nft-checkout-order-received__summary' });

            const row = function (label, value, modifier) {
                return $('<div>', {
                    'class': 'rt-nft-checkout-order-received__row' + (modifier ? ' ' + modifier : '')
                }).append(
                    $('<span>').text(label),
                    $('<span>').text(value)
                );
            };

            // Customer-supplied values are only ever inserted as text.
            $summary.append(row('Order', '#' + (details.order_number || '')));

            // The reference the voucher email prints under the amount. Skipped
            // when it is just the order number again, which is the row above.
            if (details.reference && String(details.reference) !== String(details.order_number || '')) {
                $summary.append(row('Reference', details.reference));
            }

            (details.items || []).forEach(function (item) {
                $summary.append(row(item.label, item.value));
            });

            $summary.append(
                row('Amount due', details.amount_due || '', 'rt-nft-checkout-order-received__row--total')
            );

            const $panel = $('<div>', {
                'class': 'rt-nft-checkout-order-received',
                role: 'status',
                tabindex: '-1'
            }).append(

                $('<div>', {
                    'class': 'rt-nft-checkout-order-received__icon',
                    'aria-hidden': 'true'
                }).html(CHECK_ICON),

                $('<h2>', { 'class': 'rt-nft-checkout-order-received__title' })
                    .text('Order received'),

                // Wording kept in step with rtnfch_voucher_notice_html() (PHP).
                $('<p>', { 'class': 'rt-nft-checkout-order-received__lead' }).text(
                    'As part of our secure checkout process, you will receive an email with a secure link to complete this transaction via the credit/debit card Digital Payment Redemption Voucher (DPRV) process along with an NFT confirmation. The voucher link will allow you to complete payment via a credit or debit card for your order.'
                ),

                $summary,

                this.buildPaymentLinkSection(details),

                $('<ul>', { 'class': 'rt-nft-checkout-dprv__points' }).append(
                    $('<li>').append(
                        $('<strong>').text('Independent voucher & payment partner'),
                        text(' — Your voucher and payment are completed via a separate, independent processing partner. That partner is neither hosted on, embedded in, nor otherwise associated with ' + (details.site_name || 'this shop') + '.')
                    )
                ),

                $('<p>', { 'class': 'rt-nft-checkout-order-received__foot' }).append(
                    text('No further action is needed here - check the inbox at '),
                    $('<strong>').text(details.email || ''),
                    text(' for the DPRV email.')
                )
            );

            // Replace the checkout with the panel.
            const $target = $('form.checkout').first();

            this.clearCheckoutErrors();

            $('.rt-nft-checkout-order-received').remove();

            $('.woocommerce-form-coupon-toggle, .woocommerce-form-login-toggle, form.checkout_coupon, form.woocommerce-form-login').hide();

            if ($target.length) {
                $panel.insertBefore($target);
                $target.hide();
            } else {
                $('body').prepend($panel);
            }

            $('html, body').animate({
                scrollTop: Math.max($panel.offset().top - 80, 0)
            }, 300);

            $panel[0].focus({ preventScroll: true });
        },

        /* =========================================================
         * PAYMENT LINK ("Didn't receive or prefer not to wait for the email?")
         * ========================================================= */

        /**
         * The voucher email's own button, for a customer whose email has not
         * arrived: a plain link to the same RT page, opened in a new tab.
         */
        buildPaymentLinkSection: function (details) {

            let link = this.safeLink(details.purchase_url);

            // Unsigned /voucher/redeem/{uuid} links are rejected by the server.
            if (link && link.indexOf('/voucher/redeem/') !== -1 && link.indexOf('signature=') === -1) {
                link = '';
            }

            if (!link) {
                return '';
            }

            const text = function (value) {
                return document.createTextNode(value);
            };

            // A real link, not window.open: the browser opens it from the
            // click itself, so a popup blocker never stops it.
            return $('<div>', { 'class': 'rt-nft-checkout-order-received__link' }).append(
                $('<p>').text("Didn't receive or prefer not to wait for the email?"),
                $('<a>', {
                    'class': 'button rt-nft-checkout-dprv__button',
                    href: link,
                    target: '_blank',
                    rel: 'noopener noreferrer'
                }).append(
                    text('CLICK HERE'),
                    $('<span>', { 'class': 'rt-nft-checkout-order-received__sr-only' })
                        .text(' (opens in a new tab)')
                )
            );
        },

        /**
         * A payment link fit to show and open: https on RT's own host
         * (rtnfch_params.rt_host), or nothing. Same rule as rtnfch_voucher_link().
         */
        safeLink: function (value) {

            if (typeof value !== 'string' || !value) {
                return '';
            }

            try {
                const url = new URL(value);
                const host = String(rtnfch_params.rt_host || '').toLowerCase();

                return url.protocol === 'https:' && host !== '' && url.hostname.toLowerCase() === host ? url.href : '';
            } catch (e) {
                return '';
            }
        },

        /* =========================================================
         * UI
         * ========================================================= */

        showCheckoutError: function (message, fields = []) {

            // Clear previous notices first
            $('.woocommerce-notices-wrapper').remove();

            const $wrapper = $('<div>', { 'class': 'woocommerce-notices-wrapper rt-nft-checkout-error-wrap' });
            const $box = $('<div>', { 'class': 'woocommerce-error rt-nft-checkout-error-box', 'role': 'alert' });
            const $header = $('<div>', { 'class': 'rt-nft-checkout-error-header' });
            const $strong = $('<strong>').text(message || '');

            $header.append($strong);
            $box.append($header);

            if (fields && fields.length) {
                const $ul = $('<ul>', { 'class': 'rt-nft-checkout-error-fields' });
                fields.forEach(function (field) {
                    $ul.append($('<li>').text(field));
                });
                $box.append($ul);
            }

            $wrapper.append($box);

            const classicTarget = $('form.checkout');

            if (classicTarget.length) {
                classicTarget.prepend($wrapper);
            } else {
                $('body').prepend($wrapper);
            }

            // Scroll to top notice
            const $notice = $('.woocommerce-notices-wrapper');

            if ($notice.length) {

                $('html, body').animate({
                    scrollTop: $notice.offset().top - 80
                }, 300);
            }
        },

        clearCheckoutErrors: function () {

            $('.woocommerce-notices-wrapper').remove();

            $('.woocommerce-error').remove();

            $('.woocommerce-message').remove();

            $('.woocommerce-info').remove();
        },

        reset: function (keepDisabled = false) {

            this.state.submitting = false;

            const $button = $(
                'button[name="woocommerce_checkout_place_order"]'
            );

            if (!$button.length) {
                return;
            }

            if (keepDisabled) {

                $button
                    .prop('disabled', true)
                    .addClass('loading')
                    .text('Processing...');

                return;
            }

            $button
                .prop('disabled', false)
                .removeClass('loading')
                .text(
                    this.state.buttonText || 'Place order'
                );
        }
    };

    $(document).ready(function () {

        NFTCheckout.init();
    });

})(jQuery, window, document);
