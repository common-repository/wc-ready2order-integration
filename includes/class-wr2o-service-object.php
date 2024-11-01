<?php

defined('ABSPATH') || exit;

if (!class_exists('Wr2o_Service_Handler', false)) {
    class Wr2o_Service_Handler
    {
        const ADM_URL = 'bjorntech.net/v2';

        private static $instance = null;
        private static $access_token = null;
        private static $webhook_signing_key = null;
        private static $webhook_status = null;
        private static $expires_in = null;
        private static $organization_uuid = null;
        private static $valid_to = null;
        private static $last_synced = null;
        private static $is_trial = null;

        public static function get_webhook_status()
        {
            if (self::$webhook_status === null) {
                self::$webhook_status = get_option('ready2order_webhook_status');
            }
            return self::$webhook_status;
        }

        public static function set_webhook_status($webhook_status)
        {
            self::$webhook_status = $webhook_status;
            update_option('ready2order_webhook_status', self::$webhook_status);
        }

        public static function get_webhook_signing_key()
        {
            if (self::$webhook_signing_key === null) {
                self::$webhook_signing_key = get_option('ready2order_webhook_signing_key');
            }
            return self::$webhook_signing_key;
        }

        public static function set_webhook_signing_key($webhook_signing_key)
        {
            self::$webhook_signing_key = $webhook_signing_key;
            update_option('ready2order_webhook_signing_key', self::$webhook_signing_key);
        }

        public static function get_access_token()
        {
            if (self::$access_token === null) {
                self::$access_token = get_site_transient('ready2order_access_token');
            }
            return self::$access_token;
        }

        public static function set_access_token($access_token)
        {
            self::$access_token = $access_token;
            set_site_transient('ready2order_access_token', self::$access_token);
        }

        public static function get_expires_in()
        {
            if (self::$expires_in === null) {
                self::$expires_in = get_option('ready2order_expires_in');
            }
            return self::$expires_in;
        }

        public static function set_expires_in($expires_in)
        {
            self::$expires_in = intval($expires_in / 1000);
            update_option('ready2order_expires_in', self::$expires_in);
        }

        public static function get_organization_uuid()
        {
            if (self::$organization_uuid === null) {
                self::$organization_uuid = get_option('ready2order_uuid');
            }
            return self::$organization_uuid;
        }

        public static function set_organization_uuid($organization_uuid)
        {
            self::$organization_uuid = $organization_uuid;
            update_option('ready2order_uuid', self::$organization_uuid);
        }

        public static function get_valid_to()
        {
            if (self::$valid_to === null) {
                self::$valid_to = strtotime(get_option('ready2order_valid_to'));
            }
            return self::$valid_to;
        }

        public static function set_valid_to($valid_to)
        {
            update_option('ready2order_valid_to', $valid_to);
        }

        public static function get_last_synced()
        {
            if (self::$last_synced === null) {
                self::$last_synced = get_option('ready2order_last_synced');
            }
            return self::$last_synced;
        }

        public static function set_last_synced($last_synced)
        {
            self::$last_synced = intval($last_synced / 1000);
            update_option('ready2order_last_synced', self::$last_synced);
        }

        public static function get_is_trial()
        {
            if (self::$is_trial === null) {
                self::$is_trial = get_option('ready2order_is_trial');
            }
            return self::$is_trial;
        }

        public static function set_is_trial($is_trial)
        {
            self::$is_trial = $is_trial;
            update_option('ready2order_is_trial', self::$is_trial);
        }

        public static function get_adm_url()
        {
            return ($adm_url = get_option('ready2order_alternate_service_url')) != '' ? $adm_url : self::ADM_URL;
        }

        public static function instance()
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

    }
}