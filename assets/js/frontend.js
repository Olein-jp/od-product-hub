document.addEventListener('click', (event) => {
	const button = event.target.closest('.odph-copy');
	if (!button) return;
	navigator.clipboard.writeText(button.dataset.license).then(() => {
		const status = button.parentElement.querySelector('.odph-copy-status');
		status.textContent = 'ライセンスキーをコピーしました。';
		button.textContent = 'コピー済み';
		setTimeout(() => {
			button.textContent = 'コピー';
			status.textContent = '';
		}, 2000);
	});
});

document.addEventListener('submit', (event) => {
	const form = event.target.closest('.odph-checkout-form');
	if (!form || form.dataset.submitting === 'true') return;
	form.dataset.submitting = 'true';
	form.setAttribute('aria-busy', 'true');
	const button = form.querySelector('button[type="submit"]');
	const status = form.querySelector('.odph-submit-status');
	if (button) {
		button.disabled = true;
		button.querySelector('.odph-button-label').textContent = '購入画面を準備しています…';
	}
	if (status) status.textContent = 'Stripe Checkoutを準備しています。';
});
