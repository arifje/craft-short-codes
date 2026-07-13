# Short Codes for Craft CMS

Short Codes generates a short, random article code when an eligible Craft entry is saved. The code lives in a normal Plain Text field, so editors can see it, use it in places such as an Instagram caption, or enter a controlled manual override.

The plugin itself is platform-agnostic. Instagram is included only as an example of how short codes can connect social posts to Craft entries.

```text
7K4MP
H29XR
8QWRT
```

The public code is generated with `random_int()` and never contains the entry ID.

## Requirements

- PHP 8.0.2 or newer
- Craft CMS 4.x or 5.x
- A non-translatable Plain Text field for the code

The plugin has no database tables and no control-panel settings page. All settings come from `config/short-codes.php`, which makes configuration environment- and project-config-friendly.

## Installation

Install the package and then install the Craft plugin:

```bash
composer require arjan-brinkman/craft-short-codes:@dev
php craft plugin/install short-codes
```

Until the package is available from the project's normal Composer repositories, add this GitHub repository as a VCS source first:

```bash
composer config repositories.short-codes vcs https://github.com/arifje/craft-short-codes.git
composer require arjan-brinkman/craft-short-codes:@dev
```

For local development, clone this repository into `plugins/short-codes` in the Craft project and add a path repository to the project's root `composer.json`:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "plugins/short-codes",
      "options": {
        "symlink": true
      }
    }
  ]
}
```

Then run the same `composer require` and `plugin/install` commands above. Copy [`config/short-codes.php`](config/short-codes.php) to the Craft project's own `config/short-codes.php` and adjust the handles.

## Create the Craft field

In **Settings → Fields**, create a field with these values:

- Name: `Short code`
- Handle: `shortCode` (or the handle configured below)
- Type: **Plain Text**
- Translation method: **Not translatable**
- Required: **off**

Add it to every configured entry type's field layout. Do not make the field required in Craft; Short Codes fills it during the existing save.

“Not translatable” gives each canonical entry one code across every site. A translated code field is treated as incompatible: the plugin logs a warning and leaves the entry unchanged, because otherwise one canonical article could receive a different code on each site.

## Configuration

Create `config/short-codes.php` in the Craft project:

```php
<?php

return [
    'fieldHandle' => 'shortCode',

    'sectionHandles' => [
        'media',
        'sponsored',
    ],

    'entryTypeHandles' => [],

    'codeLength' => 5,

    'alphabet' => '23456789ABCDEFGHJKMNPQRSTUVWXYZ',

    'maximumGenerationAttempts' => 100,
];
```

| Setting | Meaning |
| --- | --- |
| `fieldHandle` | Required Plain Text field handle. |
| `sectionHandles` | Allowed sections. An empty array allows all top-level sections. |
| `entryTypeHandles` | Allowed entry types within those sections. An empty array allows every type. |
| `codeLength` | Exact code length, from 3 through 32. |
| `alphabet` | Allowed `A-Z` and `0-9` characters. It is uppercased and de-duplicated. |
| `maximumGenerationAttempts` | Random candidates tried before the save is stopped, from 1 through 10,000. |

The default alphabet excludes `0`, `O`, `1`, `I`, and `L` to reduce transcription mistakes. Separators and whitespace are removed while the configured alphabet is normalized; any other unsupported character makes the configuration invalid. At least two unique characters are required.

Invalid configuration is logged and unrelated entry saves are left unchanged.

## Save behavior

For a normal eligible entry save, the plugin:

1. ignores drafts, provisional drafts/autosaves, revisions, derivatives, nested Craft 5 entries, and entries outside the configured section/type scope;
2. verifies that the configured field exists in the entry's layout, is Plain Text, and is not translatable;
3. preserves a non-empty code, normalizing it when necessary;
4. generates a random candidate only when the field is empty;
5. checks the candidate for global uniqueness;
6. assigns the value in Craft's entry-level before-save event, before Craft takes its dirty-field snapshot;
7. enforces format and uniqueness in before-save even when a caller disables normal Craft validation;
8. checks valid values again during Craft's normal validation pass; and
9. lets Craft persist the field in the original save operation.

There is no second element save and therefore no save recursion. Normal published and unpublished canonical entries can receive codes. Applying a draft to its canonical entry can also generate the canonical code at that point.

## Manual codes

Editors may enter a code manually. Before saving, the value is:

- trimmed;
- uppercased;
- stripped of whitespace, hyphens, and underscores.

For example, `ab-12 x` becomes `AB12X`.

Other unsupported characters are not silently discarded. The editor receives an inline field error when a code has the wrong length, contains a character outside the configured alphabet, or belongs to another entry. A valid existing code is never replaced with a new random one.

### Generate from the entry form

In the control panel, Short Codes adds a **Generate code** button below the configured Plain Text field. The button requests a server-generated candidate that is unique at that moment, places it in the field, and leaves the entry unsaved so the editor can review it. Replacing an existing code requires confirmation.

Saving the entry remains authoritative: the normal format and uniqueness validation runs again before persistence. As with automatic generation, a candidate is not reserved between generation and saving.

## Uniqueness and multisite behavior

Uniqueness is global within the configured section and entry-type scope. The existence query:

- includes disabled, pending, expired, and unpublished entries with `status(null)`;
- searches all sites;
- excludes drafts, provisional drafts, revisions, placeholders, and soft-deleted entries;
- excludes every localized row of the current canonical entry; and
- uses `exists()` rather than loading entry objects.

Generated candidates are checked before assignment, then the value is checked again during validation immediately before persistence.

### Concurrency limitation

A very small race remains if two database transactions validate the same random code at exactly the same time. A portable unique index cannot safely be added to a normal Craft custom field: Craft 4 uses site-specific content columns, while Craft 5 stores field values in site-specific JSON. A true atomic guarantee would require a dedicated plugin table with a unique column, which would make the normal custom field no longer the sole source of truth.

The default five-character alphabet has a large candidate space, and the double application-level check is the strongest practical protection without changing that storage architecture.

## Backfill existing entries

Generate codes for existing eligible entries:

```bash
php craft short-codes/backfill
```

Available options:

```bash
php craft short-codes/backfill --dry-run
php craft short-codes/backfill --limit=100
php craft short-codes/backfill --section=media
```

The command reads canonical entries in stable ID order, uses batches of 100, processes every site only once, skips existing codes, and reports inspected, attempted, generated, skipped, and failed counts. It uses normal validated element saves and prints validation errors for failures. `--section` must also be permitted by `config/short-codes.php`. `--limit` caps missing entries attempted, so entries that already contain a code do not consume the limit.

Dry runs perform generation and database uniqueness checks without changing entries. Codes generated during the same dry run are reserved in memory so the preview does not repeat them.

## Twig lookup

The plugin registers `craft.shortCodes`:

```twig
{% set requestedCode = craft.app.request.getQueryParam('code')|default('') %}
{% set matchingEntry = craft.shortCodes.findEntry(requestedCode) %}

{% if matchingEntry and matchingEntry.url %}
    {% redirect matchingEntry.url %}
{% endif %}
```

`findEntry()` normalizes the input and returns `null` for an empty, malformed, or unknown code. Public lookups only return live entries on the current site that resolve to a URL; disabled, unpublished, draft, revision, cross-site, and URL-less entries are not exposed.

Additional values are available to Twig:

```twig
{{ craft.shortCodes.normalize('ab-12 x') }}
{{ craft.shortCodes.fieldHandle }}
{{ craft.shortCodes.codeLength }}
{% set configuredSections = craft.shortCodes.sectionHandles %}
{% set configuredEntryTypes = craft.shortCodes.entryTypeHandles %}
```

Use `craft.shortCodes`, without parentheses.

## Public URL resolver endpoint

External automations can resolve a code to the corresponding public article URL:

```http
GET /api/short-codes/resolve?code=7K4MP
```

Successful response:

```json
{
  "code": "7K4MP",
  "url": "https://example.com/articles/example"
}
```

The endpoint is read-only and does not require authentication. It normalizes the submitted code and returns only live entries on the current site that have a public URL. Malformed codes return HTTP `400`, unknown or non-public codes return `404`, and responses use `Cache-Control: no-store` so integrations do not retain a stale code mapping.

For multisite installations, call the endpoint on the domain of the site whose entry URL should be returned. The action URL remains available as `/actions/short-codes/codes/resolve?code=7K4MP`, but the site route above is the recommended integration URL.

## Instagram link-in-bio page

This repository includes a complete, framework-independent Dutch example:

- Template: [`examples/templates/instagram/index.twig`](examples/templates/instagram/index.twig)
- Stylesheet: [`examples/web/css/instagram.css`](examples/web/css/instagram.css)

Copy them into the Craft project:

```bash
mkdir -p templates/instagram web/css
cp plugins/short-codes/examples/templates/instagram/index.twig templates/instagram/index.twig
cp plugins/short-codes/examples/web/css/instagram.css web/css/instagram.css
```

Then edit the clearly marked handles at the top of the Twig template, especially `instagramCardImageUrl`. That field is expected to be Plain Text containing a complete `http://` or `https://` image URL; it is not treated as an Asset field.

With Craft's normal template routing, the page is available at:

```text
/instagram
```

The example provides:

- a prominent server-side GET search at `/instagram?code=7K4MP`;
- a safe redirect to the matched Craft entry URL;
- Dutch not-found feedback;
- a responsive, accessible three-column feed capped at 60 live entries;
- eager loading for the first nine images and lazy loading after that;
- keyboard focus states and reduced-motion support; and
- no JavaScript or Instagram API dependency.

The target repository was empty when this plugin was created, so there was no project layout, UIKit build, or CSS architecture to extend. The example is therefore a portable full HTML document. Integrate its content and classes into the real site's base layout or CSS pipeline when one is available.

## Instagram caption examples

```text
Het volledige artikel lezen?

Ga naar de link in onze bio en vul code 7K4MP in.
```

Short version:

```text
Lees meer via de link in bio met code: 7K4MP
```

## Troubleshooting

### No code is generated

- Confirm the entry is canonical, not a draft, revision, provisional draft, autosave, or nested entry.
- Confirm its section and entry-type handles match the PHP configuration.
- Confirm the Plain Text field is present in that entry type's field layout.
- Confirm the field's translation method is **Not translatable**.
- Check `storage/logs/` for messages in the `arjanbrinkman\\craftshortcodes\\services\\ShortCodeService` category.

### Configuration is reported as invalid

Check handle syntax, the 3–32 code-length range, the 1–10,000 attempt range, and that the normalized alphabet contains at least two unique `A-Z`/`0-9` characters.

### A manual value will not save

The field error identifies whether the normalized code has the wrong length, contains unsupported characters, or already exists on another configured entry/site.

### Public lookup returns no entry

The helper intentionally requires the entry to be live on the current site and to have a URL. Unpublished and disabled entries still reserve their codes, but they cannot be opened publicly.

## Development and tests

```bash
composer install
composer test
composer phpstan
composer validate --strict --no-plugins
composer check
```

The unit suite covers alphabet and code normalization, secure generation constraints, preservation and normalization of existing values, eligible/ineligible saves, drafts and revisions, duplicate/manual format validation, current-entry exclusion, lookup normalization/results, and the backfill existing-value decision.

Database-backed Craft integration—real content storage on both Craft 4 and Craft 5, multisite propagation, actual console execution, and browser rendering—must still be verified inside the host Craft project because this standalone package does not include a Craft project, database, or site fixtures.

## Upgrade notes

### 1.1.0

- Added a localized **Generate code** button below the configured field in Craft entry forms.
- Generated candidates are checked for uniqueness server-side and remain unsaved until the editor saves the entry.
- Replacing an existing code requires confirmation; normal save-time validation still runs before persistence.
- No migrations are required.

### 1.0.1

- Renamed the default field handle from `instagramCode` to the platform-neutral `shortCode`.
- Kept Instagram-specific naming confined to the optional link-in-bio example.
- Existing installations that already use `instagramCode` can either rename that Craft field to `shortCode` or keep `'fieldHandle' => 'instagramCode'` in the project's `config/short-codes.php` file.
- No migrations are required; existing code values remain stored in the configured Craft custom field.

### 1.0.0

- Initial release.
- No migrations are required; codes remain in the configured Craft custom field.
