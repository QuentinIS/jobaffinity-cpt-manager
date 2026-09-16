=== JobAffinity CPT Manager ===
Contributors: YOUR_WPORG_USERNAME
Tags: job board, recruitment, custom post type, rest api, xml-rpc
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Receives job offers published by JobAffinity into a dedicated custom post type, over the REST API or XML-RPC, with custom fields intact.

== Description ==

By default, JobAffinity publishes your job offers as ordinary WordPress posts, mixed in with your company blog. This plugin gives them a home of their own.

It registers a single custom post type whose key you choose (`offer`, `job`, `vacancy` — whatever suits your site), declares the field schema JobAffinity sends, and can intercept incoming publications so they land in that post type instead of in Posts.

JobAffinity is a recruitment platform (applicant tracking system). This plugin is the receiving end on your WordPress site: it never contacts JobAffinity, makes no outbound HTTP request of any kind, and collects no data. JobAffinity pushes to your site over XML-RPC or the REST API, and the plugin decides where those publications land.

= What it does =

* Registers one configurable custom post type that behaves exactly like native posts: same capabilities, same editor, categories and tags, featured image, revisions, block editor support.
* Declares the 22 job fields JobAffinity sends (`job_id`, `job_link`, `job_contract_type`, salary, location, and the rest) through `register_post_meta()`, so they work in the standard `meta` object of the REST API.
* Accepts any additional meta keys you configure, alongside the required set.
* Adds a `custom_fields` REST field for keys that are not declared, hold several values, or need deleting — including the `custom_*` fields JobAffinity forwards from your own configuration.
* Optionally re-routes incoming XML-RPC and REST publications that carry a `job_id` meta from `post` to your custom post type. Ordinary posts on your blog are untouched, and updates to existing posts are never re-routed.
* Lets the REST route base differ from the post type key, so `/wp/v2/offer` can serve a post type keyed `offer-intern`.

= Two write channels =

Declared keys go through the standard `meta` object. Everything else — free-form keys, multi-valued keys, deletions — goes through `custom_fields`. If the same key arrives through both, `custom_fields` wins.

This matters because WordPress silently ignores undeclared keys inside `meta`: the request still answers 201, but the field is lost. The settings screen lists exactly which keys are currently declared.

= Developer filters =

* `ccptm_meta_keys` — the full list of declared meta keys.
* `ccptm_meta_post_types` — the post types those keys are declared on.
* `ccptm_sanitize_meta_value` — the sanitisation applied to an incoming value.

Source code and issue tracker: [github.com/quentinnicolet/jobaffinity-cpt-manager](https://github.com/quentinnicolet/jobaffinity-cpt-manager)

== Installation ==

1. Install and activate the plugin.
2. Go to Settings > JobAffinity CPT Manager. A notice will point you there until the post type key is set.
3. Choose the post type key, for example `offer`, plus a singular and plural label and a Dashicon for the admin menu. Save. A dedicated menu appears in the sidebar.
4. If JobAffinity publishes to your site over XML-RPC, tick "Automatically route JobAffinity posts sent over XML-RPC to this post type". Tick the REST equivalent if it publishes through `POST /wp/v2/posts`.
5. Create the WordPress user JobAffinity will authenticate as, with the Author or Editor role, and give it an application password.

Set the post type key before the first publication. Changing it afterwards leaves existing content attached to the old post type and changes its URLs.

== Frequently Asked Questions ==

= Does uninstalling the plugin delete my job offers? =

No. Uninstalling removes the plugin's settings and its transients, nothing else. Your offers, their meta values and their taxonomy terms stay in the database — they belong to your site, not to the plugin. Reactivating with the same post type key makes them visible again immediately.

To remove them for good, delete them from the admin before uninstalling, or use WP-CLI.

= The offers land in Posts instead of my new post type. =

The interception option is off by default. Tick the XML-RPC box if JobAffinity publishes over XML-RPC, or the REST box if it posts to `/wp/v2/posts`. Detection relies on the `job_id` meta being present in the incoming request, which is JobAffinity's signature.

= The offer is created (201) but the fields I sent in `meta` are missing. =

WordPress ignores undeclared keys in `meta` without raising an error. Either add the key under "Additional fields" in the settings, or send it through `custom_fields` instead, which accepts anything.

= I get `rest_invalid_type` on `meta.job_salary_min`. =

Every declared key is typed as a string, including salaries and coordinates, because JobAffinity sends everything as a string. Send `"45000"`, not `45000`.

= Does this work on multisite? =

Yes. Settings are stored per site, so each site in the network chooses its own post type key and its own additional fields. Network activation works, but every site still has to be configured individually.

= Does the plugin send anything to JobAffinity, or anywhere else? =

No. It makes no outbound HTTP request at all. Communication is one-way: JobAffinity pushes to your site.

== Screenshots ==

1. The settings screen, under Settings > JobAffinity CPT Manager.
2. The generated custom post type in the admin sidebar and list table.

== Changelog ==

= 1.4.0 =
* First WordPress.org release.
* Interface translated to English; the French translation is bundled.
* Added `uninstall.php`, which removes the plugin's settings and transients per site on a network, and deliberately leaves content alone.
* Fixed the declared post type list on the settings screen rendering escaped HTML entities instead of markup.
* Hardened input handling on the settings form.
* Removed a no-op `map_meta_cap` filter that ran on every capability check.

= 1.3.0 =
* The 22 JobAffinity keys are now always declared; the settings only store additional keys on top of them.
* Automatic migration of the old `meta_keys` option to `extra_meta_keys`, preserving custom keys.
* Added a configurable REST route base, independent of the post type key, with conflict detection against core routes, other post types and taxonomies.
* Added the option to declare the keys on the native `post` post type as well.

= 1.2.0 and earlier =
* Released privately, before the plugin was published on WordPress.org.

== Upgrade Notice ==

= 1.4.0 =
The admin interface is now in English, with French supplied as a translation. Settings and content are unaffected.
