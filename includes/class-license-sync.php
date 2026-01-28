<?php
/**
 * License System Sync
 *
 * @package ProcessSubscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * License Sync Class
 */
class Process_License_Sync {

    /**
     * Instance
     *
     * @var Process_License_Sync
     */
    private static $instance = null;

    /**
     * Get instance
     *
     * @return Process_License_Sync
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
        // Hook into subscription events
        add_action( 'process_subscription_created', array( $this, 'create_license' ), 10, 3 );
        add_action( 'process_subscription_renewed', array( $this, 'renew_license' ) );
        add_action( 'process_subscription_cancelled', array( $this, 'handle_cancellation' ), 10, 2 );
        add_action( 'process_subscription_ended', array( $this, 'suspend_license' ) );
        add_action( 'process_subscription_expired', array( $this, 'suspend_license' ) );
        add_action( 'process_subscription_payment_failed', array( $this, 'handle_payment_failed' ), 10, 2 );
        add_action( 'process_subscription_status_changed', array( $this, 'handle_status_change' ), 10, 2 );

        // Note: Refund/cancel hooks removed - license revocation is now handled manually
        // via the Order License Admin metabox with admin confirmation.
        // See class-order-license-admin.php for the UI implementation.
    }

    /**
     * Get license API URL
     *
     * @return string
     */
    private function get_api_url() {
        return defined( 'PROCESS_LICENSE_API_URL' )
            ? str_replace( 'create-license.php', '', PROCESS_LICENSE_API_URL )
            : 'https://pro-cess.co.uk/license-system/api/';
    }

    /**
     * Get license API key
     *
     * @return string
     */
    private function get_api_key() {
        return defined( 'PROCESS_LICENSE_API_KEY' ) ? PROCESS_LICENSE_API_KEY : '';
    }

    /**
     * Create license for new subscription
     *
     * @param int                      $subscription_id Subscription ID.
     * @param WC_Order                 $order Order object.
     * @param WC_Order_Item_Product    $item Order item.
     */
    public function create_license( $subscription_id, $order, $item ) {
        $plugin_slug = $item->get_meta( '_subscription_plugin_slug' );
        $license_type = $item->get_meta( '_subscription_license_type' ) ?: 'basic';

        if ( empty( $plugin_slug ) ) {
            return;
        }

        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            $order->add_order_note( 'License creation skipped: API key not configured.' );
            return;
        }

        $response = wp_remote_post( $this->get_api_url() . 'create-license.php', array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Key'    => $api_key,
            ),
            'body'    => wp_json_encode( array(
                'email'           => $order->get_billing_email(),
                'customer_name'   => $order->get_formatted_billing_full_name(),
                'plugin_slug'     => $plugin_slug,
                'license_type'    => $license_type,
                'order_id'        => $order->get_id(),
                'subscription_id' => $subscription_id,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            $order->add_order_note( 'License creation failed: ' . $response->get_error_message() );
            return;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body['success'] ) && ! empty( $body['license']['serial_key'] ) ) {
            // Store license info
            global $wpdb;
            $table = $wpdb->prefix . 'process_subscriptions';

            $wpdb->update(
                $table,
                array( 'license_key' => $body['license']['serial_key'] ),
                array( 'id' => $subscription_id ),
                array( '%s' ),
                array( '%d' )
            );

            // Also store on order
            $order->update_meta_data( '_subscription_license_key_' . $subscription_id, $body['license']['serial_key'] );
            $order->save();

            $order->add_order_note( sprintf(
                'License created for subscription #%d: %s',
                $subscription_id,
                $body['license']['serial_key']
            ) );

            // Send license email
            $this->send_license_email( $order, $body, $item->get_name() );
        } else {
            $error = $body['error'] ?? 'Unknown error';
            $order->add_order_note( 'License creation failed: ' . $error );
        }
    }

    /**
     * Renew license
     *
     * @param int $subscription_id Subscription ID.
     */
    public function renew_license( $subscription_id ) {
        $subscription = Process_Subscription_Manager::get_instance()->get( $subscription_id );
        if ( ! $subscription ) {
            return;
        }

        // Extend license expiry based on billing period
        $extend_days = $this->get_extension_days( $subscription );
        $this->extend_license_expiry( $subscription, $extend_days );
    }

    /**
     * Get extension days based on subscription billing period
     *
     * @param array $subscription Subscription data.
     * @return int Days to extend.
     */
    private function get_extension_days( $subscription ) {
        $interval = intval( $subscription['billing_interval'] );
        $period = $subscription['billing_period'];

        switch ( $period ) {
            case 'day':
                return $interval;
            case 'week':
                return $interval * 7;
            case 'month':
                return $interval * 30;
            case 'year':
                return $interval * 365;
            default:
                return 365; // Default to 1 year
        }
    }

    /**
     * Extend license expiry
     *
     * @param array $subscription Subscription data.
     * @param int   $days Days to extend.
     */
    private function extend_license_expiry( $subscription, $days ) {
        $license_key = $subscription['license_key'] ?? '';

        if ( empty( $license_key ) ) {
            $order = wc_get_order( $subscription['order_id'] );
            if ( $order ) {
                $license_key = $order->get_meta( '_subscription_license_key_' . $subscription['id'] );
            }
        }

        if ( empty( $license_key ) ) {
            return;
        }

        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            return;
        }

        $response = wp_remote_post( $this->get_api_url() . 'update-license.php', array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Key'    => $api_key,
            ),
            'body'    => wp_json_encode( array(
                'license_key' => $license_key,
                'status'      => 'active',
                'extend_days' => $days,
            ) ),
        ) );

        // Log result
        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $order = wc_get_order( $subscription['order_id'] );
            if ( $order && ! empty( $body['success'] ) ) {
                $order->add_order_note( sprintf(
                    'License renewed: %s (extended by %d days, new expiry: %s)',
                    $license_key,
                    $days,
                    $body['license']['expires_at'] ?? 'unknown'
                ) );
            }
        }
    }

    /**
     * Handle cancellation
     *
     * @param int  $subscription_id Subscription ID.
     * @param bool $immediately Whether cancelled immediately.
     */
    public function handle_cancellation( $subscription_id, $immediately ) {
        if ( $immediately ) {
            $this->suspend_license( $subscription_id );
        }
        // If not immediate, license will be suspended when subscription ends
    }

    /**
     * Suspend license
     *
     * @param int $subscription_id Subscription ID.
     */
    public function suspend_license( $subscription_id ) {
        $subscription = Process_Subscription_Manager::get_instance()->get( $subscription_id );
        if ( ! $subscription ) {
            return;
        }

        $this->update_license_status( $subscription, 'suspended' );
    }

    /**
     * Handle payment failed
     *
     * @param int   $subscription_id Subscription ID.
     * @param array $invoice Invoice data.
     */
    public function handle_payment_failed( $subscription_id, $invoice ) {
        // Don't immediately suspend - give grace period
        // The daily cron will handle expiration if payment isn't received
    }


    /**
     * Handle status change
     *
     * @param int    $subscription_id Subscription ID.
     * @param string $new_status New status.
     */
    public function handle_status_change( $subscription_id, $new_status ) {
        $subscription = Process_Subscription_Manager::get_instance()->get( $subscription_id );
        if ( ! $subscription ) {
            return;
        }

        switch ( $new_status ) {
            case 'active':
                $this->update_license_status( $subscription, 'active' );
                break;

            case 'cancelled':
            case 'expired':
                $this->update_license_status( $subscription, 'suspended' );
                break;

            case 'past_due':
                // Grace period - don't suspend yet
                break;
        }
    }

    /**
     * Update license status in license system
     *
     * @param array  $subscription Subscription data.
     * @param string $status Status to set.
     */
    private function update_license_status( $subscription, $status ) {
        // Get license key from subscription or order
        $license_key = $subscription['license_key'] ?? '';

        if ( empty( $license_key ) ) {
            $order = wc_get_order( $subscription['order_id'] );
            if ( $order ) {
                $license_key = $order->get_meta( '_subscription_license_key_' . $subscription['id'] );
            }
        }

        if ( empty( $license_key ) ) {
            return;
        }

        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            return;
        }

        // Call license system API to update status
        // Note: You may need to add an endpoint to your license system for this
        wp_remote_post( $this->get_api_url() . 'update-license.php', array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Key'    => $api_key,
            ),
            'body'    => wp_json_encode( array(
                'license_key' => $license_key,
                'status'      => $status,
            ) ),
        ) );
    }

    /**
     * Send license email to customer
     *
     * @param WC_Order $order Order object.
     * @param array    $license_data License data from API.
     * @param string   $product_name Product name.
     */
    private function send_license_email( $order, $license_data, $product_name ) {
        $to = $order->get_billing_email();
        $subject = sprintf( __( 'Your %s License Key', 'process-subscriptions' ), $product_name );

        // Calculate renewal date (1 year from now for annual subscriptions)
        $renewal_date = date_i18n( 'j F Y', strtotime( '+1 year' ) );

        // Get subscription to check billing period
        global $wpdb;
        $table = $wpdb->prefix . 'process_subscriptions';
        $subscription = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE order_id = %d LIMIT 1",
            $order->get_id()
        ), ARRAY_A );

        if ( $subscription ) {
            $interval = intval( $subscription['billing_interval'] ?? 1 );
            $period = $subscription['billing_period'] ?? 'year';

            switch ( $period ) {
                case 'day':
                    $renewal_date = date_i18n( 'j F Y', strtotime( "+{$interval} days" ) );
                    break;
                case 'week':
                    $renewal_date = date_i18n( 'j F Y', strtotime( "+{$interval} weeks" ) );
                    break;
                case 'month':
                    $renewal_date = date_i18n( 'j F Y', strtotime( "+{$interval} months" ) );
                    break;
                case 'year':
                    $renewal_date = date_i18n( 'j F Y', strtotime( "+{$interval} years" ) );
                    break;
            }
        }

        $account_url = wc_get_account_endpoint_url( 'subscriptions' );

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellspacing="0" cellpadding="0" style="background-color: #f4f4f4;">
        <tr>
            <td style="padding: 30px 0;">
                <table width="600" cellspacing="0" cellpadding="0" style="margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <!-- Header with Logo -->
                    <tr>
                        <td style="background: #1a1a2e; padding: 40px 30px; text-align: center;">
                            <img src="https://pro-cess.co.uk/wp-content/themes/pro-cess/assets/images/logo-white.svg" alt="Pro-cess" width="200" style="max-width: 200px; height: auto;" />
                        </td>
                    </tr>

                    <!-- Main Content -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <h1 style="color: #1a1a2e; margin: 0 0 25px; font-size: 28px; font-weight: 600;">Your License Key is Ready!</h1>

                            <p style="font-size: 16px; color: #333; margin: 0 0 15px;">Hi <?php echo esc_html( $order->get_billing_first_name() ); ?>,</p>

                            <p style="font-size: 16px; color: #333; line-height: 1.6; margin: 0 0 30px;">Thank you for subscribing to <strong><?php echo esc_html( $product_name ); ?></strong>! Your license key has been generated and is ready to use.</p>

                            <!-- License Key Box -->
                            <div style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); padding: 30px; border-radius: 8px; margin: 0 0 30px;">
                                <p style="margin: 0 0 15px; font-size: 12px; text-transform: uppercase; letter-spacing: 2px; color: #888; text-align: center;">Your License Key</p>
                                <p style="font-family: 'Courier New', monospace; font-size: 22px; color: #00d4aa; text-align: center; margin: 0; letter-spacing: 2px; word-break: break-all;">
                                    <?php echo esc_html( $license_data['license']['serial_key'] ); ?>
                                </p>
                            </div>

                            <!-- Download Button -->
                            <?php if ( ! empty( $license_data['download_url'] ) ) : ?>
                            <p style="text-align: center; margin: 0 0 35px;">
                                <a href="<?php echo esc_url( $license_data['download_url'] ); ?>" style="display: inline-block; background-color: #00d4aa; color: #1a1a2e; padding: 16px 40px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 16px;">Download Plugin</a>
                            </p>
                            <?php endif; ?>

                            <!-- Subscription Details -->
                            <div style="background: #f8f9fa; padding: 20px 25px; border-radius: 6px; border-left: 4px solid #00d4aa;">
                                <p style="margin: 0 0 8px; font-weight: 600; color: #1a1a2e;">Subscription Details:</p>
                                <p style="margin: 0 0 5px; font-size: 14px; color: #555;">Your license will automatically renew on <strong><?php echo esc_html( $renewal_date ); ?></strong>.</p>
                                <p style="margin: 0; font-size: 14px; color: #555;">You can manage or cancel your subscription anytime from your <a href="<?php echo esc_url( $account_url ); ?>" style="color: #00d4aa; text-decoration: none;">account dashboard</a>.</p>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8f8f8; padding: 25px 30px; text-align: center; border-top: 1px solid #eee;">
                            <p style="margin: 0; font-size: 14px; color: #888;">Pro-cess Systems &amp; Solutions</p>
                            <p style="margin: 8px 0 0; font-size: 12px; color: #aaa;">Business Systems &amp; WordPress Development</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
        <?php
        $message = ob_get_clean();

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: Pro-cess <lewis@pro-cess.co.uk>',
        );

        wp_mail( $to, $subject, $message, $headers );
    }
}
