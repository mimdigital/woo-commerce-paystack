<?php
/**
 * Paystack Transaction Log
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_Paystack_Transaction_Log Class
 */
class WC_Paystack_Transaction_Log {
    /**
     * Table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        // Use the same table name as in the original code
        $this->table_name = $wpdb->prefix . 'wc_paystack_transactions';
    }

    /**
     * Create the transaction log table
     */
    public function create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            transaction_id varchar(255) NOT NULL,
            order_id bigint(20) NOT NULL,
            amount decimal(10,2) NOT NULL,
            currency varchar(3) NOT NULL,
            payment_method varchar(50) NOT NULL DEFAULT 'paystack',
            transaction_type varchar(20) NOT NULL DEFAULT 'payment',
            status varchar(20) NOT NULL,
            customer_email varchar(255) NOT NULL,
            customer_name varchar(255) NOT NULL,
            reference varchar(255) NOT NULL,
            message text,
            transaction_date datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY transaction_id (transaction_id),
            KEY order_id (order_id),
            KEY reference (reference),
            KEY status (status),
            KEY transaction_type (transaction_type),
            KEY transaction_date (transaction_date)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);

        // Check if table was created successfully
        if ($wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") !== $this->table_name) {
            error_log('Failed to create Paystack transaction log table: ' . $this->table_name);
            return false;
        }

        error_log('Paystack transaction log table created successfully: ' . $this->table_name);
        return true;
    }

    /**
     * Log a transaction
     */
    public function log_transaction($data) {
        global $wpdb;

        // Ensure table exists before trying to insert
        if (!$this->table_exists()) {
            error_log('Paystack transaction table does not exist, creating it now...');
            $this->create_table();
            
            // Check again after creation attempt
            if (!$this->table_exists()) {
                error_log('Failed to create Paystack transaction table, cannot log transaction');
                return false;
            }
        }

        // Validate required fields
        $required_fields = ['transaction_id', 'order_id', 'amount', 'currency', 'status', 'customer_email', 'reference'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                error_log("Paystack transaction log: Missing required field '{$field}'");
                return false;
            }
        }

        // Prepare data for insertion
        $insert_data = array(
            'transaction_id'   => sanitize_text_field($data['transaction_id']),
            'order_id'         => intval($data['order_id']),
            'amount'           => floatval($data['amount']),
            'currency'         => sanitize_text_field($data['currency']),
            'payment_method'   => sanitize_text_field($data['payment_method'] ?? 'paystack'),
            'transaction_type' => sanitize_text_field($data['transaction_type'] ?? 'payment'),
            'status'           => sanitize_text_field($data['status']),
            'customer_email'   => sanitize_email($data['customer_email']),
            'customer_name'    => sanitize_text_field($data['customer_name'] ?? ''),
            'reference'        => sanitize_text_field($data['reference']),
            'message'          => sanitize_textarea_field($data['message'] ?? ''),
            'transaction_date' => sanitize_text_field($data['transaction_date'] ?? current_time('mysql')),
        );

        // Insert the transaction
        $result = $wpdb->insert(
            $this->table_name,
            $insert_data,
            array(
                '%s', // transaction_id
                '%d', // order_id
                '%f', // amount
                '%s', // currency
                '%s', // payment_method
                '%s', // transaction_type
                '%s', // status
                '%s', // customer_email
                '%s', // customer_name
                '%s', // reference
                '%s', // message
                '%s', // transaction_date
            )
        );

        if ($result === false) {
            error_log('Failed to insert Paystack transaction: ' . $wpdb->last_error);
            return false;
        }

        error_log('Paystack transaction logged successfully with ID: ' . $wpdb->insert_id);
        return $wpdb->insert_id;
    }

    /**
     * Get transactions with filters and pagination
     */
    public function get_transactions($args = array()) {
        global $wpdb;

        // Ensure table exists
        if (!$this->table_exists()) {
            return array();
        }

        $defaults = array(
            'per_page' => 20,
            'page'     => 1,
            'orderby'  => 'transaction_date',
            'order'    => 'DESC',
        );

        $args = wp_parse_args($args, $defaults);

        // Build WHERE clause
        $where_conditions = array('1=1');
        $where_values = array();

        if (!empty($args['transaction_id'])) {
            $where_conditions[] = 'transaction_id LIKE %s';
            $where_values[] = '%' . $wpdb->esc_like($args['transaction_id']) . '%';
        }

        if (!empty($args['order_id'])) {
            $where_conditions[] = 'order_id = %d';
            $where_values[] = intval($args['order_id']);
        }

        if (!empty($args['reference'])) {
            $where_conditions[] = 'reference LIKE %s';
            $where_values[] = '%' . $wpdb->esc_like($args['reference']) . '%';
        }

        if (!empty($args['status'])) {
            $where_conditions[] = 'status = %s';
            $where_values[] = $args['status'];
        }

        if (!empty($args['type'])) {
            $where_conditions[] = 'transaction_type = %s';
            $where_values[] = $args['type'];
        }

        if (!empty($args['date_from'])) {
            $where_conditions[] = 'DATE(transaction_date) >= %s';
            $where_values[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where_conditions[] = 'DATE(transaction_date) <= %s';
            $where_values[] = $args['date_to'];
        }

        $where_clause = implode(' AND ', $where_conditions);

        // Build ORDER BY clause
        $allowed_orderby = array('transaction_date', 'amount', 'status', 'transaction_type', 'order_id');
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'transaction_date';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Build LIMIT clause
        $per_page = max(1, min(100, intval($args['per_page'])));
        $page = max(1, intval($args['page']));
        $offset = ($page - 1) * $per_page;

        // Prepare and execute query
        $sql = "SELECT * FROM {$this->table_name} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $where_values[] = $per_page;
        $where_values[] = $offset;

        if (!empty($where_values)) {
            $prepared_sql = $wpdb->prepare($sql, $where_values);
        } else {
            $prepared_sql = $sql;
        }

        return $wpdb->get_results($prepared_sql);
    }

    /**
     * Count transactions with filters
     */
    public function count_transactions($args = array()) {
        global $wpdb;

        // Ensure table exists
        if (!$this->table_exists()) {
            return 0;
        }

        // Build WHERE clause (same as get_transactions)
        $where_conditions = array('1=1');
        $where_values = array();

        if (!empty($args['transaction_id'])) {
            $where_conditions[] = 'transaction_id LIKE %s';
            $where_values[] = '%' . $wpdb->esc_like($args['transaction_id']) . '%';
        }

        if (!empty($args['order_id'])) {
            $where_conditions[] = 'order_id = %d';
            $where_values[] = intval($args['order_id']);
        }

        if (!empty($args['reference'])) {
            $where_conditions[] = 'reference LIKE %s';
            $where_values[] = '%' . $wpdb->esc_like($args['reference']) . '%';
        }

        if (!empty($args['status'])) {
            $where_conditions[] = 'status = %s';
            $where_values[] = $args['status'];
        }

        if (!empty($args['type'])) {
            $where_conditions[] = 'transaction_type = %s';
            $where_values[] = $args['type'];
        }

        if (!empty($args['date_from'])) {
            $where_conditions[] = 'DATE(transaction_date) >= %s';
            $where_values[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where_conditions[] = 'DATE(transaction_date) <= %s';
            $where_values[] = $args['date_to'];
        }

        $where_clause = implode(' AND ', $where_conditions);

        $sql = "SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_clause}";

        if (!empty($where_values)) {
            $prepared_sql = $wpdb->prepare($sql, $where_values);
        } else {
            $prepared_sql = $sql;
        }

        return intval($wpdb->get_var($prepared_sql));
    }

    /**
     * Get transactions by order ID
     */
    public function get_transactions_by_order_id($order_id) {
        global $wpdb;

        // Ensure table exists
        if (!$this->table_exists()) {
            return array();
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE order_id = %d ORDER BY transaction_date DESC",
            intval($order_id)
        );

        return $wpdb->get_results($sql);
    }

    /**
     * Update transaction status
     */
    public function update_transaction_status($transaction_id, $status) {
        global $wpdb;

        // Ensure table exists
        if (!$this->table_exists()) {
            return false;
        }

        $result = $wpdb->update(
            $this->table_name,
            array(
                'status' => sanitize_text_field($status),
                'updated_at' => current_time('mysql')
            ),
            array('transaction_id' => sanitize_text_field($transaction_id)),
            array('%s', '%s'),
            array('%s')
        );

        return $result !== false;
    }

    /**
     * Check transaction status from Paystack API
     */
    public function check_transaction_status($transaction_id) {
        $gateway = new WC_Gateway_Paystack();
        
        if (empty($gateway->secret_key)) {
            return false;
        }

        $url = "https://api.paystack.co/transaction/verify/{$transaction_id}";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $gateway->secret_key,
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            error_log('Paystack API error: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['status']) && $body['status'] === true) {
            $new_status = $body['data']['status'];
            
            // Update the status in our database
            $this->update_transaction_status($transaction_id, $new_status);
            
            return $new_status;
        }

        return false;
    }

    /**
     * Refresh all transaction statuses
     */
    public function refresh_all_transaction_statuses() {
        global $wpdb;

        // Ensure table exists
        if (!$this->table_exists()) {
            return 0;
        }

        // Get all payment transactions that are not already final
        $sql = "SELECT transaction_id FROM {$this->table_name} 
                WHERE transaction_type = 'payment' 
                AND status NOT IN ('success', 'failed', 'reversed') 
                ORDER BY transaction_date DESC 
                LIMIT 50";

        $transactions = $wpdb->get_results($sql);
        $updated_count = 0;

        foreach ($transactions as $transaction) {
            $new_status = $this->check_transaction_status($transaction->transaction_id);
            if ($new_status) {
                $updated_count++;
            }
            
            // Add a small delay to avoid rate limiting
            usleep(100000); // 0.1 seconds
        }

        return $updated_count;
    }

    /**
     * Export transactions to CSV
     */
    public function export_transactions($filters = array()) {
        // Get all transactions matching filters (no pagination for export)
        $args = array_merge($filters, array(
            'per_page' => -1, // Get all
            'page' => 1,
        ));

        return $this->get_transactions($args);
    }

    /**
     * Clean up old transactions
     */
    public function cleanup_old_transactions($days = 365) {
        global $wpdb;

        // Ensure table exists
        if (!$this->table_exists()) {
            return 0;
        }

        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE transaction_date < %s",
            $cutoff_date
        ));

        if ($result !== false) {
            error_log("Cleaned up {$result} old Paystack transactions");
        }

        return $result;
    }

    /**
     * Get transaction statistics
     */
    public function get_transaction_stats($days = 30) {
        global $wpdb;

        // Ensure table exists
        if (!$this->table_exists()) {
            return array(
                'total' => 0,
                'successful' => 0,
                'failed' => 0,
                'total_amount' => 0,
                'success_rate' => 0
            );
        }

        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $stats = array();

        // Total transactions
        $stats['total'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE transaction_date >= %s",
            $cutoff_date
        ));

        // Successful transactions
        $stats['successful'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE transaction_date >= %s AND status = 'success'",
            $cutoff_date
        ));

        // Failed transactions
        $stats['failed'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE transaction_date >= %s AND status = 'failed'",
            $cutoff_date
        ));

        // Total amount
        $stats['total_amount'] = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM {$this->table_name} WHERE transaction_date >= %s AND status = 'success'",
            $cutoff_date
        ));

        // Success rate
        $stats['success_rate'] = $stats['total'] > 0 ? round(($stats['successful'] / $stats['total']) * 100, 2) : 0;

        return $stats;
    }

    /**
     * Check if table exists
     */
    public function table_exists() {
        global $wpdb;
        return $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
    }

    /**
     * Drop the table (for uninstall)
     */
    public function drop_table() {
        global $wpdb;
        return $wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
    }

    /**
     * Get table name
     */
    public function get_table_name() {
        return $this->table_name;
    }
}
