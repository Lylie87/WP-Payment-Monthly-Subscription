/**
 * Checkout Redirect for Subscription Products
 *
 * Handles redirecting to checkout when a subscription product is added to cart via AJAX.
 * This ensures the redirect works even when WooCommerce AJAX add-to-cart is enabled.
 *
 * @package ProcessSubscriptions
 */

(function($) {
    'use strict';

    // Bail if our data isn't available
    if (typeof processSubsCheckout === 'undefined') {
        return;
    }

    var checkoutUrl = processSubsCheckout.checkoutUrl;
    var subscriptionProducts = processSubsCheckout.subscriptionProducts || [];

    // Convert to integers for comparison
    subscriptionProducts = subscriptionProducts.map(function(id) {
        return parseInt(id, 10);
    });

    /**
     * Check if a product ID is a subscription product
     */
    function isSubscriptionProduct(productId) {
        return subscriptionProducts.indexOf(parseInt(productId, 10)) !== -1;
    }

    /**
     * Handle WooCommerce AJAX add to cart event
     *
     * WooCommerce triggers 'added_to_cart' event after successful AJAX add-to-cart.
     * We intercept this and redirect to checkout if it's a subscription product.
     */
    $(document.body).on('added_to_cart', function(event, fragments, cart_hash, $button) {
        var productId = null;

        // Try to get product ID from the button
        if ($button && $button.length) {
            productId = $button.data('product_id') || $button.val();
        }

        // If we couldn't get it from button, check the form
        if (!productId) {
            var $form = $('form.cart');
            if ($form.length) {
                productId = $form.find('button[name="add-to-cart"]').val();
            }
        }

        // If this is a subscription product, redirect to checkout
        if (productId && isSubscriptionProduct(productId)) {
            // Small delay to ensure cart is updated
            setTimeout(function() {
                window.location.href = checkoutUrl;
            }, 100);
        }
    });

    /**
     * Also handle the form submission directly for single product pages
     * This catches cases where AJAX might be disabled or fails
     */
    $(document).on('submit', 'form.cart', function(e) {
        var $form = $(this);
        var $button = $form.find('button[name="add-to-cart"]');
        var productId = $button.val();

        // If it's a subscription product and AJAX is enabled, we'll handle it via added_to_cart
        // But if AJAX fails or is disabled, the PHP redirect will handle it
        // This is just a safety net
    });

    /**
     * Handle click on add-to-cart buttons in product archives/listings
     * These use AJAX add-to-cart by default
     */
    $(document).on('click', '.add_to_cart_button', function(e) {
        var $button = $(this);
        var productId = $button.data('product_id');

        if (productId && isSubscriptionProduct(productId)) {
            // Store flag so we know to redirect after added_to_cart fires
            $button.data('process_subs_redirect', true);
        }
    });

})(jQuery);
