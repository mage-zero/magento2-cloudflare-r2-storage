# MageZero Cloudflare R2 Storage for Magento 2

Magento 2 module for Cloudflare R2 media storage. Designed for containerized deployments where media is stored in R2 and served via CDN.

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
- **R2 as Media Storage Backend** - All media files stored in Cloudflare R2
- **CDN-First Serving** - Images served directly from R2/CDN
- **Storage-Only Focus** - Module handles storage; Magento core handles image processing
- **Redis Caching** - CDN file existence checks cached for optimal performance
- **Docker/Kubernetes Ready** - Works with ephemeral containers using tmpfs mounts
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

## Container Deployment

For containerized deployments (Docker Swarm, Kubernetes), mount a writable tmpfs on `pub/media`:

**Docker Compose / Swarm:**
```yaml
services:
  php-fpm:
    volumes:
      - type: tmpfs
        target: /var/www/html/pub/media
```

**Kubernetes:**
```yaml
volumes:
  - name: media-cache
    emptyDir: {}
volumeMounts:
  - name: media-cache
    mountPath: /var/www/html/pub/media
```

**How it works:**
1. Magento core writes to `pub/media` (tmpfs - writable, ephemeral)
2. Module syncs files to R2 for persistent storage
3. Images served from R2/CDN
4. On container restart, cache regenerates as needed

## Product Image Resizing

**Pre-generation (Recommended for production):**
```bash
bin/magento catalog:images:resize
```

This generates all product image sizes using Magento core, then syncs them to R2.

**On-demand generation:**
When a requested image size doesn't exist in R2, Magento core generates it to the local tmpfs, and subsequent requests are served from R2/CDN once synced.

## Architecture

This module focuses solely on **storage operations**:
- Uploading files to R2
- Downloading files from R2
- Syncing locally generated cache to R2
- File existence checks (cached in Redis)

All image processing (resizing, watermarks, swatch generation) is handled by **Magento core**. The module intercepts storage operations, not image manipulation.

## Notes
- The module uses path-style endpoints by default, which is recommended for R2.
- The **Flush Catalog Images Cache** action clears cached resized images in R2.
- Redis or similar cache backend recommended for file existence caching.
- R2 bucket must be public or have custom domain configured.

## License
OSL-3.0

## Credits
- AWS SDK for PHP (S3 compatible client)
- [extdn/github-actions-m2](https://github.com/extdn/github-actions-m2) (CI workflows)
