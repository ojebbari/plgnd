<?php
/**
 * Plugin Name: SpaceRemit Log Listener
 * Description: Accepts any GET or POST data at /spaceremit-log-listener/ and stores it in wp_spaceremit_logs.
 * Version: 1.0
 * Author: SpaceRemit
 */

if (!defined('ABSPATH')) {
    exit;
}

class SpaceRemit_Log_Listener {

   public function __construct() {
    add_action('init', [$this, 'add_rewrite_rule']);
    add_filter('query_vars', [$this, 'add_query_vars']);
    add_action('template_redirect', [$this, 'handle_listener']);

    register_activation_hook(__FILE__, [$this, 'activate']);
    register_uninstall_hook(__FILE__, ['SpaceRemit_Log_Listener', 'uninstall']);
}


    /**
     * Add rewrite rule for endpoint
     */
    public function add_rewrite_rule() {
        add_rewrite_rule('^spaceremit-log-listener/?$', 'index.php?spaceremit_log_listener=1', 'top');
    }

    public function add_query_vars($vars) {
        $vars[] = 'spaceremit_log_listener';
        return $vars;
    }

    /**
     * Create table on activation
     */
    public function activate() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spaceremit_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            level varchar(50) DEFAULT 'info',
            message text,
            context varchar(255) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            type varchar(50) DEFAULT '',
            data longtext,
            order_id varchar(100) DEFAULT '',
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        flush_rewrite_rules();
    }

    /**
     * Uninstall - remove table if desired
     */
    public static function uninstall() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'spaceremit_logs';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        flush_rewrite_rules();
    }

    /**
     * Handle incoming requests
     */
    public function handle_listener() {
        if (!get_query_var('spaceremit_log_listener')) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'spaceremit_logs';

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))))] = $value;
            }
        }

        $method = $_SERVER['REQUEST_METHOD'];
        $body = file_get_contents('php://input');
        $parsed = [];

        // Capture request data
        if (!empty($body)) {
            $decoded = json_decode($body, true);
            $parsed = is_array($decoded) ? $decoded : ['raw_body' => $body];
        } else {
            $parsed = !empty($_POST) ? $_POST : $_GET;
        }

        $wpdb->insert(
            $table_name,
            [
                'level'      => 'info',
                'message'    => 'Incoming ' . $method . ' request',
                'context'    => sanitize_text_field($_SERVER['REMOTE_ADDR']),
                'created_at' => current_time('mysql'),
                'type'       => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'),
                'data'       => wp_json_encode([
                    'headers' => $headers,
                    'payload' => $parsed
                ]),
                'order_id'   => isset($parsed['order_id']) ? sanitize_text_field($parsed['order_id']) : ''
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        wp_send_json_success([
            'status' => 'ok',
            'message' => 'Log saved successfully',
            'received' => $parsed
        ]);
        exit;
    }
}

new SpaceRemit_Log_Listener();