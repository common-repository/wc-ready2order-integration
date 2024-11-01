<?php

/**
 * Wr2o_API
 *
 * @class           Wr2o_API
 * @since           1.0.0
 * @package         WooCommerce_Ready_To_Order_Integration
 * @category        Class
 * @author          BjornTech
 */

defined('ABSPATH') || exit;

if (!class_exists('Wr2o_API', false)) {
    class Wr2o_API
    {

        const API_ENDPOINT = 'https://rto.bjorntech.net/v1';

        private static $uuid;
        private static $api_endpoint;
        private static $refresh_token;
        private static $access_token;
        private static $timeout = 10;
        private static $language = 'en-US';

        public static function construct()
        {
            self::$api_endpoint = trailingslashit(get_option('wr2o_alternate_service_url') ?: self::API_ENDPOINT);
            self::$uuid = get_option('ready2order_uuid');
            self::$refresh_token = get_option('ready2order_refresh_token');
        }

        public static function get_api_endpoint()
        {
            return self::$api_endpoint;
        }

        public static function force_connection()
        {
            delete_site_transient('ready2order_access_token');
            self::construct();
            self::get_access_token();
        }

        public static function delete($path, $args = [], $timeout = 10): array
        {
            return self::make_request('DELETE', $path, $args, $timeout);
        }

        public static function get($path, $args = [], $timeout = 10): array
        {
            return self::make_request('GET', $path, $args, $timeout);
        }

        public static function patch($path, $args = [], $timeout = 10): array
        {
            return self::make_request('PATCH', $path, $args, $timeout);
        }

        public static function post($path, $args = [], $timeout = 10): array
        {
            return self::make_request('POST', $path, $args, $timeout);
        }

        public static function put($path, $args = [], $timeout = 10): array
        {
            return self::make_request('PUT', $path, $args, $timeout);
        }

        public static function get_access_token()
        {
            global $woocommerce;
            global $ready_to_order;

            $refresh_token = get_option('ready2order_refresh_token');
            $access_token = get_site_transient('ready2order_access_token');

            if (!empty($refresh_token) && false === $access_token) {

                $body = array(
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refresh_token,
                    'plugin_version' => $ready_to_order->version,
                    'wc_version' => $woocommerce->version,
                );

                $args = array(
                    'headers' => array(
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ),
                    'timeout' => 20,
                    'body' => $body,
                );

                $url = self::$api_endpoint . 'token';

                $response = wp_remote_post($url, $args);

                if (is_wp_error($response)) {

                    $code = $response->get_error_code();

                    $error = $response->get_error_message($code);

                    throw new Wr2o_API_Exception($error, 0, null, $url, json_encode($body));

                } else {

                    $response_body = json_decode(wp_remote_retrieve_body($response));
                    $http_code = wp_remote_retrieve_response_code($response);

                    if (200 != $http_code) {

                        Wr2o_Logger::add(print_r($url,true));
                        Wr2o_Logger::add(print_r($args,true));
                        $error_message = isset($response_body->error) ? $response_body->error : 'Unknown error message';
                        Wr2o_Logger::add(sprintf('Error %s when asking for access token from service: %s', $http_code, $error_message));
                        throw new Wr2o_API_Exception($error_message, $http_code, null, $url, json_encode($body), json_encode($response));

                    }


                    update_option('ready2order_valid_to', $response_body->valid_to);
                    update_option('ready2order_is_trial', $response_body->is_trial);
                    update_option('ready2order_webhook_status', $response_body->webhook_status);
                    update_option('ready2order_refresh_token', $response_body->refresh_token);

                    set_site_transient('ready2order_access_token', $response_body->access_token, $response_body->expires_in);

                    Wr2o_Logger::add(sprintf('Got access "%s" expiring in %s seconds', $response_body->access_token, $response_body->expires_in));

                    $access_token = $response_body->access_token;

                }

            }

            return $access_token;

        }

        public static function set_language(string $language): void
        {
            self::$language = $language;
        }

        public static function set_timeout(int $timeout): void
        {
            self::$timeout = $timeout;
        }

        public static function get_timeout(): int
        {
            return self::$timeout;
        }

        public static function ratelimiter()
        {

            $current = microtime(true);
            $time_passed = $current - (float) get_site_transient('wr2o_api_limiter', $current);
            set_site_transient('wr2o_api_limiter', $current);

            if ($time_passed < 1000000) {
                usleep(1000000 - $time_passed);
            }

        }

        /**
         * Performs the underlying HTTP request. Not very exciting.
         *
         * @param string $$http_verb The HTTP verb to use: get, post, put, patch, delete
         * @param string $path       The API method to be called
         * @param array  $args       Assoc array of parameters to be passed
         * @param mixed  $timeout
         *
         * @return array Assoc array of decoded result
         */
        private static function make_request(string $method, string $path, array $args = null, ?int $timeout = null): array
        {

            $url = self::$api_endpoint . "api/proxy/" . $path;

            if (!empty($args) && is_array($args)) {

                if ('GET' == $method || 'DELETE' == $method) {
                    $url .= '?' . preg_replace('/%5B[0-9]+%5D/simU', '%5B%5D', http_build_query($args, '', '&'));
                    $args = null;
                } else {
                    $args = json_encode($args, JSON_UNESCAPED_SLASHES);
                }

            }

            $request = array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . self::get_access_token(),
                ),
                'method' => $method,
                'timeout' => $timeout ?? self::get_timeout(),
            );

            if ($args) {
                $request['body'] = $args;
            }

            $response = wp_remote_request($url, $request);

            self::ratelimiter();

            if (is_wp_error($response)) {

                $data = self::parse_json_from_response($response, $url, $request);

                if (isset($data['error']) && $data['error'] === true && !empty($data['msg'])) {
                    $msg = $data['msg'];
                } else {
                    $msg = "API Request ({$method} {$url}) gave invalid response which could not be JSON-decoded";
                }

                if ($response->get_error_code() == 404) {
                    throw new Wr2o_API_Exception($msg);
                }

                throw new Wr2o_API_Exception($msg, $data);

                $code = $response->get_error_code();
                Wr2o_Logger::add(print_r($code, true));
                $error = $response->get_error_message($code);
                Wr2o_Logger::add(print_r($error, true));
                throw new Wr2o_API_Exception($error . ' ' . $code, 999, null, $url);

            } else {

                $data = self::parse_json_from_response($response, $url, $request);

                if (($http_code = wp_remote_retrieve_response_code($response)) > 299) {
                    Wr2o_Logger::add(print_r($http_code, true));
                    Wr2o_Logger::add(print_r($data, true));
                    throw new Wr2o_API_Exception(isset($data['error']) ? $data['msg'] : 'Error when connecting to ready2order', $http_code, null, $url, json_encode($args), json_encode($data));
                    return false;
                }

                return $data;

            }

        }

        private static function parse_json_from_response($response, $url, $request): array
        {

            $response = wp_remote_retrieve_body($response);

            $data = json_decode($response, true);

            if (is_array($data)) {
                return $data;
            }

            throw new Wr2o_API_Exception('Unable to decode JSON data', 999, null, $url, print_r($request, true), print_r($response, true));
        }

    }

    Wr2o_API::construct();

}
