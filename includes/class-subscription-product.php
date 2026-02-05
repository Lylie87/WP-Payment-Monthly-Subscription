<?php
/**
 * Subscription Product Type for WooCommerce
 *
 * @package ProcessSubscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Subscription Product Class
 */
class Process_Subscription_Product {

    /**
     * Instance
     *
     * @var Process_Subscription_Product
     */
    private static $instance = null;

    /**
     * Get instance
     *
     * @return Process_Subscription_Product
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
        // Add product type
        add_filter( 'product_type_selector', array( $this, 'add_product_type' ) );
        add_filter( 'woocommerce_product_class', array( $this, 'product_class' ), 10, 2 );

        // Product data tabs
        add_filter( 'woocommerce_product_data_tabs', array( $this, 'product_data_tabs' ) );
        add_action( 'woocommerce_product_data_panels', array( $this, 'product_data_panels' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_data' ) );

        // Show subscription info on frontend
        add_action( 'woocommerce_single_product_summary', array( $this, 'display_subscription_info' ), 15 );
        add_filter( 'woocommerce_get_price_html', array( $this, 'subscription_price_html' ), 10, 2 );

        // Add to cart template for subscription products
        add_action( 'woocommerce_process_subscription_add_to_cart', array( $this, 'add_to_cart_template' ) );

        // Add to cart handler - treat subscriptions like simple products
        add_filter( 'woocommerce_add_to_cart_handler', array( $this, 'add_to_cart_handler' ), 10, 2 );

        // Redirect to checkout after adding subscription to cart
        add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'redirect_to_checkout' ), 10, 2 );

        // Cart handling
        add_filter( 'woocommerce_cart_item_price', array( $this, 'cart_item_price' ), 10, 3 );
        add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'cart_item_subtotal' ), 10, 3 );
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_trial_cart_price' ), 20 );

        // Force payment form for £0 trial orders (so Stripe can save the card via SetupIntent)
        add_filter( 'woocommerce_cart_needs_payment', array( $this, 'trial_needs_payment' ), 10, 2 );
        add_filter( 'woocommerce_order_needs_payment', array( $this, 'trial_order_needs_payment' ), 10, 3 );

        // Checkout handling
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_item_meta' ), 10, 4 );

        // Prevent duplicate subscriptions
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'prevent_duplicate_subscription' ), 10, 2 );
    }

    /**
     * Add subscription product type
     *
     * @param array $types Product types.
     * @return array
     */
    public function add_product_type( $types ) {
        $types['process_subscription'] = __( 'Subscription', 'process-subscriptions' );
        return $types;
    }

    /**
     * Product class for subscription type
     *
     * @param string $classname Class name.
     * @param string $product_type Product type.
     * @return string
     */
    public function product_class( $classname, $product_type ) {
        if ( 'process_subscription' === $product_type ) {
            return 'WC_Product_Process_Subscription';
        }
        return $classname;
    }

    /**
     * Handle add to cart for subscription products
     * Treat them like simple products
     *
     * @param string $handler The handler type.
     * @param WC_Product $product The product.
     * @return string
     */
    public function add_to_cart_handler( $handler, $product ) {
        if ( $product && $product->get_type() === 'process_subscription' ) {
            return 'simple';
        }
        return $handler;
    }

    /**
     * Redirect to checkout after adding subscription to cart
     *
     * This is a fallback for cases where the form doesn't post directly to checkout.
     * The primary redirect is handled by the form action in the add-to-cart template.
     *
     * @param string $url The redirect URL.
     * @param WC_Product $product The product added (may be null).
     * @return string
     */
    public function redirect_to_checkout( $url, $product = null ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_REQUEST['add-to-cart'] ) ) {
            $product_id = absint( $_REQUEST['add-to-cart'] );
            $product = wc_get_product( $product_id );

            if ( $product && $product->get_type() === 'process_subscription' ) {
                return wc_get_checkout_url();
            }
        }

        return $url;
    }

    /**
     * Add subscription tab to product data
     *
     * @param array $tabs Product data tabs.
     * @return array
     */
    public function product_data_tabs( $tabs ) {
        $tabs['process_subscription'] = array(
            'label'    => __( 'Subscription', 'process-subscriptions' ),
            'target'   => 'process_subscription_data',
            'class'    => array( 'show_if_process_subscription' ),
            'priority' => 21,
        );
        return $tabs;
    }

    /**
     * Subscription data panel
     */
    public function product_data_panels() {
        global $post;
        ?>
        <div id="process_subscription_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <?php
                woocommerce_wp_text_input( array(
                    'id'          => '_subscription_price',
                    'label'       => __( 'Subscription Price', 'process-subscriptions' ) . ' (' . get_woocommerce_currency_symbol() . ')',
                    'type'        => 'text',
                    'data_type'   => 'price',
                    'description' => __( 'The recurring price for this subscription.', 'process-subscriptions' ),
                    'desc_tip'    => true,
                ) );

                woocommerce_wp_select( array(
                    'id'          => '_subscription_period',
                    'label'       => __( 'Billing Period', 'process-subscriptions' ),
                    'options'     => array(
                        'day'   => __( 'Day', 'process-subscriptions' ),
                        'week'  => __( 'Week', 'process-subscriptions' ),
                        'month' => __( 'Month', 'process-subscriptions' ),
                        'year'  => __( 'Year', 'process-subscriptions' ),
                    ),
                    'description' => __( 'How often the subscription renews.', 'process-subscriptions' ),
                    'desc_tip'    => true,
                ) );

                woocommerce_wp_text_input( array(
                    'id'                => '_subscription_interval',
                    'label'             => __( 'Billing Interval', 'process-subscriptions' ),
                    'type'              => 'number',
                    'custom_attributes' => array(
                        'min'  => '1',
                        'step' => '1',
                    ),
                    'value'             => get_post_meta( $post->ID, '_subscription_interval', true ) ?: '1',
                    'description'       => __( 'e.g., "1" month, "3" months, "1" year.', 'process-subscriptions' ),
                    'desc_tip'          => true,
                ) );

                woocommerce_wp_text_input( array(
                    'id'                => '_subscription_length',
                    'label'             => __( 'Subscription Length', 'process-subscriptions' ),
                    'type'              => 'number',
                    'custom_attributes' => array(
                        'min'  => '0',
                        'step' => '1',
                    ),
                    'value'             => get_post_meta( $post->ID, '_subscription_length', true ) ?: '0',
                    'description'       => __( 'Number of billing periods. 0 = unlimited/ongoing.', 'process-subscriptions' ),
                    'desc_tip'          => true,
                ) );

                woocommerce_wp_text_input( array(
                    'id'                => '_subscription_trial_days',
                    'label'             => __( 'Free Trial Days', 'process-subscriptions' ),
                    'type'              => 'number',
                    'custom_attributes' => array(
                        'min'  => '0',
                        'step' => '1',
                    ),
                    'value'             => get_post_meta( $post->ID, '_subscription_trial_days', true ) ?: '0',
                    'description'       => __( 'Number of free trial days. 0 = no trial.', 'process-subscriptions' ),
                    'desc_tip'          => true,
                ) );

                woocommerce_wp_text_input( array(
                    'id'          => '_subscription_signup_fee',
                    'label'       => __( 'Sign-up Fee', 'process-subscriptions' ) . ' (' . get_woocommerce_currency_symbol() . ')',
                    'type'        => 'text',
                    'data_type'   => 'price',
                    'description' => __( 'One-time fee charged at sign-up (optional).', 'process-subscriptions' ),
                    'desc_tip'    => true,
                ) );
                ?>
            </div>

            <div class="options_group">
                <h4 style="padding-left: 12px;"><?php esc_html_e( 'License Integration', 'process-subscriptions' ); ?></h4>
                <?php
                woocommerce_wp_text_input( array(
                    'id'          => '_subscription_plugin_slug',
                    'label'       => __( 'Plugin Slug', 'process-subscriptions' ),
                    'description' => __( 'The plugin slug in your license system (e.g., wp-route-optimiser).', 'process-subscriptions' ),
                    'desc_tip'    => true,
                ) );

                woocommerce_wp_text_input( array(
                    'id'          => '_subscription_license_type',
                    'label'       => __( 'License Type', 'process-subscriptions' ),
                    'description' => __( 'The license type slug (e.g., basic, premium).', 'process-subscriptions' ),
                    'desc_tip'    => true,
                    'value'       => get_post_meta( $post->ID, '_subscription_license_type', true ) ?: 'basic',
                ) );
                ?>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(function($) {
            // Show/hide subscription options
            $('select#product-type').on('change', function() {
                var isSubscription = $(this).val() === 'process_subscription';
                $('.show_if_process_subscription').toggle(isSubscription);

                // Show general tab options for subscription
                if (isSubscription) {
                    $('.show_if_simple').show();
                    $('#_virtual').prop('checked', true).trigger('change');
                    $('#_downloadable').prop('checked', true).trigger('change');
                }
            }).trigger('change');
        });
        </script>
        <?php
    }

    /**
     * Save subscription product data
     *
     * @param int $post_id Product ID.
     */
    public function save_product_data( $post_id ) {
        // Only save subscription data for subscription products
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification
        $product_type = isset( $_POST['product-type'] ) ? sanitize_text_field( wp_unslash( $_POST['product-type'] ) ) : '';

        if ( 'process_subscription' !== $product_type ) {
            return;
        }

        $fields = array(
            '_subscription_price',
            '_subscription_period',
            '_subscription_interval',
            '_subscription_length',
            '_subscription_trial_days',
            '_subscription_signup_fee',
            '_subscription_plugin_slug',
            '_subscription_license_type',
        );

        foreach ( $fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                update_post_meta( $post_id, $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
            }
        }

        // Set regular price to subscription price for WooCommerce compatibility
        // Only set if subscription price is not empty
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce handles nonce verification
        $subscription_price = isset( $_POST['_subscription_price'] ) ? sanitize_text_field( wp_unslash( $_POST['_subscription_price'] ) ) : '';
        if ( '' !== $subscription_price ) {
            update_post_meta( $post_id, '_regular_price', $subscription_price );
            update_post_meta( $post_id, '_price', $subscription_price );
        }
    }

    /**
     * Display subscription info on product page
     */
    public function display_subscription_info() {
        global $product;

        if ( ! $product || $product->get_type() !== 'process_subscription' ) {
            return;
        }

        $period   = get_post_meta( $product->get_id(), '_subscription_period', true ) ?: 'month';
        $interval = get_post_meta( $product->get_id(), '_subscription_interval', true ) ?: 1;
        $trial    = get_post_meta( $product->get_id(), '_subscription_trial_days', true ) ?: 0;

        echo '<div class="process-subscription-info">';

        if ( $trial > 0 ) {
            echo '<p class="subscription-trial"><strong>' . esc_html( $trial ) . ' day free trial</strong></p>';
        }

        echo '<p class="subscription-terms">';
        printf(
            esc_html__( 'Billed every %1$s %2$s. Cancel anytime.', 'process-subscriptions' ),
            $interval > 1 ? esc_html( $interval ) : '',
            esc_html( $this->get_period_string( $period, $interval ) )
        );
        echo '</p>';

        echo '</div>';
    }

    /**
     * Add to cart template for subscription products
     */
    public function add_to_cart_template() {
        global $product;

        if ( ! $product || ! $product->is_purchasable() ) {
            return;
        }

        // Try to load theme template first, then fall back to plugin template
        $template = wc_locate_template( 'single-product/add-to-cart/process_subscription.php' );

        if ( $template && file_exists( $template ) ) {
            // Load the theme or plugin template
            wc_get_template( 'single-product/add-to-cart/process_subscription.php' );
        } else {
            // Fallback: output the form directly
            $price    = get_post_meta( $product->get_id(), '_subscription_price', true );
            $period   = get_post_meta( $product->get_id(), '_subscription_period', true ) ?: 'year';
            $interval = get_post_meta( $product->get_id(), '_subscription_interval', true ) ?: 1;

            $period_display = array(
                'day'   => $interval > 1 ? $interval . ' days' : 'day',
                'week'  => $interval > 1 ? $interval . ' weeks' : 'week',
                'month' => $interval > 1 ? $interval . ' months' : 'month',
                'year'  => $interval > 1 ? $interval . ' years' : 'year',
            );

            echo wc_get_stock_html( $product );

            if ( $product->is_in_stock() ) :
                do_action( 'woocommerce_before_add_to_cart_form' );
                ?>
                <form class="cart" action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ); ?>" method="post" enctype="multipart/form-data">
                    <?php do_action( 'woocommerce_before_add_to_cart_button' ); ?>

                    <div style="margin-bottom: var(--space-4, 1rem); padding: var(--space-4, 1rem); background: #f9f9f9; border-radius: 8px;">
                        <p style="margin: 0; color: #666;">
                            <strong><?php echo wc_price( $price ); ?></strong> billed every <?php echo esc_html( $period_display[ $period ] ?? $period ); ?>. Cancel anytime.
                        </p>
                    </div>

                    <button type="submit" name="add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>" class="single_add_to_cart_button button alt btn btn--primary btn--large" style="width: 100%; justify-content: center; display: flex; align-items: center;">
                        <?php echo esc_html( $product->single_add_to_cart_text() ); ?>
                    </button>

                    <?php do_action( 'woocommerce_after_add_to_cart_button' ); ?>
                </form>
                <?php
                do_action( 'woocommerce_after_add_to_cart_form' );
            endif;
        }
    }

    /**
     * Modify price HTML for subscriptions
     *
     * @param string     $price_html Price HTML.
     * @param WC_Product $product Product object.
     * @return string
     */
    public function subscription_price_html( $price_html, $product ) {
        if ( $product->get_type() !== 'process_subscription' ) {
            return $price_html;
        }

        $price      = get_post_meta( $product->get_id(), '_subscription_price', true );
        $period     = get_post_meta( $product->get_id(), '_subscription_period', true ) ?: 'month';
        $interval   = get_post_meta( $product->get_id(), '_subscription_interval', true ) ?: 1;
        $signup     = get_post_meta( $product->get_id(), '_subscription_signup_fee', true );
        $trial_days = get_post_meta( $product->get_id(), '_subscription_trial_days', true );

        // Trial pricing display
        if ( $trial_days && intval( $trial_days ) > 0 ) {
            $price_html = '<strong>' . wc_price( 0 ) . ' for ' . intval( $trial_days ) . ' days</strong>';
            $price_html .= '<br><small>Then ' . wc_price( $price ) . ' / ' . $this->get_period_string( $period, $interval ) . '</small>';
            return $price_html;
        }

        $price_html = wc_price( $price ) . ' / ' . $this->get_period_string( $period, $interval );

        if ( $signup && floatval( $signup ) > 0 ) {
            $price_html .= '<br><small>' . sprintf(
                esc_html__( '+ %s sign-up fee', 'process-subscriptions' ),
                wc_price( $signup )
            ) . '</small>';
        }

        return $price_html;
    }

    /**
     * Cart item price
     *
     * @param string $price Price HTML.
     * @param array  $cart_item Cart item.
     * @param string $cart_item_key Cart item key.
     * @return string
     */
    public function cart_item_price( $price, $cart_item, $cart_item_key ) {
        $product = $cart_item['data'];

        if ( $product->get_type() !== 'process_subscription' ) {
            return $price;
        }

        $sub_price  = get_post_meta( $product->get_id(), '_subscription_price', true );
        $period     = get_post_meta( $product->get_id(), '_subscription_period', true ) ?: 'month';
        $interval   = get_post_meta( $product->get_id(), '_subscription_interval', true ) ?: 1;
        $trial_days = get_post_meta( $product->get_id(), '_subscription_trial_days', true );

        if ( $trial_days && intval( $trial_days ) > 0 ) {
            return wc_price( 0 ) . '<br><small>Then ' . wc_price( $sub_price ) . ' / ' . $this->get_period_string( $period, $interval ) . '</small>';
        }

        return wc_price( $sub_price ) . ' / ' . $this->get_period_string( $period, $interval );
    }

    /**
     * Cart item subtotal
     *
     * @param string $subtotal Subtotal HTML.
     * @param array  $cart_item Cart item.
     * @param string $cart_item_key Cart item key.
     * @return string
     */
    public function cart_item_subtotal( $subtotal, $cart_item, $cart_item_key ) {
        $product = $cart_item['data'];

        if ( $product->get_type() !== 'process_subscription' ) {
            return $subtotal;
        }

        $sub_price  = get_post_meta( $product->get_id(), '_subscription_price', true );
        $period     = get_post_meta( $product->get_id(), '_subscription_period', true ) ?: 'month';
        $interval   = get_post_meta( $product->get_id(), '_subscription_interval', true ) ?: 1;
        $signup     = get_post_meta( $product->get_id(), '_subscription_signup_fee', true );
        $trial_days = get_post_meta( $product->get_id(), '_subscription_trial_days', true );

        // Trial: show £0 subtotal with future pricing info
        if ( $trial_days && intval( $trial_days ) > 0 ) {
            $subtotal = wc_price( 0 );
            $subtotal .= '<br><small>' . intval( $trial_days ) . '-day free trial, then ' . wc_price( $sub_price ) . ' / ' . $this->get_period_string( $period, $interval ) . '</small>';
            return $subtotal;
        }

        $total = floatval( $sub_price ) * $cart_item['quantity'];

        if ( $signup ) {
            $total += floatval( $signup ) * $cart_item['quantity'];
        }

        $subtotal = wc_price( $total );
        $subtotal .= '<br><small>' . wc_price( $sub_price ) . ' / ' . $this->get_period_string( $period, $interval ) . '</small>';

        return $subtotal;
    }

    /**
     * Add subscription meta to order item
     *
     * @param WC_Order_Item_Product $item Order item.
     * @param string                $cart_item_key Cart item key.
     * @param array                 $values Cart item values.
     * @param WC_Order              $order Order object.
     */
    public function add_order_item_meta( $item, $cart_item_key, $values, $order ) {
        $product = $values['data'];

        if ( $product->get_type() !== 'process_subscription' ) {
            return;
        }

        $item->add_meta_data( '_is_subscription', 'yes' );
        $item->add_meta_data( '_subscription_price', get_post_meta( $product->get_id(), '_subscription_price', true ) );
        $item->add_meta_data( '_subscription_period', get_post_meta( $product->get_id(), '_subscription_period', true ) );
        $item->add_meta_data( '_subscription_interval', get_post_meta( $product->get_id(), '_subscription_interval', true ) );
        $item->add_meta_data( '_subscription_plugin_slug', get_post_meta( $product->get_id(), '_subscription_plugin_slug', true ) );
        $item->add_meta_data( '_subscription_license_type', get_post_meta( $product->get_id(), '_subscription_license_type', true ) );

        $trial_days = get_post_meta( $product->get_id(), '_subscription_trial_days', true );
        if ( $trial_days && intval( $trial_days ) > 0 ) {
            $item->add_meta_data( '_subscription_trial_days', intval( $trial_days ) );
        }
    }

    /**
     * Get human-readable period string
     *
     * @param string $period Period.
     * @param int    $interval Interval.
     * @return string
     */
    /**
     * Set cart price to zero for trial subscription products
     *
     * @param WC_Cart $cart Cart object.
     */
    public function set_trial_cart_price( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        foreach ( $cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'];
            if ( $product->get_type() !== 'process_subscription' ) {
                continue;
            }

            $trial_days = get_post_meta( $product->get_id(), '_subscription_trial_days', true );
            if ( $trial_days && intval( $trial_days ) > 0 ) {
                $cart_item['data']->set_price( 0 );
            }
        }
    }

    /**
     * Force payment form to show for £0 trial orders
     *
     * Without this, WooCommerce sees £0 total and skips payment entirely.
     * We need the Stripe Gateway to render so it creates a SetupIntent
     * and saves the customer's card for charging after the trial ends.
     *
     * @param bool    $needs_payment Whether the cart needs payment.
     * @param WC_Cart $cart Cart object.
     * @return bool
     */
    public function trial_needs_payment( $needs_payment, $cart ) {
        foreach ( $cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'];
            if ( $product->get_type() !== 'process_subscription' ) {
                continue;
            }

            $trial_days = get_post_meta( $product->get_id(), '_subscription_trial_days', true );
            if ( $trial_days && intval( $trial_days ) > 0 ) {
                return true;
            }
        }

        return $needs_payment;
    }

    /**
     * Force order payment processing for £0 trial orders
     *
     * Even with the cart showing the payment form, WooCommerce checks
     * $order->needs_payment() before calling the gateway's process_payment().
     * For £0 orders this returns false, skipping the Stripe SetupIntent.
     *
     * @param bool     $needs_payment Whether the order needs payment.
     * @param WC_Order $order Order object.
     * @param array    $valid_order_statuses Valid statuses.
     * @return bool
     */
    public function trial_order_needs_payment( $needs_payment, $order, $valid_order_statuses ) {
        foreach ( $order->get_items() as $item ) {
            if ( $item->get_meta( '_is_subscription' ) !== 'yes' ) {
                continue;
            }

            $trial_days = $item->get_meta( '_subscription_trial_days' );
            if ( $trial_days && intval( $trial_days ) > 0 ) {
                return true;
            }
        }

        return $needs_payment;
    }

    /**
     * Prevent duplicate subscriptions for the same product
     *
     * @param bool $valid Whether the add to cart is valid.
     * @param int  $product_id Product ID being added.
     * @return bool
     */
    public function prevent_duplicate_subscription( $valid, $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product || $product->get_type() !== 'process_subscription' ) {
            return $valid;
        }

        if ( ! is_user_logged_in() ) {
            return $valid;
        }

        global $wpdb;
        $table   = $wpdb->prefix . 'process_subscriptions';
        $user_id = get_current_user_id();

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND product_id = %d AND status IN ('active', 'trialing', 'pending-cancel') LIMIT 1",
            $user_id,
            $product_id
        ) );

        if ( $existing ) {
            $account_url = wc_get_account_endpoint_url( 'subscriptions' );
            wc_add_notice(
                sprintf(
                    __( 'You already have an active subscription for this product. <a href="%s">Manage your subscriptions</a>.', 'process-subscriptions' ),
                    esc_url( $account_url )
                ),
                'error'
            );

            // For URL-based add-to-cart (e.g. /?add-to-cart=123), redirect to cart page
            // where the notice will actually display, instead of silently landing on homepage
            if ( isset( $_GET['add-to-cart'] ) ) {
                wp_safe_redirect( wc_get_cart_url() );
                exit;
            }

            return false;
        }

        return $valid;
    }

    private function get_period_string( $period, $interval = 1 ) {
        $interval = intval( $interval );

        $strings = array(
            'day'   => $interval > 1 ? sprintf( _n( '%d day', '%d days', $interval, 'process-subscriptions' ), $interval ) : __( 'day', 'process-subscriptions' ),
            'week'  => $interval > 1 ? sprintf( _n( '%d week', '%d weeks', $interval, 'process-subscriptions' ), $interval ) : __( 'week', 'process-subscriptions' ),
            'month' => $interval > 1 ? sprintf( _n( '%d months', '%d months', $interval, 'process-subscriptions' ), $interval ) : __( 'month', 'process-subscriptions' ),
            'year'  => $interval > 1 ? sprintf( _n( '%d years', '%d years', $interval, 'process-subscriptions' ), $interval ) : __( 'year', 'process-subscriptions' ),
        );

        return isset( $strings[ $period ] ) ? $strings[ $period ] : $period;
    }
}

/**
 * Custom Product Type Class
 */
if ( ! class_exists( 'WC_Product_Process_Subscription' ) ) {

    /**
     * Subscription Product Class
     */
    class WC_Product_Process_Subscription extends WC_Product_Simple {

        /**
         * Product type
         *
         * @return string
         */
        public function get_type() {
            return 'process_subscription';
        }

        /**
         * Get price
         *
         * @param string $context View or edit context.
         * @return string
         */
        public function get_price( $context = 'view' ) {
            // If price was explicitly set (e.g., zeroed for trial via set_price(0)), respect that
            $changes = $this->get_changes();
            if ( isset( $changes['price'] ) ) {
                return parent::get_price( $context );
            }

            $price = get_post_meta( $this->get_id(), '_subscription_price', true );
            return $price !== '' ? $price : parent::get_price( $context );
        }

        /**
         * Get regular price
         *
         * @param string $context View or edit context.
         * @return string
         */
        public function get_regular_price( $context = 'view' ) {
            // If price was explicitly set (e.g., zeroed for trial), respect that
            $changes = $this->get_changes();
            if ( isset( $changes['price'] ) ) {
                return parent::get_regular_price( $context );
            }

            $price = get_post_meta( $this->get_id(), '_subscription_price', true );
            return $price !== '' ? $price : parent::get_regular_price( $context );
        }

        /**
         * Is purchasable
         *
         * @return bool
         */
        public function is_purchasable() {
            $price = get_post_meta( $this->get_id(), '_subscription_price', true );
            return $price !== '' && $this->exists() && $this->get_status() === 'publish';
        }

        /**
         * Is virtual
         *
         * @return bool
         */
        public function is_virtual() {
            return true;
        }

        /**
         * Is downloadable
         *
         * @return bool
         */
        public function is_downloadable() {
            return true;
        }

        /**
         * Is in stock - subscriptions are always in stock
         *
         * @return bool
         */
        public function is_in_stock() {
            return true;
        }

        /**
         * Managing stock - subscriptions don't need stock management
         *
         * @return bool
         */
        public function managing_stock() {
            return false;
        }

        /**
         * Add to cart button text
         *
         * @return string
         */
        public function single_add_to_cart_text() {
            $trial_days = get_post_meta( $this->get_id(), '_subscription_trial_days', true );
            if ( $trial_days && intval( $trial_days ) > 0 ) {
                return __( 'Start Free Trial', 'process-subscriptions' );
            }
            return __( 'Subscribe Now', 'process-subscriptions' );
        }

        /**
         * Add to cart text for archives
         *
         * @return string
         */
        public function add_to_cart_text() {
            $trial_days = get_post_meta( $this->get_id(), '_subscription_trial_days', true );
            if ( $trial_days && intval( $trial_days ) > 0 ) {
                return __( 'Start Free Trial', 'process-subscriptions' );
            }
            return __( 'Subscribe', 'process-subscriptions' );
        }
    }
}
