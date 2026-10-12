# Product Sheet AI for WooCommerce

Paste or upload a supplier sheet and get a filled WooCommerce product draft. You review every field before anything is saved.

![The preview with current and proposed values](.wordpress-org/screenshot-1.png)

Product Sheet AI reads the text, table, CSV or text-layer PDF your supplier sent you and fills in a product from it. Every field shows its current value next to the proposed one, with a checkbox. You tick what you want and apply. New products are always saved as drafts.

It is for store owners who retype supplier catalogs by hand, and for agencies that enter many products for clients.

Requires WordPress 6.6+, PHP 8.1+, WooCommerce 9.0+. Tested up to WordPress 7.1 and WooCommerce 11.2.

## What it fills

| Group | Fields |
| --- | --- |
| Text | Name, short description, long description |
| Catalog | Categories, tags, attributes, variations |
| Commerce | Regular and sale price, SKU, weight, dimensions |
| SEO | SEO title, SEO description (Yoast SEO, Rank Math, or built in) |
| Media | Image alt texts |

Attributes are matched to the store's existing global attributes. New attributes, attribute values and categories are created only after you confirm them. Prices, weight and dimensions are converted to the store currency and units. Fields that already have a value are kept unless you allow overwriting for that field in the settings; SKU and prices are protected by default.

## How it works

1. Open Products > Add from sheet (new product), or use the "Fill from product sheet" box on the product screen (existing product).
2. Paste the sheet or upload a `.txt`, `.csv` or `.pdf` file (up to 5 MB). A PDF needs a text layer.
3. Press "Generate preview".
4. Check the fields, untick what you do not want, and apply.

The model is asked for product data only. The answer is validated against a fixed schema and texts are cleaned like post content. The plugin never runs anything that comes back.

You bring your own key. Anthropic is the default (`claude-sonnet-5-5`); any OpenAI-compatible endpoint works too. A request log shows tokens and an approximate cost for the last 20 requests.

## Privacy and external services

Nothing is sent until an administrator presses "Generate preview". No tracking, nothing goes to the plugin author.

Sent to the provider you selected (Anthropic at `https://api.anthropic.com/v1/messages`, or the OpenAI-compatible endpoint you entered):

- The sheet text.
- Store language and tone, currency, weight and dimension units.
- Names of global attributes with up to 50 values each, and up to 200 category names.
- For an existing product: its name, short and long description (first 1500 characters of each, as plain text), prices, SKU, weight, category and tag names, and image file names and titles.

The API key goes in the request header to that provider only. It is stored in the plugin's settings option and is never printed or logged. Provider terms: [Anthropic](https://www.anthropic.com/legal/commercial-terms), [OpenAI](https://openai.com/policies/terms-of-use).

## Install

From a zip: download the release zip, then Plugins > Add New > Upload Plugin, activate, and open WooCommerce > Product Sheet AI to enter your key.

From source:

```bash
git clone https://github.com/andrewrozumny/product-sheet-ai-for-woocommerce.git
cd product-sheet-ai-for-woocommerce
bash bin/build-zip.sh
```

Then install `dist/product-sheet-ai-for-woocommerce-<version>.zip`.

## Filters and actions for developers

| Hook | Type | Purpose |
| --- | --- | --- |
| `product_sheet_ai_prompt` | filter | Change the system and user text before it is sent. Receives `array{system,user}` and the store context. |
| `product_sheet_ai_provider` | filter | Replace the provider object. Receives the provider and the settings. |
| `product_sheet_ai_attribute_aliases` | filter | Add words the matcher should treat as the same attribute (for example `colour` and `color`). |
| `product_sheet_ai_anthropic_effort` | filter | Effort level sent to Anthropic: `low` (default), `medium` or `high`. |
| `product_sheet_ai_request_complete` | action | Fires after every generation attempt with a report: user, product, provider, model, token counts, status, duration. |

Example:

```php
add_filter(
	'product_sheet_ai_prompt',
	static function ( array $prompt ): array {
		$prompt['system'] .= "\n\nAlways write the short description in two sentences.";
		return $prompt;
	}
);
```

## Development

```bash
composer install
composer lint                # PHPCS (WordPress Coding Standards)
composer analyse             # PHPStan
composer test                # PHPUnit unit suite
composer test:integration    # integration tests inside a WordPress install
bash bin/gates.sh            # all quality gates in order
bash bin/build-zip.sh        # builds dist/product-sheet-ai-for-woocommerce-<version>.zip
```

## License

GPL-2.0-or-later. See [license](https://www.gnu.org/licenses/gpl-2.0.html).

Author: Andrew Rozumny, [helpmybiz.org](https://helpmybiz.org).
