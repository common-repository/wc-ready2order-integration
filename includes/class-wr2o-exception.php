<?php

/**
 * Wr2o_Exception
 *
 * @class           Wr2o_Exception
 * @since           1.0.0
 * @package         WooCommerce_Ready_To_Order_Integration
 * @category        Class
 * @author          BjornTech
 */

defined('ABSPATH') || exit;

if (!class_exists('Wr2o_Exception', false)) {

    class Wr2o_Exception extends Exception
    {
        /**
         * Contains a log object instance
         * @access protected
         */
        protected $log;

        /**
         * Contains the curl object instance
         * @access protected
         */
        protected $curl_request_data;

        /**
         * Contains the curl url
         * @access protected
         */
        protected $curl_request_url;

        /**
         * Contains the curl response data
         * @access protected
         */
        protected $curl_response_data;

        /**
         * __Construct function.
         *
         * Redefine the exception so message isn't optional
         *
         * @access public
         * @return void
         */
        public function __construct($message, $code = 0, Exception $previous = null, $curl_request_url = '', $curl_request_data = '', $curl_response_data = '')
        {
            // make sure everything is assigned properly
            parent::__construct($message, $code, $previous);

            $this->curl_request_data = $curl_request_data;
            $this->curl_request_url = $curl_request_url;
            $this->curl_response_data = $curl_response_data;
        }

        /**
         * write_to_logs function.
         *
         * Stores the exception dump in the WooCommerce system logs
         *
         * @access public
         * @return void
         */
        public function write_to_logs()
        {
            Wr2o_Logger::separator(true);
            Wr2o_Logger::add('ready2order Exception file: ' . $this->getFile(), true);
            Wr2o_Logger::add('ready2order Exception line: ' . $this->getLine(), true);
            Wr2o_Logger::add('ready2order Exception code: ' . $this->getCode(), true);
            Wr2o_Logger::add('ready2order Exception message: ' . $this->getMessage(), true);
            Wr2o_Logger::separator(true);
        }

        public function get_response_data()
        {
            return $this->curl_response_data;
        }
        /**
         * write_standard_warning function.
         *
         * Prints out a standard warning
         *
         * @access public
         * @return void
         */
        public function write_standard_warning()
        {
            printf(
                wp_kses(
                    __("An error occured. For more information check out the <strong>%s</strong> logs inside <strong>WooCommerce -> System Status -> Logs</strong>.", 'wc-ready2order-integration'), array('strong' => array())
                ),
                Wr2o_Logger::get_domain()
            );
        }
    }
}

/**
 * Wr2o_API_Exception
 *
 * @class           Wr2o_API_Exception
 * @since           1.0.0
 * @package         WC_Ready2order_Integration
 * @category        Class
 * @author          bjorntech
 */

defined('ABSPATH') || exit;

if (!class_exists('Wr2o_API_Exception', false)) {

    class Wr2o_API_Exception extends Wr2o_Exception
    {
        /**
         * write_to_logs function.
         *
         * Stores the exception dump in the WooCommerce system logs
         *
         * @access public
         * @return void
         */
        public function write_to_logs()
        {
            Wr2o_Logger::separator(true);
            Wr2o_Logger::add('ready2order Exception file: ' . $this->getFile(), true);
            Wr2o_Logger::add('ready2order Exception line: ' . $this->getLine(), true);
            Wr2o_Logger::add('ready2order Exception code: ' . $this->getCode(), true);
            Wr2o_Logger::add('ready2order Exception message: ' . $this->getMessage(), true);

            if (!empty($this->curl_request_url)) {
                Wr2o_Logger::add('ready2order Exception Request URL: ' . $this->curl_request_url, true);
            }

            if (!empty($this->curl_request_data)) {
                Wr2o_Logger::add('ready2order Exception Request DATA: ' . $this->curl_request_data, true);
            }

            if (!empty($this->curl_response_data)) {
                Wr2o_Logger::add('ready2order Exception Response DATA: ' . $this->curl_response_data, true);
            }

            Wr2o_Logger::separator(true);

        }
    }
}
