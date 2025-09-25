<?php
/**
 * Paystack AJAX Handler
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_Paystack_AJAX_Handler Class
 */
class WC_Paystack_AJAX_Handler {
    /**
     * Constructor
     */
    public function __construct() {
        // Add AJAX actions
        add_action('wp_ajax_wc_paystack_verify_payment', array($this, 'verify_payment'));
        add_action('wp_ajax_nopriv_wc_paystack_verify_payment', array($this, 'verify_payment'));
        
        add_action('wp_ajax_wc_paystack_export_transactions', array($this, 'export_transactions'));
        
        // Add action for refreshing all transactions
        add_action('admin_init', array($this, 'refresh_all_transactions'));
    }

    /**
     * Verify payment
     */
    public function verify_payment() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'verify_paystack')) {
            wp_send_json_error(array('message' => __('Invalid request', 'wc-paystack')));
        }
        
        // Check reference
        if (!isset($_POST['reference'])) {
            wp_send_json_error(array('message' => __('Missing reference', 'wc-paystack')));
        }
        
        $reference = sanitize_text_field($_POST['reference']);
        
        // Get the gateway instance
        $gateway = new WC_Gateway_Paystack();
        
        // ────── DISABLE EMAILS WHILE VERIFYING PAYMENT ──────
        // Prevent WooCommerce from firing any email templates (and their hooks)
        add_filter('woocommerce_email_enabled_new_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_processing_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_on_hold_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_completed_order', '__return_false');
        // ────────────────────────────────────────────────────
        
        // Log the verification attempt
        error_log('Verifying Paystack payment with reference: ' . $reference);
        
        // Verify payment
        $result = $gateway->verify_paystack_transaction($reference);
        
        if (!$result) {
            error_log('Paystack payment verification failed for reference: ' . $reference);
            wp_send_json_error(array('message' => __('Payment verification failed', 'wc-paystack')));
            return;
        }
        
        // Get order ID from metadata
        $order_id = null;
        
        // Try to get order ID from metadata
        if (isset($result->metadata) && isset($result->metadata->order_id)) {
            $order_id = intval($result->metadata->order_id);
        }
        
        // If not found in metadata, try to extract from reference
        if (!$order_id) {
            $order_id = $gateway->get_order_id_from_reference($reference);
        }
        
        if (!$order_id) {
            error_log('Order not found for Paystack reference: ' . $reference);
            wp_send_json_error(array('message' => __('Order not found', 'wc-paystack')));
            return;
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            error_log('Invalid order object for order ID: ' . $order_id);
            wp_send_json_error(array('message' => __('Invalid order', 'wc-paystack')));
            return;
        }
        
        // Check if order is already paid
        if ($order->is_paid()) {
            error_log('Order #' . $order_id . ' is already paid');
            wp_send_json_success(array(
                'message' => __('Payment already verified', 'wc-paystack'),
                'redirect' => $gateway->get_return_url($order),
            ));
            return;
        }
        
        try {
            // Mark order as paid and set to processing
            if (!$order->is_paid()) {
                error_log('Payment completed for order #' . $order_id . ' with Paystack reference: ' . $reference);
                $order->payment_complete($result->id);
                $order->update_status('processing', __('Payment received via Paystack.', 'wc-paystack'));
                wc_reduce_stock_levels($order_id);
                
                // ─────── Send WooCommerce emails only ───────
                // First, remove any filters that might be blocking emails
                remove_all_filters('woocommerce_email_enabled_new_order');
                remove_all_filters('woocommerce_email_enabled_customer_processing_order');
                remove_all_filters('woocommerce_email_enabled_customer_on_hold_order');
                remove_all_filters('woocommerce_email_enabled_customer_completed_order');

                // Log that we're about to send emails
                error_log('Sending WooCommerce order notification emails for order #' . $order_id);

                // Get mailer instance
                $mailer = WC()->mailer();

                // Trigger New Order email (to admin)
                if (isset($mailer->emails['WC_Email_New_Order'])) {
                    error_log('Triggering admin New Order email');
                    $mailer->emails['WC_Email_New_Order']->trigger($order_id, $order);
                }

                // Trigger Processing Order email (to customer)
                if (isset($mailer->emails['WC_Email_Customer_Processing_Order'])) {
                    error_log('Triggering customer Processing Order email');
                    $mailer->emails['WC_Email_Customer_Processing_Order']->trigger($order_id, $order);
                }
                // ────────────────────────────────────────────────
            }
            
            // Add order note
            $order->add_order_note(
                sprintf(__('Paystack payment successful. Transaction Reference: %s', 'wc-paystack'), 
                $reference)
            );
            
            error_log('Payment completed for order #' . $order_id . ' with Paystack reference: ' . $reference);
            
            // Log the transaction if transaction log class exists
            if (class_exists('WC_Paystack_Transaction_Log')) {
                try {
                    $transaction_log = new WC_Paystack_Transaction_Log();
                    $log_data = array(
                        'transaction_id'   => $result->id,
                        'order_id'         => $order_id,
                        'amount'           => $order->get_total(),
                        'currency'         => $order->get_currency(),
                        'payment_method'   => 'paystack',
                        'transaction_type' => 'payment',
                        'status'           => 'success',
                        'customer_email'   => $order->get_billing_email(),
                        'customer_name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                        'reference'        => $reference,
                        'message'          => __('Payment completed via AJAX', 'wc-paystack'),
                        'transaction_date' => current_time('mysql'),
                    );
                    
                    $log_id = $transaction_log->log_transaction($log_data);
                    
                    if ($log_id) {
                        error_log('Paystack transaction logged successfully. Log ID: ' . $log_id);
                    } else {
                        error_log('Failed to log Paystack transaction.');
                    }
                } catch (Exception $e) {
                    error_log('Error logging Paystack transaction: ' . $e->getMessage());
                }
            }
            
            wp_send_json_success(array(
                'message' => __('Payment verified successfully', 'wc-paystack'),
                'redirect' => $gateway->get_return_url($order),
            ));
        } catch (Exception $e) {
            error_log('Exception during payment completion: ' . $e->getMessage());
            wp_send_json_error(array('message' => __('Error processing payment', 'wc-paystack')));
        }
    }

    /**
     * Export transactions
     */
    public function export_transactions() {
        // Check if user has permission
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to export transactions', 'wc-paystack'));
        }
        
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wc_paystack_export_transactions')) {
            wp_die(__('Invalid request', 'wc-paystack'));
        }
        
        // Get filters
        $filters = array();
        
        if (isset($_POST['transaction_id']) && !empty($_POST['transaction_id'])) {
            $filters['transaction_id'] = sanitize_text_field($_POST['transaction_id']);
        }
        
        if (isset($_POST['order_id']) && !empty($_POST['order_id'])) {
            $filters['order_id'] = intval($_POST['order_id']);
        }
        
        if (isset($_POST['reference']) && !empty($_POST['reference'])) {
            $filters['reference'] = sanitize_text_field($_POST['reference']);
        }
        
        if (isset($_POST['status']) && !empty($_POST['status'])) {
            $filters['status'] = sanitize_text_field($_POST['status']);
        }
        
        if (isset($_POST['type']) && !empty($_POST['type'])) {
            $filters['type'] = sanitize_text_field($_POST['type']);
        }
        
        if (isset($_POST['date_from']) && !empty($_POST['date_from'])) {
            $filters['date_from'] = sanitize_text_field($_POST['date_from']);
        }
        
        if (isset($_POST['date_to']) && !empty($_POST['date_to'])) {
            $filters['date_to'] = sanitize_text_field($_POST['date_to']);
        }
        
        // Get transactions
        $transaction_log = new WC_Paystack_Transaction_Log();
        $transactions = $transaction_log->export_transactions($filters);
        
        if (empty($transactions)) {
            wp_die(__('No transactions found', 'wc-paystack'));
        }
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=paystack-transactions-' . date('Y-m-d') . '.csv');
        
        // Create a file pointer connected to the output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM to fix UTF-8 in Excel
        fputs($output, "\xEF\xBB\xBF");
        
        // Add CSV headers
        fputcsv($output, array(
            __('Transaction ID', 'wc-paystack'),
            __('Order ID', 'wc-paystack'),
            __('Amount', 'wc-paystack'),
            __('Currency', 'wc-paystack'),
            __('Payment Method', 'wc-paystack'),
            __('Transaction Type', 'wc-paystack'),
            __('Status', 'wc-paystack'),
            __('Customer Email', 'wc-paystack'),
            __('Customer Name', 'wc-paystack'),
            __('Reference', 'wc-paystack'),
            __('Message', 'wc-paystack'),
            __('Transaction Date', 'wc-paystack'),
        ));
        
        // Add transactions
        foreach ($transactions as $transaction) {
            fputcsv($output, array(
                $transaction->transaction_id,
                $transaction->order_id,
                $transaction->amount,
                $transaction->currency,
                $transaction->payment_method,
                $transaction->transaction_type,
                $transaction->status,
                $transaction->customer_email,
                $transaction->customer_name,
                $transaction->reference,
                $transaction->message,
                $transaction->transaction_date,
            ));
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Refresh all transactions
     */
    public function refresh_all_transactions() {
        // Only run on the transactions page
        if (!isset($_GET['page']) || $_GET['page'] !== 'wc-paystack-transactions') {
            return;
        }
        
        // Check if refresh_all is set and nonce is valid
        if (isset($_GET['refresh_all']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'paystack_refresh_all')) {
            $transaction_log = new WC_Paystack_Transaction_Log();
            $updated_count = $transaction_log->refresh_all_transaction_statuses();
            
            // Add admin notice
            add_action('admin_notices', function() use ($updated_count) {
                echo '<div class="notice notice-success is-dismissible"><p>' . 
                    sprintf(_n('%d transaction status updated.', '%d transaction statuses updated.', $updated_count, 'wc-paystack'), $updated_count) . 
                    '</p></div>';
            });
            
            // Redirect to remove the refresh_all parameter
            wp_redirect(remove_query_arg(array('refresh_all', '_wpnonce'), wp_get_referer()));
            exit;
        }
    }
}

// Initialize the AJAX handler
new WC_Paystack_AJAX_Handler();
