# wordpress.org assets

These files are synced to the SVN `/assets` directory by
`.github/workflows/asset-update.yml`. They are never part of the plugin zip:
`.distignore` excludes this whole directory.

| File | Required size | Purpose |
|---|---|---|
| `icon-128x128.png` | 128 × 128 | Plugin icon |
| `icon-256x256.png` | 256 × 256 | Retina icon |
| `banner-772x250.png` | 772 × 250 | Page header |
| `banner-1544x500.png` | 1544 × 500 | Retina header |
| `screenshot-1.png` | any | Caption = item 1 under `== Screenshots ==` in readme.txt |
| `screenshot-2.png` | any | Caption = item 2 |

Dimensions are exact: wordpress.org matches on the filename, so a
`banner-1544x500.png` that is actually 1554 px wide renders distorted.

Limits: banners 4 MB, icons 1 MB, screenshots 10 MB. Filenames must be
lowercase. Every screenshot listed in readme.txt needs a matching file here, or
the plugin page shows a broken image.

Locale variants are supported if the screenshots ever need to exist in both
languages: `screenshot-1-fr_FR.png`, `banner-772x250-fr_FR.png`.
