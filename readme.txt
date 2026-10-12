=== Product Sheet AI for WooCommerce ===
Contributors: andrewrozumny
Tags: woocommerce, ai, product description, product import, claude
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Paste or upload a supplier sheet and get a filled WooCommerce product draft. You review every field before anything is saved.

== Description ==

Product Sheet AI for WooCommerce reads the sheet your supplier sent you and fills in a product from it. Paste the text, a table or a CSV, or upload a PDF that has a text layer, and press "Generate preview". You get a draft with the name, short and long description, attributes, variations, prices, SKU, weight and dimensions, categories, tags, SEO title and description, and image alt texts.

Nothing is saved until you have looked at it. The preview shows the current value and the proposed value for every field, each with a checkbox. You tick what you want and press Apply. New products are always saved as drafts, so nothing goes live by accident.

**How it works**

* Open Products > Add from sheet to create a new product, or use the "Fill from product sheet" box on the product screen to fill an existing one.
* Paste the sheet or upload a .txt, .csv or .pdf file (up to 5 MB).
* Press "Generate preview" and wait a few seconds.
* Check the fields, untick what you do not want, and apply.

**It uses your store, not its own idea of it**

* Attributes are matched to the global attributes you already have. If your store uses "Small" and the sheet says "S", you get "Small".
* New attributes, new attribute values and new categories are created only after you confirm them in the preview.
* Prices, weight and dimensions are converted to your store currency and units.
* Texts are written in the language and tone you choose in the settings.
* Fields that already have a value are kept unless you allow overwriting for that field in the settings. SKU and prices are protected by default.

**Your key, your provider**

Bring your own API key. Anthropic is the default (model claude-sonnet-5-5). You can also use any OpenAI-compatible endpoint: OpenAI itself, or a service or local server that speaks the same API. The key is stored in your site database and is never shown again after saving.

The model is asked for product data only. It never returns code, and the plugin does not run anything that comes back from it: the answer is checked against a fixed schema, and texts are cleaned the same way WordPress cleans post content.

**Request log**

The last 20 requests are recorded with the date, product, provider, model, input and output tokens and an approximate cost, so you always know what you spent. The log does not store the sheet or the key.

**SEO plugins**

If Yoast SEO or Rank Math is active, the SEO title and description are written to their fields. If neither is active, the plugin stores them itself and prints them on the product page.

**Who it is for**

* Store owners who get supplier catalogs as PDFs, spreadsheets or emails and are tired of retyping them.
* Agencies and freelancers who set up stores for clients and need to enter many products.
* Anyone with products that have many variations, where typing every size, color and price by hand is slow and easy to get wrong.

**External services**

This plugin sends data to an AI service that you choose and pay for yourself. It does nothing until an administrator presses "Generate preview". There are no background requests, no tracking and no data sent to the plugin author.

Service 1: the Anthropic API, https://api.anthropic.com/v1/messages (the default provider).
Service 2: an OpenAI-compatible endpoint that the administrator enters in the settings (for example the OpenAI API, or a server of your own).

What is sent, only when an administrator clicks "Generate preview":

* The text of the sheet (pasted, or extracted from the uploaded file).
* The store language and tone, the currency, and the weight and dimension units.
* The names of your global product attributes and their values (up to 50 values for each attribute).
* The names of your product categories (up to 200).
* When you fill an existing product: its current name, short and long description (the first 1500 characters of each, as plain text), regular and sale price, SKU, weight, category and tag names, and the file name and title of its featured and gallery images.

The API key is sent in the request header to the provider you selected. Customer data, orders and other products are not sent.

Anthropic: [commercial terms](https://www.anthropic.com/legal/commercial-terms), [privacy policy](https://www.anthropic.com/legal/privacy).
OpenAI: [terms of use](https://openai.com/policies/terms-of-use), [privacy policy](https://openai.com/policies/privacy-policy).
For another OpenAI-compatible service, see the terms and privacy policy of that service.

**Extending**

Developers can change the prompt with the `product_sheet_ai_prompt` filter. See the GitHub repository for the other filters.

**Roadmap**

Ideas for a future Pro version:

* Batch import from a CSV with many products
* History of generated drafts
* Image generation
* Translations of existing products

Source code: https://github.com/andrewrozumny/product-sheet-ai-for-woocommerce

== Installation ==

1. Install and activate WooCommerce, then upload this plugin to `/wp-content/plugins/` or install it from the Plugins screen and activate it.
2. Open WooCommerce > Product Sheet AI, choose the provider and enter your API key.
3. Set the store language, tone and which fields may be overwritten.
4. Open Products > Add from sheet, paste a supplier sheet and press "Generate preview".

== Frequently Asked Questions ==

= Which AI provider should I use? =

Anthropic with the default model claude-sonnet-5-5 is what the plugin is tested with. You can also enter any OpenAI-compatible endpoint and model name in the settings. You need your own account and API key with the provider.

= Does it change my products without asking? =

No. You always see a preview first, with the current and proposed value for every field and a checkbox for each. Only ticked fields are saved. New products are saved as drafts. New attributes, attribute values and categories are created only after you confirm them.

= Does it work with scanned PDFs? =

Only PDFs that have a text layer, which means you can select and copy the text in a PDF viewer. A scanned page that is just a picture has no text to read, and the plugin will tell you so. Run it through OCR first, or paste the text.

= Where is my API key stored? =

In your site database, in the plugin's settings option. It is not shown again after saving, it is not written to the page or to any log, and it is sent only to the provider you selected.

= How much does one product cost? =

A typical request is a few thousand input tokens and one to three thousand output tokens. With claude-sonnet-5-5 that is about one or two cents. The request log shows the real token counts and an approximate cost for each request. Prices change, so check your provider's price list.

= Can I change the prompt? =

Yes. Use the `product_sheet_ai_prompt` filter. It receives the system and user text and the store context, and returns the text to send.

= Does it work with Yoast SEO or Rank Math? =

Yes. When one of them is active, the SEO title and description go into its fields. Without them, the plugin keeps the values itself and prints the meta description and page title on single product pages.

== Screenshots ==

1. The preview: current and proposed value for every field, with checkboxes.
2. Attributes and variations mapped to the store's existing attributes, with new values waiting for confirmation.
3. The product draft created from a supplier sheet.
4. Settings: provider, model, language, tone and overwrite rules.
5. The request log with tokens and approximate cost.

== Changelog ==

= 0.1.0 =
* First release: fill a WooCommerce product from a pasted sheet, CSV or text PDF, preview with current and proposed values and checkboxes, drafts only, mapping to existing global attributes with confirmation for new ones, variations, SEO fields for Yoast SEO, Rank Math or built in, image alt texts, Anthropic and OpenAI-compatible providers, request log with tokens and approximate cost, and the `product_sheet_ai_prompt` filter.

== Upgrade Notice ==

= 0.1.0 =
First release.
