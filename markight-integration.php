<?php
/**
 * @package markightIntegration
 */

/**
 * Plugin Name: Markight Integration
 * Description: markight Integration woocommerce connector
 * Version: 2.0.0
 * Author: markight
 * Author URI: https://markight.com/
 * License: MIT
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: markight-Integration
 */

defined('ABSPATH') or die('No access!');

if (!defined('MRKT_PLUGIN_NAME')) {
    define("MRKT_PLUGIN_NAME", "markight_Integration");
}
if (!defined('MRKT_PLUGIN_PATH')) {
    define("MRKT_PLUGIN_PATH", plugin_dir_path(__FILE__));
}

/**
 * Loads the required classes
 * @return void
 * @since 1.1.0
 */
function mrkt_load_classes()
{
    require_once MRKT_PLUGIN_PATH . 'include/classes/class-mrkt-api.php';
    require_once MRKT_PLUGIN_PATH . 'include/classes/class-mrkt-db.php';
    require_once MRKT_PLUGIN_PATH . 'include/classes/class-mrkt-logger.php';
    require_once MRKT_PLUGIN_PATH . 'include/custom/mrkt-custom.php';
}

add_action('mrkt_auto_sync', 'mrkt_auto_sync_data');

function mrkt_auto_sync_data()
{
    if (!empty(get_option(MRKT_PLUGIN_NAME . '_token')) and function_exists('mrkt_load_classes')) {
        mrkt_load_classes();
        require_once MRKT_PLUGIN_PATH . 'job/sync_data.php';
    }
}

if (function_exists('mrkt_load_classes')) {
    mrkt_load_classes();
}

class Mrkt_markightIntegration
{

    /**
     * register function called everytime admin page is loaded
     * add action for adding markight item menu to admin panel
     * add filter for adding markight setting button in plugin list page
     * @return void
     * @since 1.0.0
     */
    function register()
    {
        add_action('admin_menu', array($this, 'add_admin_pages'));
        add_filter("plugin_action_links_" . plugin_basename(__FILE__), array($this, 'settings_link'));
        $this->add_hooks();
    }

    /**
     * Create markight setting button on plugin page list
     * @return array
     * @since 1.0.0
     */
    function settings_link($links)
    {
        $settings_link = '<a href="admin.php?page=markight_Integration">Setting</a>';
        array_push($links, $settings_link);
        return $links;
    }

    /**
     * Create markight menu item in wordPress menu panel
     * @return void
     * @since 1.0.0
     */
    function add_admin_pages()
    {
        add_menu_page(
            'Markight Integration',
            'Markight',
            'manage_options',
            MRKT_PLUGIN_NAME,
            array($this, 'admin_index'),
            'dashicons-controls-repeat',
            110
        );
    }

    /**
     * called when markight integration icon in admin panel clicked
     * show markight integration admin page view
     * @return void
     * @since 1.0.0
     */
    function admin_index()
    {
        require_once MRKT_PLUGIN_PATH . 'admin.php';

    }

    /**
     * called When admin activates the plugin
     * check if woocommerce plugin installed and activated on store
     * job new daily job for sync
     * create log table
     * rewrite saved rules if exists
     * @return void
     * @since 1.0.0
     */
    function activate()
    {


        if (!is_plugin_active('woocommerce/woocommerce.php')
            and current_user_can('activate_plugins')) {
            $message = "To activate the " . MRKT_PLUGIN_NAME . " plugin, please activate your WooCommerce plugin first.";
            wp_die($message . '   <br><a href="' . admin_url('plugins.php') . '">&laquo;Return to plugin</a>');
        }


        if (!wp_next_scheduled('mrkt_auto_sync')) {
            wp_schedule_event(time(), 'daily', 'mrkt_auto_sync');
        }

        $this->create_log_table();


        if (!get_option(MRKT_PLUGIN_NAME . '_token')) {

            update_option(MRKT_PLUGIN_NAME . '_token', '');
            update_option(MRKT_PLUGIN_NAME . '_api_url', 'https://api-app.markight.com/');
            update_option(MRKT_PLUGIN_NAME . '_sale_status', 'wc-completed');
            update_option(MRKT_PLUGIN_NAME . '_refunded_status', 'wc-refunded');
            update_option(MRKT_PLUGIN_NAME . '_sync_date', '');

        }

        flush_rewrite_rules();
    }

    /**
     * called When admin deactivates the plugin
     * rewrite saved rules and disable daily sync
     * @return void
     * @since 1.0.0
     */
    function deactivate()
    {
        $timestamp = wp_next_scheduled('mrkt_auto_sync');
        wp_unschedule_event($timestamp, 'mrkt_auto_sync');
        flush_rewrite_rules();
    }

    /**
     * Add hooks to track changes in data
     * @return Void
     * @since 1.0.0
     */
    function add_hooks()
    {
        $com = str_replace('wc-', '', get_option(MRKT_PLUGIN_NAME . '_sale_status'));
        $ref = str_replace('wc-', '', get_option(MRKT_PLUGIN_NAME . '_refunded_status'));
        add_action('woocommerce_order_status_' . $com, array($this, 'order_status_hook'));
        add_action('woocommerce_order_status_' . $ref, array($this, 'order_status_hook'));
    }

    /**
     * called when any woocommerce order added or changes
     * and schedule new sync job for 40 second later run in background
     * @since 1.0.0
     * param is new or edited order id
     */
    function order_status_hook($order_id)
    {
        wp_schedule_single_event(time() + 40, 'mrkt_auto_sync');
    }

    /**
     * create log table if not exist
     * this function called once on installing plugin
     * @since 1.0.0
     */
    function create_log_table()
    {

        global $wpdb;
        $collate = '';

        if ($wpdb->has_cap('collation')) {
            $collate = $wpdb->get_charset_collate();
        }

        $log_table_name = "{$wpdb->prefix}" . MRKT_PLUGIN_NAME . "_logs";

        $wpdb->query(" CREATE TABLE IF NOT EXISTS `$log_table_name` (
                    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                    `entity_id` varchar(100) ,
                    `error` TEXT(2000)  ,
                    `payload` TEXT(2000) ,
                    `date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,    
                    PRIMARY KEY (`id`)
                )  $collate
            ");
    }
}

if (class_exists('Mrkt_markightIntegration')) {
    $Integration = new Mrkt_markightIntegration();
    $Integration->register();
    register_activation_hook(__FILE__, array($Integration, 'activate'));
    register_deactivation_hook(__FILE__, array($Integration, 'deactivate'));
}

add_action('wp_ajax_mrkt_markight_sync', 'mrkt_markight_sync');

/**
 * ajax controller
 * get ajax request triggered in sync page and handle
 * @return Void
 * @since 1.0.0
 */
function mrkt_markight_sync()
{
    if (!isset($_POST['item'])) {
        wp_die();
    }

    $item = sanitize_text_field($_POST['item']);

    if ($item == 'sync') {
        require_once MRKT_PLUGIN_PATH . "job/sync_data.php";
    }

    wp_die();
}
