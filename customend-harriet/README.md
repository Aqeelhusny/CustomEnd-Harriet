# CustomEnd Harriet

Custom REST API endpoints plugin for **Harriet Shopping** (WooCommerce + Dokan multivendor).

## Installation

1. Upload the `customend-harriet` folder to `/wp-content/plugins/`
2. Activate the plugin through **Plugins > Installed Plugins**
3. Navigate to **CustomEnd Harriet** in the WordPress admin sidebar to view all available endpoints

## Requirements

- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+
- Dokan (for vendor-related endpoints)
- ACF (for size chart endpoint)
- Rank Math (for SEO info endpoints)

## Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/wp-json/acf/v1/products/{id}` | GET | Get product size chart |
| `/wp-json/acf/v1/products/{id}/size-chart` | POST | Upload size chart |
| `/wp-json/custom/v1/best-sellers` | GET | Top-selling products |
| `/wp-json/wc/v3/deals` | GET | Active sale products |
| `/wp-json/wc/v3/product-info/{slug}` | GET | Product SEO metadata |
| `/wp-json/wc/v3/products-info?slugs=a,b` | GET | Batch product SEO |
| `/wp-json/wc/v3/category-info/{slug}` | GET | Category SEO metadata |
| `/wp-json/wc/v3/categories-info?slugs=a,b` | GET | Batch category SEO |
| `/wp-json/custom/v1/register-vendor` | POST | Register Dokan vendor |
| `/wp-json/custom/v1/check-email` | GET | Check email availability |
| `/wp-json/custom/v1/check-shop-url` | GET | Check shop slug availability |
| `/wp-json/wc/v3/products/by-category/{slug}` | GET | Products by category |
| `/wp-json/wc/v3/products/by-tag/{slug}` | GET | Products by tag |

## Admin Settings

After activation, go to **CustomEnd Harriet** in the admin menu to see a dashboard listing all registered endpoints with their full URLs and status.

## Features

- Response caching with automatic invalidation
- Pagination with `X-WP-Total` and `X-WP-Pages` headers
- Price and stock filtering
- Multivendor support (Dokan)
- Rank Math SEO integration
- Variable product price normalization
- Batch endpoints (up to 20 items)

## Author

**Aqeel Husny**
- GitHub: [CustomEnd-Harriet](https://github.com/AqeelHusny/CustomEnd-Harriet)

## License

GPL-2.0+
