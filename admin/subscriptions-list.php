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
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $subscriptions ) ) : ?>
                <tr>
                    <td colspan="8"><?php esc_html_e( 'No subscriptions found.', 'process-subscriptions' ); ?></td>
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
                    ?>
                    <tr>
                        <td class="column-id">
                            <strong>#<?php echo esc_html( $sub['id'] ); ?></strong>
                            <?php if ( ! empty( $sub['stripe_subscription_id'] ) ) : ?>
                                <br><small>Stripe: <?php echo esc_html( substr( $sub['stripe_subscription_id'], 0, 20 ) ); ?>...</small>
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
                        </td>
                        <td class="column-amount">
                            <?php echo wp_kses_post( wc_price( $sub['amount'], array( 'currency' => $sub['currency'] ) ) ); ?>
                            <br><small><?php esc_html_e( 'per', 'process-subscriptions' ); ?> <?php echo esc_html( $period_label ); ?></small>
                        </td>
                        <td class="column-next-payment">
                            <?php if ( $sub['next_payment'] && in_array( $sub['status'], array( 'active', 'pending' ), true ) ) : ?>
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
