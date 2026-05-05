<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('wc/v3', '/product-info/(?P<slug>[\w-]+)', array(
        'methods'             => 'GET',
        'callback'            => 'harriet_get_product_info',
        'permission_callback' => '__return_true',
        'args'                => array(
            'slug' => array('validate_callback' => function ($param) { return is_string($param); }),
        ),
    ));

    register_rest_route('wc/v3', '/products-info', array(
        'methods'             => 'GET',
        'callback'            => 'harriet_get_products_info_batch',
        'permission_callback' => '__return_true',
        'args'                => array(
            'slugs' => array('type' => 'string', 'required' => true, 'validate_callback' => function ($param) { return is_string($param); }),
        ),
    ));

    register_rest_route('wc/v3', '/category-info/(?P<slug>[\w-]+)', array(
        'methods'             => 'GET',
        'callback'            => 'harriet_get_category_info',
        'permission_callback' => '__return_true',
        'args'                => array(
            'slug' => array('validate_callback' => function ($param) { return is_string($param); }),
        ),
    ));

    register_rest_route('wc/v3', '/categories-info', array(
        'methods'             => 'GET',
        'callback'            => 'harriet_get_categories_info_batch',
        'permission_callback' => '__return_true',
        'args'                => array(
            'slugs' => array('type' => 'string', 'required' => true, 'validate_callback' => function ($param) { return is_string($param); }),
        ),
    ));
});

function harriet_get_product_info($request) {
    $product_slug = sanitize_text_field($request['slug']);

    $cache_key = 'harriet_prod_info_' . $product_slug;
    $cached = wp_cache_get($cache_key, 'harriet');
    if ($cached !== false) return new WP_REST_Response($cached, 200);

    $products_query = new WP_Query(array('post_type' => 'product', 'name' => $product_slug, 'posts_per_page' => 1));

    if (!$products_query->have_posts()) {
        return new WP_Error('no_product', 'Product not found', array('status' => 404));
    }

    $products_query->the_post();
    $product_id = get_the_ID();
    $product = wc_get_product($product_id);

    if (!$product) {
        wp_reset_postdata();
        return new WP_Error('invalid_product', 'Product could not be loaded', array('status' => 500));
    }

    $thumbnail_id = $product->get_image_id();
    $rank_math_meta = array(
        'meta_title'       => get_post_meta($product_id, 'rank_math_title', true),
        'meta_description' => get_post_meta($product_id, 'rank_math_description', true),
        'meta_keywords'    => get_post_meta($product_id, 'rank_math_focus_keyword', true),
        'social_image_id'  => get_post_meta($product_id, 'rank_math_social_image', true),
        'canonical_url'    => get_post_meta($product_id, 'rank_math_canonical_url', true),
    );

    $product_data = array(
        'id'                => $product_id,
        'slug'              => $product->get_slug(),
        'name'              => $product->get_name(),
        'type'              => $product->get_type(),
        'status'            => $product->get_status(),
        'description'       => $product->get_description(),
        'short_description' => $product->get_short_description(),
        'image'             => array(
            'id'  => $thumbnail_id ? intval($thumbnail_id) : null,
            'url' => $thumbnail_id ? wp_get_attachment_url($thumbnail_id) : null,
        ),
        'seo'               => array(
            'title'        => $rank_math_meta['meta_title'],
            'description'  => $rank_math_meta['meta_description'],
            'keywords'     => $rank_math_meta['meta_keywords'],
            'canonical_url' => $rank_math_meta['canonical_url'],
            'social_image' => array(
                'id'  => $rank_math_meta['social_image_id'] ? intval($rank_math_meta['social_image_id']) : null,
                'url' => $rank_math_meta['social_image_id'] ? wp_get_attachment_url($rank_math_meta['social_image_id']) : null,
            ),
        ),
    );

    wp_reset_postdata();
    wp_cache_set($cache_key, $product_data, 'harriet', 6 * HOUR_IN_SECONDS);

    return new WP_REST_Response($product_data, 200);
}

function harriet_get_products_info_batch($request) {
    $slugs = array_filter(array_map('trim', explode(',', $request->get_param('slugs'))));

    if (empty($slugs)) return new WP_Error('no_slugs', 'No product slugs provided', array('status' => 400));
    if (count($slugs) > 20) $slugs = array_slice($slugs, 0, 20);

    $products_data = array();

    foreach ($slugs as $slug) {
        $cache_key = 'harriet_prod_info_' . $slug;
        $cached = wp_cache_get($cache_key, 'harriet');
        if ($cached !== false) { $products_data[] = $cached; continue; }

        $query = new WP_Query(array('post_type' => 'product', 'name' => $slug, 'posts_per_page' => 1));
        if (!$query->have_posts()) continue;

        $query->the_post();
        $product_id = get_the_ID();
        $product = wc_get_product($product_id);
        if (!$product) { wp_reset_postdata(); continue; }

        $thumbnail_id = $product->get_image_id();
        $rank_math_meta = array(
            'meta_title'       => get_post_meta($product_id, 'rank_math_title', true),
            'meta_description' => get_post_meta($product_id, 'rank_math_description', true),
            'meta_keywords'    => get_post_meta($product_id, 'rank_math_focus_keyword', true),
            'social_image_id'  => get_post_meta($product_id, 'rank_math_social_image', true),
            'canonical_url'    => get_post_meta($product_id, 'rank_math_canonical_url', true),
        );

        $product_data = array(
            'id'                => $product_id,
            'slug'              => $product->get_slug(),
            'name'              => $product->get_name(),
            'type'              => $product->get_type(),
            'status'            => $product->get_status(),
            'description'       => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'image'             => array(
                'id'  => $thumbnail_id ? intval($thumbnail_id) : null,
                'url' => $thumbnail_id ? wp_get_attachment_url($thumbnail_id) : null,
            ),
            'seo'               => array(
                'title'        => $rank_math_meta['meta_title'],
                'description'  => $rank_math_meta['meta_description'],
                'keywords'     => $rank_math_meta['meta_keywords'],
                'canonical_url' => $rank_math_meta['canonical_url'],
                'social_image' => array(
                    'id'  => $rank_math_meta['social_image_id'] ? intval($rank_math_meta['social_image_id']) : null,
                    'url' => $rank_math_meta['social_image_id'] ? wp_get_attachment_url($rank_math_meta['social_image_id']) : null,
                ),
            ),
        );

        wp_reset_postdata();
        wp_cache_set($cache_key, $product_data, 'harriet', 6 * HOUR_IN_SECONDS);
        $products_data[] = $product_data;
    }

    if (empty($products_data)) return new WP_Error('no_products', 'No products found', array('status' => 404));
    return new WP_REST_Response($products_data, 200);
}

function harriet_get_category_info($request) {
    $category_slug = sanitize_text_field($request['slug']);

    $cache_key = 'harriet_cat_info_' . $category_slug;
    $cached = wp_cache_get($cache_key, 'harriet');
    if ($cached !== false) return new WP_REST_Response($cached, 200);

    $category = get_term_by('slug', $category_slug, 'product_cat');
    if (!$category || is_wp_error($category)) {
        return new WP_Error('no_category', 'Category not found', array('status' => 404));
    }

    $term_id = $category->term_id;
    $thumbnail_id = get_term_meta($term_id, 'thumbnail_id', true);
    $rank_math_meta = array(
        'meta_title'       => get_term_meta($term_id, 'rank_math_title', true),
        'meta_description' => get_term_meta($term_id, 'rank_math_description', true),
        'meta_keywords'    => get_term_meta($term_id, 'rank_math_focus_keyword', true),
        'social_image_id'  => get_term_meta($term_id, 'rank_math_social_image', true),
    );

    $category_data = array(
        'id'          => $term_id,
        'slug'        => $category->slug,
        'name'        => $category->name,
        'description' => $category->description,
        'image'       => array(
            'id'  => $thumbnail_id ? intval($thumbnail_id) : null,
            'url' => $thumbnail_id ? wp_get_attachment_url($thumbnail_id) : null,
        ),
        'seo'         => array(
            'title'        => $rank_math_meta['meta_title'],
            'description'  => $rank_math_meta['meta_description'],
            'keywords'     => $rank_math_meta['meta_keywords'],
            'social_image' => array(
                'id'  => $rank_math_meta['social_image_id'] ? intval($rank_math_meta['social_image_id']) : null,
                'url' => $rank_math_meta['social_image_id'] ? wp_get_attachment_url($rank_math_meta['social_image_id']) : null,
            ),
        ),
    );

    wp_cache_set($cache_key, $category_data, 'harriet', 24 * HOUR_IN_SECONDS);
    return new WP_REST_Response($category_data, 200);
}

function harriet_get_categories_info_batch($request) {
    $slugs = array_filter(array_map('trim', explode(',', $request->get_param('slugs'))));

    if (empty($slugs)) return new WP_Error('no_slugs', 'No category slugs provided', array('status' => 400));
    if (count($slugs) > 20) $slugs = array_slice($slugs, 0, 20);

    $categories_data = array();

    foreach ($slugs as $slug) {
        $cache_key = 'harriet_cat_info_' . $slug;
        $cached = wp_cache_get($cache_key, 'harriet');
        if ($cached !== false) { $categories_data[] = $cached; continue; }

        $category = get_term_by('slug', $slug, 'product_cat');
        if (!$category || is_wp_error($category)) continue;

        $term_id = $category->term_id;
        $thumbnail_id = get_term_meta($term_id, 'thumbnail_id', true);
        $rank_math_meta = array(
            'meta_title'       => get_term_meta($term_id, 'rank_math_title', true),
            'meta_description' => get_term_meta($term_id, 'rank_math_description', true),
            'meta_keywords'    => get_term_meta($term_id, 'rank_math_focus_keyword', true),
            'social_image_id'  => get_term_meta($term_id, 'rank_math_social_image', true),
        );

        $category_data = array(
            'id'          => $term_id,
            'slug'        => $category->slug,
            'name'        => $category->name,
            'description' => $category->description,
            'image'       => array(
                'id'  => $thumbnail_id ? intval($thumbnail_id) : null,
                'url' => $thumbnail_id ? wp_get_attachment_url($thumbnail_id) : null,
            ),
            'seo'         => array(
                'title'        => $rank_math_meta['meta_title'],
                'description'  => $rank_math_meta['meta_description'],
                'keywords'     => $rank_math_meta['meta_keywords'],
                'social_image' => array(
                    'id'  => $rank_math_meta['social_image_id'] ? intval($rank_math_meta['social_image_id']) : null,
                    'url' => $rank_math_meta['social_image_id'] ? wp_get_attachment_url($rank_math_meta['social_image_id']) : null,
                ),
            ),
        );

        wp_cache_set($cache_key, $category_data, 'harriet', 24 * HOUR_IN_SECONDS);
        $categories_data[] = $category_data;
    }

    if (empty($categories_data)) return new WP_Error('no_categories', 'No categories found', array('status' => 404));
    return new WP_REST_Response($categories_data, 200);
}

// Cache invalidation
add_action('save_post_product', function ($post_id, $post) {
    if ($post->post_status !== 'publish') return;
    $product = wc_get_product($post_id);
    if ($product) wp_cache_delete('harriet_prod_info_' . $product->get_slug(), 'harriet');
}, 10, 2);

add_action('wp_trash_post', function ($post_id) {
    $post = get_post($post_id);
    if ($post->post_type !== 'product') return;
    $product = wc_get_product($post_id);
    if ($product) wp_cache_delete('harriet_prod_info_' . $product->get_slug(), 'harriet');
});

add_action('update_post_meta', function ($meta_id, $post_id, $meta_key, $meta_value) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'product') return;
    if (strpos($meta_key, 'rank_math_') === 0 || $meta_key === '_thumbnail_id') {
        $product = wc_get_product($post_id);
        if ($product) wp_cache_delete('harriet_prod_info_' . $product->get_slug(), 'harriet');
    }
}, 10, 4);

add_action('set_post_thumbnail', function ($post_id, $thumbnail_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'product') return;
    $product = wc_get_product($post_id);
    if ($product) wp_cache_delete('harriet_prod_info_' . $product->get_slug(), 'harriet');
}, 10, 2);

add_action('woocommerce_product_quick_edit_save', function ($product) {
    if ($product) wp_cache_delete('harriet_prod_info_' . $product->get_slug(), 'harriet');
});

add_action('edited_product_cat', function ($term_id) {
    $category = get_term($term_id, 'product_cat');
    if ($category && !is_wp_error($category)) wp_cache_delete('harriet_cat_info_' . $category->slug, 'harriet');
});

add_action('delete_product_cat', function ($term_id) {
    $category = get_term($term_id, 'product_cat');
    if ($category && !is_wp_error($category)) wp_cache_delete('harriet_cat_info_' . $category->slug, 'harriet');
});

add_action('update_term_meta', function ($meta_id, $term_id, $meta_key, $meta_value) {
    if (strpos($meta_key, 'rank_math_') !== 0 && $meta_key !== 'thumbnail_id') return;
    $category = get_term($term_id, 'product_cat');
    if ($category && !is_wp_error($category)) wp_cache_delete('harriet_cat_info_' . $category->slug, 'harriet');
}, 10, 4);
