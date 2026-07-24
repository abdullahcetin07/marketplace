# Media

`spatie/laravel-medialibrary`, wrapped by `App\Shared\Traits\HasMedia` to fix
the platform conventions in one place.

---

## Scope note

Sprint 0 was scoped to create no tables beyond users and permissions. The
`media` table is included as **infrastructure**, not as a business table: the
`HasMedia` trait is part of the required foundation and is inert without it, and
no business module can define its media collections until the backing store
exists. It holds no domain data of its own.

Flagged here rather than buried, so it can be reversed if you disagree.

---

## Two disks

| Disk | Bucket | Visibility | Holds |
|---|---|---|---|
| `s3` | public | public, CDN-fronted | Product images, store logos |
| `s3-private` | **separate bucket** | private | Tax certificates, ID scans, invoices |

Separate *bucket*, not a prefix on the public one. A bucket policy
misconfiguration should not be able to expose identity documents. Private files
are reachable only through short-lived signed URLs
(`marketplace.media.signed_url_ttl`, 300 s).

The application never writes user content to local disk — it does not survive a
container restart, cannot be shared between instances, and turns "scale out"
into "lose half the images". The `local` disk is for ephemeral scratch work
(a CSV being parsed, a PDF awaiting upload) only.

---

## Usage

```php
final class Product extends Model implements \Spatie\MediaLibrary\HasMedia
{
    use \App\Shared\Traits\HasMedia;
}
```

Note the interface and the trait share a short name. Import the **interface**
with its FQCN, as above, so the distinction stays obvious.

```php
$product->addMedia($file)->toMediaCollection('images');

$product->imageUrl();          // first image, 'preview' conversion, or null
$product->imageUrl('thumb');
$product->imageGallery();      // array for the Next.js frontend
$product->hasImages();
```

`imageUrl()` never throws on an empty collection and falls back to the original
if the requested conversion has not been generated yet.

---

## Collections

| Collection | Disk | Accepts | Notes |
|---|---|---|---|
| `images` | public | jpeg, png, webp, avif | Ordered gallery, responsive images |
| `documents` | private | above + pdf | Single file |

A module extends these by calling `parent::registerMediaCollections()` first.

---

## Conversions

| Name | Size | Format |
|---|---|---|
| `thumb` | 160×160 contain | webp |
| `preview` | 480×480 contain | webp |
| `large` | 1200×1200 contain | webp |

All WebP: materially smaller than JPEG at equivalent quality, and universally
supported now.

Conversions are **queued** (the `media` queue) so an upload request never blocks
on image processing. See [queues.md](queues.md) for why that queue gets few
workers and the lowest priority.

---

## Limits

10 MB per file (`HasMedia::maxUploadSize()`), backed by PHP's
`upload_max_filesize = 20M` and nginx's `client_max_body_size 24M`.

The layering is deliberate: nginx and PHP reject oversized requests before they
reach the application; the trait is the storage-layer backstop; the HTTP request
rules should also validate size so the user gets a proper validation error
rather than a 413.

---

## Local development

MinIO runs in `docker-compose.yml` at http://localhost:9001 (console). Using a
real S3 API locally means S3-specific bugs surface in development rather than in
staging.

Create the buckets on first run — `marketplaceos` and `marketplaceos-private`.
