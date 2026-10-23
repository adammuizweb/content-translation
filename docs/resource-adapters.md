# External Resource Adapters

Content Translation provides an optional runtime adapter contract for
multilingual resources owned by another plugin. The owning plugin remains
responsible for persistence, editing, publication rules, permissions, and
sitemap entries. Content Translation only connects published representations to
locale-prefixed routing, canonical metadata, `hreflang`, and the language
switcher.

## Registering An Adapter

Adapters are loaded lazily from `content_translation_resource_adapters`. The
filter must return a list containing no more than 32 adapters. Every adapter has
an API version, a unique stable key, and three callbacks:

```php
add_filter('content_translation_resource_adapters', function (array $adapters): array {
    $adapters[] = [
        'api_version' => 1,
        'key' => 'example-library',
        'resolve' => function (PDO $pdo, string $locale, string $path): ?array {
            if (!str_starts_with($path, 'library/')) return null;

            $item = example_library_find_localized_path($pdo, $locale, $path);
            if (!$item) return ['available' => false];

            return [
                'available' => true,
                'resource_id' => (string)$item['resource_id'],
                'route_path' => 'library/' . $item['source_slug'],
            ];
        },
        'current' => function (PDO $pdo): ?array {
            $item = example_library_current_item($pdo);
            return $item ? ['resource_id' => (string)$item['resource_id']] : null;
        },
        'alternates' => function (PDO $pdo, array $context): array {
            return example_library_public_paths($pdo, $context['resource_id']);
        },
    ];
    return $adapters;
});
```

The registry rejects the complete filtered value when it is malformed, exceeds
the cap, or contains duplicate keys. Adapter keys use lowercase letters,
numbers, dots, underscores, and hyphens, and must begin with a letter.

## Capability Discovery

Optional consumers must check both the public marker function and each required
capability before relying on adapter API extensions:

```php
if (!function_exists('ct_resource_adapter_supports')
    || !ct_resource_adapter_supports('alternate-query')
    || !ct_resource_adapter_supports('locking-locale-grant')) {
    return;
}
```

Capability names are exact and case-sensitive. `alternate-query` guarantees the
validated optional `query` field on alternate rows and its use in canonical,
`hreflang`, and switcher URLs. `locking-locale-grant` guarantees the optional
locking argument on `ct_external_resource_locale_edit_granted()`. Unknown names
return `false`.

## Callback Contract

`resolve(PDO $pdo, string $locale, string $path): ?array` receives an enabled
non-default locale and the locale-free internal request path. Return `null` only
when the adapter does not own that path. An owned but unpublished or otherwise
unavailable representation returns `['available' => false]` and produces a 404.
An available representation returns `available`, a scalar `resource_id` of at
most 191 bytes, and a safe locale-free `route_path` for the owning plugin's
normal route.

`current(PDO $pdo): ?array` identifies the resource on an unprefixed,
default-locale request. Return `null` when the adapter does not own the current
request, or return a scalar `resource_id`. More than one matching adapter is
ambiguous and fails closed.

`alternates(PDO $pdo, array $context): array` receives the normalized
`adapter_key`, `resource_id`, active `locale`, and nullable internal
`route_path`. It returns public representations as locale and root-relative path
rows. A row may include a query string without the leading `?` when filtered or
paginated state belongs to the adapter:

```php
[
    ['locale' => 'en', 'path' => '/library/atlas/', 'query' => 'view=grid&page=2'],
    ['locale' => 'de', 'path' => '/de/bibliothek/atlas/', 'query' => 'view=raster&page=2'],
]
```

Content Translation validates every locale against `ct_content_locales($pdo)`,
normalizes safe root-relative paths while preserving a trailing slash, rejects
duplicate locales or paths, and treats paths that differ only by a trailing
slash as duplicates. It caps the rows at 64 and requires a row for the active
locale. Accepted rows retain their submitted trailing slash. Internal
locale-free `route_path` values are normalized without a trailing slash.
Queries are optional, capped at 2048 bytes, and reject a leading `?`, controls,
spaces, backslashes, fragments, and malformed percent escapes. Keep path and
query separate in adapter results; Content Translation composes them for output.

The validated rows are cached once per request and shared exactly by canonical
URL, `hreflang`, and switcher output. Fewer than two rows suppress `hreflang`
and the switcher. A malformed, throwing, or ambiguous `current` callback, or
invalid alternates, does not claim metadata precedence, so built-in metadata and
switcher handling remain eligible.

## Optional Edit Authority

Consumers that provide their own editor may call:

```php
ct_external_resource_locale_edit_granted($pdo, $locale, $userId, $lock);
```

This helper checks that the locale is enabled and non-default and that the user
has both Content Translation workspace access and a Content Translation locale
edit grant. It deliberately does not check permission to edit the consumer's
resource. The consumer must separately enforce its own read/update/publish
permissions, CSRF protection, validation, and concurrency policy. The optional
`$lock` argument defaults to `false`; pass `true` inside an appropriate mutation
transaction when the locale-grant row must be checked with the underlying
locking behavior.
