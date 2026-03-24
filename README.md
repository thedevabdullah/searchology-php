# searchology-php

Official PHP SDK for the Searchology API — turn natural language search queries into structured JSON.

Instead of building dropdowns and filters, let your users search naturally:

> *"black nike shoes under $80"*

And your app receives clean, structured JSON:

```json
{
    "product_type": { "value": "shoes",  "confidence": 1.0  },
    "brand":        { "value": "nike",   "confidence": 1.0  },
    "color":        { "value": "black",  "confidence": 1.0  },
    "price_max":    { "value": 80,       "confidence": 1.0  }
}
```

---

## Install

```bash
composer require searchology/searchology-php
```

---

## Quick Start

There are only **two steps** to using Searchology:

1. Create your API key (once)
2. Call `extract()` with any search query

---

## Step 1 — Get Your API Key

Run this **once**, save the key it gives you.

```php
require 'vendor/autoload.php';

use Searchology\Searchology;

$client = new Searchology();
$result = $client->createApiKey('my-laravel-app');

echo $result['key'];
// sgy_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

> **Important:** Your key is only shown once. Save it to your `.env` file before moving on.

---

## Step 2 — Extract from any query

```php
use Searchology\Searchology;
use Searchology\SearchologyException;

$client = new Searchology([
    'api_key' => 'sgy_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
]);

$data = $client->extract('black t-shirt for my son under $15');

print_r($data['result']);
// [
//   'color'        => ['value' => 'black',   'confidence' => 1.0],
//   'product_type' => ['value' => 't-shirt',  'confidence' => 1.0],
//   'gender'       => ['value' => 'male',     'confidence' => 0.85],
//   'price_max'    => ['value' => 15,         'confidence' => 0.95],
// ]
```

---

## Saving Your API Key (Recommended)

Store it in your `.env` file:

```bash
SEARCHOLOGY_API_KEY=sgy_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Then use it in PHP:

```php
$client = new Searchology([
    'api_key' => $_ENV['SEARCHOLOGY_API_KEY']
]);
```

In Laravel use `env()`:

```php
$client = new Searchology([
    'api_key' => env('SEARCHOLOGY_API_KEY')
]);
```

---

## Laravel Example — Search Controller

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Searchology\Searchology;
use Searchology\SearchologyException;

class SearchController extends Controller
{
    private Searchology $searchology;

    public function __construct()
    {
        $this->searchology = new Searchology([
            'api_key' => env('SEARCHOLOGY_API_KEY')
        ]);
    }

    public function search(Request $request)
    {
        $request->validate(['q' => 'required|string|max:500']);

        try {
            $data    = $this->searchology->extract($request->input('q'));
            $filters = $data['result'];

            $products = \App\Models\Product::query()
                ->when(isset($filters['color']), fn($q) =>
                    $q->where('color', $filters['color']['value']))
                ->when(isset($filters['brand']), fn($q) =>
                    $q->where('brand', $filters['brand']['value']))
                ->when(isset($filters['price_max']), fn($q) =>
                    $q->where('price', '<=', $filters['price_max']['value']))
                ->when(isset($filters['gender']), fn($q) =>
                    $q->where('gender', $filters['gender']['value']))
                ->get();

            return response()->json([
                'query'      => $request->input('q'),
                'filters'    => $filters,
                'products'   => $products,
                'latency_ms' => $data['latency_ms'],
            ]);

        } catch (SearchologyException $e) {
            return response()->json([
                'error'   => $e->getErrorCode(),
                'message' => $e->getMessage(),
            ], $e->getStatusCode() ?: 500);
        }
    }
}
```

---

## Plain PHP Example

```php
require 'vendor/autoload.php';

use Searchology\Searchology;
use Searchology\SearchologyException;

$client = new Searchology(['api_key' => 'sgy_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx']);

try {
    $data = $client->extract('gaming laptop 16gb ram under $1000');

    foreach ($data['result'] as $key => $field) {
        echo "{$key}: {$field['value']} (confidence: {$field['confidence']})\n";
    }
    // product_type: laptop (confidence: 1)
    // usage: gaming (confidence: 1)
    // ram: 16gb (confidence: 1)
    // price_max: 1000 (confidence: 1)

} catch (SearchologyException $e) {
    echo "Error {$e->getStatusCode()}: {$e->getMessage()}\n";
    echo "Code: {$e->getErrorCode()}\n";
}
```

---

## Working with Confidence Scores

Each extracted field has a `value` and a `confidence` score (0 to 1):

```php
$data = $client->extract('probably a red dress for a wedding');

foreach ($data['result'] as $key => $field) {
    // only use fields with high confidence
    if ($field['confidence'] >= 0.8) {
        echo "{$key}: {$field['value']}\n";
    }
}
```

| Confidence | Meaning |
|---|---|
| `1.0` | Explicitly stated in query |
| `0.7 - 0.9` | Strongly implied |
| `0.5 - 0.7` | Inferred from context |
| `< 0.5` | Weak guess |

---

## Error Handling

```php
use Searchology\SearchologyException;

try {
    $data = $client->extract('red shoes under $50');

} catch (SearchologyException $e) {
    $e->getStatusCode();  // 401, 429, 500 etc. (0 for local errors)
    $e->getErrorCode();   // 'unauthorized', 'too_many_requests', etc.
    $e->getMessage();     // human readable message
}
```

**Error codes:**

| Status | Code | Cause |
|---|---|---|
| 400 | `invalid_input` | Query missing or empty |
| 400 | `query_too_long` | Query exceeds 500 characters |
| 401 | `unauthorized` | Missing, invalid, or expired API key |
| 429 | `too_many_requests` | Exceeded 60 requests per minute |
| 500 | `extraction_failed` | Server or LLM error |
| 0 | `connection_error` | Could not reach the API |

---

## Configuration

```php
$client = new Searchology([
    'api_key'  => 'sgy_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',  // required for extract()
]);
```

---

## Requirements

- PHP 8.0 or higher
- Guzzle 7.x (`guzzlehttp/guzzle`)

---

## License

MIT