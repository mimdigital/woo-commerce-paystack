<?php
/**
 * Paystack Webhook Handler
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_Paystack_Webhook_Handler Class
 */
class WC_Paystack_Webhook_Handler {
    /**
     * Gateway instance
     * 
     * @var WC_Gateway_Paystack
     */
    protected $gateway;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Get the gateway instance
        $this->gateway = new WC_Gateway_Paystack();
    }

    /**
     * Process webhook
     */
    public function process_webhook() {
        // Get the input
        $input = file_get_contents('php://input');
        
        if (empty($input)) {
            status_header(400);
            exit;
        }
        
        // Validate the webhook signature
        if (!$this->validate_webhook_signature($input)) {
            status_header(401);
            exit;
        }
        
        // Decode the webhook payload
        $event = json_decode($input);
        
        if (!$event || !isset($event->event)) {
            status_header(400);
            exit;
        }
        
        // Process the webhook based on the event type
        switch ($event->event) {
            case 'charge.success':
                $this->process_successful_charge($event->data);
                break;
                
            case 'transfer.success':
                $this->process_successful_transfer($event->data);
                break;
                
            case 'refund.processed':
                $this->process_refund($event->data);
                break;
                
            case 'dispute.create':
                $this->process_dispute($event->data);
                break;
                
            case 'charge.reversed':
                $this->process_reversed_charge($event->data);
                break;
                
            default:
                // Log unhandled event for debugging
                error_log('Paystack webhook received unhandled event: ' . $event->event);
                status_header(200);
                exit;
        }
        
        // Webhook processed successfully
        status_header(200);
        exit;
    }
    
    /**
     * Validate webhook signature
     */
    private function validate_webhook_signature($input) {
        if (empty($this->gateway->secret_key)) {
            return false;
        }
        
        // Get signature from header
        $signature = isset($_SERVER['HTTP_X_PAYSTACK_SIGNATURE']) ? sanitize_text_field($_SERVER['HTTP_X_PAYSTACK_SIGNATURE']) : '';
        
        if (empty($signature)) {
            return false;
        }
        
        // Compute expected signature
        $expected_signature = hash_hmac('sha512', $input, $this->gateway->secret_key);
        
        // Compare signatures
        return hash_equals($expected_signature, $signature);
    }
    
    /**
     * Process successful charge
     */
    private function process_successful_charge($data) {
        if (!isset($data->reference)) {
            return;
        }
        
        $reference = $data->reference;
        
        // Get order ID from reference
        $order_id = $this->gateway->get_order_id_from_reference($reference);
        
        if (!$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        
        // Check if order is already paid
        if ($order->is_paid()) {
            return;
        }
        
        // Disable email notifications
        add_filter('woocommerce_email_enabled_new_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_processing_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_on_hold_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_completed_order', '__return_false');
        
        // Mark order as paid and set to processing
        $order->payment_complete($data->id);
        $order->update_status('processing', __('Payment received via Paystack webhook.', 'wc-paystack'));
        
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
        
        // Add order note
        $order->add_order_note(
            sprintf(__('Paystack payment successful via webhook. Transaction Reference: %s', 'wc-paystack'), 
            $reference)
        );
        
        // Log the transaction if transaction log class exists
        if (class_exists('WC_Paystack_Transaction_Log')) {
            $transaction_log = new WC_Paystack_Transaction_Log();
            $transaction_log->log_transaction(array(
                'transaction_id'   => $data->id,
                'order_id'         => $order_id,
                'amount'           => $order->get_total(),
                'currency'         => $order->get_currency(),
                'payment_method'   => 'paystack',
                'transaction_type' => 'payment',
                'status'           => 'success',
                'customer_email'   => $order->get_billing_email(),
                'customer_name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'reference'        => $reference,
                'message'          => __('Payment completed via webhook', 'wc-paystack'),
                'transaction_date' => current_time('mysql'),
            ));
        }
    }
    
    /**
     * Process successful transfer
     */
    private function process_successful_transfer($data) {
        // Not implemented in this version
    }
    
    /**
     * Process refund
     */
    private function process_refund($data) {
        if (!isset($data->transaction_id)) {
            return;
        }
        
        // Find order by transaction ID
        global $wpdb;
        
        // Check if HPOS is active
        $is_hpos_active = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && 
                      \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        
        if ($is_hpos_active) {
            // HPOS compatible query
            $order_ids = wc_get_orders(array(
                'meta_key' => '_transaction_id',
                'meta_value' => $data->transaction_id,
                'limit' => 1,
                'return' => 'ids',
            ));
            
            $order_id = !empty($order_ids) ? $order_ids[0] : null;
        } else {
            // Legacy query
            $order_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->prefix}postmeta 
                WHERE meta_key = %s 
                AND meta_value = %s", 
                '_transaction_id',
                $data->transaction_id
            ));
        }
        
        if (!$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        
        // Add order note
        $order->add_order_note(
            sprintf(__('Refund processed via Paystack webhook. Refund ID: %s', 'wc-paystack'), 
            $data->id)
        );
        
        // Log the transaction if transaction log class exists
        if (class_exists('WC_Paystack_Transaction_Log')) {
            $transaction_log = new WC_Paystack_Transaction_Log();
            $transaction_log->log_transaction(array(
                'transaction_id'   => $data->id,
                'order_id'         => $order_id,
                'amount'           => $data->amount / 100, // Convert from kobo/pesewas
                'currency'         => $order->get_currency(),
                'payment_method'   => 'paystack',
                'transaction_type' => 'refund',
                'status'           => 'success',
                'customer_email'   => $order->get_billing_email(),
                'customer_name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'reference'        => $order->get_meta('_paystack_payment_txn_ref'),
                'message'          => __('Refund processed via webhook', 'wc-paystack'),
                'transaction_date' => current_time('mysql'),
            ));
        }
    }
    
    /**
     * Process dispute
     */
    private function process_dispute($data) {
        if (!isset($data->transaction_id)) {
            return;
        }
        
        // Find order by transaction ID
        global $wpdb;
        
        // Check if HPOS is active
        $is_hpos_active = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && 
                      \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        
        if ($is_hpos_active) {
            // HPOS compatible query
            $order_ids = wc_get_orders(array(
                'meta_key' => '_transaction_id',
                'meta_value' => $data->transaction_id,
                'limit' => 1,
                'return' => 'ids',
            ));
            
            $order_id = !empty($order_ids) ? $order_ids[0] : null;
        } else {
            // Legacy query
            $order_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->prefix}postmeta 
                WHERE meta_key = %s 
                AND meta_value = %s", 
                '_transaction_id',
                $data->transaction_id
            ));
        }
        
        if (!$order_id) {
            return;
        }
        
        $order = wc_get_order($order_id);
        
        // Add order note
        $order->add_order_note(
            sprintf(__('Dispute created via Paystack webhook. Dispute ID: %s', 'wc-paystack'), 
            $data->id)
        );
        
        // Log the transaction if transaction log class exists
        if (class_exists('WC_Paystack_Transaction_Log')) {
            $transaction_log = new WC_Paystack_Transaction_Log();
            $transaction_log->log_transaction(array(
                'transaction_id'   => $data->id,
                'order_id'         => $order_id,
                'amount'           => $data->amount / 100, // Convert from kobo/pesewas
                'currency'         => $order->get_currency(),
                'payment_method'   => 'paystack',
                'transaction_type' => 'dispute',
                'status'           => 'pending',
                'customer_email'   => $order->get_billing_email(),
                'customer_name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'reference'        => $order->get_meta('_paystack_payment_txn_ref'),
                'message'          => __('Dispute created via webhook', 'wc-paystack'),
                'transaction_date' => current_time('mysql'),
            ));
        }
    }

    /**
     * Process reversed charge
     */
    private function process_reversed_charge($data) {
        if (!isset($data->reference)) {
            error_log('Paystack reversal webhook missing reference');
            return;
        }
        
        $reference = $data->reference;
        error_log('Processing reversed charge for reference: ' . $reference);
        
        // Get order ID from reference
        $order_id = $this->gateway->get_order_id_from_reference($reference);
        
        if (!$order_id) {
            error_log('Order not found for reversed Paystack reference: ' . $reference);
            return;
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            error_log('Invalid order object for reversed transaction, order ID: ' . $order_id);
            return;
        }
        
        // Add order note
        $order->add_order_note(
            sprintf(__('Paystack payment reversed. Transaction Reference: %s', 'wc-paystack'), 
            $reference)
        );
        
        // Update order status if needed
        if ($order->get_status() === 'processing' || $order->get_status() === 'completed') {
            $order->update_status('on-hold', __('Payment reversed via Paystack.', 'wc-paystack'));
            
            // Send customer notification
            $mailer = WC()->mailer();
            if (isset($mailer->emails['WC_Email_Customer_On_Hold_Order'])) {
                $mailer->emails['WC_Email_Customer_On_Hold_Order']->trigger($order_id, $order);
            }
        }
        
        // Log the transaction if transaction log class exists
        if (class_exists('WC_Paystack_Transaction_Log')) {
            try {
                $transaction_log = new WC_Paystack_Transaction_Log();
                $log_data = array(
                    'transaction_id'   => isset($data->id) ? $data->id : $reference,
                    'order_id'         => $order_id,
                    'amount'           => $order->get_total(),
                    'currency'         => $order->get_currency(),
                    'payment_method'   => 'paystack',
                    'transaction_type' => 'reversal',
                    'status'           => 'reversed',
                    'customer_email'   => $order->get_billing_email(),
                    'customer_name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'reference'        => $reference,
                    'message'          => __('Payment reversed via Paystack webhook', 'wc-paystack'),
                    'transaction_date' => current_time('mysql'),
                );
                
                error_log('Logging reversed transaction: ' . print_r($log_data, true));
                
                $log_id = $transaction_log->log_transaction($log_data);
                
                if ($log_id) {
                    error_log('Reversed transaction logged successfully. Log ID: ' . $log_id);
                } else {
                    error_log('Failed to log reversed transaction.');
                }
            } catch (Exception $e) {
                error_log('Error logging reversed transaction: ' . $e->getMessage());
            }
        }
    }
}
