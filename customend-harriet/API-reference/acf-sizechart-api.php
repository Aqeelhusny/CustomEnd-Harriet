/**
 * Size Chart REST API Endpoints
 * 
 * Provides GET/POST endpoints for managing product size charts via ACF fields
 */

// Constants
define('SIZE_CHART_ACF_FIELD', 'size_chart');
define('SIZE_CHART_CAPABILITY', 'edit_products');
define('SIZE_CHART_MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('SIZE_CHART_ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf']);

add_action('rest_api_init', 'register_size_chart_endpoints');

function register_size_chart_endpoints() {
    // GET: Retrieve size chart (original URL maintained)
    register_rest_route('acf/v1', '/products/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'get_product_size_chart',
        'permission_callback' => '__return_true',
        'args'                => [
            'id' => [
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ]
        ]
    ]);

    // POST: Upload and set size chart
    register_rest_route('acf/v1', '/products/(?P<id>\d+)/size-chart', [
        'methods'             => 'POST',
        'callback'            => 'upload_product_size_chart',
        'permission_callback' => 'validate_size_chart_upload_permission',
        'args'                => [
            'id' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ],
            'file' => [
                'required'    => false,
                'description' => 'File upload (multipart/form-data)',
            ],
            'url' => [
                'required'    => false,
                'description' => 'URL to download file from',
                'validate_callback' => function($param) {
                    return empty($param) || filter_var($param, FILTER_VALIDATE_URL);
                }
            ],
        ],
    ]);
}

/**
 * Permission callback for size chart uploads
 */
function validate_size_chart_upload_permission() {
    return current_user_can(SIZE_CHART_CAPABILITY);
}

/**
 * GET: Retrieve product size chart
 * Original URL: /wp-json/acf/v1/products/{id}
 */
function get_product_size_chart($request) {
    $product_id = $request['id'];
    $product = wc_get_product($product_id);
    
    if (!$product) {
        return new WP_Error('no_product', 'Product not found', ['status' => 404]);
    }

    $file_id = get_field(SIZE_CHART_ACF_FIELD, $product_id);
    
    return rest_ensure_response([
        'product_id' => $product_id,
        'file_id'    => $file_id,
        'file_url'   => $file_id ? wp_get_attachment_url($file_id) : null,
    ]);
}

/**
 * POST: Upload and set product size chart
 * URL: /wp-json/acf/v1/products/{id}/size-chart
 */
function upload_product_size_chart($request) {
    $product_id = $request['id'];
    
    // Validate product exists
    $product = wc_get_product($product_id);
    if (!$product) {
        return new WP_Error('no_product', 'Product not found', ['status' => 404]);
    }

    // Check ACF is active
    if (!function_exists('update_field')) {
        return new WP_Error('acf_missing', 'ACF plugin not active', ['status' => 500]);
    }

    $attachment_id = null;
    $temp_file = null;

    try {
        // Get URL parameter from request object
        $file_url_param = $request->get_param('url');
        
        // Handle URL upload
        if (!empty($file_url_param)) {
            $result = handle_url_upload($file_url_param);
            if (is_wp_error($result)) {
                return $result;
            }
            list($attachment_id, $temp_file) = $result;
        }
        // Handle file upload - $_FILES is still correct for multipart/form-data
        elseif (!empty($_FILES['file'])) {
            $attachment_id = handle_file_upload();
            if (is_wp_error($attachment_id)) {
                return $attachment_id;
            }
        }
        else {
            return new WP_Error('no_input', 'Provide either file or url parameter', ['status' => 400]);
        }

        // Delete old attachment
        $old_attachment_id = get_field(SIZE_CHART_ACF_FIELD, $product_id);
        if ($old_attachment_id && $old_attachment_id != $attachment_id) {
            wp_delete_attachment($old_attachment_id, true);
        }

        // Update ACF field
        update_field(SIZE_CHART_ACF_FIELD, $attachment_id, $product_id);

        // Log success
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log("Size chart updated for product {$product_id}: attachment {$attachment_id}");
        }

        return rest_ensure_response([
            'success'    => true,
            'product_id' => $product_id,
            'media_id'   => $attachment_id,
            'file_url'   => wp_get_attachment_url($attachment_id),
            'message'    => 'Size chart uploaded successfully',
        ]);

    } finally {
        // Always clean up temp files
        if ($temp_file && file_exists($temp_file)) {
            @unlink($temp_file);
        }
    }
}

/**
 * Handle URL-based file upload
 */
function handle_url_upload($url) {
    // ✅ CRITICAL: Load WordPress admin functions for REST API context
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    
    $url = esc_url_raw($url);
    
    // Set download constraints
    add_filter('http_request_timeout', function() { return 10; });
    add_filter('http_request_redirection_count', function() { return 3; });
    
    $temp_file = download_url($url);
    
    if (is_wp_error($temp_file)) {
        return new WP_Error('download_failed', $temp_file->get_error_message(), ['status' => 400]);
    }

    // Validate file type and size
    $validation = validate_uploaded_file($temp_file, basename(parse_url($url, PHP_URL_PATH)));
    if (is_wp_error($validation)) {
        @unlink($temp_file);
        return $validation;
    }

    $attachment_id = media_handle_sideload([
        'name'     => sanitize_file_name(basename(parse_url($url, PHP_URL_PATH))),
        'tmp_name' => $temp_file,
    ], 0);

    if (is_wp_error($attachment_id)) {
        return new WP_Error('upload_failed', $attachment_id->get_error_message(), ['status' => 500]);
    }

    return [$attachment_id, $temp_file];
}

/**
 * Handle direct file upload
 */
function handle_file_upload() {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    // Validate before upload
    $validation = validate_uploaded_file($_FILES['file']['tmp_name'], $_FILES['file']['name']);
    if (is_wp_error($validation)) {
        return $validation;
    }

    $attachment_id = media_handle_upload('file', 0);
    
    if (is_wp_error($attachment_id)) {
        return new WP_Error('upload_failed', $attachment_id->get_error_message(), ['status' => 500]);
    }

    return $attachment_id;
}

/**
 * Validate file type and size
 */
function validate_uploaded_file($file_path, $filename) {
    // Check file size
    $file_size = filesize($file_path);
    if ($file_size > SIZE_CHART_MAX_FILE_SIZE) {
        return new WP_Error('file_too_large', 
            sprintf('File exceeds maximum size of %dMB', SIZE_CHART_MAX_FILE_SIZE / 1024 / 1024),
            ['status' => 400]
        );
    }

    // Validate MIME type
    $file_type = wp_check_filetype($filename);
    if (!in_array($file_type['type'], SIZE_CHART_ALLOWED_TYPES)) {
        return new WP_Error('invalid_file_type',
            'Only images (JPEG, PNG, GIF, WebP) and PDFs are allowed',
            ['status' => 400]
        );
    }

    return true;
}