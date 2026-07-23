[← Documentation index](index.md) · [Project README](../README.md)

# Asset downloads (zip)

Gather assets that belong together and download them jointly as a single zip archive: the
content-manager "cart". The archive layout is pluggable, image assets can be packed as a named
thumbnail, and the whole thing is a standalone, reusable service so you can build archives from your
own code without touching the REST/UI layer.

## REST endpoint

```
POST {studio_backend_prefix}/asset-pilot/assets/download-zip  (permission: asset_pilot_view)
```

Body:

| Field | Type | Description |
|-------|------|-------------|
| `assetIds` | int[] | Assets to pack. Hard-capped at 1000 ids per request (`400` beyond that), independent of `zip.max_assets`; raising `zip.max_assets` past 1000 lifts only the folder and object sources, which resolve their assets internally. |
| `strategy` | string? | Archive layout: `flat` (default), `folder`, `type`, or a custom strategy name. |
| `thumbnail` | string? | For image assets, a Pimcore thumbnail config name to pack instead of the original. |

Responses: `200` streams the `application/zip` (filename `assets.zip`); `422` when the archive cannot be produced: none of the selected
assets are downloadable (all missing, folders, or outside the user's workspace), the selection exceeds
`zip.max_assets` or `zip.max_uncompressed_bytes`, or an unknown `strategy` name was supplied; `400` on
invalid JSON or id list. Every asset is re-checked against the caller's Pimcore workspace ACL (`view`)
before it is added, so a selection can never leak an asset the user may not see.

The `200` response carries four build-contract headers: `X-Asset-Pilot-Requested`, `X-Asset-Pilot-Added`,
and `X-Asset-Pilot-Skipped` (the requested / added / skipped asset counts), plus `X-Asset-Pilot-Truncated`
(`true`/`false`, whether the archive is a partial build).

Studio uses a two-step native download: `POST /assets/download-zip/prepare` stores the validated plan
under a random, short-lived, single-use token bound to the current user, then a browser navigation to
`GET /assets/download-zip/{token}` builds and streams the archive. The plan lives in shared
`cache.app`, so the two requests may reach different pods. The archive response is never buffered in
a JavaScript `Blob`. The direct POST endpoint remains available for API clients that stream responses.

## Layout strategies

The in-archive path of each asset is decided by a `ZipEntryStrategyInterface`. Three are built in:

| Name | Layout | Example entry |
|------|--------|---------------|
| `flat` | all files at the root | `cover.jpg` |
| `folder` | mirrors the Pimcore asset folder tree | `Products/2024/cover.jpg` |
| `type` | one folder per asset type | `image/cover.jpg`, `document/spec.pdf` |

Colliding entry names are de-duplicated (`cover.jpg`, `cover-2.jpg`, …). Set the default with
`oronts_asset_pilot.zip.default_strategy`; override per request with the `strategy` field.

### Custom layout

Implement `ZipEntryStrategyInterface` and the bundle auto-tags it (container-wide
`registerForAutoconfiguration`, so `autoconfigure: true` is enough):

```php
namespace App\AssetPilot;

use Oronts\AssetPilotBundle\Zip\ZipEntryStrategyInterface;
use Pimcore\Model\Asset;

class BySkuZipStrategy implements ZipEntryStrategyInterface
{
    public function getName(): string
    {
        return 'by-sku';
    }

    public function entryPath(Asset $asset): string
    {
        return ($asset->getProperty('sku') ?: 'misc') . '/' . $asset->getFilename();
    }
}
```

Then select it with `strategy: by-sku` (request) or `zip.default_strategy: by-sku` (config).

## Thumbnails

Pass `thumbnail: <config-name>` to pack the named Pimcore thumbnail of each image instead of the
original (the entry extension follows the thumbnail format). Non-image assets and any image whose
thumbnail cannot be produced fall back to the original — the archive never ends up empty because of a
thumbnail problem.

## Programmatic use

`AssetZipServiceInterface` is the public service alias; build archives from a command, an event subscriber, or your own
controller. Three sources are supported:

```php
use Oronts\AssetPilotBundle\Service\AssetZipServiceInterface;
use Oronts\AssetPilotBundle\Zip\ZipBuildOptions;

// Specific assets
$result = $zipService->buildFromAssetIds([12, 34, 56], new ZipBuildOptions(strategy: 'folder'));

// Every asset under a folder (recursive by default)
$result = $zipService->buildFromFolder($folderId, recursive: true, options: new ZipBuildOptions(thumbnail: 'web'));

// Every asset referenced by data objects (across relations, bricks, field collections, localized + block fields)
$result = $zipService->buildFromObjects([100, 101]);

// $result is a readonly ZipBuildResult with path, requested, added, skipped, and truncated.
```

When `$result->hasArchive()` is true, stream `$result->path` (for example with a
`BinaryFileResponse` using `deleteFileAfterSend(true)`). The result constructor validates all counters
and path consistency, so custom `AssetZipServiceInterface` implementations cannot return partial or
contradictory metadata. The scratch archive is cleaned up after the response.

## CLI (non-blocking / cron / workers)

For large sets or scheduled exports, build the archive off the request with `asset-pilot:download-zip`
(same strategies and thumbnail options). The result is written to `--output`:

```bash
bin/console asset-pilot:download-zip --asset-ids=12,34,56 --strategy=folder --output=/exports/sel.zip
bin/console asset-pilot:download-zip --folder-id=42 --thumbnail=web --output=/exports/folder.zip
bin/console asset-pilot:download-zip --object-ids=100,101 --output=/exports/products.zip

# Pack only a folder's direct children (folder packing is recursive by default)
bin/console asset-pilot:download-zip --folder-id=42 --non-recursive --output=/exports/top.zip
```

Existing output files are preserved unless `--force` is supplied. Both asset count and total
uncompressed source bytes are bounded by the `zip` configuration. `--folder-id` packs the folder
subtree recursively; add `--non-recursive` to pack only its direct children.

Provide exactly one source (`--asset-ids` / `--folder-id` / `--object-ids`). For a multi-pod download
of a pre-built archive, write `--output` to a shared volume, or create a Pimcore asset from the file
so it is served through the configured (shared) asset storage adapter.

## Configuration

```yaml
oronts_asset_pilot:
    zip:
        default_strategy: flat   # flat | folder | type | <custom strategy name>
        max_assets: 1000         # hard cap on assets packed into one archive
        max_uncompressed_bytes: 536870912  # total source-byte cap before ZIP compression
        download_token_ttl: 300  # user-bound native browser download token lifetime
```

## Storage model and scaling (multiple pods / workers)

- **Reading is storage-adapter agnostic.** Assets are read through Pimcore's `getLocalFile()`, so the
  source binaries can live on any configured asset storage (local, S3, …) — they are pulled from
  wherever they are.
- **A synchronous download is multi-pod safe.** The archive is built into a local scratch file on the
  handling pod and streamed in the *same* request, then deleted. One pod builds and serves, so there is
  no cross-pod artifact to lose. (This is deliberately not written to a separate "download storage":
  that pattern only works when a shared adapter is configured and otherwise silently breaks across pods.)
- **Bounded by `max_assets` and `max_uncompressed_bytes`** to keep a request responsive. For very
  large sets, call `AssetZipServiceInterface` from a CLI command or a Messenger worker; because the service is
  plain PHP with no HTTP coupling, it runs unchanged outside a request. If you serve such an archive
  from a *different* pod than the one that built it, write the returned file to a shared store you
  control and stream it from there.
