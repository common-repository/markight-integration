<?php

/**
 * class for communicate with db
 * @class markight db
 * @since 1.1.0
 */
class Mrkt_Markight_db
{

    /**
     * @var Wpdb
     * @since 1.1.0
     */
    private wpdb $db;

    /**
     * array of filter for extract data from woocommerce tables
     * @var array
     * @since 1.2.0
     */
    private array $filters;

    /**
     * array of customer and region key name for search in woocommerce tables
     * @var array
     * @since 1.2.0
     */
    private array $order_attr = [
        'user_id' => '_customer_user',
        'first_name' => '_billing_first_name',
        'last_name' => '_billing_last_name',
        'city' => '_billing_city',
        'state' => '_billing_state',
        'country' => '_billing_country',
        'phone' => '_billing_phone'
    ];

    public function __construct($wpdp)
    {
        $this->db = $wpdp;
        $this->filters = [
            'log_table' => "{$wpdp->prefix}" . MRKT_PLUGIN_NAME . "_logs",
            'update_date' => get_option(MRKT_PLUGIN_NAME . "_sync_date"),
            'complete_status' => get_option(MRKT_PLUGIN_NAME . '_sale_status'),
            'refunded_status' => get_option(MRKT_PLUGIN_NAME . '_refunded_status')
        ];
        $this->order_attr = apply_filters("mrkt_set_orders_attr", $this->order_attr);
    }

    /**
     * reset last sync date and clear log table
     * @return void
     * @since 1.2.0
     */
    public function resetSyncDate()
    {
        $logTable = $this->filters['log_table'];
        update_option(MRKT_PLUGIN_NAME . '_sync_date', '');
        $this->db->query("TRUNCATE TABLE $logTable");
    }

    /**
     * return all products and their brands and categories list
     * @return array
     * @since 1.3.0
     */
    private function getProducts($ids): array
    {
        $data = $this->db->get_results(
            "
           SELECT ID as id , post_title  , 
           {$this->db->prefix}terms.term_id , 
           {$this->db->prefix}terms.name , 
           {$this->db->prefix}term_taxonomy.taxonomy
           FROM `{$this->db->prefix}posts` 
           JOIN `{$this->db->prefix}term_relationships` 
           ON `{$this->db->prefix}posts`.ID = `{$this->db->prefix}term_relationships`.object_id
           JOIN `{$this->db->prefix}term_taxonomy`
           ON `{$this->db->prefix}term_relationships`.term_taxonomy_id = `{$this->db->prefix}term_taxonomy`.term_taxonomy_id
           LEFT JOIN {$this->db->prefix}terms ON {$this->db->prefix}terms.term_id = {$this->db->prefix}term_taxonomy.term_id
           WHERE `{$this->db->prefix}posts`.post_type IN ( 'product','product_variation' )
           AND ( `{$this->db->prefix}term_taxonomy`.taxonomy LIKE '%brand%'
           OR `{$this->db->prefix}term_taxonomy`.taxonomy LIKE '%product_cat%'
           OR `{$this->db->prefix}term_taxonomy`.taxonomy LIKE '%weight%'
           OR `{$this->db->prefix}term_taxonomy`.taxonomy LIKE '%vendor%' )
           AND ID IN (" . implode(',', $ids) . ")
           ORDER BY {$this->db->prefix}term_taxonomy.parent
            "
            , ARRAY_A
        );
        $products = [];
        foreach ($data as $item) {
            $products[$item['id']]['name'] = $item['post_title'];
            if ($item['taxonomy'] == 'product_cat') {
                $products[$item['id']]['categories'][$item['term_id']] = $item['name'];
                continue;
            }
            if (strpos($item['taxonomy'], "brand") != false or strpos($item['taxonomy'], "vendor") != false) {
                $products[$item['id']]['brands'][$item['term_id']] = $item['name'];
                continue;
            }
            if (strpos($item['taxonomy'], "weight") != false) {
                $products[$item['id']]['weight'] = $item['name'];
            }
        }
        return apply_filters("mrkt_get_all_product_filter", $products);
    }

    /**
     * return all order based on filters
     * @return array invoices
     * return list of invoices
     * @since 1.1.0
     */
    public function getAllOrders(): array
    {
        $date = $this->filters['update_date'];
        $ref_status = $this->filters['refunded_status'];

        if (count($ids = $this->getAvailableOrderIds()) == 0) {
            return ['order' => [], 'date' => $date];
        }

        $all_invoices = $this->getOrderData($ids);
        $new_orders = [];
        $product_ids = [];

        foreach ($all_invoices as $order_key => $value) {

            $date = $value['modified_date'] ?? $date;
            $order_date = $value['post_date'];
            $first_name = $value[$this->order_attr['first_name']] ?? "Undefined";
            $last_name = $value[$this->order_attr['last_name']] ?? "customer";
            $customer_id = $value[$this->order_attr['user_id']] ?? 0;
            $customer_name = $first_name . " " . $last_name;
            if ($customer_id == 0) {
                $customer_id = $customer_name;
            }
            $customer_phone = $value[$this->order_attr['phone']] ?? "";
            $country = $value[$this->order_attr['country']] ?? "Undefined Country";
            $state = $value[$this->order_attr['state']] ?? "Undefined State";
            $city = $value[$this->order_attr['city']] ?? "Undefined City";

            if (!isset($value['items']) and isset($value['_order_total'])) {
                $value['items'][$value['_order_total']]['_fee_amount'] = $value['_order_total'];
            } elseif (!isset($value['items'])) {
                continue;
            }

            foreach ($value['items'] as $item_key => $item) {

                $qty = $item['_qty'] ?? 1;
                $discount = 0;
                $unit_price = 0;
                $total = 0;
                $product_id = 'Undefined Product';
                $product_type = 'SERVICE';

                if (isset($item['_line_subtotal'])) {

                    if ($item['_line_subtotal'] > 0) {
                        $unit_price = $item['_line_subtotal'] / $qty;
                    } else {
                        $unit_price = $item['_line_total'] / $qty;
                    }
                    if ($item['_line_total'] != 0) {
                        $total = $item['_line_total'] / $qty;
                    }
                    $discount = ($unit_price - $total) * $qty;
                    $product_id = $item['_product_id'];
                    $product_type = 'PRODUCT';

                    $product_ids[$product_id] = $product_id;

                } else if (isset($item['cost'])) {

                    $unit_price = (int)$item['cost'];
                    $product_id = 'Shipping Service Cost';

                } else if (isset($item['_fee_amount'])) {
                    $unit_price = (int)$item['_fee_amount'];
                }

                $weight = $item['pa_weight'] ?? 0;

                $order = [
                    'item_id' => (string)$item_key,
                    'invoice_id' => (string)$order_key,
                    'date' => (string)$order_date,
                    'unit_price' => (int)$unit_price,
                    'type' => "SALES",
                    'quantity' => (int)$qty,
                    'discount' => (int)$discount,
                    'weight' => (int)$weight,
                    'customer_id' => $customer_id,
                    'customer_name' => $customer_name,
                    'region0' => $country,
                    'region1' => $state,
                    'region2' => $city,
                    'sku_id' => $product_id,
                    'product_name' => $product_id,
                    'product_type' => $product_type
                ];

                if (!empty($customer_phone)) {
                    $order['customer_phone_number'] = (string)$customer_phone;
                }

                array_push($new_orders, $order);

                if ($value['status'] == $ref_status) {
                    $refund_order = $order;
                    $refund_order['item_id'] = $refund_order['item_id'] . "_refunded";
                    $refund_order['quantity'] = -$refund_order['quantity'];
                    $refund_order['discount'] = -$refund_order['discount'];
                    $refund_order['type'] = "RETURN";
                    array_push($new_orders, $refund_order);
                }
            }
        }

        $all_products = $this->getProducts($product_ids);

        foreach ($new_orders as $key => $order_item) {

            if (isset($all_products[$order_item['sku_id']])) {

                $product = $all_products[$order_item['sku_id']];
                $new_orders[$key]['product_name'] = $product['name'];

                if (isset($product['brands'])) {
                    foreach (array_values($product['brands']) as $br_key => $name) {
                        $new_orders[$key]['product_brand' . $br_key] = $name;
                        if ($br_key == 1) {
                            break;
                        }
                    }
                }

                if (isset($product['categories'])) {
                    foreach (array_values($product['categories']) as $cat_key => $name) {
                        $new_orders[$key]['product_category' . $cat_key] = $name;
                        if ($cat_key == 9) {
                            break;
                        }
                    }
                }

                if ($order_item['weight'] == 0 and isset($product['weight'])) {
                    $new_orders[$key]['weight'] = (int)$product['weight'];
                }

            }
        }

        $orders_for_sync = array_merge($this->getErrorLogs(), $new_orders);

        return ['order' => $orders_for_sync, 'date' => $date];
    }

    /**
     * Each time a data is not synchronized with the markight, it is logged
     * @return void
     * @since 1.1.0
     */
    public function saveErrors($errors)
    {
        $logTable = $this->filters['log_table'];
        $this->db->query(" INSERT ignore INTO `$logTable`(`entity_id`,`error`,`payload`) VALUES " . implode(',', $errors) . ";");
    }

    /**
     * get all error logs saved on db for sync again
     * @return array
     * @since 1.1.0
     */
    private function getErrorLogs(): array
    {
        $logTable = $this->filters['log_table'];
        $result = [];
        $res = $this->db->get_results(" SELECT * FROM `$logTable` ORDER BY `date`", ARRAY_A);

        if (count($res) == 0) {
            return $result;
        }

        foreach ($res as $log) {
            if (!empty($log['payload'])) {
                array_push($result, json_decode($log['payload'], true));
            }
        }
        $this->db->query("TRUNCATE TABLE $logTable");
        return $result;
    }

    /**
     * get list of order ids for sync based on date and status filters
     * @return array
     * @since 1.2.0
     */
    private function getAvailableOrderIds(): array
    {

        $date = $this->filters['update_date'];
        $com_status = $this->filters['complete_status'];
        $ref_status = $this->filters['refunded_status'];

        $ids = $this->db->get_results(
            "SELECT `{$this->db->prefix}posts`.ID as id FROM `{$this->db->prefix}posts`
                   WHERE `{$this->db->prefix}posts`.post_status IN ('$com_status' , '$ref_status') 
                   AND `{$this->db->prefix}posts`.post_modified > '$date'
                   GROUP BY `{$this->db->prefix}posts`.ID
                   ORDER BY `{$this->db->prefix}posts`.post_modified LIMIT 200 ",
            ARRAY_A
        );

        return apply_filters("mrkt_get_all_order_ids_filter", array_column($ids, 'id'));
    }

    /**
     * get order data of given id list
     * @param $ids
     * @return array
     * @since 1.2.0
     */
    private function getOrderData($ids): array
    {

        $attr_copy = $this->order_attr;
        array_push($attr_copy, '_order_total');
        $attr = implode("','", $attr_copy);

        $all = $this->db->get_results("
         (SELECT `{$this->db->prefix}woocommerce_order_items`.order_item_id as item_id ,
         `{$this->db->prefix}woocommerce_order_items`.order_id as order_id,
         `{$this->db->prefix}woocommerce_order_itemmeta`.meta_key as meta_key ,
         `{$this->db->prefix}woocommerce_order_itemmeta`.meta_value AS meta_value,
         `{$this->db->prefix}posts`.post_status as order_status,
         `{$this->db->prefix}posts`.post_modified as modified_date,
         `{$this->db->prefix}posts`.post_date as post_date
          FROM `{$this->db->prefix}woocommerce_order_items` 
          JOIN `{$this->db->prefix}woocommerce_order_itemmeta` 
          ON `{$this->db->prefix}woocommerce_order_itemmeta`.order_item_id = `{$this->db->prefix}woocommerce_order_items`.order_item_id
          JOIN `{$this->db->prefix}posts` ON `{$this->db->prefix}posts`.ID = `{$this->db->prefix}woocommerce_order_items`.order_id
          WHERE `{$this->db->prefix}posts`.ID IN (" . implode(',', $ids) . ")
          AND (
          `{$this->db->prefix}woocommerce_order_itemmeta`.meta_key 
          IN ('_product_id' , '_qty','_line_subtotal' , '_line_total' , 'cost' , '_fee_amount' ,'_order_total')
          OR `{$this->db->prefix}woocommerce_order_itemmeta`.meta_key LIKE '%weight%'
          )) 
          UNION 
          (SELECT `{$this->db->prefix}postmeta`.meta_id as item_id,
          `{$this->db->prefix}posts`.ID as order_id,
          `{$this->db->prefix}postmeta`.meta_key as meta_key,
          `{$this->db->prefix}postmeta`.meta_value as meta_value ,
          `{$this->db->prefix}posts`.post_status as post_status,
          `{$this->db->prefix}posts`.post_modified as modified_date,
          `{$this->db->prefix}posts`.post_date as post_date
          FROM `{$this->db->prefix}postmeta` 
          JOIN `{$this->db->prefix}posts` ON `{$this->db->prefix}posts`.ID = `{$this->db->prefix}postmeta`.post_id
          WHERE `{$this->db->prefix}postmeta`.meta_key IN ('$attr')
          AND `{$this->db->prefix}posts`.ID IN (" . implode(',', $ids) . "))
          ", ARRAY_A);

        $all_invoices = [];
        foreach ($all as $value) {

            if (in_array($value['meta_key'], $this->order_attr)) {
                $all_invoices[$value['order_id']][$value['meta_key']] = $value['meta_value'];
                continue;
            }

            $all_invoices[$value['order_id']]['modified_date'] = $value['modified_date'];
            $all_invoices[$value['order_id']]['post_date'] = $value['post_date'];
            $all_invoices[$value['order_id']]['status'] = $value['order_status'];

            if ($value['meta_key'] == '_order_total') {
                $all_invoices[$value['order_id']]['_order_total'] = $value['meta_value'];
                continue;
            }

            $all_invoices[$value['order_id']]['items'][$value['item_id']][$value['meta_key']] = $value['meta_value'];

        }
        return apply_filters("mrkt_get_all_order_data_filter", $all_invoices);
    }
}