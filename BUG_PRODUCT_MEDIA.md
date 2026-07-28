# Bug — product image upload throws FileDoesNotExist (Catalog seller panel)

**Disposable. Delete when fixed.** For the server-side session (can run the live upload).

## Symptom
Seller edits a product, uploads an image, saves → 500:
`Spatie\MediaLibrary\...\FileDoesNotExist: File "01KYM...webp" does not exist`
at `app/Modules/Catalog/Application/Actions/AttachProductMediaAction.php:47`
(`->toMediaCollection('images')`), from `.../Seller/Resources/ProductResource/Pages/
EditProduct.php:68`.

## Root cause
`EditProduct` uses a Filament `FileUpload::make('files')` (multiple, image). After the
upload, `$data['files']` holds **disk-relative path strings** (Filament stored the files
on the default filesystem disk — currently `FILESYSTEM_DISK=local` → `storage/app/private`).
`AttachProductMediaAction` then calls `$product->addMedia($file)` where `$file` is that
string. Spatie's `addMedia(string)` expects an **absolute filesystem path**; given a
disk-relative name it looks in the wrong place and throws FileDoesNotExist.

Note the `images` collection itself is configured (in `App\Shared\Traits\HasMedia`) on the
PUBLIC disk (`config('marketplace.media.public_disk')` = `public` here) — a different disk
from where Filament put the temp upload. So it is genuinely a cross-disk copy.

## Why the tests missed it
The Livewire/feature test passes `UploadedFile::fake()` (an object) to the action —
`addMedia(UploadedFile)` works. The LIVE flow passes a **stored path string**, which does
not. The test must exercise the string-path path too.

## Fix (pick the cleanest, then verify the real upload works)
The action must accept both an `UploadedFile` (object) AND a disk-relative path string:
- For a string, use `addMediaFromDisk($path, $disk)` (Spatie copies from that disk) rather
  than `addMedia($path)`. Resolve `$disk` explicitly — make `EditProduct`'s `FileUpload`
  state its disk (e.g. `->disk(config('filament.default_filesystem_disk'))` or a dedicated
  one) and pass that same disk into the action, so the two never disagree. Do NOT hardcode
  a path.
- For an `UploadedFile`, keep `addMedia($file)`.
- Reconcile `preservingOriginal()`: with `addMediaFromDisk`, Spatie copies; the Filament
  temp file should then be cleaned up (Filament usually handles its own temp cleanup, but
  confirm no orphan is left on the local disk). Do not preserve the Livewire temp file
  forever.
- Keep the disk / MIME allow-list / size cap decisions where they already live
  (`HasMedia`), per the action's docblock — this fix is only about the path/disk plumbing.
- **Check the CreateProduct path too**: if product creation attaches images the same way,
  it has the same bug — fix both, or route both through one corrected helper.

Consider whether Filament's dedicated `SpatieMediaLibraryFileUpload` component would remove
this whole class of bug (it wires upload→collection directly). If you switch to it, keep
the collection/disk config in `HasMedia` and update the test. Either approach is fine; the
requirement is that a real uploaded image lands in the `images` collection with no
FileDoesNotExist and is visible afterwards.

## Verify
1. Live: as the seller, edit product (the real one, id 2 / uuid 5a0463ca-…), upload a
   real image, save → no 500, image attached and shown; and do the same on **create**.
2. Test: a feature/Livewire test that drives the action with a STORED PATH (not just an
   UploadedFile) — e.g. put a fake file on the disk, pass its path, assert it attaches to
   `images`. Mirror the live flow so this can't regress silently.
3. `php artisan test` → 0 failed.

## Finish
Commit (scoped to the media fix + test), push `origin main` (human pushes — no creds on
the box). `git rm BUG_PRODUCT_MEDIA.md`, commit. Report the test line + confirm the live
upload worked.
