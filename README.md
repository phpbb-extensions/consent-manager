# Consent Manager

> This extension is under development and will become available on [phpBB.com](https://phpbb.com) when it's ready

Consent Manager is a GDPR-ready privacy/cookie consent management solution built for phpBB forums.

It adds a consent banner, settings modal, and category-based controls, allowing visitors to accept all, reject all, or choose specific tracking types. A footer link lets users revisit and update their preferences at any time.

The extension also provides an easy integration point for other phpBB extensions, enabling them to make their non-essential scripts compliant.

Out of the box, Consent Manager supports these categories:

- Necessary (always on)
- Analytics (optional)
- Marketing (optional)

It also includes ACP settings for enabling categories, simple admin-managed integrations, detailed consent logging for audit and compliance purposes, and consent version resets to prompt users to review their choices when policies or integrations change.

## For extension authors

If your extension adds analytics, advertising, or other tracking/cookie-related JavaScript, the usual flow is:

1. Register your extension with Consent Manager
2. If you use `INCLUDEJS`, let Consent Manager load that file after consent
3. If you output direct `<script>` tags, turn them into consent-aware placeholders

### 1. Register your extension with Consent Manager

PHP registration is the main integration point. This is how your extension tells Consent Manager:

- what the integration is called
- which category it belongs to
- what description should be shown in the consent UI

Listen to `phpbb.consentmanager.collect_registrations`:

```php
public static function getSubscribedEvents()
{
	return ['phpbb.consentmanager.collect_registrations' => 'register_analytics'];
}

public function register_analytics($event)
{
	$consent_manager = $event['consent_manager'];

	$consent_manager->register('ext.example.analytics', [
		'label' => 'Example Analytics',
		'category' => 'analytics',
		'description' => 'Tracks page views after analytics consent is granted.',
		'scripts' => [],
	]);
}
```

That basic example registers the integration for display in the consent UI, but does not ask Consent Manager to load any script files.

Registration rules:

- `id` must use letters, numbers, `.`, `_`, `:`, or `-`
- `category` must be `necessary`, `analytics`, or `marketing`
- each script entry may define exactly one of `src`, `asset`, or `inline`
- `src` must be an `http`, `https`, or relative URL
- `asset` must be a local phpBB asset path such as `@vendor_extension/js/file.js`
- `wait_for_dom_ready` may be set to `true` for PHP-registered scripts that should only be injected after `DOMContentLoaded`
- unsafe attributes such as event handlers are rejected

### 2. If your extension uses INCLUDEJS

If your extension already loads a JavaScript file through `INCLUDEJS`, you do not need to rewrite that file.

Instead, register the file as a local asset in your PHP registration event using the `scripts` rule:

```php
$consent_manager->register('ext.example.analytics', [
	'label' => 'Example Analytics',
	'category' => 'analytics',
	'description' => 'Tracks page views after analytics consent is granted.',
	'scripts' => [
		[
			'id' => 'ext.example.analytics.file',
			'asset' => '@ext_example/js/analytics.js',
			'wait_for_dom_ready' => true,
		],
	],
]);
```

Then keep your normal `INCLUDEJS` line only as a fallback for cases where Consent Manager is absent or that category is disabled:

```twig
{% if not S_CONSENTMANAGER_ANALYTICS_ENABLED %}
	{% INCLUDEJS '@ext_example/js/analytics.js' %}
{% endif %}
```
Use the template flag for the category your integration belongs to, such as `S_CONSENTMANAGER_ANALYTICS_ENABLED` or `S_CONSENTMANAGER_MARKETING_ENABLED`.

That is the preferred pattern for extension-owned JS files. Consent Manager loads the registered file after consent when it is available to do so. Add `wait_for_dom_ready => true` when the file should wait until `DOMContentLoaded`, which is useful for scripts that normally rely on phpBB's footer `INCLUDEJS` timing. Otherwise, your normal `INCLUDEJS` path still runs and your extension behaves as usual.

### 3. If your extension outputs direct script tags

If your extension writes live `<script>` tags directly in a template event, PHP registration by itself is not enough. Those tags would still run as soon as the browser parses them.

For that pattern, keep the same script tags and add Consent Manager's placeholder attributes with the category flag:

```twig
<script{% if S_CONSENTMANAGER_ANALYTICS_ENABLED %} type="text/plain" data-consent-category="analytics"{% endif %} src="https://cdn.example.com/analytics.js"></script>

<script{% if S_CONSENTMANAGER_ANALYTICS_ENABLED %} type="text/plain" data-consent-category="analytics"{% endif %}>
	window.exampleTracker && window.exampleTracker.page();
</script>
```

Use the template flag for the category your integration belongs to, such as `S_CONSENTMANAGER_ANALYTICS_ENABLED` or `S_CONSENTMANAGER_MARKETING_ENABLED`.

When that category is enabled, Consent Manager sees `type="text/plain"` plus `data-consent-category` and upgrades the placeholder after the matching category is allowed. When Consent Manager is absent or that category is disabled in the ACP, those attributes are omitted and the same tags run normally.

`type="text/plain"` is intentionally inert, so do not output it unconditionally unless your extension depends on Consent Manager being installed.

## ACP-managed integrations

The ACP includes a JSON setting for simple admin-managed integrations.

This is mainly for cases where a board admin wants to add a straightforward external analytics or advertising script URL directly from the ACP and have it appear in the consent UI. It is intentionally limited to simple metadata plus a script `src`, not arbitrary inline JavaScript.

## License

[GNU General Public License v2](license.txt)
