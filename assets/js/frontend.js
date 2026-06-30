document.addEventListener('click', (event) => {
	const button = event.target.closest('.odph-copy');
	if (!button) return;
	const messages = window.odphFrontend || {};
	const status = button.parentElement.querySelector('.odph-copy-status');
	if (!navigator.clipboard || !navigator.clipboard.writeText) {
		status.textContent = messages.copyError || 'コピーできませんでした。';
		return;
	}
	navigator.clipboard.writeText(button.dataset.license).then(() => {
		status.textContent = messages.copySuccess || 'ライセンスキーをコピーしました。';
		button.textContent = messages.copied || 'コピー済み';
		setTimeout(() => {
			button.textContent = messages.copy || 'コピー';
			status.textContent = '';
		}, 5000);
	}).catch(() => {
		status.textContent = messages.copyError || 'コピーできませんでした。';
	});
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
		if (label && form.classList.contains('odph-checkout-form')) label.textContent = '購入画面を準備しています…';
	}
	if (status) {
		status.textContent = form.classList.contains('odph-portal-form')
			? ((window.odphFrontend || {}).portalLoading || 'Stripeの支払い・契約管理画面を準備しています。')
			: 'Stripe Checkoutを準備しています。';
	}
});
