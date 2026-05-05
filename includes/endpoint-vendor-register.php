<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/register-vendor', [
        'methods'  => 'POST',
        'callback' => 'harriet_register_vendor',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('custom/v1', '/check-email', [
        'methods'  => 'GET',
        'callback' => 'harriet_check_email',
        'permission_callback' => '__return_true',
        'args' => [
            'email' => ['required' => true, 'sanitize_callback' => 'sanitize_email'],
        ],
    ]);

    register_rest_route('custom/v1', '/check-shop-url', [
        'methods'  => 'GET',
        'callback' => 'harriet_check_shop_url',
        'permission_callback' => '__return_true',
        'args' => [
            'shop_url' => ['required' => true, 'sanitize_callback' => 'sanitize_title'],
        ],
    ]);
});

function harriet_register_vendor($request) {
    $params = $request->get_json_params();

    $first_name = sanitize_text_field($params['firstName'] ?? '');
    $last_name  = sanitize_text_field($params['lastName'] ?? '');
    $email      = sanitize_email($params['email'] ?? '');
    $password   = $params['password'] ?? '';
    $phone      = sanitize_text_field($params['phone'] ?? '');
    $shop_name  = sanitize_text_field($params['shopName'] ?? '');
    $shop_slug  = sanitize_title($params['shopUrl'] ?? '');
    $username   = sanitize_user($shop_slug, true);

    $street   = sanitize_text_field($params['street'] ?? '');
    $street2  = sanitize_text_field($params['street2'] ?? '');
    $city     = sanitize_text_field($params['city'] ?? '');
    $zip      = sanitize_text_field($params['zipCode'] ?? '');
    $country  = sanitize_text_field($params['country'] ?? '');
    $category = sanitize_text_field($params['storeCategory'] ?? '');

    $account_holder = sanitize_text_field($params['accountHolder'] ?? '');
    $account_type   = sanitize_text_field($params['accountType'] ?? '');
    $account_number = sanitize_text_field($params['accountNumber'] ?? '');
    $routing_number = sanitize_text_field($params['routingNumber'] ?? '');
    $bank_name      = sanitize_text_field($params['bankName'] ?? '');
    $bank_address   = sanitize_text_field($params['bankAddress'] ?? '');
    $iban           = sanitize_text_field($params['bankIban'] ?? '');
    $swift          = sanitize_text_field($params['bankSwiftCode'] ?? '');

    if (empty($email) || empty($password) || empty($username)) {
        return new WP_Error('missing_fields', 'Required fields are missing', ['status' => 400]);
    }

    if (strlen($password) < 6) {
        return new WP_Error('weak_password', 'Password must be at least 6 characters', ['status' => 400]);
    }

    if (email_exists($email)) {
        return new WP_Error('email_exists', 'Email is already registered', ['status' => 400]);
    }

    if (username_exists($username)) {
        return new WP_Error('username_exists', 'Shop slug is already taken', ['status' => 400]);
    }

    if (get_user_by('slug', $username)) {
        return new WP_Error('username_conflict', 'Shop slug conflicts with existing user slug', ['status' => 400]);
    }

    global $wpdb;
    $existing_store = $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'dokan_store_url' AND meta_value = %s LIMIT 1",
        $username
    ));

    if ($existing_store) {
        return new WP_Error('shop_url_exists', 'Shop slug is already taken', ['status' => 400]);
    }

    $user_id = wp_insert_user([
        'user_login'    => $username,
        'user_nicename' => $username,
        'user_email'    => $email,
        'user_pass'     => $password,
        'first_name'    => $first_name,
        'last_name'     => $last_name,
        'display_name'  => $shop_name,
        'role'          => 'seller',
    ]);

    if (is_wp_error($user_id)) {
        return new WP_Error('registration_failed', $user_id->get_error_message(), ['status' => 400]);
    }

    update_user_meta($user_id, 'billing_first_name', $first_name);
    update_user_meta($user_id, 'billing_last_name', $last_name);
    update_user_meta($user_id, 'billing_phone', $phone);
    update_user_meta($user_id, 'billing_address_1', $street);
    update_user_meta($user_id, 'billing_address_2', $street2);
    update_user_meta($user_id, 'billing_city', $city);
    update_user_meta($user_id, 'billing_postcode', $zip);
    update_user_meta($user_id, 'billing_country', $country);

    update_user_meta($user_id, 'shipping_first_name', $first_name);
    update_user_meta($user_id, 'shipping_last_name', $last_name);
    update_user_meta($user_id, 'shipping_address_1', $street);
    update_user_meta($user_id, 'shipping_address_2', $street2);
    update_user_meta($user_id, 'shipping_city', $city);
    update_user_meta($user_id, 'shipping_postcode', $zip);
    update_user_meta($user_id, 'shipping_country', $country);

    update_user_meta($user_id, 'store_category', $category);

    $dokan_profile = [
        'store_name' => $shop_name,
        'social'     => ['fb' => '', 'twitter' => '', 'instagram' => ''],
        'phone'      => $phone,
        'address'    => [
            'street_1' => $street, 'street_2' => $street2,
            'city' => $city, 'zip' => $zip, 'country' => $country, 'state' => ''
        ],
        'banner'   => 0,
        'gravatar' => 0,
        'payment'  => [
            'bank' => [
                'ac_name'   => $account_holder, 'ac_type' => $account_type,
                'ac_number' => $account_number, 'routing' => $routing_number,
                'bank_name' => $bank_name, 'bank_addr' => $bank_address,
                'iban' => $iban, 'swift' => $swift,
            ]
        ]
    ];

    update_user_meta($user_id, 'dokan_profile_settings', $dokan_profile);
    update_user_meta($user_id, 'dokan_store_url', $username);

    return rest_ensure_response([
        'success'    => true,
        'user_id'    => $user_id,
        'username'   => $username,
        'role'       => 'seller',
        'store_slug' => $username,
        'store_url'  => site_url("/store/{$username}"),
    ]);
}

function harriet_check_email($request) {
    $email = $request->get_param('email');

    if (email_exists($email)) {
        return ['exists' => true, 'message' => 'Email is already registered'];
    }

    return ['exists' => false, 'message' => 'Email is available'];
}

function harriet_check_shop_url($request) {
    $shop_url = $request->get_param('shop_url');

    if (!$shop_url) {
        return new WP_Error('invalid_request', 'No shop slug provided', ['status' => 400]);
    }

    $shop_slug = sanitize_title($shop_url);
    $username  = sanitize_user($shop_slug, true);
    $full_url  = site_url("/store/{$username}");

    if (empty($username)) {
        return new WP_Error('invalid_request', 'Invalid shop slug', ['status' => 400]);
    }

    if (username_exists($username)) {
        return ['exists' => true, 'message' => 'Shop slug is already taken', 'store_slug' => $username, 'store_url' => $full_url, 'source' => 'username'];
    }

    if (get_user_by('slug', $username)) {
        return ['exists' => true, 'message' => 'Shop slug is already taken', 'store_slug' => $username, 'store_url' => $full_url, 'source' => 'user_slug'];
    }

    global $wpdb;
    $exists_user_id = $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'dokan_store_url' AND meta_value = %s LIMIT 1",
        $username
    ));

    if ($exists_user_id) {
        return ['exists' => true, 'message' => 'Shop slug is already taken', 'store_slug' => $username, 'store_url' => $full_url, 'source' => 'dokan_store_url'];
    }

    return ['exists' => false, 'message' => 'Shop slug is available', 'store_slug' => $username, 'store_url' => $full_url];
}
