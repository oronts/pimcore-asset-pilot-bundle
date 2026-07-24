[← Documentation index](index.md) · [Project README](../README.md)

# Configuration Scenarios

### E-commerce: Product Assets by Item Number

Organize product images and documents into folders named by item number. Localized documents (datasheets, manuals) get a locale subfolder.

```yaml
oronts_asset_pilot:
    rules:
        product_images:
            class: Product
            fields: [images, galleryImages, thumbnails]
            condition: 'object.getItemNumber() != null'
            target_path: '/Products/{{ object.getItemNumber() }}/Images'
            strategy: always
            priority: 100
            filters:
                types: [image]
                extensions: [jpg, png, webp]
                max_size: 52428800

        product_documents:
            class: Product
            fields: [datasheet, manual, brochure]
            condition: 'object.getItemNumber() != null'
            target_path: '/Products/{{ object.getItemNumber() }}/Documents{{ locale ? "/" ~ locale : "" }}'
            strategy: always
            priority: 80

        product_videos:
            class: Product
            fields: [productVideo, tutorialVideo]
            target_path: '/Products/{{ object.getItemNumber() }}/Media'
            strategy: always
            priority: 60
            filters:
                types: [video]
```

Resulting folder structure:

```
/Products/
├── ART-10042/
│   ├── Images/
│   │   ├── product-front.jpg
│   │   └── product-back.png
│   ├── Documents/
│   │   ├── en/
│   │   │   └── datasheet-en.pdf
│   │   └── de/
│   │       └── datasheet-de.pdf
│   └── Media/
│       └── tutorial.mp4
└── ART-10043/
    └── ...
```

### Category-Based Hierarchy

Organize assets into folders derived from the object's category relation. Uses `first_of` to grab the category key, with a fallback for uncategorized products.

```yaml
oronts_asset_pilot:
    rules:
        category_images:
            class: Product
            fields: [images]
            target_path: >-
                /Catalog/{{ object.getCategories()|first_of("key", "Uncategorized") }}/{{ object.getItemNumber()|fallback("unknown") }}/Images
            strategy: always
            priority: 100
            filters:
                types: [image]

        category_documents:
            class: Product
            fields: [datasheet, certificate]
            target_path: >-
                /Catalog/{{ object.getCategories()|first_of("key", "Uncategorized") }}/{{ object.getItemNumber()|fallback("unknown") }}/Docs{{ locale ? "/" ~ locale : "" }}
            strategy: always
            priority: 80
```

Resulting folder structure:

```
/Catalog/
├── Electronics/
│   ├── ART-10042/
│   │   ├── Images/
│   │   └── Docs/
│   │       ├── en/
│   │       └── de/
│   └── ART-10043/
│       └── ...
├── Furniture/
│   └── ...
└── Uncategorized/
    └── ...
```

### Multi-Class Setup

Apply rules to different DataObject classes. Use the `*` wildcard to create a catch-all rule for any class that doesn't have a specific rule.

```yaml
oronts_asset_pilot:
    rules:
        product_assets:
            class: Product
            target_path: '/Products/{{ object.getItemNumber()|fallback(object.getKey()) }}/Assets'
            strategy: always
            priority: 100
            filters:
                types: [image, document]

        category_banners:
            class: Category
            fields: [bannerImage, icon]
            target_path: '/Categories/{{ object.getKey() }}'
            strategy: first_assignment
            priority: 90
            filters:
                types: [image]

        brand_logos:
            class: Brand
            fields: [logo, headerImage]
            target_path: '/Brands/{{ object.getName()|slug }}'
            strategy: first_assignment
            priority: 80

        catch_all:
            class: '*'
            target_path: '/Assets/{{ className }}/{{ object.getKey()|safe_key }}'
            strategy: always
            priority: 1
```

### First Assignment Strategy

Use `first_assignment` when assets should only be organized on first save. Once moved, they stay put even if the object is updated. Useful for QR codes, generated certificates, or any asset that should not be relocated after initial placement.

```yaml
oronts_asset_pilot:
    rules:
        generated_qrcode:
            class: Product
            fields: [qrCode]
            target_path: '/Products/{{ object.getItemNumber() }}/QR{{ locale ? "/" ~ locale : "" }}'
            strategy: first_assignment
            priority: 50
            filters:
                types: [image]

        certificates:
            class: Product
            fields: [certificate, testReport]
            target_path: '/Products/{{ object.getItemNumber() }}/Certificates'
            strategy: first_assignment
            priority: 40
            filters:
                types: [document]
                extensions: [pdf]
```

### Callback Strategy with Custom Logic

Delegate the move decision to a custom service. The service receives the asset, object, rule, and a `dryRun` flag, and returns `true` to proceed or `false` to skip.

```yaml
oronts_asset_pilot:
    rules:
        conditional_move:
            class: Product
            target_path: '/Products/{{ object.getItemNumber() }}/Images'
            strategy: callback
            callback: App\AssetPilot\Strategy\ApprovalDecision
            priority: 100
```

```php
// src/AssetPilot/Strategy/ApprovalDecision.php
declare(strict_types=1);

namespace App\AssetPilot\Strategy;

use Oronts\AssetPilotBundle\Model\Rule;
use Oronts\AssetPilotBundle\Strategy\CallbackDecisionInterface;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;

class ApprovalDecision implements CallbackDecisionInterface
{
    public function decide(Asset $asset, AbstractObject $object, Rule $rule, bool $dryRun): bool
    {
        // Only move assets for published objects
        if ($object instanceof Concrete && !$object->isPublished()) {
            return false;
        }

        // Only move during business hours
        $hour = (int) date('H');
        return $hour >= 8 && $hour < 18;
    }
}
```

The callback runs during both preview and apply. Keep it query-only and use `$dryRun` to suppress
live-only diagnostics or integrations. Saving Pimcore elements or sending external work from this
method violates the preview contract.

The interface is auto-tagged, so `CallbackStrategy` resolves it from its scoped locator by id:

```yaml
services:
    App\AssetPilot\Strategy\ApprovalDecision: ~
```

### Synchronous Organization

Disable async organization to move assets immediately during the save request. This is suitable for
development environments or small catalogs where moves are fast. The `asset_pilot` and
`pimcore_maintenance` consumers are still required for durable operation events, rule actions, and
database outbox polling.

```yaml
oronts_asset_pilot:
    async:
        enabled: false

    rules:
        product_images:
            class: Product
            target_path: '/Products/{{ object.getKey() }}/Images'
            strategy: always
            priority: 10
```

### Asset Protection

Lock specific folders from automation and configure the lock property name.

```yaml
oronts_asset_pilot:
    protection:
        exclude_folders:
            - /Protected/
            - /Manual/
            - /Brand-Assets/
        lock_property: asset_pilot_locked
```

Assets in excluded folders are never processed. Individual assets can be locked/unlocked via the API (`POST /assets/{id}/lock`, `DELETE /assets/{id}/lock`) or the Studio UI.

### Date-Based Organization

Organize uploads by year and month. Useful for editorial content, blog posts, or any time-based content.

```yaml
oronts_asset_pilot:
    rules:
        blog_images:
            class: BlogPost
            fields: [heroImage, contentImages]
            target_path: '/Blog/{{ date.format("Y") }}/{{ date.format("m") }}/{{ object.getKey()|slug }}'
            strategy: always
            priority: 100
            filters:
                types: [image]

        news_attachments:
            class: NewsArticle
            target_path: '/News/{{ date.format("Y/m/d") }}/{{ object.getKey()|slug }}'
            strategy: always
            priority: 90
```

### Restrictive Filters

Combine type, extension, and size filters to tightly control which assets a rule processes.

```yaml
oronts_asset_pilot:
    rules:
        high_res_photos:
            class: Product
            fields: [images]
            target_path: '/Products/{{ object.getItemNumber() }}/HighRes'
            strategy: always
            priority: 100
            filters:
                types: [image]
                extensions: [jpg, tiff, png]
                min_size: 1048576      # at least 1 MB
                max_size: 104857600    # max 100 MB

        small_thumbnails:
            class: Product
            fields: [thumbnail]
            target_path: '/Products/{{ object.getItemNumber() }}/Thumbs'
            strategy: always
            priority: 90
            filters:
                types: [image]
                extensions: [jpg, png, webp]
                max_size: 1048576      # under 1 MB
```

### Catch-All Fallback Rule

Use a `class: '*'` wildcard at a low priority to file anything the specific rules did not claim,
grouped by class name. Each asset is still moved by the highest-priority matching rule, so the
specific rules win for their own classes and the wildcard only catches the rest.

```yaml
oronts_asset_pilot:
    rules:
        product_images:
            class: Product
            target_path: '/Products/{{ object.getItemNumber() }}/Images'
            strategy: always
            priority: 100

        catch_all:
            class: '*'
            target_path: '/Assets/{{ className }}/{{ object.getKey()|safe_key }}'
            strategy: always
            priority: 1
```
