# wordpress.org assets

These files are synced to the SVN `/assets` directory by
`.github/workflows/asset-update.yml`. They are never part of the plugin zip
(`.distignore` excludes this whole directory).

Expected files, all lowercase:

| File | Size | Purpose |
|---|---|---|
| `icon-128x128.png` | 128 × 128 | Plugin icon |
| `icon-256x256.png` | 256 × 256 | Retina icon |
| `banner-772x250.png` | 772 × 250 | Page header |
| `banner-1544x500.png` | 1544 × 500 | Retina header |
| `screenshot-1.png` | any | Caption = item 1 under `== Screenshots ==` in readme.txt |
| `screenshot-2.png` | any | Caption = item 2 |

Limits: banners 4 MB, icons 1 MB, screenshots 10 MB. Every screenshot listed
in readme.txt must have a matching file here, or the plugin page renders a
broken image.
