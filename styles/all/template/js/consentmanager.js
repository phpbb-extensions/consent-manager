(function (window, document) {
	'use strict';

	var dataElement = document.getElementById('consent-manager-data');
	if (!dataElement)
	{
		return;
	}

	var payload = JSON.parse(dataElement.textContent || '{}');
	var root = document.getElementById('consent-manager-root');
	var queued = window.consentManager && window.consentManager._queue ? window.consentManager._queue.slice(0) : [];
	var listeners = [];
	var registry = {};
	var executedScripts = {};
	var state = loadState();
	var optionalCategories = [];
	var i;

	for (i = 0; i < payload.categories.length; i++)
	{
		if (!payload.categories[i].required && payload.categories[i].enabled)
		{
			optionalCategories.push(payload.categories[i].id);
		}
	}

	function setCookie(name, value)
	{
		document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=31536000; SameSite=Lax';
	}

	function getCookie(name)
	{
		var cookies = document.cookie ? document.cookie.split('; ') : [];
		var index;

		for (index = 0; index < cookies.length; index++)
		{
			if (cookies[index].indexOf(name + '=') === 0)
			{
				return decodeURIComponent(cookies[index].substring(name.length + 1));
			}
		}

		return '';
	}

	function loadState()
	{
		var raw = '';
		var parsed;

		try
		{
			if (window.localStorage)
			{
				raw = window.localStorage.getItem(payload.storageKey) || '';
			}
		}
		catch (error)
		{
			raw = '';
		}

		if (!raw)
		{
			raw = getCookie(payload.cookieName);
		}

		if (!raw)
		{
			return null;
		}

		try
		{
			parsed = JSON.parse(raw);
		}
		catch (error)
		{
			return null;
		}

		if (!parsed || parsed.version !== payload.version || !parsed.categories)
		{
			return null;
		}

		parsed.categories = normalizeCategories(parsed.categories);

		return parsed;
	}

	function persistState(nextState)
	{
		var serialized = JSON.stringify(nextState);

		try
		{
			if (window.localStorage)
			{
				window.localStorage.setItem(payload.storageKey, serialized);
			}
		}
		catch (error)
		{
		}

		setCookie(payload.cookieName, serialized);
	}

	function normalizeCategories(categories)
	{
		var allowed = ['necessary'];
		var index;

		for (index = 0; index < categories.length; index++)
		{
			if (optionalCategories.indexOf(categories[index]) !== -1)
			{
				allowed.push(categories[index]);
			}
		}

		return unique(allowed);
	}

	function unique(items)
	{
		var deduplicated = [];
		var index;

		for (index = 0; index < items.length; index++)
		{
			if (deduplicated.indexOf(items[index]) === -1)
			{
				deduplicated.push(items[index]);
			}
		}

		return deduplicated;
	}

	function hasConsent(category)
	{
		if (category === 'necessary')
		{
			return true;
		}

		return !!(state && state.categories.indexOf(category) !== -1);
	}

	function emitChange()
	{
		var snapshot = api.getState();
		var index;

		for (index = 0; index < listeners.length; index++)
		{
			try
			{
				listeners[index](snapshot);
			}
			catch (error)
			{
				if (window.console && typeof window.console.error === 'function')
				{
					window.console.error(error);
				}
			}
		}
	}

	function setState(categories)
	{
		state = {
			categories: normalizeCategories(categories),
			timestamp: new Date().toISOString(),
			version: payload.version
		};

		persistState(state);
		updateUi();
		processRegisteredScripts();
		processDeferredNodes();
		logDecision();
		emitChange();
	}

	function registerScript(id, options)
	{
		if (!id || !options || !options.category)
		{
			return;
		}

		options.id = id;
		registry[id] = options;
		executeScript(options);
	}

	function applyAttributes(element, attributes)
	{
		var name;

		for (name in attributes)
		{
			if (attributes.hasOwnProperty(name))
			{
				element.setAttribute(name, attributes[name]);
			}
		}
	}

	function executeScript(script)
	{
		var element;

		if (!script || executedScripts[script.id] || !hasConsent(script.category))
		{
			return;
		}

		element = document.createElement('script');
		element.type = 'text/javascript';
		if (script.src)
		{
			element.src = script.src;
			if (script.async)
			{
				element.async = true;
			}
			if (script.defer)
			{
				element.defer = true;
			}
		}
		else if (script.inline)
		{
			element.text = script.inline;
		}

		if (script.attributes)
		{
			applyAttributes(element, script.attributes);
		}

		document.head.appendChild(element);
		executedScripts[script.id] = true;
	}

	function processRegisteredScripts()
	{
		var scriptId;

		for (scriptId in registry)
		{
			if (registry.hasOwnProperty(scriptId))
			{
				executeScript(registry[scriptId]);
			}
		}
	}

	function processDeferredNodes()
	{
		var nodes = document.querySelectorAll('script[type="text/plain"][data-consent-category]');
		var index;
		var source;
		var liveScript;
		var attributeIndex;
		var attribute;

		for (index = 0; index < nodes.length; index++)
		{
			source = nodes[index];

			if (source.getAttribute('data-consent-processed') === '1' || !hasConsent(source.getAttribute('data-consent-category')))
			{
				continue;
			}

			liveScript = document.createElement('script');
			liveScript.type = 'text/javascript';

			for (attributeIndex = 0; attributeIndex < source.attributes.length; attributeIndex++)
			{
				attribute = source.attributes[attributeIndex];
				if (attribute.name === 'type' || attribute.name.indexOf('data-consent-') === 0)
				{
					continue;
				}

				liveScript.setAttribute(attribute.name, attribute.value);
			}

			if (source.src)
			{
				liveScript.src = source.src;
			}
			else
			{
				liveScript.text = source.textContent;
			}

			source.setAttribute('data-consent-processed', '1');
			source.parentNode.insertBefore(liveScript, source.nextSibling);
		}
	}

	function logDecision()
	{
		var request;

		if (!payload.logEndpoint || !state)
		{
			return;
		}

		request = new XMLHttpRequest();
		request.open('POST', payload.logEndpoint, true);
		request.setRequestHeader('Content-Type', 'application/json');
		request.send(JSON.stringify({
			hash: payload.logHash,
			version: payload.version,
			categories: state.categories
		}));
	}

	function groupServices(categoryId)
	{
		var services = [];
		var index;

		for (index = 0; index < payload.services.length; index++)
		{
			if (payload.services[index].category === categoryId && payload.services[index].description)
			{
				services.push(payload.services[index]);
			}
		}

		return services;
	}

	function renderUi()
	{
		var bannerHidden = state || !optionalCategories.length ? ' hidden="hidden"' : '';
		var modalHtml = '';
		var categoryIndex;
		var category;
		var services;
		var serviceIndex;

		for (categoryIndex = 0; categoryIndex < payload.categories.length; categoryIndex++)
		{
			category = payload.categories[categoryIndex];
			if (!category.enabled)
			{
				continue;
			}

			services = groupServices(category.id);

			modalHtml += '<section class="consent-manager-category">';
			modalHtml += '<div class="consent-manager-category-header">';
			modalHtml += '<div>';
			modalHtml += '<h3 class="consent-manager-category-title">' + escapeHtml(category.label) + '</h3>';
			modalHtml += '<p class="consent-manager-category-description">' + escapeHtml(category.description) + '</p>';
			if (services.length)
			{
				modalHtml += '<div class="consent-manager-category-services"><strong>' + escapeHtml(payload.strings.serviceListHeading) + '</strong><ul>';
				for (serviceIndex = 0; serviceIndex < services.length; serviceIndex++)
				{
					modalHtml += '<li><strong>' + escapeHtml(services[serviceIndex].label) + ':</strong> ' + escapeHtml(services[serviceIndex].description) + '</li>';
				}
				modalHtml += '</ul></div>';
			}
			modalHtml += '</div>';
			modalHtml += '<label class="consent-manager-toggle">';
			modalHtml += '<input type="checkbox" data-consent-toggle="' + escapeHtml(category.id) + '"' + (category.required ? ' checked="checked" disabled="disabled"' : '') + '>';
			modalHtml += '<span>' + (category.required ? escapeHtml(payload.strings.alwaysActive) : escapeHtml(payload.strings.allowed)) + '</span>';
			modalHtml += '</label>';
			modalHtml += '</div>';
			modalHtml += '</section>';
		}

		root.innerHTML = ''
			+ '<div class="consent-manager-banner" id="consent-manager-banner"' + bannerHidden + '>'
			+ '<h2 class="consent-manager-heading">' + escapeHtml(payload.banner.title) + '</h2>'
			+ '<p class="consent-manager-copy">' + escapeHtml(payload.banner.text) + '</p>'
			+ '<div class="consent-manager-actions">'
			+ '<button type="button" class="consent-manager-button" data-consent-action="accept-all">' + escapeHtml(payload.strings.acceptAll) + '</button>'
			+ '<button type="button" class="consent-manager-button" data-consent-action="reject-all">' + escapeHtml(payload.strings.rejectAll) + '</button>'
			+ '<button type="button" class="consent-manager-button" data-consent-action="open-settings">' + escapeHtml(payload.strings.customize) + '</button>'
			+ '</div>'
			+ '</div>'
			+ '<button type="button" class="consent-manager-link" id="consent-manager-link">' + escapeHtml(payload.strings.cookieSettings) + '</button>'
			+ '<div class="consent-manager-modal" id="consent-manager-modal" hidden="hidden" role="dialog" aria-modal="true">'
			+ '<div class="consent-manager-modal-panel">'
			+ '<div class="consent-manager-actions" style="justify-content: space-between; margin-top: 0;">'
			+ '<h2 class="consent-manager-heading" style="margin: 0;">' + escapeHtml(payload.banner.title) + '</h2>'
			+ '<button type="button" class="consent-manager-button" data-consent-action="close-settings">' + escapeHtml(payload.strings.close) + '</button>'
			+ '</div>'
			+ '<p class="consent-manager-copy">' + escapeHtml(payload.banner.text) + '</p>'
			+ modalHtml
			+ '<div class="consent-manager-actions">'
			+ '<button type="button" class="consent-manager-button consent-manager-button-primary" data-consent-action="save-settings">' + escapeHtml(payload.strings.savePreferences) + '</button>'
			+ '<button type="button" class="consent-manager-button" data-consent-action="accept-all">' + escapeHtml(payload.strings.acceptAll) + '</button>'
			+ '<button type="button" class="consent-manager-button" data-consent-action="reject-all">' + escapeHtml(payload.strings.rejectAll) + '</button>'
			+ '</div>'
			+ '</div>'
			+ '</div>';
	}

	function escapeHtml(value)
	{
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function openSettings()
	{
		var modal = document.getElementById('consent-manager-modal');
		var checkboxes = root.querySelectorAll('[data-consent-toggle]');
		var index;
		var checkbox;

		for (index = 0; index < checkboxes.length; index++)
		{
			checkbox = checkboxes[index];
			checkbox.checked = hasConsent(checkbox.getAttribute('data-consent-toggle'));
		}

		modal.hidden = false;
	}

	function closeSettings()
	{
		var modal = document.getElementById('consent-manager-modal');
		if (modal)
		{
			modal.hidden = true;
		}
	}

	function updateUi()
	{
		var banner = document.getElementById('consent-manager-banner');
		if (banner)
		{
			banner.hidden = !!state || !optionalCategories.length;
		}
	}

	function selectedOptionalCategories()
	{
		var selected = [];
		var checkboxes = root.querySelectorAll('[data-consent-toggle]');
		var index;

		for (index = 0; index < checkboxes.length; index++)
		{
			if (checkboxes[index].checked)
			{
				selected.push(checkboxes[index].getAttribute('data-consent-toggle'));
			}
		}

		return selected;
	}

	function bindUi()
	{
		root.addEventListener('click', function (event) {
			var action = event.target.getAttribute('data-consent-action');
			if (!action)
			{
				if (event.target.id === 'consent-manager-link')
				{
					openSettings();
				}
				return;
			}

			if (action === 'accept-all')
			{
				setState(optionalCategories.concat(['necessary']));
				closeSettings();
			}
			else if (action === 'reject-all')
			{
				setState(['necessary']);
				closeSettings();
			}
			else if (action === 'open-settings')
			{
				openSettings();
			}
			else if (action === 'close-settings')
			{
				closeSettings();
			}
			else if (action === 'save-settings')
			{
				setState(selectedOptionalCategories().concat(['necessary']));
				closeSettings();
			}
		});
	}

	var api = {
		registerScript: registerScript,
		hasConsent: hasConsent,
		openSettings: openSettings,
		onChange: function (callback) {
			if (typeof callback === 'function')
			{
				listeners.push(callback);
			}
		},
		getState: function () {
			return state ? {
				categories: state.categories.slice(0),
				timestamp: state.timestamp,
				version: state.version
			} : null;
		}
	};

	renderUi();
	bindUi();

	window.consentManager = api;

	for (i = 0; i < payload.scripts.length; i++)
	{
		registerScript(payload.scripts[i].id, payload.scripts[i]);
	}

	for (i = 0; i < queued.length; i++)
	{
		if (queued[i][0] === 'registerScript')
		{
			registerScript(queued[i][1], queued[i][2]);
		}
		else if (queued[i][0] === 'onChange')
		{
			api.onChange(queued[i][1]);
		}
		else if (queued[i][0] === 'openSettings')
		{
			openSettings();
		}
	}

	updateUi();
	processRegisteredScripts();
	processDeferredNodes();
})(window, document);
