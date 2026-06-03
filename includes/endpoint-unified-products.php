<?php
if (!defined('ABSPATH')) exit;

function harriet_unified_get_enabled_vendors() {
    $cache_key = 'harriet_enabled_vendors_list';
    $cached = wp_cache_get($cache_key, 'harriet');
    if ($cached !== false) return $cached;

    $enabled_vendors = get_users(array(
        'role' => 'seller',
        'meta_query' => array(array('key' => 'dokan_enable_selling', 'value' => 'yes')),
        'fields' => 'ID'
    ));

    wp_cache_set($cache_key, $enabled_vendors, 'harriet', 24 * HOUR_IN_SECONDS);
    return $enabled_vendors;
}

function harriet_unified_batch_load_vendor_stores($vendor_ids) {
    if (empty($vendor_ids)) return array();
    if (!function_exists('dokan') || !dokan()->vendor) return array();

    $vendor_store_cache = array();
    $unique_ids = array_unique(array_values($vendor_ids));

    foreach ($unique_ids as $vid) {
        $store = dokan()->vendor->get($vid);
        if (!$store) continue;

        $address = $store->get_address();
        $vendor_store_cache[$vid] = array(
            'id'        => (int) $vid,
            'name'      => $store->get_name(),
            'shop_name' => $store->get_shop_name(),
            'url'       => $store->get_shop_url(),
            'enabled'   => true,
            'address'   => array(
                'street_1' => $address['street_1'] ?? '',
                'street_2' => $address['street_2'] ?? '',
                'city'     => $address['city'] ?? '',
                'zip'      => $address['zip'] ?? '',
                'state'    => $address['state'] ?? '',
                'country'  => $address['country'] ?? '',
            ),
        );
    }

    return $vendor_store_cache;
}

function harriet_unified_normalize_product_prices($product) {
    $is_variable = $product->is_type('variable');

    if ($is_variable) {
        $price_value         = $product->get_variation_price('min');
        $regular_price_value = $product->get_variation_regular_price('min');
        $sale_price_value    = $product->get_variation_sale_price('min');

        if ($sale_price_value === '' || $sale_price_value === null ||
            floatval($sale_price_value) >= floatval($regular_price_value)) {
            $sale_price_value = '';
        }

        $min_sale = $product->get_variation_sale_price('min');
        $max_sale = $product->get_variation_sale_price('max');
        $min_regular = $product->get_variation_regular_price('min');
        $max_regular = $product->get_variation_regular_price('max');
    } else {
        $price_value         = $product->get_price();
        $regular_price_value = $product->get_regular_price();
        $sale_price_value    = $product->get_sale_price();
    }

    return array(
        'price'              => $price_value,
        'regular_price'      => $regular_price_value,
        'sale_price'         => $sale_price_value,
        'is_on_sale'         => $product->is_on_sale(),
        'price_range'        => $is_variable ? array('min' => $product->get_variation_price('min'), 'max' => $product->get_variation_price('max')) : null,
        'regular_price_range' => $is_variable ? array('min' => $product->get_variation_regular_price('min'), 'max' => $product->get_variation_regular_price('max')) : null,
        'sale_price_range'   => $is_variable ? array(
            'min' => ($min_sale !== '' && floatval($min_sale) < floatval($min_regular)) ? $min_sale : '',
            'max' => ($max_sale !== '' && floatval($max_sale) < floatval($max_regular)) ? $max_sale : '',
        ) : null,
    );
}

function harriet_unified_build_product_response($product, $vendor_store_cache) {
    $pid = $product->get_id();
    $seller_id = function_exists('dokan_get_vendor_by_product') ? dokan_get_vendor_by_product($pid, true) : 0;
    $prices = harriet_unified_normalize_product_prices($product);

    $product_data = array(
        'id'                 => $pid,
        'name'               => $product->get_name(),
        'slug'               => $product->get_slug(),
        'permalink'          => $product->get_permalink(),
        'type'               => $product->get_type(),
        'status'             => $product->get_status(),
        'featured'           => $product->get_featured(),
        'date_created'       => $product->get_date_created() ? $product->get_date_created()->date('Y-m-d H:i:s') : null,
        'catalog_visibility' => $product->get_catalog_visibility(),
        'description'        => $product->get_description(),
        'short_description'  => $product->get_short_description(),
        'sku'                => $product->get_sku(),
        'on_sale'            => $prices['is_on_sale'],
        'stock_status'       => $product->get_stock_status(),
        'price'              => $prices['price'],
        'regular_price'      => $prices['regular_price'],
        'sale_price'         => $prices['sale_price'],
        'date_on_sale_from'  => $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->date('Y-m-d H:i:s') : null,
        'date_on_sale_to'    => $product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->date('Y-m-d H:i:s') : null,
        'images'             => array(),
        'categories'         => array_map(function ($t) {
            return array('id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug);
        }, wp_get_post_terms($pid, 'product_cat', array('fields' => 'all'))),
    );

    if ($prices['price_range']) $product_data['price_range'] = $prices['price_range'];
    if ($prices['regular_price_range']) $product_data['regular_price_range'] = $prices['regular_price_range'];
    if ($prices['sale_price_range']) $product_data['sale_price_range'] = $prices['sale_price_range'];

    if ($seller_id && isset($vendor_store_cache[$seller_id])) {
        $product_data['store'] = $vendor_store_cache[$seller_id];
    }

    $image_id = $product->get_image_id();
    if ($image_id) {
        $src = wp_get_attachment_url($image_id);
        $product_data['images'][] = array('id' => $image_id, 'name' => basename($src), 'src' => $src, 'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true));
    }

    foreach (array_slice($product->get_gallery_image_ids(), 0, 3) as $gid) {
        $gsrc = wp_get_attachment_url($gid);
        $product_data['images'][] = array('id' => $gid, 'name' => basename($gsrc), 'src' => $gsrc, 'alt' => get_post_meta($gid, '_wp_attachment_image_alt', true));
    }

    $product_data['variations'] = array();
    if ($product->is_type('variable')) {
        $variation_ids = $product->get_children();
        foreach ($variation_ids as $variation_id) {
            $var_product = wc_get_product($variation_id);
            if (!$var_product || !$var_product->variation_is_visible()) continue;

            $var_prices = harriet_unified_normalize_product_prices($var_product);
            $var_image_id = $var_product->get_image_id();

            $variation_data = array(
                'id'            => $var_product->get_id(),
                'name'          => $var_product->get_name(),
                'price'         => $var_prices['price'],
                'regular_price' => $var_prices['regular_price'],
                'sale_price'    => $var_prices['sale_price'],
                'sku'           => $var_product->get_sku(),
                'images'        => array(),
            );

            if ($var_image_id) {
                $var_src = wp_get_attachment_url($var_image_id);
                $variation_data['images'][] = array('id' => $var_image_id, 'name' => basename($var_src), 'src' => $var_src, 'alt' => get_post_meta($var_image_id, '_wp_attachment_image_alt', true));
            }

            $product_data['variations'][] = $variation_data;
        }
    }

    return $product_data;
}

// Category API
add_action('rest_api_init', function () {
    register_rest_route('wc/v3', '/products/by-category/(?P<category>[\w-]+(/[\w-]+)*)', array(
        'methods'             => 'GET',
        'callback'            => 'harriet_unified_fetch_products_by_category',
        'permission_callback' => '__return_true',
        'args'                => array(
            'category'     => array('validate_callback' => function ($param) { return is_string($param); }),
            'page'         => array('type' => 'integer', 'default' => 1, 'minimum' => 1),
            'per_page'     => array('type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100),
            'minPrice'     => array('type' => 'numeric', 'default' => 0),
            'maxPrice'     => array('type' => 'numeric', 'default' => null),
            'stock_status' => array('type' => 'string', 'default' => 'all', 'enum' => array('all', 'instock', 'outofstock')),
            'shuffle'      => array('type' => 'boolean', 'default' => false),
        ),
    ));
});

function harriet_unified_fetch_products_by_category($request) {
    $category_lookup = sanitize_text_field($request['category']);
    $page          = $request['page'];
    $per_page      = $request['per_page'];
    $minPrice      = floatval($request['minPrice']);
    $maxPrice      = isset($request['maxPrice']) && $request['maxPrice'] !== '' ? floatval($request['maxPrice']) : null;
    $stock_status  = $request['stock_status'];
    $shuffle       = (bool) $request->get_param('shuffle');

    $category = function_exists('harriet_find_best_matching_term')
        ? harriet_find_best_matching_term('product_cat', $category_lookup)
        : get_term_by('slug', sanitize_title($category_lookup), 'product_cat');

    if (!$category || is_wp_error($category)) {
        return new WP_Error('no_category', 'Category not found', array('status' => 404));
    }

    $cache_key = 'harriet_cat_' . md5($category->slug . $page . $per_page . $minPrice . $maxPrice . $stock_status);

    if (!$shuffle) {
        $cached = wp_cache_get($cache_key, 'harriet');
        if ($cached !== false) {
            $total_pages = (int) ceil($cached['total'] / $per_page);
            $response = new WP_REST_Response($cached['data'], 200);
            $response->header('X-WP-Total', $cached['total']);
            $response->header('X-WP-TotalPages', $total_pages);
            $response->header('X-WP-Pages', $total_pages);
            return $response;
        }
    }

    $enabled_vendors = harriet_unified_get_enabled_vendors();
    if (empty($enabled_vendors)) {
        return new WP_Error('no_vendors', 'No enabled vendors found', array('status' => 404));
    }

    $args = array(
        'post_type' => 'product', 'post_status' => 'publish',
        'author__in' => $enabled_vendors, 'posts_per_page' => $per_page, 'paged' => $page,
        'tax_query' => array(array('taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => (int) $category->term_id)),
    );

    $meta_query = array('relation' => 'AND');
    if ($maxPrice !== null && $maxPrice !== '') {
        $meta_query[] = array('key' => '_price', 'value' => array(floatval($minPrice), floatval($maxPrice)), 'compare' => 'BETWEEN', 'type' => 'NUMERIC');
    } elseif ($minPrice > 0) {
        $meta_query[] = array('key' => '_price', 'value' => floatval($minPrice), 'compare' => '>=', 'type' => 'NUMERIC');
    }
    if ($stock_status === 'instock' || $stock_status === 'outofstock') {
        $meta_query[] = array('key' => '_stock_status', 'value' => $stock_status);
    }
    $args['meta_query'] = $meta_query;

    $query = new WP_Query($args);
    $total = $query->found_posts;

    if (!$query->have_posts()) {
        wp_reset_postdata();
        return new WP_Error('no_products', 'No products found', array('status' => 404));
    }

    $vendor_map = array();
    foreach ($query->posts as $post) $vendor_map[$post->ID] = $post->post_author;
    $vendor_store_cache = harriet_unified_batch_load_vendor_stores($vendor_map);

    $products_data = array();
    while ($query->have_posts()) {
        $query->the_post();
        $product = wc_get_product(get_the_ID());
        if (!$product) continue;
        $products_data[] = harriet_unified_build_product_response($product, $vendor_store_cache);
    }
    wp_reset_postdata();

    if ($shuffle) shuffle($products_data);

    if (!$shuffle) {
        wp_cache_set($cache_key, array('data' => $products_data, 'total' => $total), 'harriet', 5 * MINUTE_IN_SECONDS);
    }

    $total_pages = (int) ceil($total / $per_page);
    $response = new WP_REST_Response($products_data, 200);
    $response->header('X-WP-Total', $total);
    $response->header('X-WP-TotalPages', $total_pages);
    $response->header('X-WP-Pages', $total_pages);
    return $response;
}

// Tag API
add_action('rest_api_init', function () {
    register_rest_route('wc/v3', '/products/by-tag/(?P<tag>[\w-]+(/[\w-]+)*)', array(
        'methods'             => 'GET',
        'callback'            => 'harriet_unified_fetch_products_by_tag',
        'permission_callback' => '__return_true',
        'args'                => array(
            'tag'          => array('validate_callback' => function ($param) { return is_string($param); }),
            'page'         => array('type' => 'integer', 'default' => 1, 'minimum' => 1),
            'per_page'     => array('type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100),
            'minPrice'     => array('type' => 'numeric', 'default' => 0),
            'maxPrice'     => array('type' => 'numeric', 'default' => null),
            'stock_status' => array('type' => 'string', 'default' => 'all', 'enum' => array('all', 'instock', 'outofstock')),
            'shuffle'      => array('type' => 'boolean', 'default' => false),
        ),
    ));
});

function harriet_unified_fetch_products_by_tag($request) {
    $tag_lookup    = sanitize_text_field($request['tag']);
    $page          = $request['page'];
    $per_page      = $request['per_page'];
    $minPrice      = floatval($request['minPrice']);
    $maxPrice      = isset($request['maxPrice']) && $request['maxPrice'] !== '' ? floatval($request['maxPrice']) : null;
    $stock_status  = $request['stock_status'];
    $shuffle       = (bool) $request->get_param('shuffle');

    $tag = function_exists('harriet_find_best_matching_term')
        ? harriet_find_best_matching_term('product_tag', $tag_lookup)
        : get_term_by('slug', sanitize_title($tag_lookup), 'product_tag');

    if (!$tag || is_wp_error($tag)) {
        return new WP_Error('no_tag', 'Tag not found', array('status' => 404));
    }

    $cache_key = 'harriet_tag_' . md5($tag->slug . $page . $per_page . $minPrice . $maxPrice . $stock_status);

    if (!$shuffle) {
        $cached = wp_cache_get($cache_key, 'harriet');
        if ($cached !== false) {
            $total_pages = (int) ceil($cached['total'] / $per_page);
            $response = new WP_REST_Response($cached['data'], 200);
            $response->header('X-WP-Total', $cached['total']);
            $response->header('X-WP-TotalPages', $total_pages);
            $response->header('X-WP-Pages', $total_pages);
            return $response;
        }
    }

    $enabled_vendors = harriet_unified_get_enabled_vendors();
    if (empty($enabled_vendors)) {
        return new WP_Error('no_vendors', 'No enabled vendors found', array('status' => 404));
    }

    $args = array(
        'post_type' => 'product', 'post_status' => 'publish',
        'author__in' => $enabled_vendors, 'posts_per_page' => $per_page, 'paged' => $page,
        'tax_query' => array(array('taxonomy' => 'product_tag', 'field' => 'term_id', 'terms' => (int) $tag->term_id)),
    );

    $meta_query = array('relation' => 'AND');
    if ($maxPrice !== null && $maxPrice !== '') {
        $meta_query[] = array('key' => '_price', 'value' => array(floatval($minPrice), floatval($maxPrice)), 'compare' => 'BETWEEN', 'type' => 'NUMERIC');
    } elseif ($minPrice > 0) {
        $meta_query[] = array('key' => '_price', 'value' => floatval($minPrice), 'compare' => '>=', 'type' => 'NUMERIC');
    }
    if ($stock_status === 'instock' || $stock_status === 'outofstock') {
        $meta_query[] = array('key' => '_stock_status', 'value' => $stock_status);
    }
    $args['meta_query'] = $meta_query;

    $query = new WP_Query($args);
    $total = $query->found_posts;

    if (!$query->have_posts()) {
        wp_reset_postdata();
        return new WP_Error('no_products', 'No products found', array('status' => 404));
    }

    $vendor_map = array();
    foreach ($query->posts as $post) $vendor_map[$post->ID] = $post->post_author;
    $vendor_store_cache = harriet_unified_batch_load_vendor_stores($vendor_map);

    $products_data = array();
    while ($query->have_posts()) {
        $query->the_post();
        $product = wc_get_product(get_the_ID());
        if (!$product) continue;
        $products_data[] = harriet_unified_build_product_response($product, $vendor_store_cache);
    }
    wp_reset_postdata();

    if ($shuffle) shuffle($products_data);

    if (!$shuffle) {
        wp_cache_set($cache_key, array('data' => $products_data, 'total' => $total), 'harriet', 5 * MINUTE_IN_SECONDS);
    }

    $total_pages = (int) ceil($total / $per_page);
    $response = new WP_REST_Response($products_data, 200);
    $response->header('X-WP-Total', $total);
    $response->header('X-WP-TotalPages', $total_pages);
    $response->header('X-WP-Pages', $total_pages);
    return $response;
}

// Cache invalidation — targeted flush, not global wp_cache_flush()
add_action('save_post_product', function ($post_id, $post) {
    // Bail on autosave, revisions, and non-published/draft posts to avoid
    // hammering cache on every WooCommerce internal save during checkout/order processing.
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;

    static $flushed = false;
    if ($flushed) return;
    $flushed = true;

    wp_cache_delete('harriet_enabled_vendors_list', 'harriet');
    if (function_exists('wp_cache_flush_group')) wp_cache_flush_group('harriet');
}, 10, 2);

add_action('edited_product_cat', function () {
    if (function_exists('wp_cache_flush_group')) wp_cache_flush_group('harriet');
});
add_action('edited_product_tag', function () {
    if (function_exists('wp_cache_flush_group')) wp_cache_flush_group('harriet');
});
