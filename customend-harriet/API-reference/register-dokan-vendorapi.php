add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/register-vendor', [
        'methods'  => 'POST',
        'callback' => 'custom_register_vendor',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('custom/v1', '/check-email', [
        'methods'  => 'GET',
        'callback' => 'custom_check_email',
        'permission_callback' => '__return_true',
        'args' => [
            'email' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_email',
            ],
        ],
    ]);

    register_rest_route('custom/v1', '/check-shop-url', [
        'methods'  => 'GET',
        'callback' => 'custom_check_shop_url',
        'permission_callback' => '__return_true',
        'args' => [
            'shop_url' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_title',
            ],
        ],
    ]);
});

function custom_register_vendor($request) {
    $params = $request->get_json_params();

    // Step 1 data
    $first_name = sanitize_text_field($params['firstName'] ?? '');
    $last_name  = sanitize_text_field($params['lastName'] ?? '');
    $email      = sanitize_email($params['email'] ?? '');
    $password   = $params['password'] ?? '';
    $phone      = sanitize_text_field($params['phone'] ?? '');
    $shop_name  = sanitize_text_field($params['shopName'] ?? '');

    // shop_url means slug only
    $shop_slug = sanitize_title($params['shopUrl'] ?? '');
    $username  = sanitize_user($shop_slug, true);

    // Step 2 data
    $street   = sanitize_text_field($params['street'] ?? '');
    $street2  = sanitize_text_field($params['street2'] ?? '');
    $city     = sanitize_text_field($params['city'] ?? '');
    $zip      = sanitize_text_field($params['zipCode'] ?? '');
    $country  = sanitize_text_field($params['country'] ?? '');
    $category = sanitize_text_field($params['storeCategory'] ?? '');

    // Step 3 data
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

    if (email_exists($email)) {
        return new WP_Error('email_exists', 'Email is already registered', ['status' => 400]);
    }

    // Check username conflict
    if (username_exists($username)) {
        return new WP_Error('username_exists', 'Shop slug is already taken', ['status' => 400]);
    }

    // Check nicename/slug conflict
    if (get_user_by('slug', $username)) {
        return new WP_Error('username_conflict', 'Shop slug conflicts with existing user slug', ['status' => 400]);
    }

    global $wpdb;

    // Check Dokan store slug conflict
    $existing_store = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT user_id
             FROM {$wpdb->usermeta}
             WHERE meta_key = 'dokan_store_url'
             AND meta_value = %s
             LIMIT 1",
            $username
        )
    );

    if ($existing_store) {
        return new WP_Error('shop_url_exists', 'Shop slug is already taken', ['status' => 400]);
    }

    // Create user using slug as username
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

    // Save WooCommerce billing/shipping
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

    // Optional custom category meta
    update_user_meta($user_id, 'store_category', $category);

    // Dokan store profile
    $dokan_profile = [
        'store_name' => $shop_name,
        'social'     => [
            'fb'        => '',
            'twitter'   => '',
            'instagram' => ''
        ],
        'phone'   => $phone,
        'address' => [
            'street_1' => $street,
            'street_2' => $street2,
            'city'     => $city,
            'zip'      => $zip,
            'country'  => $country,
            'state'    => ''
        ],
        'banner'   => 0,
        'gravatar' => 0,
        'payment'  => [
            'bank' => [
                'ac_name'   => $account_holder,
                'ac_type'   => $account_type,
                'ac_number' => $account_number,
                'routing'   => $routing_number,
                'bank_name' => $bank_name,
                'bank_addr' => $bank_address,
                'iban'      => $iban,
                'swift'     => $swift,
            ]
        ]
    ];

    update_user_meta($user_id, 'dokan_profile_settings', $dokan_profile);
    update_user_meta($user_id, 'dokan_store_url', $username);

    return [
        'success'    => true,
        'user_id'    => $user_id,
        'username'   => $username,
        'role'       => 'seller',
        'store_slug' => $username,
        'store_url'  => site_url("/store/{$username}"),
    ];
}

/**
 * Check if email is already used
 */
function custom_check_email($request) {
    $email = $request->get_param('email');

    if (email_exists($email)) {
        return [
            'exists'  => true,
            'message' => 'Email is already registered'
        ];
    }

    return [
        'exists'  => false,
        'message' => 'Email is available'
    ];
}

/**
 * Check if shop slug is already used
 */
function custom_check_shop_url($request) {
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

    // 1) Check WP username/login
    if (username_exists($username)) {
        return [
            'exists'     => true,
            'message'    => 'Shop slug is already taken',
            'store_slug' => $username,
            'store_url'  => $full_url,
            'source'     => 'username',
        ];
    }

    // 2) Check WP user nicename/slug
    if (get_user_by('slug', $username)) {
        return [
            'exists'     => true,
            'message'    => 'Shop slug is already taken',
            'store_slug' => $username,
            'store_url'  => $full_url,
            'source'     => 'user_slug',
        ];
    }

    global $wpdb;

    // 3) Check Dokan store slug meta
    $exists_user_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT user_id
             FROM {$wpdb->usermeta}
             WHERE meta_key = 'dokan_store_url'
             AND meta_value = %s
             LIMIT 1",
            $username
        )
    );

    if ($exists_user_id) {
        return [
            'exists'     => true,
            'message'    => 'Shop slug is already taken',
            'store_slug' => $username,
            'store_url'  => $full_url,
            'source'     => 'dokan_store_url',
        ];
    }

    return [
        'exists'     => false,
        'message'    => 'Shop slug is available',
        'store_slug' => $username,
        'store_url'  => $full_url,
    ];
}

// add_action('rest_api_init', function () {
//     register_rest_route('custom/v1', '/register-vendor', [
//         'methods'  => 'POST',
//         'callback' => 'custom_register_vendor',
//         'permission_callback' => '__return_true', // allow public access
//     ]);
//     register_rest_route('custom/v1', '/check-email', [
//         'methods'  => 'GET',
//         'callback' => 'custom_check_email',
//         'permission_callback' => '__return_true',
//         'args' => [
//             'email' => [
//                 'required' => true,
//                 'sanitize_callback' => 'sanitize_email',
//             ],
//         ],
//     ]);
//     register_rest_route('custom/v1', '/check-shop-url', [
//         'methods'  => 'GET',
//         'callback' => 'custom_check_shop_url',
//         'permission_callback' => '__return_true',
//         'args' => [
//             'shop_url' => [
//                 'required' => true,
//                 'sanitize_callback' => 'sanitize_title',
//             ],
//         ],
//     ]);
// });

// function custom_register_vendor($request) {
//     $params = $request->get_json_params();

//     // Step 1 data
//     $first_name = sanitize_text_field($params['firstName']);
//     $last_name  = sanitize_text_field($params['lastName']);
//     $email      = sanitize_email($params['email']);
//     $password   = $params['password']; // don't sanitize password
//     $phone      = sanitize_text_field($params['phone']);
//     $shop_name  = sanitize_text_field($params['shopName']);
//     $shop_url   = sanitize_title($params['shopUrl']);

//     // Step 2 data
//     $street   = sanitize_text_field($params['street']);
//     $street2  = sanitize_text_field($params['street2'] ?? '');
//     $city     = sanitize_text_field($params['city']);
//     $zip      = sanitize_text_field($params['zipCode']);
//     $country  = sanitize_text_field($params['country']);
//     $category = sanitize_text_field($params['storeCategory']);

//     // Step 3 data
//     $account_holder = sanitize_text_field($params['accountHolder']);
//     $account_type   = sanitize_text_field($params['accountType']);
//     $account_number = sanitize_text_field($params['accountNumber']);
//     $routing_number = sanitize_text_field($params['routingNumber']);
//     $bank_name      = sanitize_text_field($params['bankName']);
//     $bank_address   = sanitize_text_field($params['bankAddress']);
//     $iban           = sanitize_text_field($params['bankIban'] ?? '');
//     $swift          = sanitize_text_field($params['bankSwiftCode'] ?? '');

//     // Create user with "pending_vendor" role
//     $user_id = wp_insert_user([
//         'user_login'   => $email,
//         'user_email'   => $email,
//         'user_pass'    => $password,
//         'first_name'   => $first_name,
//         'last_name'    => $last_name,
//         'display_name' => $shop_name,
//         'role'         => 'seller',
//     ]);

//     if (is_wp_error($user_id)) {
//         return new WP_Error('registration_failed', $user_id->get_error_message(), ['status' => 400]);
//     }

//     // Save WooCommerce billing/shipping
//     update_user_meta($user_id, 'billing_first_name', $first_name);
//     update_user_meta($user_id, 'billing_last_name', $last_name);
//     update_user_meta($user_id, 'billing_phone', $phone);
//     update_user_meta($user_id, 'billing_address_1', $street);
//     update_user_meta($user_id, 'billing_address_2', $street2);
//     update_user_meta($user_id, 'billing_city', $city);
//     update_user_meta($user_id, 'billing_postcode', $zip);
//     update_user_meta($user_id, 'billing_country', $country);

//     update_user_meta($user_id, 'shipping_first_name', $first_name);
//     update_user_meta($user_id, 'shipping_last_name', $last_name);
//     update_user_meta($user_id, 'shipping_address_1', $street);
//     update_user_meta($user_id, 'shipping_address_2', $street2);
//     update_user_meta($user_id, 'shipping_city', $city);
//     update_user_meta($user_id, 'shipping_postcode', $zip);
//     update_user_meta($user_id, 'shipping_country', $country);

//     // Dokan store profile
//     $dokan_profile = [
//         'store_name' => $shop_name,
//         'social'     => [
//             'fb'       => '',
//             'twitter'  => '',
//             'instagram'=> ''
//         ],
//         'phone'   => $phone,
//         'address' => [
//             'street_1' => $street,
//             'street_2' => $street2,
//             'city'     => $city,
//             'zip'      => $zip,
//             'country'  => $country,
//             'state'    => ''
//         ],
//         'banner'   => 0,
//         'gravatar' => 0,
//         'payment'  => [
//             'bank' => [
//                 'ac_name'   => $account_holder,
//                 'ac_type'   => $account_type,
//                 'ac_number' => $account_number,
//                 'routing'   => $routing_number,
//                 'bank_name' => $bank_name,
//                 'bank_addr' => $bank_address,
//                 'iban'      => $iban,
//                 'swift'     => $swift,
//             ]
//         ]
//     ];

//     update_user_meta($user_id, 'dokan_profile_settings', $dokan_profile);
//     update_user_meta($user_id, 'dokan_store_url', $shop_url);

//     return [
//         'success'   => true,
//         'user_id'   => $user_id,
//         'role'      => 'seller',
//         'store_url' => site_url("/store/{$shop_url}"),
//     ];
// }

// /**
//  * Check if email is already used
//  */
// function custom_check_email($request) {
//     $email = $request->get_param('email');

//     if (email_exists($email)) {
//         return [
//             'exists' => true,
//             'message' => 'Email is already registered'
//         ];
//     }

//     return [
//         'exists' => false,
//         'message' => 'Email is available'
//     ];
// }

// /**
//  * Check if shop URL is already used
//  */
// function custom_check_shop_url($request) {
//     $shop_url = $request->get_param('shop_url');

//     if (!$shop_url) {
//         return new WP_Error('invalid_request', 'No shop URL provided', ['status' => 400]);
//     }

//     // Ensure safe slug
//     $shop_url_slug = sanitize_title($shop_url);

//     global $wpdb;

//     // Search inside dokan_profile_settings usermeta
//     $exists_user_id = $wpdb->get_var(
//         $wpdb->prepare(
//             "SELECT user_id 
//              FROM {$wpdb->usermeta} 
//              WHERE meta_key = 'dokan_profile_settings' 
//              AND meta_value LIKE %s",
//             '%' . $wpdb->esc_like('"slug";s:' . strlen($shop_url_slug) . ':"' . $shop_url_slug . '"') . '%'
//         )
//     );

//     $full_url = site_url("/store/{$shop_url_slug}");

//     if ($exists_user_id) {
//         return [
//             'exists'   => true,
//             'message'  => 'Shop URL is already taken',
//             'store_url'=> $full_url,
//         ];
//     }

//     return [
//         'exists'   => false,
//         'message'  => 'Shop URL is available',
//         'store_url'=> $full_url,
//     ];
// }
