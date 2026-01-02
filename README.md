# MageZero Cloudflare R2 Storage for Magento 2

Stateless Magento 2 module for Cloudflare R2 media storage. Designed for containerized deployments where images are served directly from R2/CDN without local filesystem writes.

## Compatibility

### Requirements

- **PHP**: 8.1, 8.2, 8.3, or 8.4
- **Magento**: 2.4.6 or later

### Tested Versions

| Magento | PHP | PHPStan | Unit Tests | Integration Tests |
|---------|-----|---------|------------|-------------------|
| 2.4.6-p9 | 8.1 | - | ✓ | ✓ |
| 2.4.7-p4 | 8.2 | - | ✓ | ✓ |
| 2.4.8-p3 | 8.3 | ✓ | ✓ | ✓ |
| 2.4.8-p3 | 8.4 | ✓ | ✓ | - |

Unit tests run on all PHP versions to ensure syntax compatibility.
PHPStan runs on PHP 8.3 and 8.4 for static analysis.
Integration tests run once per Magento version to verify R2 functionality.

## Features
- **Stateless/CDN-First Architecture** - Images served directly from R2/CDN, no local filesystem writes
- **Automatic On-Demand Generation** - Missing image sizes generated transparently when requested
- **Pre-Generation Support** - CLI command to generate all image sizes before deployment
- **Redis Caching** - CDN file existence checks cached for optimal performance
- **Docker/Kubernetes Ready** - Designed for ephemeral containers with read-only filesystems
- Handles product images, WYSIWYG media, and swatch generation in `/tmp`
- S3-compatible API with Cloudflare R2 optimizations

## Installation
Install with Composer in your Magento project:

```bash
composer require magezero/magento2-cloudflare-r2-storage
bin/magento module:enable MageZero_CloudflareR2
bin/magento setup:upgrade
bin/magento cache:flush
```

## Configuration

1. **Configure R2 Connection**:
   - Go to **Stores > Configuration > Advanced > System > Media Storage Configuration**
   - Set **Media Storage** to **Cloudflare R2 (S3 Compatible)**

2. **Configure R2 Credentials**:
   - Go to **Stores > Configuration > MageZero > Cloudflare R2 Storage**
   - Fill in:
     - Account ID (optional if you provide endpoint)
     - Endpoint (e.g. `https://<account-id>.r2.cloudflarestorage.com`)
     - Region (use `auto` for R2)
     - Bucket
     - Access Key ID / Secret Access Key
     - Optional key prefix (e.g. `media`)

3. **Configure CDN/Public URL** (required):
   - Set **Base Media URL (Secure)** to your R2 public domain or Cloudflare CDN URL
   - Example: `https://media.example.com` or `https://pub-xxxxx.r2.dev`
   - Optionally set **Base Media URL (Unsecure)** if needed

4. **Configure Caching** (optional):
   - Adjust **File Existence Cache TTL** (default: 3600 seconds)
   - This caches CDN HEAD requests in Redis for optimal performance

5. **Configure Cloudflare R2**:
   - Set your R2 bucket to **Public** or configure a [custom domain](https://developers.cloudflare.com/r2/buckets/public-buckets/)
   - Enable Cloudflare CDN caching for best performance

**How it works:**
- Images are served directly from R2/CDN (no local filesystem writes)
- File existence checks use CDN HEAD requests (cached in Redis)
- Image processing (swatches, resizes) happens in `/tmp` and uploads directly to R2
- Truly stateless - `pub/media` is never written to (except `/tmp`)

**Requirements:**
- Base Media URL must be configured
- Redis or similar cache backend recommended for file existence caching
- `/tmp` directory must be writable (standard in containers)
- R2 bucket must be public or have custom domain configured

### Product Image Resizing

This module supports three approaches for product image resizing:

1. **Pre-generation (Recommended)**: Run `bin/magento catalog:images:resize` before deployment to generate all image sizes. Images are processed in `/tmp` and uploaded directly to R2.

2. **Automatic On-Demand Generation**: When image URLs are generated in templates, missing sizes are automatically created:
   - Template requests image URL (e.g., product listing, product page)
   - Module checks if image exists in CDN (with Redis caching)
   - If missing, downloads original from R2 → `/tmp`
   - Resizes in `/tmp`
   - Uploads to R2
   - Returns URL immediately
   - Subsequent requests served directly from CDN

   This happens transparently - no code changes needed in templates.

3. **Manual On-Demand Endpoint**: Fallback controller endpoint (`/magezero_r2/media/resize`) for edge cases where automatic generation doesn't trigger.

**Best Practice**: Pre-generate during deployment, rely on automatic on-demand as a seamless fallback for any edge cases.

## Notes
- This module is designed for stateless/containerized deployments. The local filesystem (`pub/media`) is never written to except `/tmp`.
- All media files are served directly from R2/CDN - no local sync or downloads.
- The module uses path-style endpoints by default, which is recommended for R2.
- Images generated via `bin/magento catalog:images:resize` are processed in `/tmp` and uploaded directly to R2.
- The **Flush Catalog Images Cache** action clears cached resized images in R2.

## License
OSL-3.0

## Credits
- AWS SDK for PHP (S3 compatible client)
- [extdn/github-actions-m2](https://github.com/extdn/github-actions-m2) (CI workflows)
