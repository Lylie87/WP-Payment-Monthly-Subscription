<?php
/**
 * Plugin Name: Pro-cess Subscriptions
 * Plugin URI: https://pro-cess.co.uk
 * Description: Lightweight subscription handling for WooCommerce with Stripe integration and license system sync.
 * Version: 1.1.9
 * Author: Pro-cess
 * Author URI: https://pro-cess.co.uk
 * Text Domain: process-subscriptions
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 *
 * @package ProcessSubscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'PROCESS_SUBS_VERSION', '1.1.9' );
define( 'PROCESS_SUBS_PATH', plugin_dir_path( __FILE__ ) );
define( 'PROCESS_SUBS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main Plugin Class
 */
final class Process_Subscriptions {

    /**
     * Instance
     *
     * @var Process_Subscriptions
     */
    private static $instance = null;

    /**
     * Get instance
     *
     * @return Process_Subscriptions
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
        // Check dependencies
        add_action( 'plugins_loaded', array( $this, 'init' ) );
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }

        // Load includes
        $this->includes();

        // Initialize components
        add_action( 'init', array( $this, 'init_components' ) );

        // Admin menu
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

        // Enqueue scripts
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'frontend_scripts' ) );

        // HPOS compatibility
        add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
    }

    /**
     * Include required files
     */
    private function includes() {
        require_once PROCESS_SUBS_PATH . 'includes/class-subscription-product.php';
        require_once PROCESS_SUBS_PATH . 'includes/class-stripe-handler.php';
        require_once PROCESS_SUBS_PATH . 'includes/class-subscription-manager.php';
        require_once PROCESS_SUBS_PATH . 'includes/class-license-sync.php';
        require_once PROCESS_SUBS_PATH . 'includes/class-webhook-handler.php';
        require_once PROCESS_SUBS_PATH . 'includes/class-order-license-admin.php';
    }

    /**
     * Initialize components
     */
    public function init_components() {
        Process_Subscription_Product::get_instance();
        Process_Stripe_Handler::get_instance();
        Process_Subscription_Manager::get_instance();
        Process_License_Sync::get_instance();
        Process_Webhook_Handler::get_instance();
        Process_Order_License_Admin::get_instance();
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Subscriptions', 'process-subscriptions' ),
            __( 'Subscriptions', 'process-subscriptions' ),
            'manage_woocommerce',
            'process-subscriptions',
            array( $this, 'render_admin_page' )
        );

        add_submenu_page(
            'woocommerce',
            __( 'Subscription Settings', 'process-subscriptions' ),
            __( 'Sub. Settings', 'process-subscriptions' ),
            'manage_woocommerce',
            'process-subscriptions-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        include PROCESS_SUBS_PATH . 'admin/subscriptions-list.php';
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        include PROCESS_SUBS_PATH . 'admin/settings.php';
    }

    /**
     * Enqueue admin scripts
     */
    public function admin_scripts( $hook ) {
        if ( strpos( $hook, 'process-subscriptions' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'process-subs-admin',
            PROCESS_SUBS_URL . 'assets/css/admin.css',
            array(),
            PROCESS_SUBS_VERSION
        );
    }

    /**
     * Enqueue frontend scripts for subscription checkout redirect
     */
    public function frontend_scripts() {
        // Only load on single product pages or when WooCommerce is active
        if ( ! is_product() && ! is_shop() && ! is_product_category() ) {
            return;
        }

        wp_enqueue_script(
            'process-subs-checkout-redirect',
            PROCESS_SUBS_URL . 'assets/js/checkout-redirect.js',
            array( 'jquery' ),
            PROCESS_SUBS_VERSION,
            true
        );

        // Get all subscription product IDs
        $subscription_products = wc_get_products( array(
            'type'   => 'process_subscription',
            'status' => 'publish',
            'limit'  => -1,
            'return' => 'ids',
        ) );

        wp_localize_script(
            'process-subs-checkout-redirect',
            'processSubsCheckout',
            array(
                'checkoutUrl'          => wc_get_checkout_url(),
                'subscriptionProducts' => $subscription_products,
            )
        );
    }

    /**
     * Activation hook
     */
    public function activate() {
        // Create database table
        $this->create_tables();

        // Schedule cron jobs
        if ( ! wp_next_scheduled( 'process_subs_daily_check' ) ) {
            wp_schedule_event( time(), 'daily', 'process_subs_daily_check' );
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Deactivation hook
     */
    public function deactivate() {
        wp_clear_scheduled_hook( 'process_subs_daily_check' );
    }

    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'process_subscriptions';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            order_item_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            stripe_subscription_id varchar(255) DEFAULT NULL,
            stripe_customer_id varchar(255) DEFAULT NULL,
            license_key varchar(255) DEFAULT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            billing_period varchar(20) NOT NULL DEFAULT 'month',
            billing_interval int(11) NOT NULL DEFAULT 1,
            amount decimal(10,2) NOT NULL DEFAULT 0.00,
            currency varchar(3) NOT NULL DEFAULT 'GBP',
            trial_end datetime DEFAULT NULL,
            next_payment datetime DEFAULT NULL,
            last_payment datetime DEFAULT NULL,
            cancelled_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY user_id (user_id),
            KEY product_id (product_id),
            KEY stripe_subscription_id (stripe_subscription_id),
            KEY status (status)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Store DB version
        update_option( 'process_subs_db_version', PROCESS_SUBS_VERSION );
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><strong>Pro-cess Subscriptions</strong> requires WooCommerce to be installed and active.</p>
        </div>
        <?php
    }

    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }
}

// Initialize
Process_Subscriptions::get_instance();

/**
 * Helper function to get subscription by order
 *
 * @param int $order_id Order ID.
 * @return object|null
 */
function process_get_subscription_by_order( $order_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'process_subscriptions';
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE order_id = %d", $order_id ) );
}

/**
 * Helper function to get user subscriptions
 *
 * @param int $user_id User ID.
 * @return array
 */
function process_get_user_subscriptions( $user_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'process_subscriptions';
    return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE user_id = %d ORDER BY created_at DESC", $user_id ) );
}

/**
 * Get plugin settings
 *
 * @param string $key Setting key.
 * @param mixed  $default Default value.
 * @return mixed
 */
function process_subs_get_setting( $key, $default = '' ) {
    $settings = get_option( 'process_subs_settings', array() );
    return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
}
