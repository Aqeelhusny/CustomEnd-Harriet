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
            'page'       => array('type' => 'integer', 'default' => 1,  'minimum' => 1),
            'vendor_id'  => array('type' => 'integer', 'minimum' => 1,  'required' => false, 'description' => 'Filter by Dokan vendor user ID'),
        ),
    ));
});

function harriet_get_best_sellers($request) {
    global $wpdb;

    // --- Parameters ---
    $raw_start = $request->get_param('start_date');
    $raw_end   = $request->get_param('end_date');
    $per_page  = min(max(1, intval($request['per_page'])), 100);
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

    // --- Cache ---
    $cache_key = 'harriet_bestsellers_' . md5($start_date . $end_date . $per_page . $page . $vendor_id);
    $cached    = wp_cache_get($cache_key, 'harriet');

    if ($cached !== false) {
        $total     = $cached['total'];
        $response  = new WP_REST_Response($cached['payload'], 200);
        $response->header('X-WP-Total',      $total);
        $response->header('X-WP-TotalPages', (int) ceil($total / $per_page));
        $response->header('X-WP-Pages',      (int) ceil($total / $per_page));
        return $response;
    }

    // --- Enabled Dokan vendors ---
    $enabled_vendors = $wpdb->get_col("
        SELECT user_id FROM {$wpdb->usermeta}
        WHERE meta_key = 'dokan_enable_selling' AND meta_value = 'yes'
    ");

    if (empty($enabled_vendors)) {
        return new WP_Error('no_sellers', 'No enabled vendors found', array('status' => 404));
    }

    $vendors_ids_sql = implode(',', array_map('intval', $enabled_vendors));

    // Validate vendor_id against enabled list
    if ($vendor_id && !in_array($vendor_id, $enabled_vendors)) {
        return new WP_Error('invalid_vendor', 'Vendor not found or not enabled', array('status' => 404));
    }

    // --- Core WHERE clauses ---
    // Uses wc_order_product_lookup (WooCommerce Analytics table) — matches WC Admin
    // "Top Sellers" report exactly and is refund-safe (product_net_revenue accounts for refunds).
    // We resolve variations to their parent via COALESCE so each variable product
    // counts as one entry regardless of how many variations were ordered.
    $where_parts = array(
        "o.post_type = 'shop_order'",
        "o.post_status IN ('wc-completed', 'wc-processing')",
        $wpdb->prepare("opl.date_created BETWEEN %s AND %s", $start_date, $end_date),
        // Restrict to products authored by active Dokan vendors.
        // post_author on a variation is 0 — use COALESCE to the parent post.
        "COALESCE(NULLIF(p.post_parent, 0), p.post_author) IN ({$vendors_ids_sql})",
        // Stock filter via EXISTS subquery on the *resolved parent* so we never
        // accidentally join against a variation's stock row or miss products
        // with no _stock_status meta (LEFT JOIN + WHERE turns into implicit INNER JOIN).
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

    // --- Total count (distinct parent products in range) ---
    $total = (int) $wpdb->get_var("
        SELECT COUNT(DISTINCT COALESCE(NULLIF(p.post_parent, 0), opl.product_id))
        FROM {$wpdb->prefix}wc_order_product_lookup opl
        INNER JOIN {$wpdb->prefix}posts o ON o.ID = opl.order_id
        INNER JOIN {$wpdb->prefix}posts p ON p.ID = opl.product_id
        {$where}
    ");

    // --- Paginated results ---
    $sql = $wpdb->prepare("
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
    ", $per_page, $offset);

    $rows = $wpdb->get_results($sql);

    if (empty($rows)) {
        return rest_ensure_response(array(
            'meta' => array(
                'start_date'     => $start_date,
                'end_date'       => $end_date,
                'page'           => $page,
                'per_page'       => $per_page,
                'total_products' => 0,
                'total_pages'    => 0,
            ),
            'data' => array(),
        ));
    }

    // --- Hydrate products ---
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

    $total_pages = (int) ceil($total / $per_page);

    $payload = array(
        'meta' => array(
            'start_date'     => $start_date,
            'end_date'       => $end_date,
            'page'           => $page,
            'per_page'       => $per_page,
            'total_products' => $total,
            'total_pages'    => $total_pages,
        ),
        'data' => $products,
    );

    wp_cache_set($cache_key, array('payload' => $payload, 'total' => $total), 'harriet', HOUR_IN_SECONDS);

    $response = new WP_REST_Response($payload, 200);
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
