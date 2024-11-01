<?php


defined('ABSPATH') || exit;

if (!class_exists('ready2order_Integration_Authorization', false)) {

    class w2ro_Integration_Authorization
    {
        static $is_connection_ok = true;

        public function __construct()
        {
            add_filter('ready2order_is_client_allowed_to_sync', array($this, 'is_client_allowed_to_sync'), 10, 2);
            add_filter('ready2order_is_it_time_to_check_sync', array($this, 'is_it_time_to_check_sync'), 10, 5);
            add_filter('ready2order_connection_status', array($this, 'connection_status'));
            add_action('ready2order_connection_fail', array($this, 'connection_fail'));
            add_action('ready2order_connection_success', array($this, 'connection_success'));
            add_action('ready2order_force_connection', array($this, 'force_connection'));
            add_action('ready2order_service_heartbeat', array($this, 'w2ro_service_heartbeat'));
            add_action('init', array($this, 'schedule_heartbeat_sync'));

        }

        public function is_it_time_to_check_sync($sync, $name, $model, $sync_all, $microtime)
        {

            $is_client_allowed_to_sync = $this->is_client_allowed_to_sync($sync, $model, $sync_all);

            if (1440 == $model) {
                $delay = strtotime('tomorrow') - current_time('timestamp') + (HOUR_IN_SECONDS * 6);
            } elseif (is_numeric($model) && $is_client_allowed_to_sync) {
                $delay = $model * MINUTE_IN_SECONDS;
            } else {
                $delay = DAY_IN_SECONDS;
            }

            set_site_transient($name, $delay + $microtime);

            return $is_client_allowed_to_sync;

        }

        public function is_client_allowed_to_sync($sync, $sync_all = false)
        {
            $connection_status = $this->connection_status('ok');
            if (($sync_all && in_array($connection_status, array( 'trial', 'ok'))) || in_array($connection_status, array('trial', 'ok'))) {
                $sync = true;
            } else {
                Wr2o_Logger::add(sprintf('is_client_allowed_to_sync: Connection status %s is not allowed to sync %s', $connection_status, $sync_all ? 'all' : 'incremental'));
            }

            return $sync;
        }

        public function connection_fail($reason)
        {
            $failed_syncs = ($failed_syncs = get_site_transient('ready2order_number_of_failed_connections')) ? $failed_syncs++ : 1;

            set_site_transient('ready2order_number_of_failed_connections', $failed_syncs);

            if ($failed_syncs > 10) {
                $message = __(sprintf('Can not connect to the ready2order service. Update the connection <a href="%s">manually</a> to start the automatic sync again', 'woo-w2ro-integration'), get_admin_url(null, 'admin.php?page=wc-settings&tab=w2ro&section=advanced'));
                Wr2o_Logger::add($message, 'warning', 'failed_connection');
                set_site_transient('ready2order_failed_connection', $reason, DAY_IN_SECONDS);
            } else {
                set_site_transient('ready2order_failed_connection', $reason, MINUTE_IN_SECONDS * 6);
            }
        }

        public function connection_success()
        {
            delete_site_transient('ready2order_failed_connection');
            delete_site_transient('ready2order_number_of_failed_connections');
        }

        public function is_connection_ok()
        {

            return get_site_transient('ready2order_failed_connection') === false;

        }

        public function connection_status($status = '')
        {

            if (!Wr2o_Service_Handler::get_organization_uuid()) {
                return 'unauthorized';
            }

            if (!$this->is_connection_ok()) {
                return 'error';
            }

            $trial = Wr2o_Service_Handler::get_is_trial();

            $now = intval(time());
            $valid_to = intval(Wr2o_Service_Handler::get_valid_to());

            // if account is not valid
            if ($valid_to < $now) {
                return 'expired';
            }

            if ($trial) {
                return 'trial';
            }

            return $status;

        }

        public function force_connection()
        {
            $this->connection_success();
            Wr2o_Service_Handler::set_access_token('');
            Wr2o_Service_Handler::set_expires_in(0); // Forcing the client to connect again
            Wr2o_API::get_access_token();
        }

        public function schedule_heartbeat_sync()
        {

            if ($this->connection_status('ok') == 'ok') {
                if (false === as_has_scheduled_action('ready2order_service_heartbeat')) {
                    as_schedule_recurring_action(time(), HOUR_IN_SECONDS, 'ready2order_service_heartbeat');
                }
                $actions = as_get_scheduled_actions(
                    array(
                        'hook' => 'ready2order_service_heartbeat',
                        'status' => ActionScheduler_Store::STATUS_PENDING,
                        'claimed' => false,
                        'per_page' => -1,
                    ),
                    'ids'
                );
                if (count($actions) > 1) {
                    as_unschedule_action('ready2order_service_heartbeat');
                }
            } else {
                if (false !== as_has_scheduled_action('ready2order_service_heartbeat')) {
                    as_unschedule_all_actions('ready2order_service_heartbeat');
                }
            }

        }

        public function w2ro_service_heartbeat()
        {
            Wr2o_Logger::add(sprintf('ready2order_service_heartbeat: Connecting to service'));
            Wr2o_API::get_access_token();
        }
    }
    new w2ro_Integration_Authorization();
}
