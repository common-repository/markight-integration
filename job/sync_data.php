<?php

defined('ABSPATH') or die('No access!');

global $wpdb;

$logger = new Mrkt_Markight_logger();

try {

    $logger->ping();

    $db = new Mrkt_Markight_db($wpdb);
    $api = new Mrkt_Markight_api();
    $orders_items = $db->getAllOrders();

    $order_query = $orders_items['order'];
    $last_sync_date = $orders_items['date'];
    $orders = apply_filters("mrkt_get_all_orders_filter", $order_query);

    if (count($orders) > 0) {

        $result = $api->sendItems($orders);

        if ($api->isUnauthorized($result)) {
            update_option(MRKT_PLUGIN_NAME . "_token", '');
            $logger->exception("api key is unauthorized, The store account was deactivated and the user was taken to the login page");
            echo json_encode([
                'stop' => true,
                'count' => '0',
                'message' => "Your account has been blocked, please contact Markight Support! ",
                'date' => (string)$last_sync_date
            ]);
            exit();
        }

        $logger->synced($result['errors'], count($orders), json_encode($result['response'] ?? []));

        if (count($result['errors']) > 0) {
            $db->saveErrors($result['errors']);
        }

        update_option(MRKT_PLUGIN_NAME . "_sync_date", (string)$last_sync_date);

        $count = (string)count($orders);

        echo json_encode([
            'stop' => false,
            'message' => "Sending stored invoices after the date: $last_sync_date",
            'state' => "$count lines of invoices were synchronized",
            'date' => (string)$last_sync_date
        ]);

    } else {

        $logger->emptyData();

        echo json_encode([
            'stop' => true,
            'message' => "No new data was found and all sales data was synced until the displayed date.",
            'state' => 'End of sync',
            'date' => (string)$last_sync_date
        ]);
    }

} catch (Exception $exception) {

    $logger->exception($exception->getMessage() . " / " . $exception->getTraceAsString());

    echo json_encode([
        'stop' => true,
        'message' => $exception->getMessage(),
        'state' => 'Sync occurred. Please refresh the page and try again ',
        'date' => "Error receiving information "
    ]);
}
