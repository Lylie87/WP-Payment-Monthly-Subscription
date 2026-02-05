<?php
/**
 * Subscription Settings Page
 *
 * @package ProcessSubscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Handle form submission
if ( isset( $_POST['process_subs_save_settings'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'process_subs_settings' ) ) {
    $settings = array(
        'stripe_secret_key'      => sanitize_text_field( $_POST['stripe_secret_key'] ?? '' ),
        'stripe_publishable_key' => sanitize_text_field( $_POST['stripe_publishable_key'] ?? '' ),
        'stripe_webhook_secret'  => sanitize_text_field( $_POST['stripe_webhook_secret'] ?? '' ),
        'license_api_url'        => esc_url_raw( $_POST['license_api_url'] ?? '' ),
        'license_api_key'        => sanitize_text_field( $_POST['license_api_key'] ?? '' ),
    );

    update_option( 'process_subs_settings', $settings );

    echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'process-subscriptions' ) . '</p></div>';
}

$settings = get_option( 'process_subs_settings', array() );

// Check if using WooCommerce Stripe Gateway as fallback
$wc_stripe = get_option( 'woocommerce_stripe_settings', array() );
$has_wc_stripe = ! empty( $wc_stripe['secret_key'] ) || ! empty( $wc_stripe['test_secret_key'] );
$has_plugin_keys = ! empty( $settings['stripe_secret_key'] );
?>

<div class="wrap">
    <h1><?php esc_html_e( 'Subscription Settings', 'process-subscriptions' ); ?></h1>

    <form method="post" action="">
        <?php wp_nonce_field( 'process_subs_settings' ); ?>

        <h2><?php esc_html_e( 'Stripe Settings', 'process-subscriptions' ); ?></h2>

        <?php if ( $has_wc_stripe && ! $has_plugin_keys ) : ?>
            <div class="notice notice-info inline">
                <p><?php esc_html_e( 'WooCommerce Stripe Gateway detected. Enter keys below to override, or leave blank to use WooCommerce Stripe settings.', 'process-subscriptions' ); ?></p>
            </div>
        <?php endif; ?>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="stripe_secret_key"><?php esc_html_e( 'Stripe Secret Key', 'process-subscriptions' ); ?></label>
                </th>
                <td>
                    <input type="password" name="stripe_secret_key" id="stripe_secret_key" class="regular-text" value="<?php echo esc_attr( $settings['stripe_secret_key'] ?? '' ); ?>">
                    <p class="description"><?php esc_html_e( 'Your Stripe secret key (starts with sk_live_ or sk_test_).', 'process-subscriptions' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="stripe_publishable_key"><?php esc_html_e( 'Stripe Publishable Key', 'process-subscriptions' ); ?></label>
                </th>
                <td>
                    <input type="text" name="stripe_publishable_key" id="stripe_publishable_key" class="regular-text" value="<?php echo esc_attr( $settings['stripe_publishable_key'] ?? '' ); ?>">
                    <p class="description"><?php esc_html_e( 'Your Stripe publishable key (starts with pk_live_ or pk_test_).', 'process-subscriptions' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="stripe_webhook_secret"><?php esc_html_e( 'Stripe Webhook Secret', 'process-subscriptions' ); ?></label>
                </th>
                <td>
                    <input type="password" name="stripe_webhook_secret" id="stripe_webhook_secret" class="regular-text" value="<?php echo esc_attr( $settings['stripe_webhook_secret'] ?? '' ); ?>">
                    <p class="description"><?php esc_html_e( 'Your Stripe webhook signing secret (starts with whsec_).', 'process-subscriptions' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Webhook URL', 'process-subscriptions' ); ?></th>
                <td>
                    <code><?php echo esc_url( rest_url( 'process-subscriptions/v1/webhook' ) ); ?></code>
                    <p class="description"><?php esc_html_e( 'Add this URL to your Stripe Dashboard → Webhooks.', 'process-subscriptions' ); ?></p>
                    <p class="description">
                        <strong><?php esc_html_e( 'Required events:', 'process-subscriptions' ); ?></strong><br>
                        invoice.payment_succeeded, invoice.payment_failed, customer.subscription.updated, customer.subscription.deleted, customer.subscription.trial_will_end
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'License System Integration', 'process-subscriptions' ); ?></h2>

        <?php
        $has_constants = defined( 'PROCESS_LICENSE_API_URL' ) || defined( 'PROCESS_LICENSE_API_KEY' );
        if ( $has_constants ) :
        ?>
            <div class="notice notice-info inline">
                <p><?php esc_html_e( 'License API settings detected in wp-config.php. Those will be used automatically.', 'process-subscriptions' ); ?></p>
            </div>
        <?php endif; ?>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="license_api_url"><?php esc_html_e( 'License API URL', 'process-subscriptions' ); ?></label>
                </th>
                <td>
                    <?php if ( defined( 'PROCESS_LICENSE_API_URL' ) ) : ?>
                        <code><?php echo esc_url( PROCESS_LICENSE_API_URL ); ?></code>
                        <p class="description"><?php esc_html_e( 'Defined in wp-config.php', 'process-subscriptions' ); ?></p>
                    <?php else : ?>
                        <input type="url" name="license_api_url" id="license_api_url" class="regular-text" value="<?php echo esc_attr( $settings['license_api_url'] ?? '' ); ?>" placeholder="https://pro-cess.co.uk/license-system/api/create-license.php">
                        <p class="description"><?php esc_html_e( 'URL to your license system API.', 'process-subscriptions' ); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="license_api_key"><?php esc_html_e( 'License API Key', 'process-subscriptions' ); ?></label>
                </th>
                <td>
                    <?php if ( defined( 'PROCESS_LICENSE_API_KEY' ) ) : ?>
                        <code>••••••••••••••••</code>
                        <p class="description"><?php esc_html_e( 'Defined in wp-config.php', 'process-subscriptions' ); ?></p>
                    <?php else : ?>
                        <input type="password" name="license_api_key" id="license_api_key" class="regular-text" value="<?php echo esc_attr( $settings['license_api_key'] ?? '' ); ?>">
                        <p class="description"><?php esc_html_e( 'API key for license system authentication.', 'process-subscriptions' ); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'Status', 'process-subscriptions' ); ?></h2>

        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e( 'Stripe Connection', 'process-subscriptions' ); ?></th>
                <td>
                    <?php
                    $stripe = Process_Stripe_Handler::get_instance();
                    if ( $stripe->is_configured() ) {
                        echo '<span style="color: green;">&#10003; ' . esc_html__( 'Connected', 'process-subscriptions' ) . '</span>';
                    } else {
                        echo '<span style="color: red;">&#10007; ' . esc_html__( 'Not configured', 'process-subscriptions' ) . '</span>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Key Source Debug', 'process-subscriptions' ); ?></th>
                <td>
                    <?php
                    $plugin_key = $settings['stripe_secret_key'] ?? '';
                    $wc_settings = get_option( 'woocommerce_stripe_settings', array() );
                    $wc_testmode = isset( $wc_settings['testmode'] ) && 'yes' === $wc_settings['testmode'];

                    if ( ! empty( $plugin_key ) ) {
                        $key_type = strpos( $plugin_key, 'sk_live_' ) === 0 ? 'LIVE' : ( strpos( $plugin_key, 'sk_test_' ) === 0 ? 'TEST' : 'UNKNOWN' );
                        echo '<strong>Using: Plugin settings</strong><br>';
                        echo 'Key type: <span style="color: ' . ( $key_type === 'LIVE' ? 'green' : 'red' ) . '; font-weight: bold;">' . esc_html( $key_type ) . '</span><br>';
                        echo 'Key starts with: <code>' . esc_html( substr( $plugin_key, 0, 12 ) ) . '...</code>';
                    } elseif ( ! empty( $wc_settings ) ) {
                        $wc_key = $wc_testmode ? ( $wc_settings['test_secret_key'] ?? '' ) : ( $wc_settings['secret_key'] ?? '' );
                        $key_type = strpos( $wc_key, 'sk_live_' ) === 0 ? 'LIVE' : ( strpos( $wc_key, 'sk_test_' ) === 0 ? 'TEST' : 'UNKNOWN' );
                        echo '<strong>Using: WooCommerce Stripe Gateway (fallback)</strong><br>';
                        echo 'WC Testmode: ' . ( $wc_testmode ? 'Yes' : 'No' ) . '<br>';
                        echo 'Key type: <span style="color: ' . ( $key_type === 'LIVE' ? 'green' : 'red' ) . '; font-weight: bold;">' . esc_html( $key_type ) . '</span><br>';
                        echo 'Key starts with: <code>' . esc_html( substr( $wc_key, 0, 12 ) ) . '...</code>';
                    } else {
                        echo '<span style="color: red;">No Stripe keys configured anywhere!</span>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'License API', 'process-subscriptions' ); ?></th>
                <td>
                    <?php
                    $has_api = defined( 'PROCESS_LICENSE_API_KEY' ) || ! empty( $settings['license_api_key'] );
                    if ( $has_api ) {
                        echo '<span style="color: green;">&#10003; ' . esc_html__( 'Configured', 'process-subscriptions' ) . '</span>';
                    } else {
                        echo '<span style="color: orange;">&#9888; ' . esc_html__( 'Not configured (licenses won\'t be created automatically)', 'process-subscriptions' ) . '</span>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Active Subscriptions', 'process-subscriptions' ); ?></th>
                <td>
                    <?php
                    $manager = Process_Subscription_Manager::get_instance();
                    echo esc_html( $manager->get_count( 'active' ) );
                    ?>
                </td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" name="process_subs_save_settings" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'process-subscriptions' ); ?>">
        </p>
    </form>
</div>
