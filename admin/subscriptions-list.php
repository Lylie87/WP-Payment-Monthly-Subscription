<?php
/**
 * Subscriptions List Admin Page
 *
 * @package ProcessSubscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$manager = Process_Subscription_Manager::get_instance();

// Handle actions
if ( isset( $_GET['action'], $_GET['sub_id'], $_GET['_wpnonce'] ) ) {
    $action = sanitize_text_field( $_GET['action'] );
    $sub_id = intval( $_GET['sub_id'] );

    if ( wp_verify_nonce( $_GET['_wpnonce'], 'process_sub_action_' . $sub_id ) ) {
        $notice = '';
        switch ( $action ) {
            case 'cancel':
                $manager->cancel( $sub_id, true );
                $notice = 'Subscription #' . $sub_id . ' cancelled.';
                break;

            case 'delete':
                global $wpdb;
                $table = $wpdb->prefix . 'process_subscriptions';
                $wpdb->delete( $table, array( 'id' => $sub_id ), array( '%d' ) );
                $notice = 'Subscription #' . $sub_id . ' deleted.';
                break;

            case 'activate':
                $manager->update( $sub_id, array( 'status' => 'active' ) );
                $notice = 'Subscription #' . $sub_id . ' set to active.';
                break;

            case 'setup_trial_addon':
                // Manually trigger trial addon creation
                $sub = $manager->get( $sub_id );
                if ( ! $sub ) {
                    $notice = 'Subscription #' . $sub_id . ' not found.';
                    break;
                }
                $order = wc_get_order( $sub['order_id'] );
                if ( ! $order ) {
                    $notice = 'Order #' . $sub['order_id'] . ' not found.';
                    break;
                }

                $license_key  = $sub['license_key'] ?? '';
                $license_type = '';
                $trial_days   = 14;

                if ( empty( $license_key ) ) {
                    $license_key = $order->get_meta( '_subscription_license_key_' . $sub_id );
                }

                foreach ( $order->get_items() as $item ) {
                    if ( $item->get_meta( '_is_subscription' ) === 'yes' ) {
                        $license_type = $item->get_meta( '_subscription_license_type' );
                        $td = $item->get_meta( '_subscription_trial_days' );
                        if ( $td ) {
                            $trial_days = intval( $td );
                        }
                        break;
                    }
                }

                if ( ! empty( $license_key ) && ! empty( $license_type ) ) {
                    $sync = Process_License_Sync::get_instance();
                    $method = new ReflectionMethod( $sync, 'setup_trial_addon' );
                    $method->setAccessible( true );
                    $method->invoke( $sync, $license_key, $license_type, $sub_id, $trial_days );
                    $notice = 'Trial addon setup triggered for subscription #' . $sub_id . ' (key: ' . $license_key . ', type: ' . $license_type . '). Check order notes.';
                } else {
                    $notice = 'Cannot set up addon: license_key=' . $license_key . ', license_type=' . $license_type;
                }
                break;

            case 'convert_trial':
                // Manually trigger trial-to-paid conversion
                $sub = $manager->get( $sub_id );
                if ( $sub ) {
                    do_action( 'process_subscription_trial_converted', $sub_id, array( 'manual_trigger' => true ) );
                    $notice = 'Trial conversion triggered for subscription #' . $sub_id . '. Check order notes.';
                } else {
                    $notice = 'Subscription #' . $sub_id . ' not found.';
                }
                break;

            case 'extend_license':
                // Manually extend license by 30 days
                $sub = $manager->get( $sub_id );
                if ( $sub ) {
                    $sync = Process_License_Sync::get_instance();
                    $method = new ReflectionMethod( $sync, 'extend_license_expiry' );
                    $method->setAccessible( true );
                    $method->invoke( $sync, $sub, 30 );
                    $notice = 'License extension triggered for subscription #' . $sub_id . ' (+30 days). Check order notes.';
                } else {
                    $notice = 'Subscription #' . $sub_id . ' not found.';
                }
                break;
        }

        // Redirect to clean URL
        wp_safe_redirect( add_query_arg( array( 'page' => 'process-subscriptions', 'notice' => urlencode( $notice ) ), admin_url( 'admin.php' ) ) );
        exit;
    }
}

// Show notice
if ( ! empty( $_GET['notice'] ) ) {
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( $_GET['notice'] ) ) . '</p></div>';
}

// Get filter values
$status_filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
$page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
$per_page = 20;

// Get subscriptions
$subscriptions = $manager->get_all( array(
    'status'   => $status_filter,
    'per_page' => $per_page,
    'page'     => $page,
) );

// Get counts
$total = $manager->get_count( $status_filter );
$total_pages = ceil( $total / $per_page );

// Status counts
$counts = array(
    'all'            => $manager->get_count(),
    'active'         => $manager->get_count( 'active' ),
    'trialing'       => $manager->get_count( 'trialing' ),
    'pending-cancel' => $manager->get_count( 'pending-cancel' ),
    'cancelled'      => $manager->get_count( 'cancelled' ),
    'expired'        => $manager->get_count( 'expired' ),
    'past_due'       => $manager->get_count( 'past_due' ),
);
?>

<div class="wrap process-subscriptions-admin">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'Subscriptions', 'process-subscriptions' ); ?></h1>

    <hr class="wp-header-end">

    <!-- Status filters -->
    <ul class="subsubsub">
        <li>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=process-subscriptions' ) ); ?>" <?php echo empty( $status_filter ) ? 'class="current"' : ''; ?>>
                <?php esc_html_e( 'All', 'process-subscriptions' ); ?>
                <span class="count">(<?php echo esc_html( $counts['all'] ); ?>)</span>
            </a> |
        </li>
        <li>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=process-subscriptions&status=active' ) ); ?>" <?php echo $status_filter === 'active' ? 'class="current"' : ''; ?>>
                <?php esc_html_e( 'Active', 'process-subscriptions' ); ?>
                <span class="count">(<?php echo esc_html( $counts['active'] ); ?>)</span>
            </a> |
        </li>
        <li>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=process-subscriptions&status=trialing' ) ); ?>" <?php echo $status_filter === 'trialing' ? 'class="current"' : ''; ?>>
                <?php esc_html_e( 'Trialing', 'process-subscriptions' ); ?>
                <span class="count">(<?php echo esc_html( $counts['trialing'] ); ?>)</span>
            </a> |
        </li>
        <li>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=process-subscriptions&status=pending-cancel' ) ); ?>" <?php echo $status_filter === 'pending-cancel' ? 'class="current"' : ''; ?>>
                <?php esc_html_e( 'Pending Cancel', 'process-subscriptions' ); ?>
                <span class="count">(<?php echo esc_html( $counts['pending-cancel'] ); ?>)</span>
            </a> |
        </li>
        <li>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=process-subscriptions&status=past_due' ) ); ?>" <?php echo $status_filter === 'past_due' ? 'class="current"' : ''; ?>>
                <?php esc_html_e( 'Past Due', 'process-subscriptions' ); ?>
                <span class="count">(<?php echo esc_html( $counts['past_due'] ); ?>)</span>
            </a> |
        </li>
        <li>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=process-subscriptions&status=cancelled' ) ); ?>" <?php echo $status_filter === 'cancelled' ? 'class="current"' : ''; ?>>
                <?php esc_html_e( 'Cancelled', 'process-subscriptions' ); ?>
                <span class="count">(<?php echo esc_html( $counts['cancelled'] ); ?>)</span>
            </a> |
        </li>
        <li>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=process-subscriptions&status=expired' ) ); ?>" <?php echo $status_filter === 'expired' ? 'class="current"' : ''; ?>>
                <?php esc_html_e( 'Expired', 'process-subscriptions' ); ?>
                <span class="count">(<?php echo esc_html( $counts['expired'] ); ?>)</span>
            </a>
        </li>
    </ul>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th class="column-id"><?php esc_html_e( 'ID', 'process-subscriptions' ); ?></th>
                <th class="column-order"><?php esc_html_e( 'Order', 'process-subscriptions' ); ?></th>
                <th class="column-customer"><?php esc_html_e( 'Customer', 'process-subscriptions' ); ?></th>
                <th class="column-product"><?php esc_html_e( 'Product', 'process-subscriptions' ); ?></th>
                <th class="column-status"><?php esc_html_e( 'Status', 'process-subscriptions' ); ?></th>
                <th class="column-amount"><?php esc_html_e( 'Amount', 'process-subscriptions' ); ?></th>
                <th class="column-next-payment"><?php esc_html_e( 'Next Payment', 'process-subscriptions' ); ?></th>
                <th class="column-created"><?php esc_html_e( 'Created', 'process-subscriptions' ); ?></th>
                <th class="column-actions"><?php esc_html_e( 'Actions', 'process-subscriptions' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $subscriptions ) ) : ?>
                <tr>
                    <td colspan="9"><?php esc_html_e( 'No subscriptions found.', 'process-subscriptions' ); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ( $subscriptions as $sub ) : ?>
                    <?php
                    $order = wc_get_order( $sub['order_id'] );
                    $product = wc_get_product( $sub['product_id'] );
                    $user = get_user_by( 'id', $sub['user_id'] );
                    $period_label = $sub['billing_interval'] > 1
                        ? $sub['billing_interval'] . ' ' . $sub['billing_period'] . 's'
                        : $sub['billing_period'];
                    $nonce = wp_create_nonce( 'process_sub_action_' . $sub['id'] );
                    ?>
                    <tr>
                        <td class="column-id">
                            <strong>#<?php echo esc_html( $sub['id'] ); ?></strong>
                            <?php if ( ! empty( $sub['stripe_subscription_id'] ) ) : ?>
                                <br><small>Stripe: <?php echo esc_html( substr( $sub['stripe_subscription_id'], 0, 20 ) ); ?>...</small>
                            <?php endif; ?>
                            <?php if ( ! empty( $sub['license_key'] ) ) : ?>
                                <br><small>Key: <?php echo esc_html( $sub['license_key'] ); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="column-order">
                            <?php if ( $order ) : ?>
                                <a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
                                    #<?php echo esc_html( $order->get_order_number() ); ?>
                                </a>
                            <?php else : ?>
                                #<?php echo esc_html( $sub['order_id'] ); ?>
                            <?php endif; ?>
                        </td>
                        <td class="column-customer">
                            <?php if ( $user ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . $user->ID ) ); ?>">
                                    <?php echo esc_html( $user->display_name ); ?>
                                </a>
                                <br><small><?php echo esc_html( $user->user_email ); ?></small>
                            <?php elseif ( $order ) : ?>
                                <?php echo esc_html( $order->get_formatted_billing_full_name() ); ?>
                                <br><small><?php echo esc_html( $order->get_billing_email() ); ?></small>
                            <?php else : ?>
                                <?php esc_html_e( 'Guest', 'process-subscriptions' ); ?>
                            <?php endif; ?>
                        </td>
                        <td class="column-product">
                            <?php if ( $product ) : ?>
                                <a href="<?php echo esc_url( get_edit_post_link( $product->get_id() ) ); ?>">
                                    <?php echo esc_html( $product->get_name() ); ?>
                                </a>
                            <?php else : ?>
                                <?php esc_html_e( 'Product #', 'process-subscriptions' ); ?><?php echo esc_html( $sub['product_id'] ); ?>
                            <?php endif; ?>
                        </td>
                        <td class="column-status">
                            <span class="subscription-status status-<?php echo esc_attr( $sub['status'] ); ?>">
                                <?php echo esc_html( ucfirst( str_replace( array( '-', '_' ), ' ', $sub['status'] ) ) ); ?>
                            </span>
                            <?php if ( $sub['status'] === 'trialing' && ! empty( $sub['trial_end'] ) ) : ?>
                                <br><small>Ends: <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $sub['trial_end'] ) ) ); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="column-amount">
                            <?php echo wp_kses_post( wc_price( $sub['amount'], array( 'currency' => $sub['currency'] ) ) ); ?>
                            <br><small><?php esc_html_e( 'per', 'process-subscriptions' ); ?> <?php echo esc_html( $period_label ); ?></small>
                        </td>
                        <td class="column-next-payment">
                            <?php if ( $sub['next_payment'] && in_array( $sub['status'], array( 'active', 'pending', 'trialing' ), true ) ) : ?>
                                <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $sub['next_payment'] ) ) ); ?>
                            <?php elseif ( $sub['expires_at'] ) : ?>
                                <span style="color: #999;"><?php esc_html_e( 'Expires:', 'process-subscriptions' ); ?> <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $sub['expires_at'] ) ) ); ?></span>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="column-created">
                            <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $sub['created_at'] ) ) ); ?>
                        </td>
                        <td class="column-actions">
                            <?php if ( in_array( $sub['status'], array( 'active', 'trialing', 'pending', 'past_due' ), true ) ) : ?>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=process-subscriptions&action=cancel&sub_id=' . $sub['id'] ), 'process_sub_action_' . $sub['id'] ) ); ?>"
                                   class="button button-small"
                                   onclick="return confirm('Cancel subscription #<?php echo esc_attr( $sub['id'] ); ?>? This will cancel in Stripe too.');">
                                    Cancel
                                </a>
                            <?php endif; ?>

                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=process-subscriptions&action=setup_trial_addon&sub_id=' . $sub['id'] ), 'process_sub_action_' . $sub['id'] ) ); ?>"
                               class="button button-small"
                               onclick="return confirm('Trigger trial addon setup for #<?php echo esc_attr( $sub['id'] ); ?>?');"
                               title="Manually trigger trial addon creation">
                                Setup Addon
                            </a>
                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=process-subscriptions&action=convert_trial&sub_id=' . $sub['id'] ), 'process_sub_action_' . $sub['id'] ) ); ?>"
                               class="button button-small"
                               onclick="return confirm('Trigger trial conversion for #<?php echo esc_attr( $sub['id'] ); ?>? This will activate the paid addon and extend license.');"
                               title="Manually trigger trial-to-paid conversion">
                                Convert
                            </a>
                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=process-subscriptions&action=extend_license&sub_id=' . $sub['id'] ), 'process_sub_action_' . $sub['id'] ) ); ?>"
                               class="button button-small"
                               onclick="return confirm('Extend license for #<?php echo esc_attr( $sub['id'] ); ?> by 30 days?');"
                               title="Extend license expiry by 30 days">
                                +30 Days
                            </a>

                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=process-subscriptions&action=delete&sub_id=' . $sub['id'] ), 'process_sub_action_' . $sub['id'] ) ); ?>"
                               class="button button-small button-link-delete"
                               onclick="return confirm('Permanently delete subscription #<?php echo esc_attr( $sub['id'] ); ?>? This cannot be undone.');"
                               style="color: #a00;">
                                Delete
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ( $total_pages > 1 ) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php printf( esc_html( _n( '%s item', '%s items', $total, 'process-subscriptions' ) ), number_format_i18n( $total ) ); ?>
                </span>
                <span class="pagination-links">
                    <?php
                    echo paginate_links( array(
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'format'    => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total'     => $total_pages,
                        'current'   => $page,
                    ) );
                    ?>
                </span>
            </div>
        </div>
    <?php endif; ?>
</div>
