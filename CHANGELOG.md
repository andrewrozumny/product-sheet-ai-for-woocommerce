# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-10-12

First release.

### Added
- Fill a WooCommerce product from a pasted supplier sheet, a `.txt` or `.csv` file, or a PDF with a text layer.
- Preview with the current and proposed value of every field and a checkbox for each. New products are always saved as drafts.
- Fields: name, short and long description, attributes, variations, prices, SKU, weight and dimensions, categories, tags, SEO title and description, image alt texts.
- Mapping to the store's existing global attributes; new attributes, values and categories are created only after confirmation.
- Overwrite rules per field in the settings (SKU and prices protected by default), store language and tone.
- SEO fields written to Yoast SEO or Rank Math when active, or stored and printed by the plugin.
- Providers: Anthropic (default model `claude-sonnet-5-5`) and any OpenAI-compatible endpoint, with your own key.
- Request log with token counts and approximate cost.
- Entry points: Products > Add from sheet, and the "Fill from product sheet" box on the product screen.
- Filters and action: `product_sheet_ai_prompt`, `product_sheet_ai_provider`, `product_sheet_ai_attribute_aliases`, `product_sheet_ai_anthropic_effort`, `product_sheet_ai_request_complete`.
