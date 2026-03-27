(function () {
	'use strict';

	const config = window.ogsSeoDashboard;
	if (!config) {
		return;
	}

	const root = document.querySelector('[data-ogs-live-status]');
	if (!root) {
		return;
	}

	const endpoints = config.endpoints || {};
	const texts = config.texts || {};

	function getText(key, fallback) {
		if (typeof texts[key] === 'string' && texts[key].length > 0) {
			return texts[key];
		}
		return fallback;
	}

	function createParagraph(className, message) {
		const paragraph = document.createElement('p');
		if (className) {
			paragraph.className = className;
		}
		paragraph.textContent = message;
		return paragraph;
	}

	function clearRoot() {
		root.replaceChildren();
	}

	function setLoadingState() {
		root.setAttribute('aria-busy', 'true');
		clearRoot();
		root.appendChild(createParagraph('description', getText('loadingText', 'Loading live status checks...')));
	}

	function setErrorState() {
		root.setAttribute('aria-busy', 'false');
		clearRoot();
		root.appendChild(createParagraph('ogs-status ogs-status-bad', getText('errorText', 'Live status unavailable.')));
	}

	function toNumber(value) {
		const parsed = Number(value);
		return Number.isFinite(parsed) ? parsed : 0;
	}

	function formatDate(timestamp) {
		const numeric = toNumber(timestamp);
		if (numeric <= 0) {
			return getText('noneLabel', 'None');
		}
		const date = new Date(numeric * 1000);
		return date.toLocaleString();
	}

	function appendDetails(container, details) {
		if (!Array.isArray(details) || details.length === 0) {
			return;
		}
		const list = document.createElement('dl');
		list.className = 'ogs-live-check-details';
		details.forEach(function (detail) {
			const itemLabel = typeof detail.label === 'string' ? detail.label : '';
			const itemValue = typeof detail.value === 'string' ? detail.value : '';
			if (!itemLabel || !itemValue) {
				return;
			}
			const term = document.createElement('dt');
			term.textContent = itemLabel;
			const description = document.createElement('dd');
			description.textContent = itemValue;
			list.appendChild(term);
			list.appendChild(description);
		});
		if (list.childElementCount > 0) {
			container.appendChild(list);
		}
	}

	function appendCheck(container, check) {
		const item = document.createElement('li');
		item.className = 'ogs-live-check ogs-live-check-' + check.status;

		const title = document.createElement('h3');
		title.className = 'ogs-live-check-title';
		title.textContent = check.label;
		item.appendChild(title);

		const summary = createParagraph('ogs-status ogs-status-' + check.status, check.summary);
		item.appendChild(summary);

		appendDetails(item, check.details);
		container.appendChild(item);
	}

	function renderChecks(checks) {
		root.setAttribute('aria-busy', 'false');
		clearRoot();

		if (!Array.isArray(checks) || checks.length === 0) {
			root.appendChild(createParagraph('ogs-empty', getText('emptyText', 'No live data was returned.')));
			return;
		}

		root.appendChild(createParagraph('description', getText('updatedText', 'Live checks refreshed.')));
		const list = document.createElement('ul');
		list.className = 'ogs-live-check-list';
		checks.forEach(function (check) {
			appendCheck(list, check);
		});
		root.appendChild(list);
	}

	function fetchJson(url) {
		if (!url || typeof url !== 'string') {
			return Promise.reject(new Error('Missing URL'));
		}
		return fetch(url, {
			method: 'GET',
			headers: {
				'X-WP-Nonce': config.nonce,
				'Content-Type': 'application/json'
			}
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('Request failed');
			}
			return response.json();
		});
	}

	function buildSitemapsCheck(data) {
		const postTypes = Array.isArray(data.post_types) ? data.post_types : [];
		const enabled = !!data.enabled && postTypes.length > 0;
		return {
			label: getText('sitemapsLabel', 'Sitemaps runtime'),
			status: enabled ? 'good' : 'warn',
			summary: enabled ? getText('sitemapsEnabledText', 'Sitemaps are enabled.') : getText('sitemapsDisabledText', 'Sitemaps are not fully configured.'),
			details: [
				{
					label: getText('indexUrlLabel', 'Index URL'),
					value: typeof data.index_url === 'string' && data.index_url.length > 0 ? data.index_url : getText('statusUnavailableText', 'Status unavailable.')
				},
				{
					label: getText('postTypesLabel', 'Post types'),
					value: postTypes.length > 0 ? postTypes.join(', ') : getText('noneLabel', 'None')
				}
			]
		};
	}

	function buildAuditCheck(data) {
		const issues = Array.isArray(data.issues) ? data.issues : [];
		let critical = 0;
		let important = 0;
		let minor = 0;

		issues.forEach(function (issue) {
			const severity = typeof issue.severity === 'string' ? issue.severity.toLowerCase() : '';
			if (severity === 'critical') {
				critical += 1;
			} else if (severity === 'important') {
				important += 1;
			} else {
				minor += 1;
			}
		});

		let summary = getText('auditHealthyText', 'No current audit issues detected.');
		let status = 'good';
		if (toNumber(data.last_run) <= 0) {
			status = 'warn';
			summary = getText('auditPendingText', 'No completed audit yet.');
		} else if (critical > 0) {
			status = 'bad';
			summary = getText('auditCriticalText', 'Critical issues detected in latest audit.');
		} else if (important > 0) {
			status = 'warn';
			summary = getText('auditNeedsAttentionText', 'Important issues detected in latest audit.');
		}

		const state = data.state && typeof data.state === 'object' ? data.state : {};
		if (state.finished === false) {
			status = status === 'bad' ? 'bad' : 'warn';
			summary += ' ' + getText('auditInProgressText', 'Incremental scan appears in progress.');
		}

		return {
			label: getText('auditLabel', 'Audit runtime'),
			status: status,
			summary: summary,
			details: [
				{
					label: getText('lastRunLabel', 'Last audit run'),
					value: formatDate(data.last_run)
				},
				{
					label: getText('issuesLabel', 'Issues'),
					value: getText('criticalLabel', 'Critical') + ': ' + String(critical) + ', ' + getText('importantLabel', 'Important') + ': ' + String(important) + ', ' + getText('minorLabel', 'Minor') + ': ' + String(minor)
				}
			]
		};
	}

	function buildIntegrationsCheck(data) {
		const summary = data && typeof data.summary === 'object' ? data.summary : {};
		const enabled = toNumber(summary.enabled);
		const configured = toNumber(summary.configured);
		const connected = toNumber(summary.connected);

		let status = 'warn';
		let message = getText('integrationsOptionalText', 'No optional integrations enabled.');
		if (enabled > 0) {
			if (configured < enabled) {
				message = getText('integrationsNeedsConfigText', 'One or more enabled integrations need configuration.');
			} else if (connected < enabled) {
				message = getText('integrationsNeedConnectionText', 'Some enabled integrations are not currently connected.');
			} else {
				status = 'good';
				message = getText('integrationsHealthyText', 'Enabled integrations are configured.');
			}
		}

		return {
			label: getText('integrationsLabel', 'Integrations runtime'),
			status: status,
			summary: message,
			details: [
				{
					label: getText('enabledLabel', 'Enabled'),
					value: String(enabled)
				},
				{
					label: getText('configuredLabel', 'Configured'),
					value: String(configured)
				},
				{
					label: getText('connectedLabel', 'Connected'),
					value: String(connected)
				}
			]
		};
	}

	setLoadingState();

	const requests = [
		fetchJson(endpoints.sitemaps || config.restUrl),
		fetchJson(endpoints.audit || config.auditRestUrl),
		fetchJson(endpoints.integrations || config.integrationsRestUrl)
	];

	Promise.allSettled(requests)
		.then(function (results) {
			const checks = [];
			const hasSuccess = results.some(function (result) {
				return result.status === 'fulfilled';
			});

			if (!hasSuccess) {
				setErrorState();
				return;
			}

			if (results[0] && results[0].status === 'fulfilled') {
				checks.push(buildSitemapsCheck(results[0].value));
			} else {
				checks.push({
					label: getText('sitemapsLabel', 'Sitemaps runtime'),
					status: 'bad',
					summary: getText('statusUnavailableText', 'Status unavailable.'),
					details: []
				});
			}

			if (results[1] && results[1].status === 'fulfilled') {
				checks.push(buildAuditCheck(results[1].value));
			} else {
				checks.push({
					label: getText('auditLabel', 'Audit runtime'),
					status: 'bad',
					summary: getText('statusUnavailableText', 'Status unavailable.'),
					details: []
				});
			}

			if (results[2] && results[2].status === 'fulfilled') {
				checks.push(buildIntegrationsCheck(results[2].value));
			} else {
				checks.push({
					label: getText('integrationsLabel', 'Integrations runtime'),
					status: 'bad',
					summary: getText('statusUnavailableText', 'Status unavailable.'),
					details: []
				});
			}

			renderChecks(checks);
		})
		.catch(function () {
			setErrorState();
		});
})();
