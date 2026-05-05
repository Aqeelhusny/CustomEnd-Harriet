<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('wc/v3', '/deals', array(
        'methods'             => 'GET',
        'callback'            => 'harriet_get_active_deals',
        'permission_callback' => 'harriet_check_deals_permission',
        'args'                => array(
            'page'            => array('type' => 'integer', 'default' => 1,     'minimum' => 1),
            'per_page'        => array('type' => 'integer', 'default' => 10,    'minimum' => 1, 'maximum' => 100),
            'orderby'         => array('type' => 'string',  'default' => 'date','enum' => array('date', 'price', 'discount', 'name')),
            'order'           => array('type' => 'string',  'default' => 'DESC','enum' => array('ASC', 'DESC')),
            'min_discount'    => array('type' => 'number',  'default' => 0,     'minimum' => 0, 'maximum' => 100),
            'date_from'       => array('type' => 'string',  'format' => 'date'),
            'date_to'         => array('type' => 'string',  'format' => 'date'),
            'vendor_id'       => array('type' => 'integer', 'minimum' => 1),
            'category'        => array('type' => 'string'),
            'consumer_key'    => array('type' => 'string'),
            'consumer_secret' => array('type' => 'string'),
        ),
    ));
});

function harriet_check_deals_permission($request) {
    $consumer_key    = $request->get_param('consumer_key');
    $consumer_secret = $request->get_param('consumer_secret');

    if (!empty($consumer_key) && !empty($consumer_secret)) {
        global $wpdb;
        $key = $wpdb->get_row($wpdb->prepare(
            "SELECT consumer_secret FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s LIMIT 1",
            wc_api_hash($consumer_key)
        ));
        if ($key && hash_equals($key->consumer_secret, $consumer_secret)) {
            return true;
        }
        return new WP_Error('invalid_api_key', 'Invalid API credentials', array('status' => 401));
    }

    return current_user_can('manage_woocommerce');
}

function harriet_get_active_deals($request) {
    global $wpdb;

    $per_page     = min(intval($request['per_page']), 100);
    $page         = max(1, intval($request['page']));
    $offset       = ($page - 1) * $per_page;
    $orderby      = in_array($request['orderby'], array('date', 'price', 'discount', 'name')) ? $request['orderby'] : 'date';
    $order        = strtoupper($request['order']) === 'ASC' ? 'ASC' : 'DESC';
    $min_discount = max(0, floatval($request['min_discount']));
    $vendor_id    = $request->get_param('vendor_id') ? intval($request['vendor_id']) : null;
    $category     = $request->get_param('category') ? sanitize_text_field($request['category']) : null;
    $date_from    = $request->get_param('date_from') ? sanitize_text_field($request['date_from']) : null;
    $date_to      = $request->get_param('date_to')   ? sanitize_text_field($request['date_to'])   : null;

    $cache_key = 'harriet_deals_' . md5($page . $per_page . $orderby . $order . $min_discount . $vendor_id . $category . $date_from . $date_to);
    $cached    = wp_cache_get($cache_key, 'harriet');

    if ($cached !== false) {
        $total_pages = (int) ceil($cached['total'] / $per_page);
        $response = new WP_REST_Response($cached['products'], 200);
        $response->header('X-WP-Total',      $cached['total']);
        $response->header('X-WP-TotalPages', $total_pages);
        $response->header('X-WP-Pages',      $total_pages);
        return $response;
    }

    // --- Fetch enabled Dokan sellers ---
    $enabled_sellers = $wpdb->get_col("
        SELECT user_id FROM {$wpdb->usermeta}
        WHERE meta_key = 'dokan_enable_selling' AND meta_value = 'yes'
    ");

    if (empty($enabled_sellers)) {
        return new WP_Error('no_sellers', 'No enabled vendors found', array('status' => 404));
    }

    $sellers_ids  = implode(',', array_map('intval', $enabled_sellers));
    $current_time = time();

    // -------------------------------------------------------------------
    // Build WHERE on parent products only (post_type = 'product').
    // A product qualifies as "on sale" if:
    //   (a) it is a simple/external product with a _sale_price set, OR
    //   (b) it is a variable product that has at least one variation on sale.
    // We handle (b) via a sub-select so the outer query stays deduplicated
    // at the parent level — eliminating the root cause of duplicate results.
    // -------------------------------------------------------------------
    $where_clauses = array(
        "p.post_type   = 'product'",
        "p.post_status = 'publish'",
        "p.post_author IN ({$sellers_ids})",
    );

    if ($vendor_id && in_array($vendor_id, $enabled_sellers)) {
        $where_clauses[] = $wpdb->prepare("p.post_author = %d", $vendor_id);
    }

    // Sale condition: simple products with a direct _sale_price, OR variable
    // products that have at least one variation child with a _sale_price.
    $sale_exists_subquery = "
        EXISTS (
            SELECT 1 FROM {$wpdb->postmeta} pm_s
            WHERE pm_s.post_id = p.ID
              AND pm_s.meta_key = '_sale_price'
              AND pm_s.meta_value != ''
        )
        OR EXISTS (
            SELECT 1
            FROM {$wpdb->posts} var
            INNER JOIN {$wpdb->postmeta} pm_vs ON var.ID = pm_vs.post_id AND pm_vs.meta_key = '_sale_price' AND pm_vs.meta_value != ''
            INNER JOIN {$wpdb->postmeta} pm_vr ON var.ID = pm_vr.post_id AND pm_vr.meta_key = '_regular_price' AND pm_vr.meta_value != ''
            WHERE var.post_parent = p.ID
              AND var.post_type   = 'product_variation'
              AND var.post_status = 'publish'
              AND CAST(pm_vs.meta_value AS DECIMAL(10,2)) < CAST(pm_vr.meta_value AS DECIMAL(10,2))
        )
    ";
    $where_clauses[] = "({$sale_exists_subquery})";

    // min_discount filter — apply to simple products; variable products pass
    // (WooCommerce's is_on_sale() is authoritative for the final PHP check).
    if ($min_discount > 0) {
        $ratio = (100 - $min_discount) / 100;
        $where_clauses[] = $wpdb->prepare("
            (
                p.post_type != 'product'
                OR EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm_sd
                    INNER JOIN {$wpdb->postmeta} pm_rd
                        ON pm_rd.post_id = pm_sd.post_id AND pm_rd.meta_key = '_regular_price' AND pm_rd.meta_value != ''
                    WHERE pm_sd.post_id = p.ID
                      AND pm_sd.meta_key = '_sale_price'
                      AND pm_sd.meta_value != ''
                      AND CAST(pm_rd.meta_value AS DECIMAL(10,2)) > 0
                      AND (CAST(pm_sd.meta_value AS DECIMAL(10,2)) / CAST(pm_rd.meta_value AS DECIMAL(10,2))) < %f
                )
                OR EXISTS (
                    SELECT 1
                    FROM {$wpdb->posts} var2
                    INNER JOIN {$wpdb->postmeta} pm_vs2 ON var2.ID = pm_vs2.post_id AND pm_vs2.meta_key = '_sale_price' AND pm_vs2.meta_value != ''
                    INNER JOIN {$wpdb->postmeta} pm_vr2 ON var2.ID = pm_vr2.post_id AND pm_vr2.meta_key = '_regular_price' AND pm_vr2.meta_value != ''
                    WHERE var2.post_parent = p.ID
                      AND var2.post_type   = 'product_variation'
                      AND var2.post_status = 'publish'
                      AND CAST(pm_vr2.meta_value AS DECIMAL(10,2)) > 0
                      AND (CAST(pm_vs2.meta_value AS DECIMAL(10,2)) / CAST(pm_vr2.meta_value AS DECIMAL(10,2))) < %f
                )
            )
        ", $ratio, $ratio);
    }

    // Sale date window filters
    if ($date_from) {
        $from_ts = strtotime($date_from);
        if ($from_ts) {
            $where_clauses[] = $wpdb->prepare("
                (
                    NOT EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} pm_df
                        WHERE pm_df.post_id = p.ID AND pm_df.meta_key = '_sale_price_dates_from'
                          AND pm_df.meta_value != '' AND CAST(pm_df.meta_value AS UNSIGNED) > %d
                    )
                )
            ", $from_ts);
        }
    }

    if ($date_to) {
        $to_ts = strtotime($date_to . ' 23:59:59');
        if ($to_ts) {
            $where_clauses[] = $wpdb->prepare("
                (
                    NOT EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} pm_dt
                        WHERE pm_dt.post_id = p.ID AND pm_dt.meta_key = '_sale_price_dates_to'
                          AND pm_dt.meta_value != '' AND CAST(pm_dt.meta_value AS UNSIGNED) < %d
                    )
                )
            ", $to_ts);
        }
    }

    // Category filter via JOIN when provided — avoids PHP-level post-filter
    // that was silently shrinking pages below per_page.
    $category_join = '';
    if ($category) {
        $term = get_term_by('slug', $category, 'product_cat');
        if (!$term || is_wp_error($term)) {
            return new WP_Error('invalid_category', 'Category not found', array('status' => 404));
        }
        $category_join = $wpdb->prepare("
            INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy}      tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                                                      AND tt.taxonomy = 'product_cat'
                                                      AND tt.term_id = %d
        ", $term->term_id);
    }

    // ORDER BY — all on the parent product row; no postmeta join needed
    // because sale price comparisons live in sub-selects above.
    switch ($orderby) {
        case 'price':
            $order_sql = "ORDER BY CAST((
                SELECT pm_op.meta_value FROM {$wpdb->postmeta} pm_op
                WHERE pm_op.post_id = p.ID AND pm_op.meta_key = '_price' LIMIT 1
            ) AS DECIMAL(10,2)) {$order}";
            break;
        case 'discount':
            $order_sql = "ORDER BY (
                SELECT (1 - CAST(pm_os.meta_value AS DECIMAL(10,2)) / NULLIF(CAST(pm_or.meta_value AS DECIMAL(10,2)), 0))
                FROM {$wpdb->postmeta} pm_os
                INNER JOIN {$wpdb->postmeta} pm_or ON pm_or.post_id = pm_os.post_id AND pm_or.meta_key = '_regular_price'
                WHERE pm_os.post_id = p.ID AND pm_os.meta_key = '_sale_price' AND pm_os.meta_value != ''
                LIMIT 1
            ) {$order}";
            break;
        case 'name':
            $order_sql = "ORDER BY p.post_title {$order}";
            break;
        default:
            $order_sql = "ORDER BY p.post_date {$order}";
    }

    $where = 'WHERE ' . implode(' AND ', $where_clauses);

    $base_from = "FROM {$wpdb->posts} p {$category_join}";

    $total = intval($wpdb->get_var("SELECT COUNT(DISTINCT p.ID) {$base_from} {$where}"));

    $product_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT p.ID {$base_from} {$where} {$order_sql} LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ));

    if (empty($product_ids)) {
        return new WP_Error('no_deals', 'No active deals found matching criteria', array('status' => 404));
    }

    // --- Hydrate products — deduplicated by parent ID ---
    $fields_to_remove = array(
        'downloadable', 'downloads', 'download_limit', 'download_expiry',
        'external_url', 'button_text', 'tax_status', 'tax_class',
        'weight', 'dimensions', 'shipping_required', 'shipping_taxable',
        'shipping_class', 'shipping_class_id', 'post_password', 'global_unique_id', '_links',
    );

    $products_controller = new WC_REST_Products_Controller();
    $seen_ids = array();
    $products = array();

    foreach ($product_ids as $product_id) {
        $product_id = intval($product_id);

        if (isset($seen_ids[$product_id])) continue;
        $seen_ids[$product_id] = true;

        $product = wc_get_product($product_id);
        if (!$product || !$product->is_on_sale()) continue;

        $data      = $products_controller->prepare_object_for_response($product, $request);
        $formatted = $products_controller->prepare_response_for_collection($data);

        foreach ($fields_to_remove as $field) unset($formatted[$field]);

        $regular = floatval($product->get_regular_price());
        $sale    = floatval($product->get_sale_price());

        if ($product->is_type('variable')) {
            $min_regular = floatval($product->get_variation_regular_price('min'));
            $min_sale    = floatval($product->get_variation_sale_price('min'));
            if ($min_regular > 0 && $min_sale < $min_regular) {
                $formatted['discount_percentage'] = round((($min_regular - $min_sale) / $min_regular) * 100, 2);
            }
        } elseif ($regular > 0 && $sale < $regular) {
            $formatted['discount_percentage'] = round((($regular - $sale) / $regular) * 100, 2);
        }

        $products[] = $formatted;
    }

    if (empty($products)) {
        return new WP_Error('no_deals', 'No active deals found matching criteria', array('status' => 404));
    }

    wp_cache_set($cache_key, array('products' => $products, 'total' => $total), 'harriet', 15 * MINUTE_IN_SECONDS);

    $total_pages = (int) ceil($total / $per_page);
    $response = new WP_REST_Response($products, 200);
    $response->header('X-WP-Total',      $total);
    $response->header('X-WP-TotalPages', $total_pages);
    $response->header('X-WP-Pages',      $total_pages);
    return $response;
}

add_action('update_post_meta', function ($meta_id, $post_id, $meta_key, $meta_value) {
    if (in_array($meta_key, array('_sale_price', '_regular_price', '_sale_price_dates_from', '_sale_price_dates_to'))) {
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('harriet');
        }
    }
}, 10, 4);

add_action('woocommerce_scheduled_sales', function () {
    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('harriet');
    }
});
