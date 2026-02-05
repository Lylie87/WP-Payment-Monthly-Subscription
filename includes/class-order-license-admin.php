<?php
/**
 * Order License Admin UI
 *
 * Adds license management metabox to WooCommerce order admin pages.
 * Allows admins to view, cancel subscriptions, refund, and revoke licenses.
 *
 * @package ProcessSubscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Order License Admin Class
 */
class Process_Order_License_Admin {

    /**
     * Instance
     *
     * @var Process_Order_License_Admin
     */
    private static $instance = null;

    /**
     * Get instance
     *
     * @return Process_Order_License_Admin
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Add metabox to order page
        add_action( 'add_meta_boxes', array( $this, 'add_license_metabox' ), 5 );

        // AJAX handlers
        add_action( 'wp_ajax_process_admin_cancel_subscription', array( $this, 'ajax_cancel_subscription' ) );
        add_action( 'wp_ajax_process_revoke_license', array( $this, 'ajax_revoke_license' ) );
        add_action( 'wp_ajax_process_reactivate_license', array( $this, 'ajax_reactivate_license' ) );

        // Enqueue admin scripts
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
    }

    /**
     * Enqueue admin scripts on order pages
     *
     * @param string $hook Current admin page.
     */
    public function enqueue_admin_scripts( $hook ) {
        // Check if we're on an order edit page
        if ( ! in_array( $hook, array( 'post.php', 'woocommerce_page_wc-orders' ), true ) ) {
            return;
        }

        // Add inline styles for the modal
        wp_add_inline_style( 'woocommerce_admin_styles', $this->get_modal_styles() );
    }

    /**
     * Get modal CSS styles
     *
     * @return string CSS styles.
     */
    private function get_modal_styles() {
        return '
            .process-subscription-modal-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.7);
                z-index: 100000;
                display: none;
                align-items: center;
                justify-content: center;
            }
            .process-subscription-modal-overlay.active {
                display: flex;
            }
            .process-subscription-modal {
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.3);
                max-width: 500px;
                width: 90%;
                max-height: 90vh;
                overflow-y: auto;
            }
            .process-subscription-modal-header {
                padding: 20px;
                border-bottom: 1px solid #ddd;
                background: #f8f9fa;
                border-radius: 8px 8px 0 0;
            }
            .process-subscription-modal-header h2 {
                margin: 0;
                font-size: 18px;
                color: #1d2327;
            }
            .process-subscription-modal-body {
                padding: 20px;
            }
            .process-subscription-modal-footer {
                padding: 15px 20px;
                border-top: 1px solid #ddd;
                background: #f8f9fa;
                border-radius: 0 0 8px 8px;
                display: flex;
                justify-content: flex-end;
                gap: 10px;
            }
            .process-modal-field {
                margin-bottom: 20px;
            }
            .process-modal-field label {
                display: block;
                font-weight: 600;
                margin-bottom: 8px;
                color: #1d2327;
            }
            .process-modal-field input[type="number"],
            .process-modal-field input[type="text"] {
                width: 100%;
                padding: 8px 12px;
                border: 1px solid #8c8f94;
                border-radius: 4px;
                font-size: 14px;
            }
            .process-modal-field .description {
                margin-top: 5px;
                color: #646970;
                font-size: 12px;
            }
            .process-refund-methods {
                display: flex;
                gap: 10px;
                margin-top: 10px;
            }
            .process-refund-method {
                flex: 1;
                padding: 15px;
                border: 2px solid #ddd;
                border-radius: 6px;
                cursor: pointer;
                text-align: center;
                transition: all 0.2s;
            }
            .process-refund-method:hover {
                border-color: #2271b1;
            }
            .process-refund-method.selected {
                border-color: #2271b1;
                background: #f0f6fc;
            }
            .process-refund-method input {
                display: none;
            }
            .process-refund-method-title {
                font-weight: 600;
                margin-bottom: 5px;
            }
            .process-refund-method-desc {
                font-size: 12px;
                color: #646970;
            }
            .process-license-option {
                padding: 15px;
                background: #fff8e5;
                border: 1px solid #ffcc00;
                border-radius: 6px;
                margin-top: 15px;
            }
            .process-license-option label {
                display: flex;
                align-items: center;
                gap: 10px;
                cursor: pointer;
                font-weight: normal;
                margin: 0;
            }
            .process-license-option input[type="checkbox"] {
                width: 18px;
                height: 18px;
            }
            .process-order-summary {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 6px;
                margin-bottom: 20px;
            }
            .process-order-summary-row {
                display: flex;
                justify-content: space-between;
                padding: 5px 0;
            }
            .process-order-summary-row.total {
                border-top: 1px solid #ddd;
                margin-top: 10px;
                padding-top: 10px;
                font-weight: 600;
            }
            .btn-cancel-subscription {
                background: #dc3545 !important;
                border-color: #dc3545 !important;
                color: #fff !important;
                padding: 8px 16px !important;
                font-size: 13px !important;
                font-weight: 600 !important;
                border-radius: 4px !important;
                cursor: pointer !important;
                width: 100%;
                text-align: center;
                margin-bottom: 10px;
            }
            .btn-cancel-subscription:hover {
                background: #bb2d3b !important;
                border-color: #bb2d3b !important;
            }
            .btn-cancel-subscription:disabled {
                opacity: 0.6;
                cursor: not-allowed !important;
            }
            .btn-reactivate-license {
                background: #28a745 !important;
                border-color: #28a745 !important;
                color: #fff !important;
                padding: 8px 16px !important;
                font-size: 13px !important;
                font-weight: 600 !important;
                border-radius: 4px !important;
                cursor: pointer !important;
                width: 100%;
                text-align: center;
            }
            .btn-reactivate-license:hover {
                background: #218838 !important;
                border-color: #218838 !important;
            }
            .process-modal-btn-confirm {
                background: #dc3545;
                border: none;
                color: #fff;
                padding: 10px 20px;
                border-radius: 4px;
                cursor: pointer;
                font-weight: 600;
            }
            .process-modal-btn-confirm:hover {
                background: #bb2d3b;
            }
            .process-modal-btn-confirm:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            .process-modal-btn-cancel {
                background: #fff;
                border: 1px solid #8c8f94;
                color: #1d2327;
                padding: 10px 20px;
                border-radius: 4px;
                cursor: pointer;
            }
            .process-modal-btn-cancel:hover {
                background: #f0f0f1;
            }
            .process-license-status {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: 600;
            }
            .process-license-status.active,
            .process-license-status.inactive {
                background: #d4edda;
                color: #155724;
            }
            .process-license-status.revoked {
                background: #f8d7da;
                color: #721c24;
            }
            .process-license-status.suspended {
                background: #fff3cd;
                color: #856404;
            }
            .process-license-key {
                font-family: monospace;
                font-size: 13px;
                background: #f0f0f1;
                padding: 8px 12px;
                border-radius: 4px;
                margin: 10px 0;
                word-break: break-all;
            }
        ';
    }

    /**
     * Add license management metabox
     */
    public function add_license_metabox() {
        $screen = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id( 'shop-order' )
            : 'shop_order';

        add_meta_box(
            'process_subscription_license',
            __( 'Subscription & License', 'process-subscriptions' ),
            array( $this, 'render_license_metabox' ),
            $screen,
            'side',
            'high'
        );
    }

    /**
     * Render license metabox
     *
     * @param WP_Post|WC_Order $post_or_order Post or order object.
     */
    public function render_license_metabox( $post_or_order ) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );

        if ( ! $order ) {
            echo '<p>' . esc_html__( 'Order not found.', 'process-subscriptions' ) . '</p>';
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'process_subscriptions';

        // Get subscriptions for this order
        $subscriptions = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE order_id = %d",
            $order->get_id()
        ), ARRAY_A );

        if ( empty( $subscriptions ) ) {
            echo '<p style="color: #666; font-style: italic;">' . esc_html__( 'No subscriptions found for this order.', 'process-subscriptions' ) . '</p>';
            return;
        }

        $nonce = wp_create_nonce( 'process_license_action' );

        foreach ( $subscriptions as $subscription ) {
            $license_key = $subscription['license_key'] ?? '';
            if ( empty( $license_key ) ) {
                $license_key = $order->get_meta( '_subscription_license_key_' . $subscription['id'] );
            }

            $product = wc_get_product( $subscription['product_id'] );
            $product_name = $product ? $product->get_name() : 'Product #' . $subscription['product_id'];
            $license_status = $license_key ? $this->get_license_status( $license_key ) : 'none';
            $sub_status = $subscription['status'] ?? 'unknown';
            $is_active = in_array( $sub_status, array( 'active', 'pending', 'trialing' ), true );
            $amount = floatval( $subscription['amount'] ?? 0 );
            $currency = $subscription['currency'] ?? 'GBP';

            // Get refund info
            $total_refunded = $order->get_total_refunded();
            $order_total = $order->get_total();
            $available_to_refund = $order_total - $total_refunded;

            echo '<div class="process-subscription-item" style="margin-bottom: 15px;">';

            // Product name and subscription status
            echo '<div style="margin-bottom: 10px;">';
            echo '<strong>' . esc_html( $product_name ) . '</strong><br>';
            echo '<span style="color: #666; font-size: 12px;">Subscription #' . esc_html( $subscription['id'] ) . '</span>';
            echo '</div>';

            // License info
            if ( $license_key ) {
                echo '<div class="process-license-key">' . esc_html( $license_key ) . '</div>';
                echo '<div style="margin-bottom: 15px;">';
                echo '<span class="process-license-status ' . esc_attr( $license_status ) . '">';
                echo esc_html( ucfirst( $license_status ) );
                echo '</span>';
                echo '</div>';
            } else {
                echo '<p style="color: #666; font-style: italic; margin-bottom: 15px;">' . esc_html__( 'No license key generated', 'process-subscriptions' ) . '</p>';
            }

            // Action buttons
            if ( $is_active && in_array( $license_status, array( 'active', 'inactive' ), true ) ) {
                // Cancel/Refund button for active subscriptions
                echo '<button type="button" class="button btn-cancel-subscription" ';
                echo 'data-subscription="' . esc_attr( $subscription['id'] ) . '" ';
                echo 'data-order="' . esc_attr( $order->get_id() ) . '" ';
                echo 'data-license="' . esc_attr( $license_key ) . '" ';
                echo 'data-product="' . esc_attr( $product_name ) . '" ';
                echo 'data-amount="' . esc_attr( $amount ) . '" ';
                echo 'data-currency="' . esc_attr( $currency ) . '" ';
                echo 'data-total="' . esc_attr( $order_total ) . '" ';
                echo 'data-refunded="' . esc_attr( $total_refunded ) . '" ';
                echo 'data-available="' . esc_attr( $available_to_refund ) . '" ';
                echo 'data-stripe-sub="' . esc_attr( $subscription['stripe_subscription_id'] ?? '' ) . '">';
                echo esc_html__( 'Cancel & Refund', 'process-subscriptions' );
                echo '</button>';
            } elseif ( in_array( $license_status, array( 'revoked', 'suspended' ), true ) ) {
                // Reactivate button for revoked/suspended licenses
                echo '<button type="button" class="button btn-reactivate-license" ';
                echo 'data-license="' . esc_attr( $license_key ) . '" ';
                echo 'data-subscription="' . esc_attr( $subscription['id'] ) . '" ';
                echo 'data-order="' . esc_attr( $order->get_id() ) . '">';
                echo esc_html__( 'Reactivate License', 'process-subscriptions' );
                echo '</button>';
            } elseif ( ! $is_active ) {
                echo '<p style="color: #666; font-size: 12px;">Subscription status: ' . esc_html( ucfirst( $sub_status ) ) . '</p>';
            }

            echo '</div>';
        }

        // Modal HTML
        $this->render_modal();

        // JavaScript
        $this->render_scripts( $order, $nonce );
    }

    /**
     * Render the modal HTML
     */
    private function render_modal() {
        ?>
        <div class="process-subscription-modal-overlay" id="process-cancel-modal">
            <div class="process-subscription-modal">
                <div class="process-subscription-modal-header">
                    <h2><?php esc_html_e( 'Cancel Subscription & Refund', 'process-subscriptions' ); ?></h2>
                </div>
                <div class="process-subscription-modal-body">
                    <div class="process-order-summary">
                        <div class="process-order-summary-row">
                            <span><?php esc_html_e( 'Product:', 'process-subscriptions' ); ?></span>
                            <span id="modal-product-name"></span>
                        </div>
                        <div class="process-order-summary-row">
                            <span><?php esc_html_e( 'Order Total:', 'process-subscriptions' ); ?></span>
                            <span id="modal-order-total"></span>
                        </div>
                        <div class="process-order-summary-row">
                            <span><?php esc_html_e( 'Already Refunded:', 'process-subscriptions' ); ?></span>
                            <span id="modal-already-refunded"></span>
                        </div>
                        <div class="process-order-summary-row total">
                            <span><?php esc_html_e( 'Available to Refund:', 'process-subscriptions' ); ?></span>
                            <span id="modal-available-refund"></span>
                        </div>
                    </div>

                    <div class="process-modal-field">
                        <label for="refund-amount"><?php esc_html_e( 'Refund Amount', 'process-subscriptions' ); ?></label>
                        <input type="number" id="refund-amount" step="0.01" min="0" placeholder="0.00">
                        <p class="description"><?php esc_html_e( 'Enter 0 to cancel without refund.', 'process-subscriptions' ); ?></p>
                    </div>

                    <div class="process-modal-field" id="refund-method-field">
                        <label><?php esc_html_e( 'Refund Method', 'process-subscriptions' ); ?></label>
                        <div class="process-refund-methods">
                            <label class="process-refund-method selected" data-method="stripe">
                                <input type="radio" name="refund_method" value="stripe" checked>
                                <div class="process-refund-method-title"><?php esc_html_e( 'Via Stripe', 'process-subscriptions' ); ?></div>
                                <div class="process-refund-method-desc"><?php esc_html_e( 'Refund to original payment method', 'process-subscriptions' ); ?></div>
                            </label>
                            <label class="process-refund-method" data-method="manual">
                                <input type="radio" name="refund_method" value="manual">
                                <div class="process-refund-method-title"><?php esc_html_e( 'Manual', 'process-subscriptions' ); ?></div>
                                <div class="process-refund-method-desc"><?php esc_html_e( 'Record refund only', 'process-subscriptions' ); ?></div>
                            </label>
                        </div>
                    </div>

                    <div class="process-modal-field">
                        <label for="refund-reason"><?php esc_html_e( 'Reason (optional)', 'process-subscriptions' ); ?></label>
                        <input type="text" id="refund-reason" placeholder="<?php esc_attr_e( 'Customer requested cancellation', 'process-subscriptions' ); ?>">
                    </div>

                    <div class="process-license-option" id="license-option-field">
                        <label>
                            <input type="checkbox" id="revoke-license" checked>
                            <span>
                                <strong><?php esc_html_e( 'Revoke License', 'process-subscriptions' ); ?></strong><br>
                                <span style="font-size: 12px; color: #666;"><?php esc_html_e( 'Customer will no longer be able to use the software.', 'process-subscriptions' ); ?></span>
                            </span>
                        </label>
                    </div>
                </div>
                <div class="process-subscription-modal-footer">
                    <button type="button" class="process-modal-btn-cancel" id="modal-cancel-btn">
                        <?php esc_html_e( 'Cancel', 'process-subscriptions' ); ?>
                    </button>
                    <button type="button" class="process-modal-btn-confirm" id="modal-confirm-btn">
                        <?php esc_html_e( 'Confirm Cancellation', 'process-subscriptions' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render JavaScript for the metabox
     *
     * @param WC_Order $order Order object.
     */
    private function render_scripts( $order, $nonce ) {
        $currency_symbol = html_entity_decode( get_woocommerce_currency_symbol( $order->get_currency() ), ENT_QUOTES, 'UTF-8' );
        ?>
        <script type="text/javascript">
        jQuery(function($) {
            var currentData = {};
            var currencySymbol = '<?php echo esc_js( $currency_symbol ); ?>';
            var processNonce = '<?php echo esc_js( $nonce ); ?>';

            // Open modal
            $('.btn-cancel-subscription').on('click', function() {
                var $btn = $(this);
                currentData = {
                    subscription: $btn.data('subscription'),
                    order: $btn.data('order'),
                    license: $btn.data('license'),
                    product: $btn.data('product'),
                    amount: parseFloat($btn.data('amount')),
                    total: parseFloat($btn.data('total')),
                    refunded: parseFloat($btn.data('refunded')),
                    available: parseFloat($btn.data('available')),
                    stripeSub: $btn.data('stripe-sub')
                };

                // Populate modal
                $('#modal-product-name').text(currentData.product);
                $('#modal-order-total').text(currencySymbol + currentData.total.toFixed(2));
                $('#modal-already-refunded').text(currencySymbol + currentData.refunded.toFixed(2));
                $('#modal-available-refund').text(currencySymbol + currentData.available.toFixed(2));
                $('#refund-amount').attr('max', currentData.available).val(currentData.available.toFixed(2));

                // Show/hide license option
                if (currentData.license) {
                    $('#license-option-field').show();
                } else {
                    $('#license-option-field').hide();
                }

                // Show modal
                $('#process-cancel-modal').addClass('active');
            });

            // Close modal
            $('#modal-cancel-btn, .process-subscription-modal-overlay').on('click', function(e) {
                if (e.target === this) {
                    $('#process-cancel-modal').removeClass('active');
                }
            });

            // Prevent modal close when clicking inside
            $('.process-subscription-modal').on('click', function(e) {
                e.stopPropagation();
            });

            // Toggle refund method visibility
            $('#refund-amount').on('input', function() {
                var amount = parseFloat($(this).val()) || 0;
                if (amount > 0) {
                    $('#refund-method-field').slideDown();
                } else {
                    $('#refund-method-field').slideUp();
                }
            }).trigger('input');

            // Refund method selection
            $('.process-refund-method').on('click', function() {
                $('.process-refund-method').removeClass('selected');
                $(this).addClass('selected');
                $(this).find('input').prop('checked', true);
            });

            // Confirm cancellation
            $('#modal-confirm-btn').on('click', function() {
                var $btn = $(this);
                var refundAmount = parseFloat($('#refund-amount').val()) || 0;
                var refundMethod = $('input[name="refund_method"]:checked').val();
                var reason = $('#refund-reason').val();
                var revokeLicense = $('#revoke-license').is(':checked');

                if (refundAmount > currentData.available) {
                    alert('<?php echo esc_js( __( 'Refund amount cannot exceed available amount.', 'process-subscriptions' ) ); ?>');
                    return;
                }

                $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Processing...', 'process-subscriptions' ) ); ?>');

                var postData = {
                    action: 'process_admin_cancel_subscription',
                    subscription_id: currentData.subscription,
                    order_id: currentData.order,
                    license_key: currentData.license,
                    stripe_subscription_id: currentData.stripeSub,
                    refund_amount: refundAmount,
                    refund_method: refundMethod,
                    reason: reason,
                    revoke_license: revokeLicense ? 1 : 0,
                    nonce: processNonce
                };

                $.post(ajaxurl, postData, function(response) {
                    if (response.success) {
                        alert(response.data.message || '<?php echo esc_js( __( 'Subscription cancelled successfully.', 'process-subscriptions' ) ); ?>');
                        location.reload();
                    } else {
                        var errorMsg = response.data.message || response.data || '<?php echo esc_js( __( 'Failed to cancel subscription.', 'process-subscriptions' ) ); ?>';
                        alert(errorMsg);
                        $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Confirm Cancellation', 'process-subscriptions' ) ); ?>');
                    }
                }).fail(function() {
                    alert('<?php echo esc_js( __( 'Request failed. Please try again.', 'process-subscriptions' ) ); ?>');
                    $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Confirm Cancellation', 'process-subscriptions' ) ); ?>');
                });
            });

            // Reactivate license
            $('.btn-reactivate-license').on('click', function() {
                var $btn = $(this);
                var license = $btn.data('license');
                var subscription = $btn.data('subscription');
                var order = $btn.data('order');

                if (!confirm('<?php echo esc_js( __( 'Are you sure you want to reactivate this license?\n\nThis will allow the customer to use the software again.', 'process-subscriptions' ) ); ?>')) {
                    return;
                }

                $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Reactivating...', 'process-subscriptions' ) ); ?>');

                $.post(ajaxurl, {
                    action: 'process_reactivate_license',
                    license_key: license,
                    subscription_id: subscription,
                    order_id: order,
                    nonce: processNonce
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data || '<?php echo esc_js( __( 'Failed to reactivate license.', 'process-subscriptions' ) ); ?>');
                        $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Reactivate License', 'process-subscriptions' ) ); ?>');
                    }
                }).fail(function() {
                    alert('<?php echo esc_js( __( 'Request failed. Please try again.', 'process-subscriptions' ) ); ?>');
                    $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Reactivate License', 'process-subscriptions' ) ); ?>');
                });
            });

            // ESC to close modal
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    $('#process-cancel-modal').removeClass('active');
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Get license status from license system
     *
     * @param string $license_key License key.
     * @return string Status.
     */
    private function get_license_status( $license_key ) {
        $api_key = defined( 'PROCESS_LICENSE_API_KEY' ) ? PROCESS_LICENSE_API_KEY : '';
        $api_url = defined( 'PROCESS_LICENSE_API_URL' )
            ? str_replace( 'create-license.php', '', PROCESS_LICENSE_API_URL )
            : 'https://pro-cess.co.uk/license-system/api/';

        if ( empty( $api_key ) ) {
            return 'unknown';
        }

        // Use validate endpoint to check status
        $response = wp_remote_post( $api_url . 'validate.php', array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'license_key' => $license_key,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            return 'unknown';
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body['valid'] ) ) {
            return 'active';
        }

        // Check error message for status
        $error = $body['error'] ?? '';
        if ( strpos( $error, 'revoked' ) !== false ) {
            return 'revoked';
        } elseif ( strpos( $error, 'suspended' ) !== false ) {
            return 'suspended';
        } elseif ( strpos( $error, 'expired' ) !== false ) {
            return 'expired';
        }

        return 'inactive';
    }

    /**
     * AJAX handler for cancelling subscription with refund
     */
    public function ajax_cancel_subscription() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'process_license_action' ) ) {
            wp_send_json_error( __( 'Security check failed.', 'process-subscriptions' ) );
        }

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'process-subscriptions' ) );
        }

        $subscription_id = intval( $_POST['subscription_id'] ?? 0 );
        $order_id = intval( $_POST['order_id'] ?? 0 );
        $license_key = sanitize_text_field( $_POST['license_key'] ?? '' );
        $stripe_subscription_id = sanitize_text_field( $_POST['stripe_subscription_id'] ?? '' );
        $refund_amount = floatval( $_POST['refund_amount'] ?? 0 );
        $refund_method = sanitize_text_field( $_POST['refund_method'] ?? 'stripe' );
        $reason = sanitize_text_field( $_POST['reason'] ?? '' );
        $revoke_license = intval( $_POST['revoke_license'] ?? 0 );

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'process-subscriptions' ) );
        }

        $messages = array();

        // 1. Cancel Stripe subscription if exists
        if ( ! empty( $stripe_subscription_id ) ) {
            try {
                $cancelled = Process_Stripe_Handler::get_instance()->cancel_subscription( $stripe_subscription_id, true );
                if ( $cancelled ) {
                    $messages[] = __( 'Stripe subscription cancelled.', 'process-subscriptions' );
                    $order->add_order_note( sprintf(
                        __( 'Stripe subscription %s cancelled by admin.', 'process-subscriptions' ),
                        $stripe_subscription_id
                    ) );
                }
            } catch ( Exception $e ) {
                // Log but continue
                $order->add_order_note( sprintf(
                    __( 'Failed to cancel Stripe subscription: %s', 'process-subscriptions' ),
                    $e->getMessage()
                ) );
            }
        }

        // 2. Process refund if amount > 0
        if ( $refund_amount > 0 ) {
            $refund_args = array(
                'amount'   => $refund_amount,
                'reason'   => $reason ?: __( 'Subscription cancelled', 'process-subscriptions' ),
                'order_id' => $order_id,
            );

            if ( $refund_method === 'stripe' ) {
                $refund_args['refund_payment'] = true;
            }

            $refund = wc_create_refund( $refund_args );

            if ( is_wp_error( $refund ) ) {
                wp_send_json_error( sprintf(
                    __( 'Refund failed: %s', 'process-subscriptions' ),
                    $refund->get_error_message()
                ) );
            }

            $messages[] = sprintf(
                __( 'Refund of %s processed.', 'process-subscriptions' ),
                wc_price( $refund_amount, array( 'currency' => $order->get_currency() ) )
            );
        }

        // 3. Revoke license if requested
        if ( $revoke_license && ! empty( $license_key ) ) {
            $revoked = $this->revoke_license( $license_key, $reason );
            if ( $revoked ) {
                $messages[] = __( 'License revoked.', 'process-subscriptions' );
                $order->add_order_note( sprintf(
                    __( 'License %s revoked by admin. Reason: %s', 'process-subscriptions' ),
                    $license_key,
                    $reason ?: __( 'No reason provided', 'process-subscriptions' )
                ) );
            }
        }

        // 4. Update subscription status
        if ( $subscription_id ) {
            Process_Subscription_Manager::get_instance()->update( $subscription_id, array(
                'status' => 'cancelled',
                'cancelled_at' => current_time( 'mysql' ),
            ) );
            $messages[] = __( 'Subscription status updated.', 'process-subscriptions' );
        }

        wp_send_json_success( array(
            'message' => implode( ' ', $messages ),
        ) );
    }

    /**
     * Revoke a license via API
     *
     * @param string $license_key License key.
     * @param string $reason Reason for revocation.
     * @return bool Success.
     */
    private function revoke_license( $license_key, $reason = '' ) {
        $api_key = defined( 'PROCESS_LICENSE_API_KEY' ) ? PROCESS_LICENSE_API_KEY : '';
        $api_url = defined( 'PROCESS_LICENSE_API_URL' )
            ? str_replace( 'create-license.php', '', PROCESS_LICENSE_API_URL )
            : 'https://pro-cess.co.uk/license-system/api/';

        if ( empty( $api_key ) ) {
            return false;
        }

        // 1. Cancel any active addons first (Route Optimisation, GPT-4o, etc.)
        $this->cancel_license_addons( $license_key, $api_key, $api_url );

        // 2. Revoke the main license
        $response = wp_remote_post( $api_url . 'update-license.php', array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Key'    => $api_key,
            ),
            'body'    => wp_json_encode( array(
                'license_key' => $license_key,
                'status'      => 'revoked',
                'reason'      => $reason,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return ! empty( $body['success'] );
    }

    /**
     * Cancel all active addons for a license
     *
     * @param string $license_key License key.
     * @param string $api_key API key.
     * @param string $api_url Base API URL.
     */
    private function cancel_license_addons( $license_key, $api_key, $api_url ) {
        $addon_types = array( 'route_optimization', 'gpt4o' );

        foreach ( $addon_types as $addon_type ) {
            wp_remote_post( $api_url . 'addon-subscription.php', array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-API-Key'    => $api_key,
                ),
                'body'    => wp_json_encode( array(
                    'action'      => 'cancel',
                    'license_key' => $license_key,
                    'addon_type'  => $addon_type,
                ) ),
            ) );
            // Continue even if cancellation fails - we still want to revoke the license
        }
    }

    /**
     * AJAX handler for revoking license (standalone)
     */
    public function ajax_revoke_license() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'process_license_action' ) ) {
            wp_send_json_error( __( 'Security check failed.', 'process-subscriptions' ) );
        }

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'process-subscriptions' ) );
        }

        $license_key = sanitize_text_field( $_POST['license_key'] ?? '' );
        $subscription_id = intval( $_POST['subscription_id'] ?? 0 );
        $order_id = intval( $_POST['order_id'] ?? 0 );
        $reason = sanitize_text_field( $_POST['reason'] ?? '' );

        if ( empty( $license_key ) ) {
            wp_send_json_error( __( 'License key is required.', 'process-subscriptions' ) );
        }

        $revoked = $this->revoke_license( $license_key, $reason );

        if ( $revoked ) {
            // Add order note
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->add_order_note( sprintf(
                    __( 'License %s revoked by admin. Reason: %s', 'process-subscriptions' ),
                    $license_key,
                    $reason ?: __( 'No reason provided', 'process-subscriptions' )
                ) );
            }

            wp_send_json_success( array( 'message' => __( 'License revoked successfully.', 'process-subscriptions' ) ) );
        } else {
            wp_send_json_error( __( 'Failed to revoke license.', 'process-subscriptions' ) );
        }
    }

    /**
     * AJAX handler for reactivating license
     */
    public function ajax_reactivate_license() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'process_license_action' ) ) {
            wp_send_json_error( __( 'Security check failed.', 'process-subscriptions' ) );
        }

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'process-subscriptions' ) );
        }

        $license_key = sanitize_text_field( $_POST['license_key'] ?? '' );
        $subscription_id = intval( $_POST['subscription_id'] ?? 0 );
        $order_id = intval( $_POST['order_id'] ?? 0 );

        if ( empty( $license_key ) ) {
            wp_send_json_error( __( 'License key is required.', 'process-subscriptions' ) );
        }

        $api_key = defined( 'PROCESS_LICENSE_API_KEY' ) ? PROCESS_LICENSE_API_KEY : '';
        $api_url = defined( 'PROCESS_LICENSE_API_URL' )
            ? str_replace( 'create-license.php', '', PROCESS_LICENSE_API_URL )
            : 'https://pro-cess.co.uk/license-system/api/';

        if ( empty( $api_key ) ) {
            wp_send_json_error( __( 'License API not configured.', 'process-subscriptions' ) );
        }

        $response = wp_remote_post( $api_url . 'update-license.php', array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Key'    => $api_key,
            ),
            'body'    => wp_json_encode( array(
                'license_key' => $license_key,
                'status'      => 'active',
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body['success'] ) ) {
            // Update subscription status
            if ( $subscription_id ) {
                Process_Subscription_Manager::get_instance()->update( $subscription_id, array(
                    'status' => 'active',
                ) );
            }

            // Add order note
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->add_order_note( sprintf(
                    __( 'License %s reactivated by admin.', 'process-subscriptions' ),
                    $license_key
                ) );
            }

            wp_send_json_success( array( 'message' => __( 'License reactivated successfully.', 'process-subscriptions' ) ) );
        } else {
            wp_send_json_error( $body['error'] ?? __( 'Failed to reactivate license.', 'process-subscriptions' ) );
        }
    }
}
