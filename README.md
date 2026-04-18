# Consent Manager

Consent Manager provides centralized, GDPR-oriented consent handling for phpBB. It keeps non-essential scripts out of the page until the visitor has explicitly granted consent, exposes a PHP registration API for other extensions, and provides a global browser API for consent-aware client code.

## How script blocking works

1. Consent Manager injects a lightweight necessary script that reads the stored consent state and exposes `window.consentManager`.
2. Extensions register optional integrations through the PHP service or `window.consentManager.registerScript()`.
3. Registered scripts are rendered into a non-executable JSON payload instead of normal `<script>` tags.
4. The runtime only creates executable script elements for categories the visitor has allowed.
5. Inline tracking snippets can be deferred by rendering them as:

```html
<script type="text/plain" data-consent-category="analytics">
	window.exampleTracker && window.exampleTracker.page();
</script>
```

Consent Manager only converts those placeholders into real scripts after consent exists for the matching category.

## PHP integration API

Inject `phpbb.consentmanager.service` and register integrations during the `phpbb.consentmanager.collect_registrations` event:

```php
$consent_manager->register('ext.vendor.analytics', [
	'label' => 'Vendor Analytics',
	'category' => 'analytics',
	'description' => 'Tracks page views for forum performance reporting.',
	'src' => 'https://cdn.example.com/analytics.js',
]);
```

You can also provide multiple deferred scripts:

```php
$consent_manager->register('ext.vendor.marketing', [
	'label' => 'Vendor Marketing',
	'category' => 'marketing',
	'description' => 'Loads ad attribution and remarketing tags.',
	'scripts' => [
		['id' => 'vendor-core', 'src' => 'https://cdn.example.com/core.js'],
		['id' => 'vendor-inline', 'inline' => 'window.vendor && window.vendor.boot();'],
	],
]);
```

## JavaScript integration API

Consent-aware client code can use:

```js
window.consentManager.hasConsent('analytics');
window.consentManager.onChange(function (state) {
	console.log(state.categories);
});
window.consentManager.registerScript('google-analytics', {
	category: 'analytics',
	src: 'https://www.googletagmanager.com/gtag/js?id=UA-XXXX',
	async: true
});
```

## Security considerations

- ACP-managed integrations only accept a constrained JSON schema and reject `javascript:` / `data:` / protocol-relative sources.
- The runtime uses JSON payloads instead of executable markup to reduce accidental early execution.
- Consent logging stores only a phpBB-side HMAC of the current user/session identifier, not the raw identifier.
- The logging endpoint requires a session-bound phpBB link hash to reduce CSRF risk.
- Inline script execution is supported only for trusted extension code. ACP-managed integrations intentionally do not accept arbitrary inline JavaScript.
