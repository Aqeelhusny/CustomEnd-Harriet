<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/best-sellers', array(
        'methods'             => 'GET',
        'callback'            => 'harriet_get_best_sellers',
        'permission_callback' => '__return_true',
        'args'                => array(
            'start_date' => array('type' => 'string',  'format' => 'date', 'required' => false, 'description' => 'Start date Y-m-d, defaults to 60 days ago'),
            'end_date'   => array('type' => 'string',  'format' => 'date', 'required' => false, 'description' => 'End date Y-m-d, defaults to today'),
            'per_page'   => array('type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100),
            'limit'      => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Alias for per_page'),
            'page'       => array('type' => 'integer', 'default' => 1,  'minimum' => 1),
            'vendor_id'  => array('type' => 'integer', 'minimum' => 1,  'required' => false, 'description' => 'Filter by Dokan vendor user ID'),
        ),
    ));
});

function harriet_get_best_sellers($request) {
    global $wpdb;

    // Resolve per_page: explicit per_page wins, then limit, then default 10.
    // Must use get_param() for both — $request['per_page'] always returns the
    // registered default (10) even when the param was not sent by the client.
    $per_page_param = $request->get_param('per_page');
    $limit_param    = $request->get_param('limit');
    $per_page = min(max(1, intval(
        $per_page_param !== null ? $per_page_param : ($limit_param !== null ? $limit_param : 10)
    )), 100);

    $raw_start = $request->get_param('start_date');
    $raw_end   = $request->get_param('end_date');
    $page      = max(1, intval($request['page']));
    $offset    = ($page - 1) * $per_page;
    $vendor_id = $request->get_param('vendor_id') ? intval($request['vendor_id']) : null;

    if ($raw_start && !strtotime($raw_start)) {
        return new WP_Error('invalid_start_date', 'Invalid start_date format. Use Y-m-d', array('status' => 400));
    }
    if ($raw_end && !strtotime($raw_end)) {
        return new WP_Error('invalid_end_date', 'Invalid end_date format. Use Y-m-d', array('status' => 400));
    }

    $start_date = $raw_start
        ? gmdate('Y-m-d H:i:s', strtotime($raw_start))
        : gmdate('Y-m-d H:i:s', strtotime('-60 days'));
    $end_date = $raw_end
        ? gmdate('Y-m-d H:i:s', strtotime($raw_end . ' 23:59:59'))
        : gmdate('Y-m-d H:i:s');

    $cache_key = 'harriet_bestsellers_' . md5($start_date . $end_date . $per_page . $page . $vendor_id);
    $cached    = wp_cache_get($cache_key, 'harriet');

    if ($cached !== false) {
        $total      = $cached['total'];
        $total_pages = (int) ceil($total / $per_page);
        $response   = new WP_REST_Response($cached['products'], 200);
        $response->header('X-WP-Total',      $total);
        $response->header('X-WP-TotalPages', $total_pages);
        $response->header('X-WP-Pages',      $total_pages);
        return $response;
    }

    $enabled_vendors = $wpdb->get_col("
        SELECT user_id FROM {$wpdb->usermeta}
        WHERE meta_key = 'dokan_enable_selling' AND meta_value = 'yes'
    ");

    if (empty($enabled_vendors)) {
        return new WP_Error('no_sellers', 'No enabled vendors found', array('status' => 404));
    }

    $vendors_ids_sql = implode(',', array_map('intval', $enabled_vendors));

    if ($vendor_id && !in_array($vendor_id, $enabled_vendors)) {
        return new WP_Error('invalid_vendor', 'Vendor not found or not enabled', array('status' => 404));
    }

    // Uses wc_order_product_lookup (WooCommerce Analytics table) — matches WC Admin
    // "Top Sellers" report exactly and is refund-safe.
    // COALESCE resolves variations to their parent so each variable product counts once.
    $where_parts = array(
        "o.post_type = 'shop_order'",
        "o.post_status IN ('wc-completed', 'wc-processing')",
        $wpdb->prepare("opl.date_created BETWEEN %s AND %s", $start_date, $end_date),
        "COALESCE(NULLIF(p.post_parent, 0), p.post_author) IN ({$vendors_ids_sql})",
        "EXISTS (
            SELECT 1 FROM {$wpdb->postmeta} pm_s
            WHERE pm_s.post_id = COALESCE(NULLIF(p.post_parent, 0), p.ID)
              AND pm_s.meta_key = '_stock_status'
              AND pm_s.meta_value IN ('instock', 'onbackorder')
        )",
    );

    if ($vendor_id) {
        $where_parts[] = $wpdb->prepare(
            "COALESCE(NULLIF(p.post_parent, 0), p.post_author) = %d",
            $vendor_id
        );
    }

    $where = 'WHERE ' . implode(' AND ', $where_parts);

    $total = (int) $wpdb->get_var("
        SELECT COUNT(DISTINCT COALESCE(NULLIF(p.post_parent, 0), opl.product_id))
        FROM {$wpdb->prefix}wc_order_product_lookup opl
        INNER JOIN {$wpdb->prefix}posts o ON o.ID = opl.order_id
        INNER JOIN {$wpdb->prefix}posts p ON p.ID = opl.product_id
        {$where}
    ");

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT
            COALESCE(NULLIF(p.post_parent, 0), opl.product_id) AS product_id,
            SUM(opl.product_qty)                                AS total_qty
        FROM {$wpdb->prefix}wc_order_product_lookup opl
        INNER JOIN {$wpdb->prefix}posts o ON o.ID = opl.order_id
        INNER JOIN {$wpdb->prefix}posts p ON p.ID = opl.product_id
        {$where}
        GROUP BY product_id
        ORDER BY total_qty DESC
        LIMIT %d OFFSET %d
    ", $per_page, $offset));

    $total_pages = (int) ceil($total / $per_page);

    if (empty($rows)) {
        $response = new WP_REST_Response(array(), 200);
        $response->header('X-WP-Total',      0);
        $response->header('X-WP-TotalPages', 0);
        $response->header('X-WP-Pages',      0);
        return $response;
    }

    $fields_to_remove = array(
        'downloadable', 'downloads', 'download_limit', 'download_expiry',
        'external_url', 'button_text', 'tax_status', 'tax_class',
        'weight', 'dimensions', 'shipping_required', 'shipping_taxable',
        'shipping_class', 'shipping_class_id', 'post_password', 'global_unique_id', '_links',
    );

    $controller = new WC_REST_Products_Controller();
    $seen_ids   = array();
    $products   = array();

    foreach ($rows as $row) {
        $product_id = (int) $row->product_id;

        if (isset($seen_ids[$product_id])) continue;
        $seen_ids[$product_id] = true;

        $product = wc_get_product($product_id);
        if (!$product || $product->get_status() !== 'publish') continue;

        $wc_response = $controller->prepare_object_for_response($product, $request);
        if (is_wp_error($wc_response)) continue;

        $data = $wc_response->get_data();
        foreach ($fields_to_remove as $field) unset($data[$field]);
        $data['total_sold_in_range'] = (int) $row->total_qty;

        $products[] = $data;
    }

    wp_cache_set($cache_key, array('products' => $products, 'total' => $total), 'harriet', HOUR_IN_SECONDS);

    $response = new WP_REST_Response($products, 200);
    $response->header('X-WP-Total',      $total);
    $response->header('X-WP-TotalPages', $total_pages);
    $response->header('X-WP-Pages',      $total_pages);
    return $response;
}

add_action('woocommerce_order_status_changed', function ($order_id, $old_status, $new_status) {
    if (in_array($new_status, array('completed', 'processing')) ||
        in_array($old_status, array('completed', 'processing'))) {
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
