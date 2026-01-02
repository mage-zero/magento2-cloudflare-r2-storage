# MageZero Cloudflare R2 Storage for Magento 2

Magento 2 module that adds Cloudflare R2 as a media storage backend using the S3 compatible API.

## Supported Magento Versions

Requires Magento Open Source 2.4.6 or later.

### Tested Versions

| Magento Version | PHP Version | Unit Tests | Integration Tests |
|-----------------|-------------|------------|-------------------|
| 2.4.6-p9        | 8.1         | ✓          | ✓                 |
| 2.4.7-p4        | 8.2         | ✓          | ✓                 |
| 2.4.8-p3        | 8.3         | ✓          | ✓                 |
| 2.4.8-p3        | 8.4         | ✓          | ✓                 |

## Features
- Adds a "Cloudflare R2 (S3 Compatible)" option to media storage configuration.
- Uploads media files to R2 on save/synchronize.
- Restores media files from R2 when required by Magento.
- Handles WYSIWYG thumbnails and swatch images while using remote storage.

## Installation
Install with Composer in your Magento project:

```bash
composer require magezero/magento2-cloudflare-r2-storage
bin/magento module:enable MageZero_CloudflareR2
bin/magento setup:upgrade
bin/magento cache:flush
```

## Configuration
1. Go to **Stores > Configuration > Advanced > System > Media Storage Configuration** and set **Media Storage** to **Cloudflare R2 (S3 Compatible)**.
2. Go to **Stores > Configuration > MageZero > Cloudflare R2 Storage** and fill in:
   - Account ID (optional if you provide endpoint)
   - Endpoint (e.g. https://<account-id>.r2.cloudflarestorage.com)
   - Region (use `auto` for R2)
   - Bucket
   - Access Key ID / Secret Access Key
   - Optional key prefix (e.g. `media`)
   - Base Media URL (unsecure/secure) if you want Magento to serve media from an R2 public domain or CDN
3. Save config and run **Synchronize** from Media Storage Configuration if you want to push existing media to R2.

## Notes
- The module uses path-style endpoints by default, which is recommended for R2.
- Resized images generated via `bin/magento catalog:images:resize` are synced to R2 when Cloudflare R2 is the active media storage.
- The **Flush Catalog Images Cache** action also clears cached resized images in R2.

## License
OSL-3.0

## Credits
- AWS SDK for PHP (S3 compatible client)
- [extdn/github-actions-m2](https://github.com/extdn/github-actions-m2) (CI workflows)
