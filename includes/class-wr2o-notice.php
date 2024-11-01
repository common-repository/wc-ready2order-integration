<?php
/**
 * This class handles notices to admin
 *
 * @package   WooCommerce_Ready_To_Order_Integration
 * @author    BjornTech <info@bjorntech.com>
 * @license   GPL-3.0
 * @link      http://bjorntech.com
 * @copyright 2017-2020 BjornTech - BjornTech AB
 */

defined('ABSPATH') || exit;

if (!class_exists('Wr2o_Notice', false)) {

    class Wr2o_Notice
    {
        public function __construct()
        {
            add_action('admin_notices', array($this, 'check_displaylist'), 100);
        }

        public static function add($message, $type = 'info', $id = false, $dismiss = true, $time = false)
        {

            $id = $id === false ? uniqid('id-') : 'id-' . esc_html($id);

            $notices = get_site_transient('ready2order_notices');
            if (!$notices) {
                $notices = array();
            }
            $notice = array(
                'type' => $type,
                'valid_to' => $time === false ? false : $time,
                'messsage' => $message,
                'dismissable' => $dismiss,
            );

            $notices[$id] = $notice;
            set_site_transient('ready2order_notices', $notices);

            return $id;
        }

        public static function clear($id = false)
        {
            $id = $id === false ? false : esc_html($id);

            $notices = get_site_transient('ready2order_notices');
            if ($id) {
                if(isset($notices[$id])){
                    unset($notices[$id]);
                }
            } else {
                $notices = array();
            }
            set_site_transient('ready2order_notices', $notices);
        }

        public static function display($message, $type = 'error', $dismiss = false, $id = '')
        {
            $dismissable = $dismiss ? 'is-dismissible' : '';
            echo '<div class="ready2order_notice ' . $dismissable . ' notice notice-' . $type . ' ' . $id . '"><p>' . $message . '</p></div>';
        }

        public function check_displaylist()
        {
            $notices = get_site_transient('ready2order_notices');

            if (false !== $notices && !empty($notices)) {
                foreach ($notices as $key => $notice) {
                    self::display($notice['messsage'], $notice['type'], $notice['dismissable'], $key);
                    if ($notice['valid_to'] !== false && $notice['valid_to'] < time()) {
                        unset($notices[$key]);
                    }
                }
            }

            set_site_transient('ready2order_notices', $notices);
        }

    }

    new Wr2o_Notice();
}
