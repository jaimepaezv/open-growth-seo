(function () {
	'use strict';

	const titleTemplate = document.querySelector('input[name="ogs[title_template]"]');
	const descTemplate = document.querySelector('input[name="ogs[meta_description_template]"]');
	const separator = document.querySelector('input[name="ogs[title_separator]"]');
	const ogEnabled = document.querySelector('select[name="ogs[og_enabled]"]');
	const twitterEnabled = document.querySelector('select[name="ogs[twitter_enabled]"]');
	const preview = document.getElementById('ogs-snippet-preview');
	if (!titleTemplate || !descTemplate || !separator || !preview) {
		return;
	}

	const ctx = window.ogsSeoSearchAppearance || {};
	const titleOut = preview.querySelector('.ogs-snippet-title');
	const descOut = preview.querySelector('.ogs-snippet-desc');
	const socialTitleOut = preview.querySelector('[data-role="social-title"]');
	const socialDescOut = preview.querySelector('[data-role="social-desc"]');
	const ogEnabledOut = preview.querySelector('[data-role="og-enabled"]');
	const twitterEnabledOut = preview.querySelector('[data-role="twitter-enabled"]');
	const titleCountOut = preview.querySelector('[data-role="serp-title-count"]');
	const descCountOut = preview.querySelector('[data-role="serp-desc-count"]');
	const hintsOut = preview.querySelector('[data-role="rich-hints"]');
	const statusOut = preview.querySelector('[data-role="preview-status"]');

	let requestTimer = null;
	let requestCounter = 0;

	const safeText = function (value, fallback) {
		const text = (value || '').toString().trim();
		return text || fallback;
	};

	const replaceTokens = function (template, values) {
		let output = template;
		Object.keys(values).forEach(function (token) {
			output = output.split(token).join(values[token]);
		});
		return output.replace(/\s+/g, ' ').trim().replace(/<[^>]*>/g, '');
	};

	const trimForPreview = function (text, max) {
		if (text.length <= max) {
			return text;
		}
		return text.slice(0, Math.max(0, max - 3)).trim() + '...';
	};

	const updatePreview = function () {
		const siteName = safeText(ctx.siteName, 'Site');
		const siteDesc = safeText(ctx.siteDescription, 'Site description');
		const sampleTitle = safeText(ctx.sampleTitle, 'Example Page');
		const sampleExcerpt = safeText(ctx.sampleExcerpt, siteDesc);
		const query = safeText(ctx.searchQuery, 'example query');
		const sep = separator.value || '|';

		const resolvedTitle = replaceTokens(titleTemplate.value, {
			'%%title%%': sampleTitle,
			'%%sitename%%': siteName,
			'%%sep%%': sep,
			'%%site_description%%': siteDesc
		});
		const resolvedDesc = replaceTokens(descTemplate.value, {
			'%%excerpt%%': sampleExcerpt,
			'%%sitename%%': siteName,
			'%%site_description%%': siteDesc,
			'%%query%%': query
		});

		const finalTitle = trimForPreview(safeText(resolvedTitle, sampleTitle + ' ' + sep + ' ' + siteName), 60);
		const finalDesc = trimForPreview(safeText(resolvedDesc, sampleExcerpt), 160);

		titleOut.textContent = finalTitle;
		descOut.textContent = finalDesc;
		if (socialTitleOut) {
			socialTitleOut.textContent = trimForPreview(finalTitle, 90);
		}
		if (socialDescOut) {
			socialDescOut.textContent = trimForPreview(finalDesc, 200);
		}
		if (ogEnabledOut && ogEnabled) {
			ogEnabledOut.textContent = ogEnabled.value === '1' ? safeText(ctx.enabledLabel, 'Enabled') : safeText(ctx.disabledLabel, 'Disabled');
		}
		if (twitterEnabledOut && twitterEnabled) {
			twitterEnabledOut.textContent = twitterEnabled.value === '1' ? safeText(ctx.enabledLabel, 'Enabled') : safeText(ctx.disabledLabel, 'Disabled');
		}
		if (titleCountOut) {
			titleCountOut.textContent = String(finalTitle.length);
		}
		if (descCountOut) {
			descCountOut.textContent = String(finalDesc.length);
		}
	};

	const setStatus = function (text) {
		if (statusOut) {
			statusOut.textContent = text;
		}
	};

	const updateHints = function (hints) {
		if (!hintsOut) {
			return;
		}
		hintsOut.innerHTML = '';
		(hints || []).forEach(function (hint) {
			const li = document.createElement('li');
			li.textContent = hint;
			hintsOut.appendChild(li);
		});
	};

	const syncWithResolver = function () {
		if (!ctx.previewEndpoint) {
			return;
		}
		const currentRequest = ++requestCounter;
		const payload = {
			title_template: titleTemplate.value || '',
			meta_description_template: descTemplate.value || '',
			title_separator: separator.value || '',
			sample_title: safeText(ctx.sampleTitle, 'Example Service Page'),
			sample_excerpt: safeText(ctx.sampleExcerpt, safeText(ctx.siteDescription, 'Site description')),
			search_query: safeText(ctx.searchQuery, 'example query'),
			og_enabled: ogEnabled ? ogEnabled.value === '1' : true,
			twitter_enabled: twitterEnabled ? twitterEnabled.value === '1' : true
		};

		setStatus(safeText(ctx.loadingText, 'Updating preview...'));
		window
			.fetch(ctx.previewEndpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': safeText(ctx.nonce, '')
				},
				credentials: 'same-origin',
				body: JSON.stringify(payload)
			})
			.then(function (response) {
				if (!response.ok) {
					throw new Error('Preview resolver failed with status ' + response.status);
				}
				return response.json();
			})
			.then(function (data) {
				if (currentRequest !== requestCounter || !data) {
					return;
				}
				if (titleOut && data.serp_title) {
					titleOut.textContent = data.serp_title;
				}
				if (descOut && data.serp_description) {
					descOut.textContent = data.serp_description;
				}
				if (socialTitleOut && data.social_title) {
					socialTitleOut.textContent = data.social_title;
				}
				if (socialDescOut && data.social_description) {
					socialDescOut.textContent = data.social_description;
				}
				if (titleCountOut && typeof data.title_count !== 'undefined') {
					titleCountOut.textContent = String(data.title_count);
				}
				if (descCountOut && typeof data.description_count !== 'undefined') {
					descCountOut.textContent = String(data.description_count);
				}
				if (data.schema_hints) {
					updateHints(data.schema_hints);
				}
				setStatus(safeText(ctx.updatedText, 'Preview updated from live resolver.'));
			})
			.catch(function () {
				if (currentRequest !== requestCounter) {
					return;
				}
				setStatus(safeText(ctx.errorText, 'Live preview unavailable. Showing local fallback.'));
			});
	};

	const scheduleSync = function () {
		if (requestTimer) {
			window.clearTimeout(requestTimer);
		}
		requestTimer = window.setTimeout(syncWithResolver, 180);
	};

	titleTemplate.addEventListener('input', updatePreview);
	descTemplate.addEventListener('input', updatePreview);
	separator.addEventListener('input', updatePreview);
	if (ogEnabled) {
		ogEnabled.addEventListener('change', updatePreview);
	}
	if (twitterEnabled) {
		twitterEnabled.addEventListener('change', updatePreview);
	}
	titleTemplate.addEventListener('input', scheduleSync);
	descTemplate.addEventListener('input', scheduleSync);
	separator.addEventListener('input', scheduleSync);
	if (ogEnabled) {
		ogEnabled.addEventListener('change', scheduleSync);
	}
	if (twitterEnabled) {
		twitterEnabled.addEventListener('change', scheduleSync);
	}
	updatePreview();
	scheduleSync();
})();
