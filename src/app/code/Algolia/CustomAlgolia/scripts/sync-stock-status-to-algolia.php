#!/usr/bin/env php
<?php
/**
 * Synchronize Magento's MSI-aware product stock status to an existing Algolia
 * product index. The Magento product ID is used as the Algolia objectID.
 *
 * Required environment variables:
 *   MAGENTO_GRAPHQL_URL      e.g. https://store.example.com/graphql
 *   ALGOLIA_APP_ID
 *   ALGOLIA_ADMIN_API_KEY    Must have addObject permission for the target index
 *   ALGOLIA_INDEX_NAME       Product-index template, with {store_code} for each store
 *
 * Optional environment variables:
 *   MAGENTO_ACCESS_TOKEN     Bearer token if the GraphQL endpoint requires one
 *   MAGENTO_PAGE_SIZE        Magento products per GraphQL request (default: 100)
 *   ALGOLIA_BATCH_SIZE       Algolia records per batch request (default: 1000)
 *
 * Usage:
 *   php scripts/sync-stock-status-to-algolia.php --dry-run
 *   php scripts/sync-stock-status-to-algolia.php
 */
declare(strict_types=1);

use Algolia\AlgoliaSearch\Api\SearchClient;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

const PRODUCT_STOCK_STATUS_QUERY = <<<'GRAPHQL'
query ProductStockStatus($pageSize: Int!, $currentPage: Int!) {
  products(filter: {}, pageSize: $pageSize, currentPage: $currentPage) {
    items {
      id
      stock_status
    }
    page_info {
      current_page
      total_pages
    }
  }
}
GRAPHQL;

/**
 * Store views whose website-level MSI stock status should be synchronized.
 * Add or remove store codes here; no MAGENTO_STORE_CODE environment variable
 * is used by this script.
 *
 * @var list<string>
 */
const MAGENTO_STORE_CODES = [
    'default',
    'north_palm_beach',
];

/** @return never */
function fail(string $message): void
{
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
}

function requiredEnvironment(string $name): string
{
    $value = getenv($name);

    if ($value === false || trim($value) === '') {
        fail("{$name} must be set.");
    }

    return trim($value);
}

function positiveIntegerEnvironment(string $name, int $default): int
{
    $value = getenv($name);

    if ($value === false || $value === '') {
        return $default;
    }

    $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($integer === false) {
        fail("{$name} must be a positive integer.");
    }

    return $integer;
}

function algoliaIndexNameForStore(string $template, string $storeCode): string
{
    if (count(MAGENTO_STORE_CODES) > 1 && !str_contains($template, '{store_code}')) {
        fail('ALGOLIA_INDEX_NAME must contain {store_code} when synchronizing multiple stores.');
    }

    return str_replace('{store_code}', $storeCode, $template);
}

/**
 * @return array<string, mixed>
 */
function fetchMagentoProductPage(
    string $url,
    string $storeCode,
    ?string $accessToken,
    int $pageSize,
    int $currentPage
): array {
    if (!function_exists('curl_init')) {
        fail('The PHP cURL extension is required.');
    }

    $payload = json_encode([
        'query' => PRODUCT_STOCK_STATUS_QUERY,
        'variables' => [
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
        ],
    ], JSON_THROW_ON_ERROR);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        "Store: {$storeCode}",
    ];

    if ($accessToken !== null) {
        $headers[] = "Authorization: Bearer {$accessToken}";
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 90,
    ]);

    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($responseBody === false) {
        fail("Magento GraphQL request failed: {$curlError}");
    }

    try {
        /** @var array<string, mixed> $response */
        $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail("Magento GraphQL returned invalid JSON (HTTP {$statusCode}): {$exception->getMessage()}");
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $message = isset($response['message']) && is_string($response['message'])
            ? $response['message']
            : 'Unexpected HTTP response.';
        fail("Magento GraphQL returned HTTP {$statusCode}: {$message}");
    }

    if (isset($response['errors'])) {
        $messages = array_map(
            static fn (mixed $error): string => is_array($error) && isset($error['message'])
                ? (string) $error['message']
                : 'Unknown GraphQL error.',
            $response['errors']
        );
        fail('Magento GraphQL error: ' . implode(' | ', $messages));
    }

    return $response;
}

function main(array $arguments): void
{
    $dryRun = in_array('--dry-run', $arguments, true);
    $unknownArguments = array_diff($arguments, ['--dry-run']);
    if ($unknownArguments !== []) {
        fail('Unsupported option(s): ' . implode(', ', $unknownArguments));
    }

    $magentoGraphqlUrl = requiredEnvironment('MAGENTO_GRAPHQL_URL');
    $algoliaIndexNameTemplate = requiredEnvironment('ALGOLIA_INDEX_NAME');
    $pageSize = positiveIntegerEnvironment('MAGENTO_PAGE_SIZE', 100);
    $batchSize = positiveIntegerEnvironment('ALGOLIA_BATCH_SIZE', 1000);

    $accessToken = getenv('MAGENTO_ACCESS_TOKEN');
    $accessToken = $accessToken === false || trim($accessToken) === '' ? null : trim($accessToken);

    $algoliaClient = null;
    if (!$dryRun) {
        $algoliaClient = SearchClient::create(
            requiredEnvironment('ALGOLIA_APP_ID'),
            requiredEnvironment('ALGOLIA_ADMIN_API_KEY')
        );
    }

    foreach (MAGENTO_STORE_CODES as $magentoStoreCode) {
        $algoliaIndexName = algoliaIndexNameForStore($algoliaIndexNameTemplate, $magentoStoreCode);
        $currentPage = 1;
        $totalPages = 1;
        $productsRead = 0;
        $recordsUpdated = 0;

        do {
            $response = fetchMagentoProductPage(
                $magentoGraphqlUrl,
                $magentoStoreCode,
                $accessToken,
                $pageSize,
                $currentPage
            );

            $products = $response['data']['products'] ?? null;
            if (!is_array($products) || !isset($products['items'], $products['page_info']) || !is_array($products['items']) || !is_array($products['page_info'])) {
                fail('Magento GraphQL response did not contain product items and page information.');
            }

            $totalPages = (int) ($products['page_info']['total_pages'] ?? 0);
            if ($totalPages < 1) {
                fail('Magento GraphQL returned an invalid total page count.');
            }

            $updates = [];
            foreach ($products['items'] as $product) {
                if (!is_array($product) || !isset($product['id'], $product['stock_status'])) {
                    fail('Magento GraphQL returned a product without an ID or stock status.');
                }

                $updates[] = [
                    'objectID' => (string) $product['id'],
                    'in_stock' => $product['stock_status'] === 'IN_STOCK',
                ];
            }

            $productsRead += count($updates);

            if ($dryRun) {
                fwrite(STDOUT, sprintf("[%s] Page %d/%d: would update %d record(s).\n", $magentoStoreCode, $currentPage, $totalPages, count($updates)));
            } elseif ($updates !== []) {
                // false selects partialUpdateObjectNoCreate: existing Algolia records are
                // updated, while a missing objectID is never created as a sparse record.
                $algoliaClient->partialUpdateObjects($algoliaIndexName, $updates, false, true, $batchSize);
                $recordsUpdated += count($updates);
                fwrite(STDOUT, sprintf("[%s] Page %d/%d: updated %d record(s).\n", $magentoStoreCode, $currentPage, $totalPages, count($updates)));
            }

            ++$currentPage;
        } while ($currentPage <= $totalPages);

        $action = $dryRun ? 'Would update' : 'Updated';
        fwrite(STDOUT, sprintf("[%s] %s %d existing Algolia record(s) from %d Magento product(s) in %s.\n", $magentoStoreCode, $action, $dryRun ? $productsRead : $recordsUpdated, $productsRead, $algoliaIndexName));
    }
}

try {
    main(array_slice($argv, 1));
} catch (Throwable $exception) {
    fail($exception->getMessage());
}
