<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/best-sellers', array(
        'methods'             => 'GET',
        'callback'            => 'harriet_get_best_sellers',
        'permission_callback' => '__return_true',
        'args'                => array(
            'start_date' => array('type' => 'string', 'format' => 'date'),
            'end_date'   => array('type' => 'string', 'format' => 'date'),
            'limit'      => array('type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100),
            'page'       => array('type' => 'integer', 'default' => 1, 'minimum' => 1),
            'vendor_id'  => array('type' => 'integer', 'minimum' => 1),
        ),
    ));
});

function harriet_get_best_sellers($request) {
    global $wpdb;

    $start_date = $request->get_param('start_date');
    $end_date = $request->get_param('end_date');
    $limit = min(intval($request['limit']), 100);
    $page = intval($request['page']);
    $offset = ($page - 1) * $limit;
    $vendor_id = $request->get_param('vendor_id') ? intval($request['vendor_id']) : null;

    if ($start_date && !strtotime($start_date)) {
        return new WP_Error('invalid_start_date', 'Invalid start_date format (use Y-m-d)', array('status' => 400));
    }
    if ($end_date && !strtotime($end_date)) {
        return new WP_Error('invalid_end_date', 'Invalid end_date format (use Y-m-d)', array('status' => 400));
    }

    $start_date = $start_date ? gmdate('Y-m-d H:i:s', strtotime($start_date)) : gmdate('Y-m-d H:i:s', strtotime('-60 days'));
    $end_date = $end_date ? gmdate('Y-m-d H:i:s', strtotime($end_date . ' 23:59:59')) : gmdate('Y-m-d H:i:s');

    $cache_key = 'harriet_bestsellers_' . md5($start_date . $end_date . $limit . $page . $vendor_id);
    $cached = wp_cache_get($cache_key, 'harriet');

    if ($cached !== false) {
        $response = new WP_REST_Response($cached['products'], 200);
        $response->header('X-WP-Total', $cached['total']);
        $response->header('X-WP-Pages', ceil($cached['total'] / $limit));
        return $response;
    }

    $enabled_vendors = $wpdb->get_col("
        SELECT user_id FROM {$wpdb->usermeta}
        WHERE meta_key = 'dokan_enable_selling' AND meta_value = 'yes'
    ");

    if (empty($enabled_vendors)) {
        return new WP_Error('no_sellers', 'No enabled vendors found', array('status' => 404));
    }

    $vendors_ids = implode(',', array_map('intval', $enabled_vendors));

    $where_clauses = array(
        "o.post_type = 'shop_order'",
        "o.post_status IN ('wc-completed', 'wc-processing')",
        $wpdb->prepare("opl.date_created BETWEEN %s AND %s", $start_date, $end_date),
        "p.post_author IN ({$vendors_ids})",
        // Check stock status on the parent product (not the variation row from opl),
        // and use IN() so NULL rows (missing meta) are excluded rather than passing through.
        "pm_stock.meta_value IN ('instock', 'onbackorder')",
    );

    if ($vendor_id && in_array($vendor_id, $enabled_vendors)) {
        $where_clauses[] = $wpdb->prepare("p.post_author = %d", $vendor_id);
    }

    $where = "WHERE " . implode(" AND ", $where_clauses);

    $sql = $wpdb->prepare("
        SELECT COALESCE(NULLIF(p.post_parent, 0), opl.product_id) AS product_id,
               SUM(opl.product_qty) AS total_qty
        FROM {$wpdb->prefix}wc_order_product_lookup opl
        INNER JOIN {$wpdb->prefix}posts o ON o.ID = opl.order_id
        INNER JOIN {$wpdb->prefix}posts p ON p.ID = opl.product_id
        LEFT JOIN {$wpdb->prefix}postmeta pm_stock
            ON pm_stock.post_id = COALESCE(NULLIF(p.post_parent, 0), p.ID)
            AND pm_stock.meta_key = '_stock_status'
        {$where}
        GROUP BY product_id
        ORDER BY total_qty DESC
        LIMIT %d OFFSET %d
    ", $limit, $offset);

    $results = $wpdb->get_results($sql);

    if (empty($results)) {
        return new WP_Error('no_results', 'No best sellers found for the specified date range', array('status' => 404));
    }

    $count_sql = "
        SELECT COUNT(DISTINCT COALESCE(p.post_parent, opl.product_id))
        FROM {$wpdb->prefix}wc_order_product_lookup opl
        INNER JOIN {$wpdb->prefix}posts o ON o.ID = opl.order_id
        INNER JOIN {$wpdb->prefix}posts p ON p.ID = opl.product_id
        LEFT JOIN {$wpdb->prefix}postmeta pm_stock
            ON pm_stock.post_id = COALESCE(NULLIF(p.post_parent, 0), p.ID)
            AND pm_stock.meta_key = '_stock_status'
        {$where}
    ";
    $total = intval($wpdb->get_var($count_sql));

    $fields_to_remove = array(
        'downloadable', 'downloads', 'download_limit', 'download_expiry',
        'external_url', 'button_text', 'tax_status', 'tax_class',
        'weight', 'dimensions', 'shipping_required', 'shipping_taxable',
        'shipping_class', 'shipping_class_id', 'post_password', 'global_unique_id', '_links',
    );

    $controller = new WC_REST_Products_Controller();
    $products = array();

    foreach ($results as $row) {
        $product = wc_get_product($row->product_id);
        if (!$product || $product->get_status() !== 'publish') continue;

        $response = $controller->prepare_object_for_response($product, $request);
        if (is_wp_error($response)) continue;

        $data = $response->get_data();
        foreach ($fields_to_remove as $field) unset($data[$field]);
        $data['total_sold_in_range'] = intval($row->total_qty);
        $products[] = $data;
    }

    if (empty($products)) {
        return new WP_Error('no_products', 'No published products found in results', array('status' => 404));
    }

    wp_cache_set($cache_key, array('products' => $products, 'total' => $total), 'harriet', HOUR_IN_SECONDS);

    $response = new WP_REST_Response($products, 200);
    $response->header('X-WP-Total', $total);
    $response->header('X-WP-Pages', ceil($total / $limit));
    return $response;
}

add_action('woocommerce_order_status_changed', function ($order_id, $old_status, $new_status) {
    if (in_array($new_status, array('completed', 'processing')) || in_array($old_status, array('completed', 'processing'))) {
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('harriet');
        }
    }
}, 10, 3);

add_action('woocommerce_refund_created', function ($refund_id, $args) {
    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('harriet');
    }
}, 10, 2);
