<?php
/**
 * Paystack Admin
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_Paystack_Admin Class
 */
class WC_Paystack_Admin {
    /**
     * Constructor
     */
    public function __construct() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Add admin scripts
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        
        // Add transaction column to orders
        add_filter('manage_edit-shop_order_columns', array($this, 'add_order_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'order_column_content'), 10, 2);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Paystack Transactions', 'wc-paystack'),
            __('Paystack Transactions', 'wc-paystack'),
            'manage_woocommerce',
            'wc-paystack-transactions',
            array($this, 'transactions_page')
        );
    }

    /**
     * Admin scripts
     */
    public function admin_scripts($hook) {
        // Only load on Paystack transactions page
        if ($hook !== 'woocommerce_page_wc-paystack-transactions') {
            return;
        }
        
        // Register and enqueue the admin script
        wp_register_script('wc-paystack-admin', WC_PAYSTACK_URL . 'assets/js/admin.js', array('jquery'), WC_PAYSTACK_VERSION, true);
        wp_enqueue_script('wc-paystack-admin');
        
        // Register and enqueue the admin style
        wp_register_style('wc-paystack-admin', WC_PAYSTACK_URL . 'assets/css/admin.css', array(), WC_PAYSTACK_VERSION);
        wp_enqueue_style('wc-paystack-admin');
        
        // Add localization
        wp_localize_script('wc-paystack-admin', 'wc_paystack_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wc_paystack_export_transactions'),
            'i18n'     => array(
                'export_confirm' => __('Are you sure you want to export these transactions?', 'wc-paystack'),
                'no_transactions' => __('No transactions found', 'wc-paystack'),
            ),
        ));
    }

    /**
     * Transactions page
     */
    public function transactions_page() {
        // Include transactions page template
        include_once WC_PAYSTACK_PATH . 'includes/admin/transactions-page.php';
    }

    /**
     * Add order column
     */
    public function add_order_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $column_name => $column_info) {
            $new_columns[$column_name] = $column_info;
            
            if ($column_name === 'order_status') {
                $new_columns['paystack_transaction'] = __('Paystack', 'wc-paystack');
            }
        }
        
        return $new_columns;
    }

    /**
     * Order column content
     */
    public function order_column_content($column, $post_id) {
        if ($column !== 'paystack_transaction') {
            return;
        }
        
        $order = wc_get_order($post_id);
        
        if (!$order || $order->get_payment_method() !== 'paystack') {
            echo '—';
            return;
        }
        
        $transaction_id = $order->get_transaction_id();
        $reference = $order->get_meta('_paystack_payment_txn_ref');
        
        if ($transaction_id) {
            $transaction_url = wc_paystack_get_transaction_url($transaction_id);
            
            if ($transaction_url) {
                echo '<a href="' . esc_url($transaction_url) . '" target="_blank" title="' . esc_attr($reference) . '">' . esc_html($transaction_id) . '</a>';
            } else {
                echo esc_html($transaction_id);
            }
        } else {
            echo '—';
        }
    }
}

// Initialize the admin class
new WC_Paystack_Admin();
