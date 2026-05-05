<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('wc/v3', '/deals', array(
        'methods'             => 'GET',
        'callback'            => 'harriet_get_active_deals',
        'permission_callback' => 'harriet_check_deals_permission',
        'args'                => array(
            'page'           => array('type' => 'integer', 'default' => 1, 'minimum' => 1),
            'per_page'       => array('type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100),
            'orderby'        => array('type' => 'string', 'default' => 'date', 'enum' => array('date', 'price', 'discount', 'name')),
            'order'          => array('type' => 'string', 'default' => 'DESC', 'enum' => array('ASC', 'DESC')),
            'min_discount'   => array('type' => 'number', 'default' => 0, 'minimum' => 0, 'maximum' => 100),
            'date_from'      => array('type' => 'string', 'format' => 'date'),
            'date_to'        => array('type' => 'string', 'format' => 'date'),
            'vendor_id'      => array('type' => 'integer', 'minimum' => 1),
            'category'       => array('type' => 'string'),
            'consumer_key'   => array('type' => 'string'),
            'consumer_secret' => array('type' => 'string'),
        ),
    ));
});

function harriet_check_deals_permission($request) {
    $consumer_key = $request->get_param('consumer_key');
    $consumer_secret = $request->get_param('consumer_secret');

    if (!empty($consumer_key) && !empty($consumer_secret)) {
        return true;
    }

    return current_user_can('manage_woocommerce');
}

function harriet_get_active_deals($request) {
    global $wpdb;

    $per_page = min(intval($request['per_page']), 100);
    $page = intval($request['page']);
    $offset = ($page - 1) * $per_page;
    $orderby = sanitize_text_field($request['orderby']);
    $order = strtoupper(sanitize_text_field($request['order']));
    $min_discount = max(0, floatval($request['min_discount']));
    $vendor_id = $request['vendor_id'] ? intval($request['vendor_id']) : null;
    $category = $request['category'] ? sanitize_text_field($request['category']) : null;
    $date_from = $request['date_from'] ? sanitize_text_field($request['date_from']) : null;
    $date_to = $request['date_to'] ? sanitize_text_field($request['date_to']) : null;

    $cache_key = 'harriet_deals_' . md5($page . $per_page . $orderby . $order . $min_discount . $vendor_id . $category . $date_from . $date_to);

    $cached = wp_cache_get($cache_key, 'harriet');
    if ($cached !== false) {
        $response = new WP_REST_Response($cached['products'], 200);
        $response->header('X-WP-Total', $cached['total']);
        $response->header('X-WP-Pages', ceil($cached['total'] / $per_page));
        return $response;
    }

    $current_time = current_time('timestamp');

    $enabled_sellers = $wpdb->get_col("
        SELECT user_id FROM {$wpdb->usermeta}
        WHERE meta_key = 'dokan_enable_selling' AND meta_value = 'yes'
    ");

    if (empty($enabled_sellers)) {
        return new WP_Error('no_sellers', 'No enabled vendors found', array('status' => 404));
    }

    $sellers_ids = implode(',', array_map('intval', $enabled_sellers));

    $where_clauses = array(
        "p.post_type IN ('product', 'product_variation')",
        "p.post_status = 'publish'",
        "p.post_author IN ({$sellers_ids})",
        "pm_sale.meta_key = '_sale_price'",
        "pm_sale.meta_value != ''",
        "pm_reg.meta_key = '_regular_price'",
        "pm_reg.meta_value != ''",
    );

    if ($vendor_id && in_array($vendor_id, $enabled_sellers)) {
        $where_clauses[] = "p.post_author = {$vendor_id}";
    }

    if ($min_discount > 0) {
        $where_clauses[] = $wpdb->prepare(
            "(CAST(pm_sale.meta_value AS DECIMAL(10,2)) / CAST(pm_reg.meta_value AS DECIMAL(10,2))) < %f",
            (100 - $min_discount) / 100
        );
    }

    if ($date_from) {
        $where_clauses[] = $wpdb->prepare("(pm_from.meta_value IS NULL OR CAST(pm_from.meta_value AS UNSIGNED) <= %d)", $current_time);
    }

    if ($date_to) {
        $to_timestamp = strtotime($date_to . ' 23:59:59');
        if ($to_timestamp) {
            $where_clauses[] = $wpdb->prepare("(pm_to.meta_value IS NULL OR CAST(pm_to.meta_value AS UNSIGNED) >= %d)", $to_timestamp);
        }
    }

    $where = "WHERE " . implode(" AND ", $where_clauses);

    $order_sql = "ORDER BY p.post_date {$order}";
    if ($orderby === 'price') {
        $order_sql = "ORDER BY CAST(pm_sale.meta_value AS DECIMAL(10,2)) {$order}";
    } elseif ($orderby === 'discount') {
        $order_sql = "ORDER BY (1 - (CAST(pm_sale.meta_value AS DECIMAL(10,2)) / CAST(pm_reg.meta_value AS DECIMAL(10,2)))) {$order}";
    } elseif ($orderby === 'name') {
        $order_sql = "ORDER BY p.post_title {$order}";
    }

    $ids_query = "
        SELECT DISTINCT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm_sale ON p.ID = pm_sale.post_id
        INNER JOIN {$wpdb->postmeta} pm_reg ON p.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price'
        LEFT JOIN {$wpdb->postmeta} pm_from ON p.ID = pm_from.post_id AND pm_from.meta_key = '_sale_price_dates_from'
        LEFT JOIN {$wpdb->postmeta} pm_to ON p.ID = pm_to.post_id AND pm_to.meta_key = '_sale_price_dates_to'
        {$where}
        {$order_sql}
    ";

    $total_query = "
        SELECT COUNT(DISTINCT p.ID)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm_sale ON p.ID = pm_sale.post_id
        INNER JOIN {$wpdb->postmeta} pm_reg ON p.ID = pm_reg.post_id AND pm_reg.meta_key = '_regular_price'
        LEFT JOIN {$wpdb->postmeta} pm_from ON p.ID = pm_from.post_id AND pm_from.meta_key = '_sale_price_dates_from'
        LEFT JOIN {$wpdb->postmeta} pm_to ON p.ID = pm_to.post_id AND pm_to.meta_key = '_sale_price_dates_to'
        {$where}
    ";

    $total = intval($wpdb->get_var($total_query));
    $paged_query = $ids_query . $wpdb->prepare(" LIMIT %d OFFSET %d", $per_page, $offset);
    $product_ids = $wpdb->get_col($paged_query);

    if (empty($product_ids)) {
        return new WP_Error('no_deals', 'No active deals found matching criteria', array('status' => 404));
    }

    $products = array();
    $products_controller = new WC_REST_Products_Controller();

    $fields_to_remove = array(
        'downloadable', 'downloads', 'download_limit', 'download_expiry',
        'external_url', 'button_text', 'tax_status', 'tax_class',
        'weight', 'dimensions', 'shipping_required', 'shipping_taxable',
        'shipping_class', 'shipping_class_id', 'post_password', 'global_unique_id', '_links',
    );

    foreach ($product_ids as $product_id) {
        $post = get_post($product_id);
        if ($post->post_type === 'product_variation') {
            $product_id = wp_get_post_parent_id($product_id);
        }
        if ($product_id <= 0) continue;

        $product = wc_get_product($product_id);
        if (!$product || !$product->is_on_sale()) continue;

        if ($category && !has_term($category, 'product_cat', $product_id)) continue;

        $data = $products_controller->prepare_object_for_response($product, $request);
        $formatted = $products_controller->prepare_response_for_collection($data);

        foreach ($fields_to_remove as $field) unset($formatted[$field]);

        $regular = floatval($product->get_regular_price());
        $sale = floatval($product->get_sale_price());
        if ($regular > 0) {
            $formatted['discount_percentage'] = round(((($regular - $sale) / $regular) * 100), 2);
        }

        $products[] = $formatted;
    }

    if (empty($products)) {
        return new WP_Error('no_deals', 'No active deals found matching criteria', array('status' => 404));
    }

    wp_cache_set($cache_key, array('products' => $products, 'total' => $total), 'harriet', 15 * MINUTE_IN_SECONDS);

    $response = new WP_REST_Response($products, 200);
    $response->header('X-WP-Total', $total);
    $response->header('X-WP-Pages', ceil($total / $per_page));
    return $response;
}

add_action('update_post_meta', function ($meta_id, $post_id, $meta_key, $meta_value) {
    if (in_array($meta_key, array('_sale_price', '_regular_price', '_sale_price_dates_from', '_sale_price_dates_to'))) {
        wp_cache_flush();
    }
}, 10, 4);

add_action('woocommerce_scheduled_sales', function () {
    wp_cache_flush();
});
