# About

This repository includes a script that updates the `in_stock` value 
independently of Algolia's standard indexing job.

This script queries Magento GraphQL for every product's `id` and `stock_status`,
then partially updates the matching Algolia record `stock_status` attribute.
Magento product IDs are used as Algolia `objectID` values, so only these fields are sent within the partialUpdate():

```json
{
  "objectID": "123",
  "in_stock": true
}
```

Crucially, this bypasses the need for a SQL query that traverses the entire Magentro framework for `stock_status`. In theory, this greatly increases the indexing performance for implmentations with numerous Magento websites / store views.

## Key Assumptions
The following assumptions are made about the Magento environment that the script will run in:
- Each store location is a Magento Website.
- Each Website has one Store and one Store View.
- The same catalog products are assigned to every Website.
- Each Website is assigned an MSI Stock (backed by one or more Sources), so salable quantity and in_stock can differ by location.
- By default Algolia creates one primary product index per Store View, producing location-named indexes such as magento2_prod_north_palm_beach_products.

## Running the script
The script is located here:

[`src/app/code/Algolia/CustomAlgolia/scripts/sync-stock-status-to-algolia.php`](src/app/code/Algolia/CustomAlgolia/scripts/sync-stock-status-to-algolia.php)

bin/cli env \
  MAGENTO_GRAPHQL_URL='https://your-magento-domain/graphql' \
  MAGENTO_ACCESS_TOKEN='your-magento-bearer-token' \
  ALGOLIA_APP_ID='your-app-id' \
  ALGOLIA_ADMIN_API_KEY='your-write-api-key' \
  ALGOLIA_INDEX_NAME='magento2_{store_code}_products' \
  php app/code/Algolia/CustomAlgolia/scripts/sync-stock-status-to-algolia.php

`ALGOLIA_INDEX_NAME` is an index-name template. The script substitutes
`{store_code}` with each entry in `MAGENTO_STORE_CODES` array within the script. For example, if `ALGOLIA_INDEX_NAME` is set to:

```sh
export ALGOLIA_INDEX_NAME='magento2_{store_code}_products'
```

Then this resolves to `magento2_default_products` and
`magento2_north_palm_beach_products` when `MAGENTO_STORE_CODES` = ["default", "north_palm_beach"]

The optional batch settings below are useful for larger catalogs:

```sh
export MAGENTO_PAGE_SIZE=100      # Magento products per GraphQL request
export ALGOLIA_BATCH_SIZE=1000    # Algolia records per batch request
```
