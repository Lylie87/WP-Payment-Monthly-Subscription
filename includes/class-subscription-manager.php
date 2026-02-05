<?php
/**
 * Subscription Manager
 *
 * @package ProcessSubscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Subscription Manager Class
 */
class Process_Subscription_Manager {

    /**
     * Instance
     *
     * @var Process_Subscription_Manager
     */
    private static $instance = null;

    /**
     * Table name
     *
     * @var string
     */
    private $table;

    /**
     * Get instance
     *
     * @return Process_Subscription_Manager
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
        global $wpdb;
        $this->table = $wpdb->prefix . 'process_subscriptions';

        // Daily cron for checking expirations
        add_action( 'process_subs_daily_check', array( $this, 'daily_subscription_check' ) );

        // Customer account
        add_action( 'woocommerce_account_dashboard', array( $this, 'show_subscriptions_in_account' ) );

        // Add subscriptions endpoint to My Account
        add_action( 'init', array( $this, 'add_endpoints' ) );
        add_filter( 'woocommerce_account_menu_items', array( $this, 'add_account_menu_item' ) );
        add_action( 'woocommerce_account_subscriptions_endpoint', array( $this, 'subscriptions_endpoint_content' ) );

        // Handle cancellation
        add_action( 'wp_ajax_process_cancel_subscription', array( $this, 'ajax_cancel_subscription' ) );
    }

    /**
     * Add endpoints
     */
    public function add_endpoints() {
        add_rewrite_endpoint( 'subscriptions', EP_ROOT | EP_PAGES );
    }

    /**
     * Add menu item to My Account
     *
     * @param array $items Menu items.
     * @return array
     */
    public function add_account_menu_item( $items ) {
        $new_items = array();

        foreach ( $items as $key => $value ) {
            $new_items[ $key ] = $value;

            if ( 'orders' === $key ) {
                $new_items['subscriptions'] = __( 'Subscriptions', 'process-subscriptions' );
            }
        }

        return $new_items;
    }

    /**
     * Create subscriptions for an order
     *
     * @param WC_Order $order Order object.
     */
    public function create_subscriptions_for_order( $order ) {
        global $wpdb;

        foreach ( $order->get_items() as $item_id => $item ) {
            if ( $item->get_meta( '_is_subscription' ) !== 'yes' ) {
                continue;
            }

            $product_id = $item->get_product_id();
            $price      = $item->get_meta( '_subscription_price' );
            $period     = $item->get_meta( '_subscription_period' ) ?: 'month';
            $interval   = $item->get_meta( '_subscription_interval' ) ?: 1;

            // Check if subscription already exists
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$this->table} WHERE order_id = %d AND order_item_id = %d",
                $order->get_id(),
                $item_id
            ) );

            if ( $existing ) {
                continue;
            }

            // Check for trial period
            $trial_days = get_post_meta( $product_id, '_subscription_trial_days', true );
            $trial_end  = null;
            $status     = 'active';

            if ( $trial_days && intval( $trial_days ) > 0 ) {
                $trial_end    = date( 'Y-m-d H:i:s', strtotime( '+' . intval( $trial_days ) . ' days' ) );
                $next_payment = $trial_end; // First payment after trial ends
                $status       = 'trialing';
            } else {
                $next_payment = date( 'Y-m-d H:i:s', strtotime( "+{$interval} {$period}" ) );
            }

            // Insert subscription
            $insert_data = array(
                'order_id'         => $order->get_id(),
                'order_item_id'    => $item_id,
                'user_id'          => $order->get_user_id(),
                'product_id'       => $product_id,
                'status'           => $status,
                'billing_period'   => $period,
                'billing_interval' => $interval,
                'amount'           => $price,
                'currency'         => $order->get_currency(),
                'next_payment'     => $next_payment,
            );
            $insert_format = array( '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%f', '%s', '%s' );

            if ( $trial_end ) {
                $insert_data['trial_end'] = $trial_end;
                $insert_format[] = '%s';
            } else {
                $insert_data['last_payment'] = current_time( 'mysql' );
                $insert_format[] = '%s';
            }

            $wpdb->insert( $this->table, $insert_data, $insert_format );

            $subscription_id = $wpdb->insert_id;

            // Try to create Stripe subscription
            $stripe = Process_Stripe_Handler::get_instance();
            if ( $stripe->is_configured() ) {
                $subscription_data = $this->get( $subscription_id );
                $result = $stripe->create_subscription( $order, $subscription_data );

                if ( ! is_wp_error( $result ) && isset( $result['id'] ) ) {
                    $this->update( $subscription_id, array(
                        'stripe_subscription_id' => $result['id'],
                        'stripe_customer_id'     => $result['customer'],
                    ) );
                    $order->add_order_note( sprintf(
                        'Stripe subscription created successfully (ID: %s). Next billing: %s',
                        $result['id'],
                        date( 'Y-m-d', $result['billing_cycle_anchor'] ?? strtotime( $next_payment ) )
                    ) );
                } else {
                    // Log error but don't fail - subscription is created locally
                    $error_msg = is_wp_error( $result ) ? $result->get_error_message() : 'Unknown Stripe error';
                    $order->add_order_note( 'Stripe subscription creation failed: ' . $error_msg );
                }
            } else {
                $order->add_order_note( 'Stripe not configured - subscription created locally only (will not auto-renew).' );
            }

            // Trigger license creation
            do_action( 'process_subscription_created', $subscription_id, $order, $item );

            $order->add_order_note( sprintf(
                __( 'Subscription #%d created for %s', 'process-subscriptions' ),
                $subscription_id,
                $item->get_name()
            ) );
        }
    }

    /**
     * Get subscription by ID
     *
     * @param int $id Subscription ID.
     * @return object|null
     */
    public function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
    }

    /**
     * Get subscription by Stripe ID
     *
     * @param string $stripe_id Stripe subscription ID.
     * @return object|null
     */
    public function get_by_stripe_id( $stripe_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE stripe_subscription_id = %s",
            $stripe_id
        ), ARRAY_A );
    }

    /**
     * Update subscription
     *
     * @param int   $id Subscription ID.
     * @param array $data Data to update.
     * @return bool
     */
    public function update( $id, $data ) {
        global $wpdb;

        $data['updated_at'] = current_time( 'mysql' );

        return $wpdb->update( $this->table, $data, array( 'id' => $id ) ) !== false;
    }

    /**
     * Get all subscriptions
     *
     * @param array $args Query arguments.
     * @return array
     */
    public function get_all( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'status'   => '',
            'user_id'  => 0,
            'per_page' => 20,
            'page'     => 1,
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );

        $where = array( '1=1' );
        $values = array();

        if ( $args['status'] ) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        if ( $args['user_id'] ) {
            $where[] = 'user_id = %d';
            $values[] = $args['user_id'];
        }

        $where_clause = implode( ' AND ', $where );
        $offset = ( $args['page'] - 1 ) * $args['per_page'];

        $sql = "SELECT * FROM {$this->table} WHERE {$where_clause} ORDER BY {$args['orderby']} {$args['order']} LIMIT %d OFFSET %d";
        $values[] = $args['per_page'];
        $values[] = $offset;

        return $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
    }

    /**
     * Get subscription count
     *
     * @param string $status Status filter.
     * @return int
     */
    public function get_count( $status = '' ) {
        global $wpdb;

        if ( $status ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE status = %s",
                $status
            ) );
        }

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
    }

    /**
     * Cancel subscription
     *
     * @param int  $id Subscription ID.
     * @param bool $immediately Cancel immediately or at period end.
     * @return bool
     */
    public function cancel( $id, $immediately = false ) {
        $subscription = $this->get( $id );
        if ( ! $subscription ) {
            return false;
        }

        $is_trialing = ( $subscription['status'] === 'trialing' );

        // For trials, always cancel Stripe immediately (no billing period to honour)
        // but let the trial run its natural course locally
        if ( $is_trialing && ! $immediately ) {
            if ( ! empty( $subscription['stripe_subscription_id'] ) ) {
                $stripe = Process_Stripe_Handler::get_instance();
                $stripe->cancel_subscription( $subscription['stripe_subscription_id'], true );
            }

            $update_data = array(
                'status'       => 'pending-cancel',
                'cancelled_at' => current_time( 'mysql' ),
                'expires_at'   => $subscription['trial_end'] ?: $subscription['next_payment'],
            );

            $result = $this->update( $id, $update_data );

            if ( $result ) {
                do_action( 'process_subscription_cancelled', $id, false );
            }

            return $result;
        }

        // Cancel in Stripe if applicable
        if ( ! empty( $subscription['stripe_subscription_id'] ) ) {
            $stripe = Process_Stripe_Handler::get_instance();
            $stripe->cancel_subscription( $subscription['stripe_subscription_id'], $immediately );
        }

        $update_data = array(
            'cancelled_at' => current_time( 'mysql' ),
        );

        if ( $immediately ) {
            $update_data['status'] = 'cancelled';
            $update_data['expires_at'] = current_time( 'mysql' );
        } else {
            $update_data['status'] = 'pending-cancel';
            $update_data['expires_at'] = $subscription['next_payment'];
        }

        $result = $this->update( $id, $update_data );

        if ( $result ) {
            do_action( 'process_subscription_cancelled', $id, $immediately );
        }

        return $result;
    }

    /**
     * Renew subscription
     *
     * @param int $id Subscription ID.
     * @return bool
     */
    public function renew( $id ) {
        $subscription = $this->get( $id );
        if ( ! $subscription ) {
            return false;
        }

        $next_payment = date(
            'Y-m-d H:i:s',
            strtotime( "+{$subscription['billing_interval']} {$subscription['billing_period']}", strtotime( $subscription['next_payment'] ) )
        );

        $result = $this->update( $id, array(
            'status'       => 'active',
            'next_payment' => $next_payment,
            'last_payment' => current_time( 'mysql' ),
        ) );

        if ( $result ) {
            do_action( 'process_subscription_renewed', $id );
        }

        return $result;
    }

    /**
     * Daily subscription check
     */
    public function daily_subscription_check() {
        global $wpdb;

        // Find subscriptions that should have renewed but haven't (no Stripe)
        $pending = $wpdb->get_results(
            "SELECT * FROM {$this->table}
             WHERE status = 'active'
             AND stripe_subscription_id IS NULL
             AND next_payment < NOW()",
            ARRAY_A
        );

        foreach ( $pending as $subscription ) {
            // Mark as expired if no payment received
            $this->update( $subscription['id'], array(
                'status' => 'expired',
            ) );

            do_action( 'process_subscription_expired', $subscription['id'] );
        }

        // Find expired trials (safety net - Stripe should handle this, but just in case)
        $expired_trials = $wpdb->get_results(
            "SELECT * FROM {$this->table}
             WHERE status = 'trialing'
             AND trial_end IS NOT NULL
             AND trial_end < NOW()
             AND stripe_subscription_id IS NULL",
            ARRAY_A
        );

        foreach ( $expired_trials as $subscription ) {
            $this->update( $subscription['id'], array(
                'status' => 'expired',
            ) );

            do_action( 'process_subscription_trial_expired', $subscription['id'] );
        }

        // Find pending-cancel subscriptions past their end date
        $pending_cancel = $wpdb->get_results(
            "SELECT * FROM {$this->table}
             WHERE status = 'pending-cancel'
             AND expires_at < NOW()",
            ARRAY_A
        );

        foreach ( $pending_cancel as $subscription ) {
            $this->update( $subscription['id'], array(
                'status' => 'cancelled',
            ) );

            do_action( 'process_subscription_ended', $subscription['id'] );
        }

        // Send renewal reminders (7 days before)
        $upcoming = $wpdb->get_results(
            "SELECT * FROM {$this->table}
             WHERE status = 'active'
             AND next_payment BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)",
            ARRAY_A
        );

        foreach ( $upcoming as $subscription ) {
            $last_reminder = get_post_meta( $subscription['order_id'], '_renewal_reminder_sent', true );
            if ( $last_reminder !== $subscription['next_payment'] ) {
                do_action( 'process_subscription_renewal_reminder', $subscription['id'] );
                update_post_meta( $subscription['order_id'], '_renewal_reminder_sent', $subscription['next_payment'] );
            }
        }
    }

    /**
     * Show subscriptions in account dashboard
     */
    public function show_subscriptions_in_account() {
        $user_id = get_current_user_id();
        $subscriptions = $this->get_all( array(
            'user_id'  => $user_id,
            'per_page' => 5,
        ) );

        if ( empty( $subscriptions ) ) {
            return;
        }

        echo '<h3>' . esc_html__( 'Your Subscriptions', 'process-subscriptions' ) . '</h3>';
        echo '<table class="woocommerce-orders-table shop_table"><thead><tr>';
        echo '<th>' . esc_html__( 'Product', 'process-subscriptions' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'process-subscriptions' ) . '</th>';
        echo '<th>' . esc_html__( 'Next Payment', 'process-subscriptions' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $subscriptions as $sub ) {
            $product = wc_get_product( $sub['product_id'] );
            echo '<tr>';
            echo '<td>' . ( $product ? esc_html( $product->get_name() ) : 'Product #' . $sub['product_id'] ) . '</td>';
            $status_label = ucfirst( $sub['status'] );
            if ( $sub['status'] === 'trialing' ) {
                $status_label = 'Free Trial';
            }
            echo '<td><span class="subscription-status status-' . esc_attr( $sub['status'] ) . '">' . esc_html( $status_label ) . '</span>';
            if ( $sub['status'] === 'trialing' && ! empty( $sub['trial_end'] ) ) {
                $trial_end_date = date_i18n( get_option( 'date_format' ), strtotime( $sub['trial_end'] ) );
                $days_remaining = max( 0, ceil( ( strtotime( $sub['trial_end'] ) - time() ) / 86400 ) );
                echo '<br><small>Ends ' . esc_html( $trial_end_date ) . ' (' . $days_remaining . ' days left)</small>';
            }
            echo '</td>';
            echo '<td>' . ( $sub['next_payment'] ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $sub['next_payment'] ) ) ) : '—' ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p><a href="' . esc_url( wc_get_account_endpoint_url( 'subscriptions' ) ) . '">' . esc_html__( 'View all subscriptions', 'process-subscriptions' ) . '</a></p>';
    }

    /**
     * Subscriptions endpoint content
     */
    public function subscriptions_endpoint_content() {
        $user_id = get_current_user_id();
        $subscriptions = $this->get_all( array( 'user_id' => $user_id ) );

        if ( empty( $subscriptions ) ) {
            echo '<p>' . esc_html__( 'You have no active subscriptions.', 'process-subscriptions' ) . '</p>';
            return;
        }

        echo '<table class="woocommerce-orders-table shop_table shop_table_responsive">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Subscription', 'process-subscriptions' ) . '</th>';
        echo '<th>' . esc_html__( 'Product', 'process-subscriptions' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'process-subscriptions' ) . '</th>';
        echo '<th>' . esc_html__( 'Price', 'process-subscriptions' ) . '</th>';
        echo '<th>' . esc_html__( 'Next Payment', 'process-subscriptions' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'process-subscriptions' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $subscriptions as $sub ) {
            $product = wc_get_product( $sub['product_id'] );
            $period_label = $sub['billing_interval'] > 1
                ? $sub['billing_interval'] . ' ' . $sub['billing_period'] . 's'
                : $sub['billing_period'];

            echo '<tr>';
            echo '<td>#' . esc_html( $sub['id'] ) . '</td>';
            echo '<td>' . ( $product ? esc_html( $product->get_name() ) : 'Product #' . $sub['product_id'] ) . '</td>';
            $status_display = ucfirst( str_replace( '-', ' ', $sub['status'] ) );
            if ( $sub['status'] === 'trialing' ) {
                $status_display = 'Free Trial';
            }
            echo '<td><span class="subscription-status status-' . esc_attr( $sub['status'] ) . '">' . esc_html( $status_display ) . '</span>';
            if ( $sub['status'] === 'trialing' && ! empty( $sub['trial_end'] ) ) {
                $trial_end_date = date_i18n( get_option( 'date_format' ), strtotime( $sub['trial_end'] ) );
                $days_remaining = max( 0, ceil( ( strtotime( $sub['trial_end'] ) - time() ) / 86400 ) );
                echo '<br><small>Ends ' . esc_html( $trial_end_date ) . ' (' . $days_remaining . ' days left)</small>';
            }
            echo '</td>';
            echo '<td>' . wc_price( $sub['amount'] ) . ' / ' . esc_html( $period_label ) . '</td>';
            echo '<td>' . ( $sub['next_payment'] ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $sub['next_payment'] ) ) ) : '—' ) . '</td>';
            echo '<td>';

            if ( in_array( $sub['status'], array( 'active', 'pending', 'trialing' ), true ) ) {
                echo '<button class="button cancel-subscription" data-id="' . esc_attr( $sub['id'] ) . '" data-nonce="' . wp_create_nonce( 'cancel_subscription_' . $sub['id'] ) . '">' . esc_html__( 'Cancel', 'process-subscriptions' ) . '</button>';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        ?>
        <script>
        jQuery(function($) {
            $('.cancel-subscription').on('click', function(e) {
                e.preventDefault();

                if (!confirm('<?php echo esc_js( __( 'Are you sure you want to cancel this subscription? It will remain active until the end of the current billing period.', 'process-subscriptions' ) ); ?>')) {
                    return;
                }

                var $btn = $(this);
                var id = $btn.data('id');
                var nonce = $btn.data('nonce');

                $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Cancelling...', 'process-subscriptions' ) ); ?>');

                $.post('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
                    action: 'process_cancel_subscription',
                    subscription_id: id,
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data || '<?php echo esc_js( __( 'An error occurred.', 'process-subscriptions' ) ); ?>');
                        $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Cancel', 'process-subscriptions' ) ); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX cancel subscription
     */
    public function ajax_cancel_subscription() {
        $subscription_id = isset( $_POST['subscription_id'] ) ? intval( $_POST['subscription_id'] ) : 0;
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';

        if ( ! wp_verify_nonce( $nonce, 'cancel_subscription_' . $subscription_id ) ) {
            wp_send_json_error( __( 'Security check failed.', 'process-subscriptions' ) );
        }

        $subscription = $this->get( $subscription_id );

        if ( ! $subscription || (int) $subscription['user_id'] !== get_current_user_id() ) {
            wp_send_json_error( __( 'Subscription not found.', 'process-subscriptions' ) );
        }

        $result = $this->cancel( $subscription_id, false );

        if ( $result ) {
            wp_send_json_success();
        } else {
            wp_send_json_error( __( 'Failed to cancel subscription.', 'process-subscriptions' ) );
        }
    }
}
