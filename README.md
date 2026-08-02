# zencart-api

Zen Cart-side API endpoints for talking to external ERPs, meant to be dropped into a
Zen Cart web root (same level as `index.php`).

- **`freebooks-zencart.php`** — the FreeBooks ERP integration. Documented below.
- **`erpnext-zencart.php`** — an older ERPNext connector, kept for reference.

## freebooks-zencart.php

### Installation

1. Copy `freebooks-zencart.php` to your Zen Cart web root.
2. Generate a strong random key:
   ```
   php -r "echo bin2hex(random_bytes(32));"
   ```
3. Insert the key into Zen Cart's `configuration` table:
   ```sql
   INSERT INTO configuration
     (configuration_title, configuration_key, configuration_value,
      configuration_description, configuration_group_id, sort_order, date_added)
   VALUES
     ('FreeBooks API Key', 'FREEBOOKS_API_KEY', '<your-key>',
      'Secret key for FreeBooks ERP integration', 1, 100, NOW());
   ```
4. Callers must send `Authorization: Bearer <your-key>`.

### Endpoints (all via `?task=<name>`)

| Task | Method | Body |
|---|---|---|
| `version` | GET | — |
| `orders` | GET | `?last_order=<id>` |
| `updateOrderComments` | POST | `{"orderId":N,"comment":"...","status":N}` |
| `updateProducts` | POST | `{"products":[...]}` |
| `updateProductsQty` | POST | `{"products":[{"products_model":"...","quantity":N,"price":N},...]}` |

Any other `task` value 404s, unless a hook has registered a `task.<name>` action
(see below).

## Customisation hooks

This file is shared across stores and is meant to stay store-neutral — a store's
own quirks (a SKU prefix, holding back a stock buffer on one product, an extra
order-total line item from a custom payment/fee module, ...) do not belong hardcoded
here. Instead, drop plain PHP files into a hooks directory and register callbacks;
this file calls them at fixed points as data crosses the Zen Cart ↔ ERP boundary.

The hook mechanism itself (function names, hook names) is ERP-agnostic even though
this particular file only talks to FreeBooks today.

### Where hook files live

`{zencart root}/includes/erp_hooks/*.php`, loaded in filename order (so a numeric
prefix like `10-sku-prefix.php` controls load order relative to other hook files).
Define `ZC_HOOK_DIR` yourself (e.g. in `includes/extra_configures/`) before
`freebooks-zencart.php` runs if you want a different location.

Every hook file **must** start with:

```php
<?php
if (!defined('ZC_HOOKS')) exit;
```

This is the load-bearing security check on servers without `.htaccess` support
(Apache is additionally covered by Zen Cart's own `includes/.htaccess`, which already
denies direct HTTP access to everything under `includes/`). Hooks only ever run
*after* the request's `Authorization: Bearer` key has been checked — no hook code
runs for an unauthenticated request. Hook files are ordinary PHP with full database
access via the ZenCart `$db` object (in scope at top level, and inside any closure
via `use ($db)`) — the real boundary is filesystem/deploy permissions.

### Registering a hook

```php
zc_add_filter(string $name, callable $fn, int $priority = 10);  // transforms and returns a value
zc_add_action(string $name, callable $fn, int $priority = 10);  // side effect; return value ignored
```

Both callback forms receive `($value, array $ctx)`. Lower `$priority` runs first;
hooks at the same point run in registration order otherwise. A filter that forgets
to `return` (returns `null`) is treated as "no change" — the previous value passes
through untouched, and a warning is written to the PHP error log, rather than
silently blanking whatever it was transforming. If a hook throws, the request fails
cleanly as JSON — `{"ok":false,"error":"Hook \"<name>\" failed: <message>"}` with
HTTP 500 — instead of a raw PHP fatal breaking `Content-Type: application/json`.

### Hook reference

**SKU translation** — the two hooks solving a mismatched SKU convention between
Zen Cart's `products_model` and the ERP's SKU (see the worked example below):

| Hook | Type | Fires |
|---|---|---|
| `sku.export` | filter | Once per order line item, in `orders` (Zen Cart SKU → value sent to the ERP) |
| `sku.import` | filter | `updateProducts` (both the main SKU and each POSM variant SKU), `updateProductsQty` (ERP SKU → Zen Cart's `products_model`) |

**Quantity** — one filter, applied at all three places an incoming quantity can land
in Zen Cart, so a hook doesn't have to be duplicated per code path:

| Hook | Type | Fires |
|---|---|---|
| `quantity.import` | filter | `updateProducts` main quantity, `updateProducts` POSM variant quantity, `updateProductsQty` |

ctx for both of the above includes `sku` (Zen Cart-side, after `sku.import` where
applicable) and `source_sku` (as the ERP sent it).

**Orders out** (`?task=orders`):

| Hook | Type | Value / ctx |
|---|---|---|
| `order.item` | filter | one order line item; ctx `order_id`, raw `fields` |
| `order.total_line` | filter | called for any `orders_total.class` this file doesn't already handle (coupon/shipping/Taxamo/DHL landed cost/EAS IOSS); return an item array shaped like a normal line item to append it as an extra order line, or `null`/anything else to ignore. ctx `class`, `title`, `value`, `vat_rate`, `order` |
| `order` | filter | the fully assembled order, just before it's added to the response; ctx raw `row` |
| `orders.response` | filter | the whole `{"orders":[...]}` body, just before sending |

**Products in** (`?task=updateProducts`):

| Hook | Type | Value / ctx |
|---|---|---|
| `product.incoming` | filter | the raw product array as received, before anything is read from it |
| `product.row` | filter | the `products` table row about to be written; ctx `product`, `sku`, `lang_id`, `is_new`, `products_id` (`null` for a new product) |
| `product.desc_row` | filter | the `products_description` row about to be written; same ctx as `product.row` |
| `product.saved` | action | after the product/description/category rows are written; ctx `products_id`, `sku`, `product`, `is_new`, `lang_id` |
| `products.response` | filter | the whole `{"added":[...],"updated":[...],"notUpdated":[...]}` body |

**Quantity sync** (`?task=updateProductsQty`):

| Hook | Type | Value / ctx |
|---|---|---|
| `product_qty.incoming` | filter | the raw per-product array |
| `product_qty.row` | filter | the row about to be written; ctx `sku`, `source_sku`, `products_id`, `product`. Return `[]` to skip that SKU — it's reported in `notUpdated`, nothing is written |
| `products_qty.response` | filter | the whole response body |

**Comments / router:**

| Hook | Type | Value / ctx |
|---|---|---|
| `order_comment.incoming` | filter | the decoded request body for `updateOrderComments` |
| `boot` | action | runs once per request, right after hook files are loaded; ctx `task` |
| `task.<name>` | action | lets a hook add an entirely new `?task=` endpoint. If no built-in task matches and a `task.<name>` hook is registered, it runs instead of a 404; the hook is responsible for calling `api_ok()`/`api_error()` itself. ctx `task`, `request` (`$_REQUEST`), `body` (decoded JSON body, or `null`) |

### Checking what's loaded

`?task=version` (with a valid `Authorization` header) reports `hookDir` and the full
list of currently-registered hook names, so you can confirm a hook file loaded
without shell access on the store:

```json
{"version":4,"ok":true,"data":{"apiVersion":4,"file":"freebooks-zencart.php",
 "hookDir":"/path/to/store/includes/erp_hooks/",
 "hooks":["sku.export","sku.import"]}}
```

### Worked example

`examples/10-sku-prefix.php.dist` — copy to `includes/erp_hooks/10-sku-prefix.php`
and edit the prefix for your store. It uses a fictitious `ACME-` prefix; it's generic
documentation, not a real store's configuration.

```php
<?php
if (!defined('ZC_HOOKS')) exit;

// Example: this store's Zen Cart products_model values carry an 'ACME-' prefix
// that the matching ERP SKUs do not.
zc_add_filter('sku.export', function ($sku) {
    return preg_replace('/^ACME-/', '', (string)$sku);
});

zc_add_filter('sku.import', function ($sku) {
    $sku = (string)$sku;
    return strpos($sku, 'ACME-') === 0 ? $sku : 'ACME-' . $sku;
});

// Example: hold back a buffer of stock on one SKU, whichever route the
// quantity arrives by.
zc_add_filter('quantity.import', function ($qty, array $ctx) {
    return $ctx['sku'] === 'ACME-12345' ? max(0.0, $qty - 5) : $qty;
});
```
