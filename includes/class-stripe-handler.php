<?php
/**
 * Stripe Integration Handler
 *
 * @package ProcessSubscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Stripe Handler Class
 */
class Process_Stripe_Handler {

    /**
     * Instance
     *
     * @var Process_Stripe_Handler
     */
    private static $instance = null;

    /**
     * Stripe API version
     *
     * @var string
     */
    private $api_version = '2023-10-16';

    /**
     * Get instance
     *
     * @return Process_Stripe_Handler
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
        // Hook into WooCommerce Stripe Gateway payment complete
        add_action( 'woocommerce_payment_complete', array( $this, 'handle_payment_complete' ) );
        add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_complete' ) );
        add_action( 'woocommerce_order_status_processing', array( $this, 'handle_order_complete' ) );
    }

    /**
     * Get Stripe secret key
     *
     * @return string
     */
    private function get_secret_key() {
        // Prioritise plugin settings if set
        $plugin_key = process_subs_get_setting( 'stripe_secret_key', '' );
        if ( ! empty( $plugin_key ) ) {
            return $plugin_key;
        }

        // Fall back to WooCommerce Stripe Gateway settings
        $stripe_settings = get_option( 'woocommerce_stripe_settings', array() );

        if ( ! empty( $stripe_settings ) ) {
            $testmode = isset( $stripe_settings['testmode'] ) && 'yes' === $stripe_settings['testmode'];

            if ( $testmode ) {
                return $stripe_settings['test_secret_key'] ?? '';
            } else {
                return $stripe_settings['secret_key'] ?? '';
            }
        }

        return '';
    }

    /**
     * Get Stripe publishable key
     *
     * @return string
     */
    public function get_publishable_key() {
        // Prioritise plugin settings if set
        $plugin_key = process_subs_get_setting( 'stripe_publishable_key', '' );
        if ( ! empty( $plugin_key ) ) {
            return $plugin_key;
        }

        // Fall back to WooCommerce Stripe Gateway settings
        $stripe_settings = get_option( 'woocommerce_stripe_settings', array() );

        if ( ! empty( $stripe_settings ) ) {
            $testmode = isset( $stripe_settings['testmode'] ) && 'yes' === $stripe_settings['testmode'];

            if ( $testmode ) {
                return $stripe_settings['test_publishable_key'] ?? '';
            } else {
                return $stripe_settings['publishable_key'] ?? '';
            }
        }

        return '';
    }

    /**
     * Check if Stripe is configured
     *
     * @return bool
     */
    public function is_configured() {
        return ! empty( $this->get_secret_key() );
    }

    /**
     * Make Stripe API request
     *
     * @param string $endpoint API endpoint.
     * @param array  $data Request data.
     * @param string $method HTTP method.
     * @return array|WP_Error
     */
    public function api_request( $endpoint, $data = array(), $method = 'POST' ) {
        $secret_key = $this->get_secret_key();

        if ( empty( $secret_key ) ) {
            return new WP_Error( 'stripe_not_configured', __( 'Stripe is not configured.', 'process-subscriptions' ) );
        }

        $url = 'https://api.stripe.com/v1/' . $endpoint;

        $args = array(
            'method'  => $method,
            'headers' => array(
                'Authorization'  => 'Bearer ' . $secret_key,
                'Stripe-Version' => $this->api_version,
                'Content-Type'   => 'application/x-www-form-urlencoded',
            ),
            'timeout' => 30,
        );

        if ( ! empty( $data ) && in_array( $method, array( 'POST', 'PUT' ), true ) ) {
            $args['body'] = $data;
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $code = wp_remote_retrieve_response_code( $response );

        if ( $code >= 400 ) {
            $error_message = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Stripe API error';
            return new WP_Error( 'stripe_error', $error_message, $body );
        }

        return $body;
    }

    /**
     * Create or get Stripe customer
     *
     * @param WC_Order $order Order object.
     * @return string|WP_Error Customer ID or error.
     */
    public function get_or_create_customer( $order ) {
        $user_id = $order->get_user_id();

        // Check if user already has a Stripe customer ID
        if ( $user_id ) {
            $customer_id = get_user_meta( $user_id, '_stripe_customer_id', true );
            if ( $customer_id && $this->validate_customer_exists( $customer_id ) ) {
                return $customer_id;
            }
            // Clear invalid customer ID
            if ( $customer_id ) {
                delete_user_meta( $user_id, '_stripe_customer_id' );
            }
        }

        // Check order meta for Stripe customer ID (set by WooCommerce Stripe Gateway)
        $customer_id = $order->get_meta( '_stripe_customer_id' );
        if ( $customer_id && $this->validate_customer_exists( $customer_id ) ) {
            if ( $user_id ) {
                update_user_meta( $user_id, '_stripe_customer_id', $customer_id );
            }
            return $customer_id;
        }

        // Create new customer
        $customer_data = array(
            'email'    => $order->get_billing_email(),
            'name'     => $order->get_formatted_billing_full_name(),
            'metadata' => array(
                'wordpress_user_id' => $user_id,
                'order_id'          => $order->get_id(),
            ),
        );

        // Add address
        $customer_data['address'] = array(
            'line1'       => $order->get_billing_address_1(),
            'line2'       => $order->get_billing_address_2(),
            'city'        => $order->get_billing_city(),
            'postal_code' => $order->get_billing_postcode(),
            'country'     => $order->get_billing_country(),
        );

        $response = $this->api_request( 'customers', $customer_data );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $customer_id = $response['id'];

        // Save customer ID
        if ( $user_id ) {
            update_user_meta( $user_id, '_stripe_customer_id', $customer_id );
        }
        $order->update_meta_data( '_stripe_customer_id', $customer_id );
        $order->save();

        return $customer_id;
    }

    /**
     * Validate that a Stripe customer exists
     *
     * @param string $customer_id Stripe customer ID.
     * @return bool True if customer exists, false otherwise.
     */
    private function validate_customer_exists( $customer_id ) {
        if ( empty( $customer_id ) ) {
            return false;
        }

        $response = $this->api_request( 'customers/' . $customer_id, array(), 'GET' );

        // If we get a WP_Error or the customer has been deleted, return false
        if ( is_wp_error( $response ) ) {
            return false;
        }

        // Check if customer is deleted
        if ( isset( $response['deleted'] ) && $response['deleted'] ) {
            return false;
        }

        return isset( $response['id'] );
    }

    /**
     * Create Stripe subscription
     *
     * @param WC_Order $order Order object.
     * @param array    $subscription_data Subscription data from DB.
     * @return array|WP_Error
     */
    public function create_subscription( $order, $subscription_data ) {
        // Get customer
        $customer_id = $this->get_or_create_customer( $order );
        if ( is_wp_error( $customer_id ) ) {
            return $customer_id;
        }

        // Get payment method from the order
        $payment_method = $order->get_meta( '_stripe_source_id' );
        if ( empty( $payment_method ) ) {
            $payment_method = $order->get_meta( '_stripe_payment_method' );
        }

        // Create or get price
        $price_id = $this->get_or_create_price( $subscription_data );
        if ( is_wp_error( $price_id ) ) {
            return $price_id;
        }

        // Build subscription data
        $sub_data = array(
            'customer' => $customer_id,
            'items'    => array(
                array(
                    'price' => $price_id,
                ),
            ),
            'metadata' => array(
                'order_id'        => $order->get_id(),
                'subscription_id' => $subscription_data['id'],
                'product_id'      => $subscription_data['product_id'],
            ),
        );

        // Add payment method if available and valid
        $valid_payment_method = false;
        if ( $payment_method ) {
            // Validate the payment method exists and is attached to customer
            $pm_response = $this->api_request( 'payment_methods/' . $payment_method, array(), 'GET' );
            if ( ! is_wp_error( $pm_response ) && isset( $pm_response['id'] ) ) {
                // Check if attached to our customer, if not try to attach it
                if ( empty( $pm_response['customer'] ) ) {
                    $attach_response = $this->api_request( 'payment_methods/' . $payment_method . '/attach', array(
                        'customer' => $customer_id,
                    ) );
                    if ( ! is_wp_error( $attach_response ) ) {
                        $valid_payment_method = true;
                        $sub_data['default_payment_method'] = $payment_method;
                    }
                } elseif ( $pm_response['customer'] === $customer_id ) {
                    $valid_payment_method = true;
                    $sub_data['default_payment_method'] = $payment_method;
                }
            }
        }

        // Add trial if set
        $trial_days = get_post_meta( $subscription_data['product_id'], '_subscription_trial_days', true );
        if ( $trial_days && intval( $trial_days ) > 0 ) {
            $sub_data['trial_period_days'] = intval( $trial_days );
        }

        // If there's a trial or the first payment is already taken, start billing from next period
        $sub_data['billing_cycle_anchor'] = strtotime( '+' . $subscription_data['billing_interval'] . ' ' . $subscription_data['billing_period'] );

        // Payment behavior depends on whether we have a valid payment method
        if ( $valid_payment_method ) {
            $sub_data['payment_behavior'] = 'default_incomplete';
        } else {
            // No valid payment method - create subscription anyway, Stripe will email customer for payment
            $sub_data['payment_behavior'] = 'allow_incomplete';
            $sub_data['collection_method'] = 'send_invoice';
            $sub_data['days_until_due'] = 7; // Give customer 7 days to pay invoice
        }
        $sub_data['proration_behavior'] = 'none';

        $response = $this->api_request( 'subscriptions', $sub_data );

        return $response;
    }

    /**
     * Get or create Stripe price for subscription
     *
     * @param array $subscription_data Subscription data.
     * @return string|WP_Error Price ID or error.
     */
    private function get_or_create_price( $subscription_data ) {
        $product_id = $subscription_data['product_id'];

        // Check if we already have a price ID for this product
        $price_id = get_post_meta( $product_id, '_stripe_price_id', true );
        if ( $price_id ) {
            // Validate price exists in Stripe (handles test/live mode switch)
            $price_check = $this->api_request( 'prices/' . $price_id, array(), 'GET' );
            if ( ! is_wp_error( $price_check ) && isset( $price_check['id'] ) ) {
                return $price_id;
            }
            // Price doesn't exist - clear cached ID and create new one
            delete_post_meta( $product_id, '_stripe_price_id' );
        }

        // Get or create Stripe product
        $stripe_product_id = $this->get_or_create_stripe_product( $product_id );
        if ( is_wp_error( $stripe_product_id ) ) {
            return $stripe_product_id;
        }

        // Create price
        $price_data = array(
            'product'     => $stripe_product_id,
            'unit_amount' => intval( floatval( $subscription_data['amount'] ) * 100 ), // Convert to pence
            'currency'    => strtolower( $subscription_data['currency'] ),
            'recurring'   => array(
                'interval'       => $subscription_data['billing_period'],
                'interval_count' => $subscription_data['billing_interval'],
            ),
        );

        $response = $this->api_request( 'prices', $price_data );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Save price ID
        update_post_meta( $product_id, '_stripe_price_id', $response['id'] );

        return $response['id'];
    }

    /**
     * Get or create Stripe product
     *
     * @param int $product_id WooCommerce product ID.
     * @return string|WP_Error Stripe product ID or error.
     */
    private function get_or_create_stripe_product( $product_id ) {
        // Check if we already have a Stripe product ID
        $stripe_product_id = get_post_meta( $product_id, '_stripe_product_id', true );
        if ( $stripe_product_id ) {
            // Validate product exists in Stripe (handles test/live mode switch)
            $product_check = $this->api_request( 'products/' . $stripe_product_id, array(), 'GET' );
            if ( ! is_wp_error( $product_check ) && isset( $product_check['id'] ) && empty( $product_check['deleted'] ) ) {
                return $stripe_product_id;
            }
            // Product doesn't exist - clear cached ID and create new one
            delete_post_meta( $product_id, '_stripe_product_id' );
            delete_post_meta( $product_id, '_stripe_price_id' ); // Also clear price as it depends on product
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( 'product_not_found', 'Product not found' );
        }

        $product_data = array(
            'name'     => $product->get_name(),
            'metadata' => array(
                'wc_product_id' => $product_id,
            ),
        );

        $response = $this->api_request( 'products', $product_data );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Save Stripe product ID
        update_post_meta( $product_id, '_stripe_product_id', $response['id'] );

        return $response['id'];
    }

    /**
     * Cancel Stripe subscription
     *
     * @param string $stripe_subscription_id Stripe subscription ID.
     * @param bool   $immediately Cancel immediately or at period end.
     * @return array|WP_Error
     */
    public function cancel_subscription( $stripe_subscription_id, $immediately = false ) {
        if ( $immediately ) {
            return $this->api_request( 'subscriptions/' . $stripe_subscription_id, array(), 'DELETE' );
        }

        return $this->api_request( 'subscriptions/' . $stripe_subscription_id, array(
            'cancel_at_period_end' => 'true',
        ) );
    }

    /**
     * Get Stripe subscription
     *
     * @param string $stripe_subscription_id Stripe subscription ID.
     * @return array|WP_Error
     */
    public function get_subscription( $stripe_subscription_id ) {
        return $this->api_request( 'subscriptions/' . $stripe_subscription_id, array(), 'GET' );
    }

    /**
     * Handle payment complete - create subscription if needed
     *
     * @param int $order_id Order ID.
     */
    public function handle_payment_complete( $order_id ) {
        $this->maybe_create_subscription( $order_id );
    }

    /**
     * Handle order complete
     *
     * @param int $order_id Order ID.
     */
    public function handle_order_complete( $order_id ) {
        $this->maybe_create_subscription( $order_id );
    }

    /**
     * Maybe create subscription for order
     *
     * @param int $order_id Order ID.
     */
    private function maybe_create_subscription( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Check if we've already processed this order
        if ( $order->get_meta( '_subscription_created' ) === 'yes' ) {
            return;
        }

        // Check for subscription items
        $has_subscription = false;
        foreach ( $order->get_items() as $item ) {
            if ( $item->get_meta( '_is_subscription' ) === 'yes' ) {
                $has_subscription = true;
                break;
            }
        }

        if ( ! $has_subscription ) {
            return;
        }

        // Create subscriptions via the manager
        $manager = Process_Subscription_Manager::get_instance();
        $manager->create_subscriptions_for_order( $order );

        $order->update_meta_data( '_subscription_created', 'yes' );
        $order->save();
    }
}
