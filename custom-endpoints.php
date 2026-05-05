<?php
/**
 * Plugin Name: CustomEnd Harriet
 * Plugin URI: https://github.com/AqeelHusny/CustomEnd-Harriet
 * Description: Custom REST API endpoints for Harriet Shopping — Size Charts, Best Sellers, Deals, SEO Info, Vendor Registration, and Unified Product APIs.
 * Version: 1.0.1
 * Author: Aqeel Husny
 * Author URI: https://github.com/AqeelHusny/CustomEnd-Harriet
 * License: GPL-2.0+
 * Text Domain: customend-harriet
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CUSTOMEND_HARRIET_VERSION')) {
    define('CUSTOMEND_HARRIET_VERSION', '1.0.1');
}
if (!defined('CUSTOMEND_HARRIET_PATH')) {
    define('CUSTOMEND_HARRIET_PATH', plugin_dir_path(__FILE__));
}
if (!defined('CUSTOMEND_HARRIET_URL')) {
    define('CUSTOMEND_HARRIET_URL', plugin_dir_url(__FILE__));
}

final class CustomEnd_Harriet {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="error"><p><strong>CustomEnd Harriet</strong> requires WooCommerce to be installed and active.</p></div>';
            });
            return;
        }

        $this->load_endpoints();
    }

    private function load_endpoints() {
        require_once CUSTOMEND_HARRIET_PATH . 'includes/endpoint-sizechart.php';
        require_once CUSTOMEND_HARRIET_PATH . 'includes/endpoint-best-sellers.php';
        require_once CUSTOMEND_HARRIET_PATH . 'includes/endpoint-deals.php';
        require_once CUSTOMEND_HARRIET_PATH . 'includes/endpoint-seo-info.php';
        require_once CUSTOMEND_HARRIET_PATH . 'includes/endpoint-vendor-register.php';
        require_once CUSTOMEND_HARRIET_PATH . 'includes/endpoint-unified-products.php';
        require_once CUSTOMEND_HARRIET_PATH . 'includes/endpoint-just-for-you.php';
    }

    public function add_admin_menu() {
        add_menu_page(
            'CustomEnd Harriet',
            'CustomEnd Harriet',
            'manage_options',
            'customend-harriet',
            array($this, 'render_admin_page'),
            'dashicons-rest-api',
            80
        );
    }

    public function render_admin_page() {
        $site_url = rest_url();
        $endpoints = array(
            array('name' => 'Size Chart (GET)', 'route' => 'acf/v1/products/{id}', 'method' => 'GET', 'desc' => 'Retrieve product size chart by product ID'),
            array('name' => 'Size Chart Upload (POST)', 'route' => 'acf/v1/products/{id}/size-chart', 'method' => 'POST', 'desc' => 'Upload size chart image/PDF for a product'),
            array('name' => 'Best Sellers', 'route' => 'custom/v1/best-sellers', 'method' => 'GET', 'desc' => 'Top-selling products with date range & vendor filtering'),
            array('name' => 'Deals', 'route' => 'wc/v3/deals', 'method' => 'GET', 'desc' => 'Active sale products with discount/date/vendor/category filters'),
            array('name' => 'Product SEO Info', 'route' => 'wc/v3/product-info/{slug}', 'method' => 'GET', 'desc' => 'Single product SEO metadata (Rank Math)'),
            array('name' => 'Batch Products Info', 'route' => 'wc/v3/products-info?slugs=slug1,slug2', 'method' => 'GET', 'desc' => 'Batch product SEO metadata (up to 20)'),
            array('name' => 'Category SEO Info', 'route' => 'wc/v3/category-info/{slug}', 'method' => 'GET', 'desc' => 'Single category SEO metadata'),
            array('name' => 'Batch Categories Info', 'route' => 'wc/v3/categories-info?slugs=slug1,slug2', 'method' => 'GET', 'desc' => 'Batch category SEO metadata (up to 20)'),
            array('name' => 'Register Vendor', 'route' => 'custom/v1/register-vendor', 'method' => 'POST', 'desc' => 'Register a new Dokan vendor (multi-step)'),
            array('name' => 'Check Email', 'route' => 'custom/v1/check-email?email=test@example.com', 'method' => 'GET', 'desc' => 'Check if email is already registered'),
            array('name' => 'Check Shop URL', 'route' => 'custom/v1/check-shop-url?shop_url=my-store', 'method' => 'GET', 'desc' => 'Check if shop slug is available'),
            array('name' => 'Products by Category', 'route' => 'wc/v3/products/by-category/{slug}', 'method' => 'GET', 'desc' => 'Products filtered by category with price/stock/pagination'),
            array('name' => 'Products by Tag', 'route' => 'wc/v3/products/by-tag/{slug}', 'method' => 'GET', 'desc' => 'Products filtered by tag with price/stock/pagination'),
            array('name' => 'Just For You', 'route' => 'custom/v1/just-for-you?customer_id=123', 'method' => 'GET', 'desc' => 'Latest products personalised by customer gender (women → women/perfumes/lipstick; men → men/grooming/perfumes)'),
        );
        ?>
        <div class="wrap">
            <h1>CustomEnd Harriet — API Endpoints</h1>
            <p>All custom REST API endpoints registered by this plugin. Base URL: <code><?php echo esc_html($site_url); ?></code></p>
            <table class="widefat striped" style="margin-top:20px;">
                <thead>
                    <tr>
                        <th>Endpoint</th>
                        <th>Method</th>
                        <th>Full URL</th>
                        <th>Description</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($endpoints as $ep) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($ep['name']); ?></strong></td>
                        <td><code><?php echo esc_html($ep['method']); ?></code></td>
                        <td><code><?php echo esc_html($site_url . $ep['route']); ?></code></td>
                        <td><?php echo esc_html($ep['desc']); ?></td>
                        <td><span style="color:green;">&#10003; Active</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:20px;color:#666;">Plugin Version: <?php echo esc_html(CUSTOMEND_HARRIET_VERSION); ?> | Author: <a href="https://github.com/AqeelHusny/CustomEnd-Harriet" target="_blank">Aqeel Husny</a></p>
        </div>
        <?php
    }
}

CustomEnd_Harriet::instance();
