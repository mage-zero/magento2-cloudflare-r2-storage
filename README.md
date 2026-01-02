# MageZero Cloudflare R2 Storage for Magento 2

Magento 2 module that adds Cloudflare R2 as a media storage backend using the S3 compatible API.

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
- Adds a "Cloudflare R2 (S3 Compatible)" option to media storage configuration.
- Uploads media files to R2 on save/synchronize.
- Restores media files from R2 when required by Magento.
- Handles WYSIWYG thumbnails and swatch images while using remote storage.
- **Read-Only Filesystem Mode** - Serve images directly from R2/CDN without local filesystem writes (ideal for containerized/stateless deployments).

## Installation
Install with Composer in your Magento project:

```bash
composer require magezero/magento2-cloudflare-r2-storage
bin/magento module:enable MageZero_CloudflareR2
bin/magento setup:upgrade
bin/magento cache:flush
```

## Configuration

### Basic Configuration
1. Go to **Stores > Configuration > Advanced > System > Media Storage Configuration** and set **Media Storage** to **Cloudflare R2 (S3 Compatible)**.
2. Go to **Stores > Configuration > MageZero > Cloudflare R2 Storage** and fill in:
   - Account ID (optional if you provide endpoint)
   - Endpoint (e.g. https://<account-id>.r2.cloudflarestorage.com)
   - Region (use `auto` for R2)
   - Bucket
   - Access Key ID / Secret Access Key
   - Optional key prefix (e.g. `media`)
   - **Base Media URL (unsecure/secure)** - Set this to your R2 public domain or CDN URL
3. Save config and run **Synchronize** from Media Storage Configuration if you want to push existing media to R2.

### Read-Only Filesystem Mode (Optional)

For Docker/containerized deployments where `pub/media` is mounted read-only:

1. **Configure Base Media URL** (required):
   - Set **Base Media URL (Secure)** to your R2 public domain or Cloudflare CDN URL
   - Example: `https://media.example.com` or `https://pub-xxxxx.r2.dev`

2. **Enable Read-Only Mode**:
   - Go to **Stores > Configuration > MageZero > Cloudflare R2 Storage**
   - Set **Read-Only Filesystem Mode** to **Yes**
   - Optionally adjust **File Existence Cache TTL** (default: 3600 seconds)

3. **Configure Cloudflare R2**:
   - Set your R2 bucket to **Public** or configure a [custom domain](https://developers.cloudflare.com/r2/buckets/public-buckets/)
   - Enable Cloudflare CDN caching for optimal performance

**How it works:**
- Images are served directly from R2/CDN (no local downloads)
- File existence checks use CDN HEAD requests (cached in Redis)
- Image processing (swatches, resizes) happens in `/tmp` and uploads directly to R2
- Truly stateless - `pub/media` never written to (except `/tmp`)

**Requirements:**
- Base Media URL must be configured
- Redis or similar cache backend recommended for file existence caching
- `/tmp` directory must be writable (standard in containers)

**Product Image Resizing:**

Read-only mode supports two approaches for product image resizing:

1. **Pre-generation (Recommended)**: Run `bin/magento catalog:images:resize` before deployment to generate all image sizes in R2. Images are processed in `/tmp` and uploaded directly.

2. **On-demand Resizing**: Missing image sizes are generated automatically on first request:
   - User requests missing size
   - Module downloads original from R2 → `/tmp`
   - Resizes in `/tmp`
   - Uploads to R2
   - Redirects to CDN URL
   - Subsequent requests served directly from CDN (cached)

**Best Practice**: Pre-generate during deployment, rely on on-demand as fallback for edge cases.

## Notes
- The module uses path-style endpoints by default, which is recommended for R2.
- Resized images generated via `bin/magento catalog:images:resize` are synced to R2 when Cloudflare R2 is the active media storage.
- The **Flush Catalog Images Cache** action also clears cached resized images in R2.

## License
OSL-3.0

## Credits
- AWS SDK for PHP (S3 compatible client)
- [extdn/github-actions-m2](https://github.com/extdn/github-actions-m2) (CI workflows)
