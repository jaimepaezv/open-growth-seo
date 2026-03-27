(function () {
	'use strict';

	const form = document.getElementById('ogs-setup-wizard-form');
	if (!form) {
		return;
	}
	const i18n = window.ogsSeoSetupWizard || {};

	const visibilityFields = form.querySelectorAll('input[name="ogs_wizard[visibility]"]');
	const confirmRow = document.getElementById('ogs-private-confirm');
	const confirmInput = form.querySelector('input[name="ogs_wizard[confirm_private]"]');

	const toggleConfirm = function () {
		let selected = 'keep';
		visibilityFields.forEach(function (field) {
			if (field.checked) {
				selected = field.value;
			}
		});
		if (confirmRow) {
			if (selected === 'private') {
				confirmRow.removeAttribute('hidden');
			} else {
				confirmRow.setAttribute('hidden', 'hidden');
				if (confirmInput) {
					confirmInput.removeAttribute('aria-invalid');
				}
				const staleWarning = form.querySelector('.ogs-wizard-inline-error');
				if (staleWarning) {
					staleWarning.remove();
				}
			}
		}
	};

	visibilityFields.forEach(function (field) {
		field.addEventListener('change', toggleConfirm);
	});

	toggleConfirm();

	form.addEventListener('submit', function (event) {
		const stepInput = form.querySelector('input[name="ogs_wizard_step"]');
		if (!stepInput) {
			return;
		}
		let action = 'next';
		if (event.submitter && event.submitter.name === 'ogs_wizard_action') {
			action = event.submitter.value;
		} else {
			const actionInput = form.querySelector('input[name="ogs_wizard_action"]');
			if (actionInput && actionInput.value) {
				action = actionInput.value;
			}
		}
		const step = parseInt(stepInput.value, 10);
		if (step === 2 && (action === 'next' || action === 'apply')) {
			let selected = 'keep';
			visibilityFields.forEach(function (field) {
				if (field.checked) {
					selected = field.value;
				}
			});
			if (selected === 'private' && confirmInput && !confirmInput.checked) {
				event.preventDefault();
				confirmInput.setAttribute('aria-invalid', 'true');
				if (!form.querySelector('.ogs-wizard-inline-error')) {
					const warning = document.createElement('p');
					warning.className = 'ogs-status ogs-status-bad ogs-wizard-inline-error';
					warning.setAttribute('role', 'alert');
					warning.textContent = i18n.confirmPrivateText || 'Confirm indexing warning before continuing.';
					form.prepend(warning);
				}
				confirmInput.focus();
				return;
			}
			if (confirmInput) {
				confirmInput.removeAttribute('aria-invalid');
			}
			const existingWarning = form.querySelector('.ogs-wizard-inline-error');
			if (existingWarning) {
				existingWarning.remove();
			}
		}
	});

	if (confirmInput) {
		confirmInput.addEventListener('change', function () {
			if (confirmInput.checked) {
				confirmInput.removeAttribute('aria-invalid');
				const staleWarning = form.querySelector('.ogs-wizard-inline-error');
				if (staleWarning) {
					staleWarning.remove();
				}
			}
		});
	}
})();
