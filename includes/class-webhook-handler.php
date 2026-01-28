<?php
/**
 * Stripe Webhook Handler
 *
 * @package ProcessSubscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Webhook Handler Class
 */
class Process_Webhook_Handler {

    /**
     * Instance
     *
     * @var Process_Webhook_Handler
     */
    private static $instance = null;

    /**
     * Get instance
     *
     * @return Process_Webhook_Handler
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
        add_action( 'rest_api_init', array( $this, 'register_webhook_endpoint' ) );
        add_action( 'woocommerce_api_process_subscriptions_webhook', array( $this, 'handle_webhook' ) );
    }

    /**
     * Register REST API endpoint
     */
    public function register_webhook_endpoint() {
        register_rest_route( 'process-subscriptions/v1', '/webhook', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_webhook' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Handle incoming webhook
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function handle_webhook( $request = null ) {
        $payload = file_get_contents( 'php://input' );
        $event = json_decode( $payload, true );

        if ( ! $event || ! isset( $event['type'] ) ) {
            return new WP_REST_Response( array( 'error' => 'Invalid payload' ), 400 );
        }

        // Verify webhook signature if secret is set
        $webhook_secret = process_subs_get_setting( 'stripe_webhook_secret', '' );
        if ( $webhook_secret ) {
            $sig_header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';

            if ( ! $this->verify_signature( $payload, $sig_header, $webhook_secret ) ) {
                return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 400 );
            }
        }

        // Log webhook
        $this->log_webhook( $event );

        // Handle event
        $handled = $this->process_event( $event );

        return new WP_REST_Response( array( 'received' => true, 'handled' => $handled ), 200 );
    }

    /**
     * Verify Stripe signature
     *
     * @param string $payload Raw payload.
     * @param string $sig_header Signature header.
     * @param string $secret Webhook secret.
     * @return bool
     */
    private function verify_signature( $payload, $sig_header, $secret ) {
        if ( empty( $sig_header ) ) {
            return false;
        }

        // Parse signature header
        $parts = array();
        foreach ( explode( ',', $sig_header ) as $part ) {
            $kv = explode( '=', $part, 2 );
            if ( count( $kv ) === 2 ) {
                $parts[ $kv[0] ] = $kv[1];
            }
        }

        if ( ! isset( $parts['t'] ) || ! isset( $parts['v1'] ) ) {
            return false;
        }

        $timestamp = $parts['t'];
        $signature = $parts['v1'];

        // Check timestamp (allow 5 minute tolerance)
        if ( abs( time() - $timestamp ) > 300 ) {
            return false;
        }

        // Compute expected signature
        $signed_payload = $timestamp . '.' . $payload;
        $expected = hash_hmac( 'sha256', $signed_payload, $secret );

        return hash_equals( $expected, $signature );
    }

    /**
     * Process webhook event
     *
     * @param array $event Event data.
     * @return bool
     */
    private function process_event( $event ) {
        $manager = Process_Subscription_Manager::get_instance();

        switch ( $event['type'] ) {
            case 'invoice.payment_succeeded':
                return $this->handle_payment_succeeded( $event['data']['object'], $manager );

            case 'invoice.payment_failed':
                return $this->handle_payment_failed( $event['data']['object'], $manager );

            case 'customer.subscription.updated':
                return $this->handle_subscription_updated( $event['data']['object'], $manager );

            case 'customer.subscription.deleted':
                return $this->handle_subscription_deleted( $event['data']['object'], $manager );

            case 'customer.subscription.trial_will_end':
                return $this->handle_trial_ending( $event['data']['object'], $manager );

            default:
                return false;
        }
    }

    /**
     * Handle successful payment
     *
     * @param array                        $invoice Invoice object.
     * @param Process_Subscription_Manager $manager Subscription manager.
     * @return bool
     */
    private function handle_payment_succeeded( $invoice, $manager ) {
        if ( empty( $invoice['subscription'] ) ) {
            return false;
        }

        $subscription = $manager->get_by_stripe_id( $invoice['subscription'] );
        if ( ! $subscription ) {
            return false;
        }

        // Renew the subscription
        $manager->renew( $subscription['id'] );

        // Trigger license renewal
        do_action( 'process_subscription_payment_received', $subscription['id'], $invoice );

        // Send receipt email
        $this->send_payment_receipt( $subscription, $invoice );

        return true;
    }

    /**
     * Handle failed payment
     *
     * @param array                        $invoice Invoice object.
     * @param Process_Subscription_Manager $manager Subscription manager.
     * @return bool
     */
    private function handle_payment_failed( $invoice, $manager ) {
        if ( empty( $invoice['subscription'] ) ) {
            return false;
        }

        $subscription = $manager->get_by_stripe_id( $invoice['subscription'] );
        if ( ! $subscription ) {
            return false;
        }

        // Update status
        $manager->update( $subscription['id'], array(
            'status' => 'past_due',
        ) );

        // Trigger action
        do_action( 'process_subscription_payment_failed', $subscription['id'], $invoice );

        // Send failed payment email
        $this->send_payment_failed_email( $subscription, $invoice );

        return true;
    }

    /**
     * Handle subscription updated
     *
     * @param array                        $stripe_sub Stripe subscription object.
     * @param Process_Subscription_Manager $manager Subscription manager.
     * @return bool
     */
    private function handle_subscription_updated( $stripe_sub, $manager ) {
        $subscription = $manager->get_by_stripe_id( $stripe_sub['id'] );
        if ( ! $subscription ) {
            return false;
        }

        $update_data = array();

        // Map Stripe status to our status
        $status_map = array(
            'active'            => 'active',
            'past_due'          => 'past_due',
            'unpaid'            => 'past_due',
            'canceled'          => 'cancelled',
            'incomplete'        => 'pending',
            'incomplete_expired'=> 'expired',
            'trialing'          => 'active',
        );

        if ( isset( $status_map[ $stripe_sub['status'] ] ) ) {
            $update_data['status'] = $status_map[ $stripe_sub['status'] ];
        }

        // Update next payment date
        if ( isset( $stripe_sub['current_period_end'] ) ) {
            $update_data['next_payment'] = date( 'Y-m-d H:i:s', $stripe_sub['current_period_end'] );
        }

        // Check for cancellation
        if ( ! empty( $stripe_sub['cancel_at_period_end'] ) ) {
            $update_data['status'] = 'pending-cancel';
            $update_data['cancelled_at'] = current_time( 'mysql' );
            $update_data['expires_at'] = date( 'Y-m-d H:i:s', $stripe_sub['current_period_end'] );
        }

        if ( ! empty( $update_data ) ) {
            $manager->update( $subscription['id'], $update_data );
            do_action( 'process_subscription_status_changed', $subscription['id'], $update_data['status'] ?? $subscription['status'] );
        }

        return true;
    }

    /**
     * Handle subscription deleted
     *
     * @param array                        $stripe_sub Stripe subscription object.
     * @param Process_Subscription_Manager $manager Subscription manager.
     * @return bool
     */
    private function handle_subscription_deleted( $stripe_sub, $manager ) {
        $subscription = $manager->get_by_stripe_id( $stripe_sub['id'] );
        if ( ! $subscription ) {
            return false;
        }

        $manager->update( $subscription['id'], array(
            'status'     => 'cancelled',
            'expires_at' => current_time( 'mysql' ),
        ) );

        do_action( 'process_subscription_ended', $subscription['id'] );

        return true;
    }

    /**
     * Handle trial ending
     *
     * @param array                        $stripe_sub Stripe subscription object.
     * @param Process_Subscription_Manager $manager Subscription manager.
     * @return bool
     */
    private function handle_trial_ending( $stripe_sub, $manager ) {
        $subscription = $manager->get_by_stripe_id( $stripe_sub['id'] );
        if ( ! $subscription ) {
            return false;
        }

        // Send trial ending email
        $this->send_trial_ending_email( $subscription, $stripe_sub['trial_end'] );

        do_action( 'process_subscription_trial_ending', $subscription['id'] );

        return true;
    }

    /**
     * Log webhook
     *
     * @param array $event Event data.
     */
    private function log_webhook( $event ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'Process Subscriptions Webhook: ' . $event['type'] . ' - ' . $event['id'] );
        }
    }

    /**
     * Send payment receipt email
     *
     * @param array $subscription Subscription data.
     * @param array $invoice Invoice data.
     */
    private function send_payment_receipt( $subscription, $invoice ) {
        $order = wc_get_order( $subscription['order_id'] );
        if ( ! $order ) {
            return;
        }

        $product = wc_get_product( $subscription['product_id'] );
        $product_name = $product ? $product->get_name() : 'Subscription #' . $subscription['id'];

        $to = $order->get_billing_email();
        $subject = sprintf( __( 'Payment received for %s', 'process-subscriptions' ), $product_name );

        $message = sprintf(
            __( "Hello %s,\n\nThank you! We've received your payment of %s for %s.\n\nYour subscription will continue until %s.\n\nThank you for your business!", 'process-subscriptions' ),
            $order->get_billing_first_name(),
            wc_price( $invoice['amount_paid'] / 100 ),
            $product_name,
            date_i18n( get_option( 'date_format' ), strtotime( $subscription['next_payment'] ) )
        );

        wp_mail( $to, $subject, $message );
    }

    /**
     * Send payment failed email
     *
     * @param array $subscription Subscription data.
     * @param array $invoice Invoice data.
     */
    private function send_payment_failed_email( $subscription, $invoice ) {
        $order = wc_get_order( $subscription['order_id'] );
        if ( ! $order ) {
            return;
        }

        $product = wc_get_product( $subscription['product_id'] );
        $product_name = $product ? $product->get_name() : 'Subscription #' . $subscription['id'];

        $to = $order->get_billing_email();
        $subject = sprintf( __( 'Payment failed for %s', 'process-subscriptions' ), $product_name );

        $message = sprintf(
            __( "Hello %s,\n\nWe were unable to process your payment of %s for %s.\n\nPlease update your payment method to avoid service interruption.\n\nIf you have any questions, please contact us.", 'process-subscriptions' ),
            $order->get_billing_first_name(),
            wc_price( $invoice['amount_due'] / 100 ),
            $product_name
        );

        wp_mail( $to, $subject, $message );
    }

    /**
     * Send trial ending email
     *
     * @param array $subscription Subscription data.
     * @param int   $trial_end Trial end timestamp.
     */
    private function send_trial_ending_email( $subscription, $trial_end ) {
        $order = wc_get_order( $subscription['order_id'] );
        if ( ! $order ) {
            return;
        }

        $product = wc_get_product( $subscription['product_id'] );
        $product_name = $product ? $product->get_name() : 'Subscription #' . $subscription['id'];

        $to = $order->get_billing_email();
        $subject = sprintf( __( 'Your %s trial is ending soon', 'process-subscriptions' ), $product_name );

        $message = sprintf(
            __( "Hello %s,\n\nYour free trial for %s will end on %s.\n\nYour subscription will automatically continue at %s per %s. If you wish to cancel, you can do so from your account.\n\nThank you for trying %s!", 'process-subscriptions' ),
            $order->get_billing_first_name(),
            $product_name,
            date_i18n( get_option( 'date_format' ), $trial_end ),
            wc_price( $subscription['amount'] ),
            $subscription['billing_period'],
            $product_name
        );

        wp_mail( $to, $subject, $message );
    }
}
