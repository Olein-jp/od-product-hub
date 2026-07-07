document.addEventListener('click', (event) => {
	const button = event.target.closest('.odph-copy');
	if (!button) return;
	const messages = window.odphFrontend || {};
	const status = button.parentElement.querySelector('.odph-copy-status');
	const source = document.getElementById(button.dataset.copyTarget || '');
	if (!source || !navigator.clipboard || !navigator.clipboard.writeText) {
		status.textContent = messages.copyError || 'Could not copy the license key.';
		return;
	}
	navigator.clipboard.writeText(source.textContent).then(() => {
		status.textContent = messages.copySuccess || 'License key copied.';
		button.textContent = messages.copied || 'Copied';
		setTimeout(() => {
			button.textContent = messages.copy || 'Copy';
			status.textContent = '';
		}, 5000);
	}).catch(() => {
		status.textContent = messages.copyError || 'Could not copy the license key.';
	});
});

document.addEventListener('DOMContentLoaded', () => {
	const alert = document.querySelector('.odph-surface .odph-alert[tabindex="-1"]');
	if (alert) alert.focus();
});

document.addEventListener('submit', (event) => {
	const form = event.target.closest('.odph-checkout-form, .odph-portal-form');
	if (!form) return;
	if (form.dataset.submitting === 'true') {
		event.preventDefault();
		return;
	}
	form.dataset.submitting = 'true';
	form.setAttribute('aria-busy', 'true');
	const button = form.querySelector('button[type="submit"]');
	const status = form.querySelector('.odph-submit-status');
	if (button) {
		button.disabled = true;
		const label = button.querySelector('.odph-button-label');
		if (label && form.classList.contains('odph-checkout-form')) label.textContent = (window.odphFrontend || {}).checkoutButtonLoading || 'Preparing checkout…';
	}
	if (status) {
		status.textContent = form.classList.contains('odph-portal-form')
			? ((window.odphFrontend || {}).portalLoading || 'Preparing Stripe billing and subscription management.')
			: ((window.odphFrontend || {}).checkoutLoading || 'Preparing Stripe Checkout.');
	}
});
