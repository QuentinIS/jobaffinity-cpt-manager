# JobAffinity CPT Manager

[![Lint](https://github.com/quentinnicolet/jobaffinity-cpt-manager/actions/workflows/lint.yml/badge.svg)](https://github.com/quentinnicolet/jobaffinity-cpt-manager/actions/workflows/lint.yml)

WordPress plugin that gives JobAffinity job offers a custom post type of their
own instead of mixing them into Posts, and exposes every field JobAffinity
sends through the REST API.

The plugin is the receiving end only: it never contacts JobAffinity and makes
no outbound HTTP request of any kind. JobAffinity pushes to your site over
XML-RPC or REST, and the plugin decides where those publications land.

End-user documentation lives in [`readme.txt`](readme.txt) (the wordpress.org
format) and, in French, in
[`INTEGRATION-JOBAFFINITY.md`](INTEGRATION-JOBAFFINITY.md).

## Features

- **Configurable post type key.** After activation, *Settings → JobAffinity CPT
  Manager* lets you pick the key (`offer`, for example), the labels and the menu
  icon.
- **Behaves like native posts.** Same supports (title, editor, author,
  thumbnail, excerpt, custom-fields, comments, revisions, post-formats), same
  taxonomies (categories, tags), same capability logic via
  `capability_type => 'post'`, so administrators, editors and authors get the
  rights they already have on posts.
- **Decoupled REST route base.** The route may differ from the post type key —
  a post type keyed `offer-intern` can be served at `/wp/v2/offer` — with
  collision detection against core routes, other post types and taxonomies.
- **22 JobAffinity keys always declared** through `register_post_meta()`, so
  they work in the standard `meta` object. Settings add keys on top; they never
  remove the required set.
- **Arbitrary fields** through a `custom_fields` REST field (aliased as
  `meta_input`), which accepts any key without pre-declaration, supports
  multi-valued keys and deletes a key when sent `null`.
- **Optional interception** of incoming XML-RPC and REST publications carrying
  a `job_id` meta, re-routing them from `post` to the custom post type.

## Architecture

```
jobaffinity-cpt-manager.php       Bootstrap, activation, i18n
includes/
  class-ccptm-settings.php        Options, validation, migration
  class-ccptm-cpt.php             Post type registration (init, priority 5)
  class-ccptm-meta.php            register_post_meta() declarations
  class-ccptm-rest.php            custom_fields REST field + REST interception
  class-ccptm-xmlrpc.php          XML-RPC interception
  class-ccptm-admin.php           Settings screen
uninstall.php                     Per-site cleanup, content left untouched
languages/                        .pot, and the bundled French translation
```

`plugins_loaded` instantiates the classes in order; `CCPTM_Settings::maybe_migrate()`
runs before `CCPTM_Meta` so the legacy `meta_keys` option is converted before
meta keys are registered on `init` priority 11.

## `meta` or `custom_fields`?

|                                 | `meta`                     | `custom_fields`        |
| ------------------------------- | -------------------------- | ---------------------- |
| WordPress standard              | yes                        | no, plugin-specific    |
| Accepted keys                   | declared keys only         | any key                |
| Multiple values                 | no                         | yes, indexed array     |
| Available on `/wp/v2/posts`     | yes, if the option is on   | no                     |

WordPress **silently ignores** an undeclared key sent in `meta`: the request
answers `201` and the field is lost. That is why the plugin declares the
configured list. To see what is actually exposed:

```bash
curl -X OPTIONS https://example.com/wp-json/wp/v2/offer \
  | python3 -c "import sys,json;print(sorted(json.load(sys.stdin)['schema']['properties']['meta']['properties']))"
```

If the same key arrives through both channels in one request, the
`custom_fields` value wins.

### Create with declared fields

```bash
curl -X POST https://example.com/wp-json/wp/v2/offer \
  -u "user:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Sales assistant - Versailles",
    "status": "publish",
    "meta": {
      "job_id": "1023736",
      "job_contract_type": "CDI",
      "job_salary_min": "28000"
    }
  }'
```

Every key is typed `string`. A JSON number or boolean is cast to a string
before validation; an array or object is still rejected with
`rest_invalid_type`.

### Create with arbitrary fields

```bash
curl -X POST https://example.com/wp-json/wp/v2/offer \
  -u "user:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Spring opening",
    "status": "publish",
    "custom_fields": {
      "price": "99.90",
      "currency": "EUR",
      "internal_tags": ["promo", "new"]
    }
  }'
```

Send `null` as a value to delete a meta key.

## Security notes

- Protected meta (underscore-prefixed, such as `_wp_page_template`) is filtered
  out on read and refused on write unless the user explicitly holds the matching
  `edit_post_meta` capability.
- String values go through `wp_kses_post`, the same rule as post content. The
  keys `job_link` and `apply_url`, and any key ending in `_url` or `_link`, go
  through `esc_url_raw`, which preserves `&` in URLs.
- Sanitisation is wired through `register_post_meta()`, so it covers every write
  path at once: the `meta` object, `custom_fields`, XML-RPC and the Custom
  Fields metabox.
- Writing a declared meta requires `edit_post` on the target post, not just
  `edit_posts`.
- Indexed arrays are stored as multi-valued meta (`delete_post_meta` then a loop
  of `add_post_meta`) so `get_post_meta( $id, $key, false )` keeps working.

## Developer filters

| Filter                       | Purpose                                                                                      |
| ---------------------------- | -------------------------------------------------------------------------------------------- |
| `ccptm_meta_keys`            | Add keys to the declared list. The JobAffinity set is re-injected after the filter and cannot be removed. |
| `ccptm_meta_post_types`      | Change which post types the declarations are applied to.                                       |
| `ccptm_sanitize_meta_value`  | Customise the sanitisation of a declared meta value.                                           |

## Multisite

Settings live in the **per-site** option `ccptm_settings`. Each site in a
network therefore has its own post type key, route base and field list — usually
what you want, since `custom_*` fields differ per site. A site with no settings
registers neither the post type nor the meta. Network activation works, but
each site still has to be configured individually.

## Changing the key afterwards

The key can be changed, but doing so changes the public URLs, changes the REST
route base if that field is left empty, and does not migrate existing posts:
they stay attached to the old `post_type` until migrated by hand. Settle on the
key before creating content.

## Compatibility

Do not run the `offer-xmlrpc` plugin at the same time with the key `offer`. It
registers the same post type at `init` priority 10 **without** `show_in_rest`,
overwriting this plugin's registration at priority 5 and removing the REST
route.

## Development

WordPress runs in Docker; nothing is installed on the host.

```bash
# Regenerate the translation template and compile the French catalogue
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app wordpress:cli \
  wp i18n make-pot . languages/jobaffinity-cpt-manager.pot \
    --slug=jobaffinity-cpt-manager --domain=jobaffinity-cpt-manager \
    --exclude=node_modules,.github,.wordpress-org
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app wordpress:cli \
  wp i18n make-mo languages/

# Syntax check
docker run --rm -v "$PWD":/app -w /app php:8.2-cli \
  sh -c 'for f in *.php includes/*.php; do php -l "$f"; done'
```

Coding standards (`phpcs.xml.dist`) and the WordPress Plugin Check run in CI;
see `.github/workflows/lint.yml`.

## Releasing

Releases are tag-driven. `Version` in the plugin header, `Stable tag` in
`readme.txt` and the git tag must all match, and tags carry **no** `v` prefix —
10up's deploy action reuses the tag name as the SVN tag directory.

```bash
git tag 1.4.0 && git push origin 1.4.0
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
