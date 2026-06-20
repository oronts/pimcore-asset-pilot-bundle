[← Documentation index](index.md) · [Project README](../README.md)

# Asset downloads (zip)

Gather assets that belong together and download them jointly as a single zip archive: the
content-manager "cart". The archive layout is pluggable, image assets can be packed as a named
thumbnail, and the whole thing is a standalone, reusable service so you can build archives from your
own code without touching the REST/UI layer.

## REST endpoint

```
POST /pimcore-studio/api/asset-pilot/assets/download-zip      (permission: asset_pilot_view)
```

Body:

| Field | Type | Description |
|-------|------|-------------|
| `assetIds` | int[] | Assets to pack (validated + bounded like every bulk endpoint; max 1000 ids per request). |
| `strategy` | string? | Archive layout: `flat` (default), `folder`, `type`, or a custom strategy name. |
| `thumbnail` | string? | For image assets, a Pimcore thumbnail config name to pack instead of the original. |

Responses: `200` streams the `application/zip` (filename `assets.zip`); `422` when none of the selected
assets are downloadable (all missing, folders, or outside the user's workspace); `400` on invalid JSON
or id list. Every asset is re-checked against the caller's Pimcore workspace ACL (`view`) before it is
added, so a selection can never leak an asset the user may not see.

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

`AssetZipService` is a normal service; build archives from a command, an event subscriber, or your own
controller. Three sources are supported:

```php
use Oronts\AssetPilotBundle\Service\AssetZipService;
use Oronts\AssetPilotBundle\Zip\ZipBuildOptions;

// Specific assets
$result = $zipService->buildFromAssetIds([12, 34, 56], new ZipBuildOptions(strategy: 'folder'));

// Every asset under a folder (recursive by default)
$result = $zipService->buildFromFolder($folderId, recursive: true, options: new ZipBuildOptions(thumbnail: 'web'));

// Every asset referenced by data objects (across relations, bricks, field collections, localized + block fields)
$result = $zipService->buildFromObjects([100, 101]);

// $result = ['path' => '/.../scratch.zip' | null, 'added' => int, 'skipped' => int]
```

Stream `$result['path']` (e.g. a `BinaryFileResponse` with `deleteFileAfterSend(true)`) and it is
cleaned up after the response.

## Configuration

```yaml
oronts_asset_pilot:
    zip:
        default_strategy: flat   # flat | folder | type | <custom strategy name>
        max_assets: 1000         # hard cap on assets packed into one archive
```

## Storage model and scaling (multiple pods / workers)

- **Reading is storage-adapter agnostic.** Assets are read through Pimcore's `getLocalFile()`, so the
  source binaries can live on any configured asset storage (local, S3, …) — they are pulled from
  wherever they are.
- **A synchronous download is multi-pod safe.** The archive is built into a local scratch file on the
  handling pod and streamed in the *same* request, then deleted. One pod builds and serves, so there is
  no cross-pod artifact to lose. (This is deliberately not written to a separate "download storage":
  that pattern only works when a shared adapter is configured and otherwise silently breaks across pods.)
- **Bounded by `max_assets`** to keep a request responsive. For very large sets, call
  `AssetZipService` from a CLI command or a Messenger worker; because the service is plain PHP with no
  HTTP coupling, it runs unchanged outside a request. If you serve such an archive from a *different*
  pod than the one that built it, write the returned file to a shared store you control and stream it
  from there.
