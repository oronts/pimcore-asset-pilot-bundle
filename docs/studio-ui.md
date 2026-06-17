[← Documentation index](index.md) · [Project README](../README.md)

# Studio UI

Asset Pilot integrates into Pimcore Studio as a Module Federation remote. The UI provides six tabs:

| Tab | Description |
|-----|-------------|
| **Dashboard** | Statistics overview (organized, pending, failed, skipped counts), class breakdown table, recent operations list |
| **Rules** | View all configured rules with priority, strategy, target path. Detail modal with configuration and statistics. Preview modal to test a rule against a specific object ID. |
| **Operations** | Single object organize (with dry-run, async, and explain modes). Bulk organize by class with paginated preview and "Organize All" button. System status with refresh. |
| **Audit Log** | Full operation history with sorting. Filter by class, status, and rule name. CSV export. Revert individual operations. |
| **Unused Assets** | Confidence-scored unused asset list with color-coded badges. Filter by type, extensions, date range, folder, and confidence level. Bulk delete or move selected assets. Filter presets. |
| **Asset Management** | Search assets by filename/path, filter by type, folder, or Object ID. Lock/unlock assets. Bulk assign tags. Bulk set custom properties. Sortable columns with pagination. |

### Confidence Badges

Unused assets display color-coded confidence badges:

| Badge | Color | Meaning |
|-------|-------|---------|
| Definitely Unused | Green | Safe to clean up (>90 days, no references) |
| Probably Unused | Yellow | Review recommended (30-90 days) |
| Recently Uploaded | Red | Wait before action (<30 days) |
| Historically Used | Orange | Was previously organized — investigate |
| Protected | Gray | Locked asset, excluded from cleanup |

### Localization

The Studio UI ships with English and German translations. All UI strings use the `asset-pilot.*` i18n namespace.
