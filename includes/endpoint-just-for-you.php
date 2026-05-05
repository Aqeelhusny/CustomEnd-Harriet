<?php
if (!defined('ABSPATH')) exit;

/**
 * GET /custom/v1/just-for-you?customer_id=123&per_page=20&page=1
 *
 * Returns the latest published products personalised to a customer's gender.
 *
 * Gender resolution order:
 *   1. `gender` query param (male|female) – useful for guest shoppers.
 *   2. `_gender` user meta on the WooCommerce customer (set by My Account or
 *      checkout fields).  Accepted values: male|female|man|woman|m|f.
 *   3. If neither is available → returns products from ALL categories so the
 *      response is never empty.
 *
 * Female feed  → categories: women, perfumes, lipstick, lip-balm
 * Male   feed  → categories: men, perfumes (unisex), grooming
 *
 * All category slugs are configurable via the `harriet_jfy_category_slugs`
 * filter so they can be changed without touching this file.
 */

add_action('rest_api_init', 'harriet_jfy_register_route');

function harriet_jfy_register_route() {
    register_rest_route('custom/v1', '/just-for-you', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'harriet_jfy_handler',
        'permission_callback' => '__return_true',
        'args'                => array(
            'customer_id' => array(
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 0,
            ),
            'gender' => array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ),
            'per_page' => array(
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 20,
                'minimum'           => 1,
                'maximum'           => 50,
            ),
            'page' => array(
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 1,
                'minimum'           => 1,
            ),
        ),
    ));
}

function harriet_jfy_handler(WP_REST_Request $request) {
    $customer_id = (int) $request->get_param('customer_id');
    $gender_param = strtolower(trim($request->get_param('gender')));
    $per_page = (int) $request->get_param('per_page');
    $page     = (int) $request->get_param('page');

    // --- Resolve gender ---
    $gender = harriet_jfy_resolve_gender($customer_id, $gender_param);

    // --- Build category slug list ---
    $category_slugs = harriet_jfy_get_category_slugs($gender);

    // --- Cache key ---
    $cache_key = 'harriet_jfy_' . md5($gender . implode(',', $category_slugs) . $per_page . $page);
    $cached = wp_cache_get($cache_key, 'harriet');
    if ($cached !== false) {
        $response = new WP_REST_Response($cached, 200);
        $response->header('X-WP-Total',      $cached['total']);
        $response->header('X-WP-TotalPages', $cached['total_pages']);
        $response->header('X-WP-Pages',      $cached['total_pages']);
        return $response;
    }

    // --- Query products ---
    $tax_query = array();
    if (!empty($category_slugs)) {
        $tax_query[] = array(
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => $category_slugs,
            'operator' => 'IN',
        );
    }

    $query_args = array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'tax_query'      => $tax_query,
        'meta_query'     => array(
            array(
                'key'     => '_stock_status',
                'value'   => array('instock', 'onbackorder'),
                'compare' => 'IN',
            ),
        ),
    );

    // Restrict to enabled Dokan vendors when Dokan is active.
    if (function_exists('dokan')) {
        $enabled_vendors = harriet_jfy_get_enabled_vendors();
        if (!empty($enabled_vendors)) {
            $query_args['author__in'] = $enabled_vendors;
        }
    }

    $query    = new WP_Query($query_args);
    $products = array();

    if ($query->have_posts()) {
        $vendor_ids = array();
        $raw_posts  = $query->posts;

        foreach ($raw_posts as $post) {
            $product = wc_get_product($post->ID);
            if (!$product) continue;
            $vendor_ids[$post->ID] = (int) $post->post_author;
        }

        $vendor_store_cache = harriet_jfy_batch_load_vendor_stores(array_unique(array_values($vendor_ids)));

        foreach ($raw_posts as $post) {
            $product = wc_get_product($post->ID);
            if (!$product) continue;

            $products[] = harriet_jfy_build_product($product, $post, $vendor_store_cache);
        }
    }

    $total_products = (int) $query->found_posts;
    $total_pages    = max(1, (int) ceil($total_products / $per_page));

    $response_data = array(
        'gender'          => $gender ?: 'all',
        'total'           => $total_products,
        'total_pages'     => $total_pages,
        'page'            => $page,
        'per_page'        => $per_page,
        'categories_used' => $category_slugs,
        'products'        => $products,
    );

    wp_cache_set($cache_key, $response_data, 'harriet', 5 * MINUTE_IN_SECONDS);

    $response = new WP_REST_Response($response_data, 200);
    $response->header('X-WP-Total',      $total_products);
    $response->header('X-WP-TotalPages', $total_pages);
    $response->header('X-WP-Pages',      $total_pages);
    return $response;
}

// ---------------------------------------------------------------------------
// Gender resolution
// ---------------------------------------------------------------------------

function harriet_jfy_resolve_gender(int $customer_id, string $gender_param): string {
    $female_values = array('female', 'woman', 'f', 'women');
    $male_values   = array('male', 'man', 'm', 'men');

    // 1. Explicit query param wins.
    if ($gender_param !== '') {
        if (in_array($gender_param, $female_values, true)) return 'female';
        if (in_array($gender_param, $male_values, true))   return 'male';
    }

    // 2. WooCommerce customer meta.
    if ($customer_id > 0) {
        $meta = strtolower(trim((string) get_user_meta($customer_id, '_gender', true)));
        if ($meta === '') {
            // Some setups use 'billing_gender' or 'gender' as the meta key.
            foreach (array('gender', 'billing_gender') as $key) {
                $meta = strtolower(trim((string) get_user_meta($customer_id, $key, true)));
                if ($meta !== '') break;
            }
        }
        if (in_array($meta, $female_values, true)) return 'female';
        if (in_array($meta, $male_values, true))   return 'male';
    }

    return ''; // unknown → show everything
}

// ---------------------------------------------------------------------------
// Category slugs per gender
// ---------------------------------------------------------------------------

function harriet_jfy_get_category_slugs(string $gender): array {
    $defaults = array(
        'female' => array('women', 'perfumes', 'lipstick', 'lip-balm'),
        'male'   => array('men', 'perfumes', 'grooming'),
        'all'    => array(), // empty → no tax_query filter → all categories
    );

    /**
     * Filters the category slugs used for each gender bucket.
     *
     * @param array  $defaults  Associative array: gender → slug list.
     * @param string $gender    Resolved gender string.
     */
    $slugs_map = apply_filters('harriet_jfy_category_slugs', $defaults, $gender);

    if ($gender === 'female') return $slugs_map['female'] ?? $defaults['female'];
    if ($gender === 'male')   return $slugs_map['male']   ?? $defaults['male'];

    return array(); // no restriction
}

// ---------------------------------------------------------------------------
// Vendor helpers (self-contained so this file has no dependency on
// endpoint-unified-products.php)
// ---------------------------------------------------------------------------

function harriet_jfy_get_enabled_vendors(): array {
    $cache_key = 'harriet_jfy_enabled_vendors';
    $cached = wp_cache_get($cache_key, 'harriet');
    if ($cached !== false) return $cached;

    $ids = get_users(array(
        'role'       => 'seller',
        'meta_query' => array(array('key' => 'dokan_enable_selling', 'value' => 'yes')),
        'fields'     => 'ID',
    ));

    wp_cache_set($cache_key, $ids, 'harriet', HOUR_IN_SECONDS);
    return $ids;
}

function harriet_jfy_batch_load_vendor_stores(array $vendor_ids): array {
    if (empty($vendor_ids) || !function_exists('dokan') || !dokan()->vendor) return array();

    $stores = array();
    foreach ($vendor_ids as $vid) {
        $store = dokan()->vendor->get($vid);
        if (!$store) continue;
        $address = $store->get_address();
        $stores[$vid] = array(
            'id'        => (int) $vid,
            'name'      => $store->get_name(),
            'shop_name' => $store->get_shop_name(),
            'url'       => $store->get_shop_url(),
            'address'   => array(
                'street_1' => $address['street_1'] ?? '',
                'city'     => $address['city'] ?? '',
                'country'  => $address['country'] ?? '',
            ),
        );
    }
    return $stores;
}

// ---------------------------------------------------------------------------
// Product builder
// ---------------------------------------------------------------------------

function harriet_jfy_build_product(WC_Product $product, WP_Post $post, array $vendor_stores): array {
    $is_variable = $product->is_type('variable');

    // Prices
    if ($is_variable) {
        $price         = $product->get_variation_price('min');
        $regular_price = $product->get_variation_regular_price('min');
        $sale_price    = $product->get_variation_sale_price('min');
        if ($sale_price === '' || floatval($sale_price) >= floatval($regular_price)) $sale_price = '';
        $price_range = array(
            'min' => $product->get_variation_price('min'),
            'max' => $product->get_variation_price('max'),
        );
    } else {
        $price         = $product->get_price();
        $regular_price = $product->get_regular_price();
        $sale_price    = $product->get_sale_price();
        $price_range   = null;
    }

    // Images
    $image_id   = $product->get_image_id();
    $main_image = $image_id ? wp_get_attachment_image_url($image_id, 'woocommerce_single') : '';
    $gallery    = array();
    foreach (array_slice($product->get_gallery_image_ids(), 0, 3) as $gid) {
        $url = wp_get_attachment_image_url($gid, 'woocommerce_single');
        if ($url) $gallery[] = $url;
    }

    // Categories
    $cats = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));

    // Vendor
    $vid   = (int) $post->post_author;
    $store = $vendor_stores[$vid] ?? null;

    return array(
        'id'            => $product->get_id(),
        'name'          => $product->get_name(),
        'slug'          => $product->get_slug(),
        'permalink'     => get_permalink($product->get_id()),
        'date_created'  => $product->get_date_created() ? $product->get_date_created()->date('c') : null,
        'type'          => $product->get_type(),
        'status'        => $product->get_status(),
        'categories'    => is_array($cats) ? $cats : array(),
        'price'         => $price,
        'regular_price' => $regular_price,
        'sale_price'    => $sale_price,
        'is_on_sale'    => $product->is_on_sale(),
        'price_range'   => $price_range,
        'stock_status'  => $product->get_stock_status(),
        'images'        => array(
            'main'    => $main_image,
            'gallery' => $gallery,
        ),
        'short_description' => wp_strip_all_tags($product->get_short_description()),
        'vendor'        => $store,
    );
}

// ---------------------------------------------------------------------------
// Cache invalidation: clear JFY cache when any product is saved/updated.
// ---------------------------------------------------------------------------
add_action('save_post_product', 'harriet_jfy_bust_cache');
add_action('woocommerce_product_object_updated_props', 'harriet_jfy_bust_cache');

function harriet_jfy_bust_cache() {
    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('harriet');
    }
}
