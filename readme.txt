=== Product Sheet AI for WooCommerce ===
Contributors: andrewrozumny
Tags: woocommerce, ai, product, import, catalog
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn a supplier sheet (pasted text, CSV or PDF) into a complete WooCommerce product draft with AI. You review a preview, then choose what gets saved.

== Description ==

Product Sheet AI reads the sheet your supplier sent you and prepares a WooCommerce product from it: name, descriptions, prices, attributes, variations, categories, tags and SEO texts. Nothing is saved until you have seen a preview and ticked the fields you want.

This first release adds the settings page where you choose the AI provider (Anthropic or any OpenAI-compatible service), enter your own API key, and pick the store language and tone of voice.

**Your key, your data.** The API key is stored in your site database and sent only to the provider you choose. It is never shown again after saving.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin and activate it.
3. Open WooCommerce > Product Sheet AI and enter your API key.

== Frequently Asked Questions ==

= Which AI providers are supported? =

Anthropic (Claude) and any service that offers an OpenAI-compatible chat completions endpoint over https.

== Changelog ==

= 0.1.0 =
* Settings page, provider connection layer.
