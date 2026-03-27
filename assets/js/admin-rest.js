(function () {
	'use strict';

	const config = window.ogsSeoAdminRest || {};
	const texts = config.texts || {};

	const prettyPrint = function (payload) {
		if (typeof payload === 'string') {
			try {
				return JSON.stringify(JSON.parse(payload), null, 2);
			} catch (error) {
				return payload;
			}
		}
		return JSON.stringify(payload, null, 2);
	};

	const setButtonState = function (button, isExpanded) {
		if (!button) {
			return;
		}
		button.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
		if (isExpanded) {
			button.textContent = texts.hideResponse || button.getAttribute('data-hide-label') || 'Hide response';
			return;
		}
		button.textContent = button.getAttribute('data-original-label') || texts.showResponse || button.getAttribute('data-default-label') || 'Show response';
	};

	const fetchResponse = function (button, panel) {
		const endpoint = button.getAttribute('data-endpoint') || '';
		if (!endpoint) {
			return;
		}

		panel.classList.remove('ogs-is-hidden');
		panel.textContent = texts.loading || 'Loading REST response...';

		window.fetch(endpoint, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce || '',
				Accept: 'application/json'
			}
		})
			.then(function (response) {
				return response.text().then(function (body) {
					let payload = body;
					try {
						payload = JSON.parse(body);
					} catch (error) {
						// Keep raw text if body is not JSON.
					}
					if (!response.ok) {
						const errorOutput = typeof payload === 'string'
							? payload
							: prettyPrint(payload);
						throw new Error(errorOutput);
					}
					return payload;
				});
			})
			.then(function (payload) {
				panel.textContent = prettyPrint(payload);
				button.setAttribute('data-has-response', '1');
				setButtonState(button, true);
			})
			.catch(function (error) {
				panel.textContent = error && error.message ? error.message : (texts.fetchFailed || 'REST request failed.');
				button.setAttribute('data-has-response', '1');
				setButtonState(button, true);
			});
	};

	document.querySelectorAll('.ogs-rest-action').forEach(function (button) {
		const panel = button.parentElement && button.parentElement.parentElement
			? button.parentElement.parentElement.querySelector('.ogs-rest-response')
			: null;

		button.setAttribute('data-original-label', button.textContent);
		setButtonState(button, false);

		if (!panel) {
			return;
		}

		button.addEventListener('click', function () {
			const isExpanded = button.getAttribute('aria-expanded') === 'true';

			if (isExpanded) {
				panel.classList.add('ogs-is-hidden');
				setButtonState(button, false);
				return;
			}

			fetchResponse(button, panel);
		});
	});

	document.querySelectorAll('.ogs-rest-copy').forEach(function (button) {
		button.setAttribute('data-original-label', button.textContent);
		button.addEventListener('click', function () {
			const endpoint = button.getAttribute('data-endpoint') || '';
			if (!endpoint) {
				return;
			}

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(endpoint)
					.then(function () {
						button.textContent = texts.copied || 'Route copied.';
						window.setTimeout(function () {
							button.textContent = button.getAttribute('data-original-label') || 'Copy route';
						}, 1800);
					})
					.catch(function () {
						window.alert(texts.copyFailed || 'Copy the route manually from the code field.');
					});
				return;
			}

			window.alert(texts.copyFailed || 'Copy the route manually from the code field.');
		});
	});

	const helpModal = document.querySelector('[data-ogs-help-modal]');
	const helpModalContent = helpModal ? helpModal.querySelector('[data-ogs-help-modal-content]') : null;
	const modalTitle = helpModal ? helpModal.querySelector('#ogs-help-modal-title') : null;

	const closeHelpModal = function () {
		if (!helpModal || !helpModalContent) {
			return;
		}
		helpModalContent.replaceChildren();
		if (modalTitle) {
			modalTitle.textContent = 'Verification help';
		}
		helpModal.classList.add('ogs-is-hidden');
		helpModal.setAttribute('hidden', 'hidden');
		document.body.classList.remove('ogs-help-modal-open');
	};

	const openHelpModal = function (templateId) {
		if (!helpModal || !helpModalContent || !templateId) {
			return;
		}
		const template = document.getElementById(templateId);
		if (!template || !('content' in template)) {
			return;
		}
		helpModalContent.replaceChildren(template.content.cloneNode(true));
		const heading = helpModalContent.querySelector('h3');
		if (heading && modalTitle) {
			modalTitle.textContent = heading.textContent || 'Verification help';
			heading.remove();
		}
		helpModal.classList.remove('ogs-is-hidden');
		helpModal.removeAttribute('hidden');
		document.body.classList.add('ogs-help-modal-open');
	};

	document.querySelectorAll('[data-ogs-help-template]').forEach(function (trigger) {
		trigger.addEventListener('click', function () {
			openHelpModal(trigger.getAttribute('data-ogs-help-template'));
		});
	});

	if (helpModal) {
		helpModal.addEventListener('click', function (event) {
			if (event.target && event.target.getAttribute('data-ogs-help-close') === '1') {
				closeHelpModal();
			}
		});
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && !helpModal.classList.contains('ogs-is-hidden')) {
				closeHelpModal();
			}
		});
	}
})();
