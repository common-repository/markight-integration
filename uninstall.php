<?php
/**
 * @package MarkightIntegration
 */

defined('ABSPATH') or die('No access!');


/**
 * delete all temp table and saved option when plugin being uninstalled
 * @since 1.0.0
 */
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE '%markight%';");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}markight_Integration_logs");
wp_cache_flush();
