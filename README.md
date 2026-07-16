# Sync Magento stock status to Algolia

This repository includes a custom script that keeps Algolia's `in_stock` value
aligned with Magento's MSI-aware product stock status.

## Where the script lives

The custom script is here:

[`src/app/code/Algolia/CustomAlgolia/scripts/sync-stock-status-to-algolia.php`](src/app/code/Algolia/CustomAlgolia/scripts/sync-stock-status-to-algolia.php)

The file is part of the `Algolia_CustomAlgolia` module. On the host, it lives
under `src/`; inside this project's PHP container, `src/` is Magento's
application root, so the same file is available at:

```text
app/code/Algolia/CustomAlgolia/scripts/sync-stock-status-to-algolia.php
```

## What it does

The script asks Magento GraphQL for every product's `id` and `stock_status`,
then partially updates the matching Algolia record. Magento product IDs are
used as Algolia `objectID` values, so the script sends only these fields:

```json
{
  "objectID": "123",
  "in_stock": true
}
```

It does not create products in Algolia. If Magento returns a product that does
not already have a matching Algolia record, that update is ignored.

## Stores and MSI

The store views to process live in the `MAGENTO_STORE_CODES` list at the top
of the script. At the moment, it runs for:

- `default`
- `north_palm_beach`

For each store, the script sends Magento's `Store` GraphQL header. That is
important with MSI: Magento uses the store's website to resolve the assigned
stock, so `stock_status` is the customer-facing sellable status. This script
does not read raw source-item quantities.

## Configuration

Set these variables before a live run:

```sh
export MAGENTO_GRAPHQL_URL='https://store.example.com/graphql'
export ALGOLIA_APP_ID='your-app-id'
export ALGOLIA_ADMIN_API_KEY='your-write-api-key'
export ALGOLIA_INDEX_NAME='your_prefix_{store_code}_products'
```

`ALGOLIA_INDEX_NAME` is an index-name template. The script substitutes
`{store_code}` with each entry in `MAGENTO_STORE_CODES`. For example, the
local test setup uses:

```sh
export ALGOLIA_INDEX_NAME='magento2_{store_code}_products'
```

This resolves to `magento2_default_products` and
`magento2_north_palm_beach_products`. When more than one store is configured,
the `{store_code}` placeholder is required so two stores cannot be written to
the same index by mistake.

If the GraphQL endpoint needs authentication, also set:

```sh
export MAGENTO_ACCESS_TOKEN='your-magento-bearer-token'
```

The optional batch settings below are useful for larger catalogs:

```sh
export MAGENTO_PAGE_SIZE=100      # Magento products per GraphQL request
export ALGOLIA_BATCH_SIZE=1000    # Algolia records per batch request
```

## Running it

From Magento's application root, check what the script would send first:

```sh
php app/code/Algolia/CustomAlgolia/scripts/sync-stock-status-to-algolia.php --dry-run
```

In this Docker-based project, run it in the PHP container instead:

```sh
bin/cli php app/code/Algolia/CustomAlgolia/scripts/sync-stock-status-to-algolia.php --dry-run
```

The dry run calls Magento but does not connect to or update Algolia, so the
Algolia credentials are not needed for that command.

When the output looks right, remove `--dry-run` to update Algolia:

```sh
bin/cli php app/code/Algolia/CustomAlgolia/scripts/sync-stock-status-to-algolia.php
```

The output is grouped by store. A successful run looks like this:

```text
[default] Updated 2 existing Algolia record(s) from 2 Magento product(s) in magento2_default_products.
[north_palm_beach] Updated 1 existing Algolia record(s) from 1 Magento product(s) in magento2_north_palm_beach_products.
```

The script uses Algolia's partial-update operation with record creation turned
off. In other words, it changes only `in_stock` on existing records and leaves
the rest of each record alone.
