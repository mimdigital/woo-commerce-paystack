<?php
/**
* Plugin Name: WooCommerce Paystack Payment Gateway
* Plugin URI: https://www.mimdigital.net
* Description: Accept payments via Paystack in your WooCommerce store
* Version: 1.0.9
* Author: MiMDigital
* Author URI: https://www.mimdigital.net
* Text Domain: wc-paystack
* Domain Path: /languages
* Requires at least: 5.8
* Tested up to: 6.5
* Requires PHP: 7.4
* WC requires at least: 6.0
* WC tested up to: 8.9
* License: GPL v2 or later
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if (!defined('ABSPATH')) {
  exit;
}

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Define plugin constants
if (!defined('WC_PAYSTACK_VERSION')) {
  define('WC_PAYSTACK_VERSION', '1.0.9');
}

if (!defined('WC_PAYSTACK_URL')) {
  define('WC_PAYSTACK_URL', plugin_dir_url(__FILE__));
}

if (!defined('WC_PAYSTACK_PATH')) {
  define('WC_PAYSTACK_PATH', plugin_dir_path(__FILE__));
}

if (!defined('WC_PAYSTACK_MAIN_FILE')) {
  define('WC_PAYSTACK_MAIN_FILE', __FILE__);
}

/**
* Initialize the plugin
*/
function wc_paystack_init() {
    try {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', 'wc_paystack_woocommerce_missing_notice');
            return;
        }
        
        // Include required files
        require_once WC_PAYSTACK_PATH . 'includes/class-wc-gateway-paystack.php';
        
        // Include optional files that exist
        $optional_files = [
            'includes/class-wc-paystack-webhook-handler.php',
            'includes/class-wc-paystack-transaction-log.php',
            'includes/class-wc-paystack-ajax-handler.php',
            'includes/wc-paystack-functions.php'
        ];
        
        foreach ($optional_files as $file) {
            if (file_exists(WC_PAYSTACK_PATH . $file)) {
                require_once WC_PAYSTACK_PATH . $file;
            }
        }
        
        // Include admin class if in admin
        if (is_admin() && file_exists(WC_PAYSTACK_PATH . 'includes/class-wc-paystack-admin.php')) {
            require_once WC_PAYSTACK_PATH . 'includes/class-wc-paystack-admin.php';
        }
        
        // Add the gateway to WooCommerce
        add_filter('woocommerce_payment_gateways', 'wc_paystack_add_gateway');
        
        // Register scripts
        add_action('wp_enqueue_scripts', 'wc_paystack_register_scripts');
        
        // Load plugin text domain
        add_action('init', 'wc_paystack_load_plugin_textdomain');
        
        // Handle webhook
        if (class_exists('WC_Paystack_Webhook_Handler')) {
            add_action('woocommerce_api_wc_gateway_paystack', 'wc_paystack_webhook_handler');
        }
        
        // Add settings link on plugins page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_paystack_plugin_action_links');
        
        // Ensure transaction table exists on every load (for robustness)
        add_action('init', 'wc_paystack_ensure_transaction_table');
        
    } catch (Exception $e) {
        // Log the error but don't break the site
        error_log('Paystack Plugin Initialization Error: ' . $e->getMessage());
        
        // Add admin notice about the error
        add_action('admin_notices', function() use ($e) {
            echo '<div class="error"><p>' . 
                sprintf(__('Paystack Plugin Error: %s', 'wc-paystack'), esc_html($e->getMessage())) . 
                '</p></div>';
        });
    }
}
add_action('plugins_loaded', 'wc_paystack_init');

/**
 * Ensure transaction table exists
 */
function wc_paystack_ensure_transaction_table() {
    if (class_exists('WC_Paystack_Transaction_Log')) {
        $transaction_log = new WC_Paystack_Transaction_Log();
        if (!$transaction_log->table_exists()) {
            error_log('Paystack transaction table missing, creating it now...');
            $transaction_log->create_table();
        }
    }
}

/**
 * Register scripts and styles
 */
function wc_paystack_register_scripts() {
    // Register Paystack inline script - Updated to v2
    if (!wp_script_is('paystack', 'registered')) {
        wp_register_script(
            'paystack',
            'https://js.paystack.co/v2/inline.js',
            [],
            WC_PAYSTACK_VERSION,
            true
        );
    }

    // Register plugin JS
    if (!wp_script_is('wc-paystack', 'registered')) {
        wp_register_script(
            'wc-paystack',
            WC_PAYSTACK_URL . 'assets/js/paystack.js',
            array('jquery', 'paystack', 'jquery-blockui'),
            WC_PAYSTACK_VERSION,
            true
        );
    }

    // Register plugin CSS
    if (!wp_style_is('wc-paystack', 'registered')) {
        wp_register_style(
            'wc-paystack', 
            WC_PAYSTACK_URL . 'assets/css/paystack.css', 
            array(), 
            WC_PAYSTACK_VERSION
        );
    }
}

/**
 * Add the gateway to WooCommerce
 */
function wc_paystack_add_gateway($gateways) {
  $gateways[] = 'WC_Gateway_Paystack';
  return $gateways;
}

/**
 * Load plugin text domain
 */
function wc_paystack_load_plugin_textdomain() {
  load_plugin_textdomain('wc-paystack', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

/**
 * WooCommerce missing notice
 */
function wc_paystack_woocommerce_missing_notice() {
  echo '<div class="error"><p>' . sprintf(__('WooCommerce Paystack Payment Gateway requires WooCommerce to be installed and active. You can download %s here.', 'wc-paystack'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</p></div>';
}

/**
 * Handle webhook
 */
function wc_paystack_webhook_handler() {
  if (class_exists('WC_Paystack_Webhook_Handler')) {
      $webhook_handler = new WC_Paystack_Webhook_Handler();
      $webhook_handler->process_webhook();
  }
}

/**
 * Add settings link to plugin actions
 */
function wc_paystack_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=paystack') . '">' . __('Settings', 'wc-paystack') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

/**
 * Create transaction log table on plugin activation
 */
function wc_paystack_activate() {
    // Create transaction log table
    if (class_exists('WC_Paystack_Transaction_Log')) {
        try {
            error_log('Activating Paystack plugin - creating transaction log table');
            $transaction_log = new WC_Paystack_Transaction_Log();
            $result = $transaction_log->create_table();
            if ($result) {
                error_log('Paystack transaction log table creation completed successfully');
            } else {
                error_log('Paystack transaction log table creation failed');
            }
        } catch (Exception $e) {
            error_log('Error creating Paystack transaction log table: ' . $e->getMessage());
        }
    } else {
        error_log('WC_Paystack_Transaction_Log class not found during activation');
    }
    
    // Create necessary directories
    $upload_dir = wp_upload_dir();
    $paystack_dir = $upload_dir['basedir'] . '/wc-paystack';
    
    if (!file_exists($paystack_dir)) {
        wp_mkdir_p($paystack_dir);
    }
    
    // Create .htaccess file to protect exports
    if (file_exists($paystack_dir) && !file_exists($paystack_dir . '/.htaccess')) {
        $htaccess_content = 'deny from all';
        @file_put_contents($paystack_dir . '/.htaccess', $htaccess_content);
    }
    
    // Set default options
    add_option('wc_paystack_version', WC_PAYSTACK_VERSION);
}
register_activation_hook(__FILE__, 'wc_paystack_activate');

/**
 * Plugin deactivation
 */
function wc_paystack_deactivate() {
    // Clear any scheduled events
    wp_clear_scheduled_hook('wc_paystack_cleanup_logs');
}
register_deactivation_hook(__FILE__, 'wc_paystack_deactivate');

/**
 * Check for plugin updates
 */
function wc_paystack_check_version() {
    $current_version = get_option('wc_paystack_version', '1.0.0');
    
    if (version_compare($current_version, WC_PAYSTACK_VERSION, '<')) {
        // Run upgrade routines
        wc_paystack_upgrade_routine($current_version);
        update_option('wc_paystack_version', WC_PAYSTACK_VERSION);
    }
}
add_action('admin_init', 'wc_paystack_check_version');

/**
 * Upgrade routine
 */
function wc_paystack_upgrade_routine($from_version) {
    // Recreate transaction log table if upgrading from older version
    if (version_compare($from_version, '1.0.5', '<') && class_exists('WC_Paystack_Transaction_Log')) {
        $transaction_log = new WC_Paystack_Transaction_Log();
        $transaction_log->create_table();
    }
}

/**
 * Register Paystack with WooCommerce Blocks
 */
function wc_paystack_register_blocks_support() {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    // Include the Blocks integration class if it exists
    if (file_exists(WC_PAYSTACK_PATH . 'includes/blocks/class-wc-paystack-blocks-support.php')) {
        require_once WC_PAYSTACK_PATH . 'includes/blocks/class-wc-paystack-blocks-support.php';
        
        // Register the integration with WooCommerce Blocks
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function($payment_method_registry) {
                $payment_method_registry->register(new WC_Paystack_Blocks_Support());
            }
        );
    }
}
add_action('init', 'wc_paystack_register_blocks_support');

/**
 * Add system status info
 */
function wc_paystack_system_status_report($report) {
    $gateway = wc_paystack_get_gateway();
    
    $report['paystack'] = array(
        'name'   => __('Paystack', 'wc-paystack'),
        'fields' => array(
            'version' => array(
                'label' => __('Plugin Version', 'wc-paystack'),
                'value' => WC_PAYSTACK_VERSION,
            ),
            'enabled' => array(
                'label' => __('Enabled', 'wc-paystack'),
                'value' => $gateway && $gateway->enabled === 'yes' ? __('Yes', 'wc-paystack') : __('No', 'wc-paystack'),
            ),
            'test_mode' => array(
                'label' => __('Test Mode', 'wc-paystack'),
                'value' => $gateway && $gateway->testmode ? __('Yes', 'wc-paystack') : __('No', 'wc-paystack'),
            ),
            'webhook_url' => array(
                'label' => __('Webhook URL', 'wc-paystack'),
                'value' => WC()->api_request_url('WC_Gateway_Paystack'),
            ),
        ),
    );
    
    return $report;
}
add_filter('woocommerce_system_status_report', 'wc_paystack_system_status_report');

/**
 * Get Paystack gateway instance
 */
function wc_paystack_get_gateway() {
    $gateways = WC()->payment_gateways->get_available_payment_gateways();
    return isset($gateways['paystack']) ? $gateways['paystack'] : null;
}

/**
 * Manual table creation function for debugging
 */
function wc_paystack_create_table_manually() {
    if (class_exists('WC_Paystack_Transaction_Log')) {
        $transaction_log = new WC_Paystack_Transaction_Log();
        $result = $transaction_log->create_table();
        
        if ($result) {
            error_log('Manual table creation successful');
            return true;
        } else {
            error_log('Manual table creation failed');
            return false;
        }
    }
    
    error_log('WC_Paystack_Transaction_Log class not found');
    return false;
}
