=== Google Indexing API Submitter ===
Contributors: Expert Developer
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.0.0
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight plugin that integrates with the Google Indexing API to automatically notify Google whenever a post/page is published, updated, or deleted.

== Description ==

This lightweight plugin automatically notifies the Google Indexing API whenever you publish, update, or delete a post or page on your WordPress site.

Features:
* Automatically sends URL_UPDATED requests on publish/update.
* Automatically sends URL_DELETED requests on trash/delete.
* Securely stores your Google Service Account JSON Key.
* Zero bloat: single file, native WordPress API usage.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Settings -> Google Indexing API.
4. Paste your Google Cloud Service Account JSON key into the field and save.

== Frequently Asked Questions ==

= Does this guarantee my pages will be indexed? =
No. The Indexing API only notifies Google of updates. Google decides when and if to crawl and index your pages.

= Where can I get a Service Account JSON Key? =
You need to set up a project in Google Cloud Console, enable the Web Search Indexing API, create a Service Account, and download its JSON key. You must also add the Service Account's email as an owner in your Google Search Console property.

== Changelog ==

= 1.0.0 =
* Initial release. Light-weight Google Indexing API integration.
