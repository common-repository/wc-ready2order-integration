<?php
/**
 * Wr2o_Logger class
 *
 * @class          Wr2o_Logger
 * @package        WooCommerce_Ready_To_Order_Integration/Classes
 * @category       Logs
 */

defined('ABSPATH') || exit;

if (!class_exists('Wr2o_Logger', false)) {
    class Wr2o_Logger
    {
        private static $logger;
        private static $log_all;
        private static $handle = 'wc-ready2order-integration';

        /**
         * Log
         *
         * @param string|arrray $message
         */
        public static function add($message, $force = false, $wp_debug = false)
        {
            if (empty(self::$logger)) {
                self::$logger = wc_get_logger();
                self::$log_all = 'yes' == get_option('wr2o_logging_enabled');
            }

            if (true === self::$log_all || true === $force) {
                if (is_array($message)) {
                    $message = print_r($message, true);
                }

                self::$logger->add(
                    self::$handle,
                    getmypid() . ' - ' . $message
                );

                if (true === $wp_debug && defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(getmypid() . ' - ' . $message);
                }
            }
        }

        /**
         * separator function.
         *
         * Inserts a separation line for better overview in the logs.
         *
         * @access public
         * @return void
         */
        public static function separator($force = false)
        {
            self::add('-------------------------------------------------------', $force);
        }

        public function get_domain()
        {
            return $this->handle;
        }

        /**
         * Returns a link to the log files in the WP backend.
         */
        public static function get_admin_link()
        {
            $log_path = wc_get_log_file_path(self::$handle);
            $log_path_parts = explode('/', $log_path);
            return add_query_arg(array(
                'page' => 'wc-status',
                'tab' => 'logs',
                'log_file' => end($log_path_parts),
            ), admin_url('admin.php'));
        }
    }
}
