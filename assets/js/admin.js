/* global leastudiosMailer */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	function showResult(el, message, type) {
		el.className = type;
		el.textContent = message;
		el.style.display = 'block';
	}

	ready(function () {
		var sendBtn = document.getElementById('leastudios-mailer-send-test');
		var healthBtn = document.getElementById('leastudios-mailer-health-check');
		var resultEl = document.getElementById('leastudios-mailer-test-result');

		if (sendBtn && resultEl) {
			sendBtn.addEventListener('click', function () {
				var toInput = document.getElementById('leastudios-mailer-test-to');
				var to = toInput ? toInput.value.trim() : '';

				if (!to) {
					showResult(resultEl, 'Please enter an email address.', 'error');
					return;
				}

				sendBtn.disabled = true;
				showResult(resultEl, leastudiosMailer.strings.sending, 'loading');

				var data = new FormData();
				data.append('action', 'leastudios_mailer_send_test');
				data.append('_wpnonce', leastudiosMailer.sendTestNonce);
				data.append('to', to);

				fetch(leastudiosMailer.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data,
				})
					.then(function (res) { return res.json(); })
					.then(function (res) {
						if (res.success) {
							showResult(resultEl, leastudiosMailer.strings.success + ' ' + res.data, 'success');
						} else {
							showResult(resultEl, leastudiosMailer.strings.error + ' ' + res.data, 'error');
						}
					})
					.catch(function (err) {
						showResult(resultEl, leastudiosMailer.strings.error + ' ' + err.message, 'error');
					})
					.finally(function () {
						sendBtn.disabled = false;
					});
			});
		}

		if (healthBtn && resultEl) {
			healthBtn.addEventListener('click', function () {
				healthBtn.disabled = true;
				showResult(resultEl, leastudiosMailer.strings.checking, 'loading');

				var data = new FormData();
				data.append('action', 'leastudios_mailer_health_check');
				data.append('_wpnonce', leastudiosMailer.healthCheckNonce);

				fetch(leastudiosMailer.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data,
				})
					.then(function (res) { return res.json(); })
					.then(function (res) {
						if (!res.success) {
							showResult(resultEl, leastudiosMailer.strings.error + ' ' + res.data, 'error');
							return;
						}

						var d = res.data;
						var html = '<div id="leastudios-mailer-health-result">';

						html += '<div class="health-item ' + (d.credentials.valid ? 'health-pass' : 'health-fail') + '">';
						html += (d.credentials.valid ? '&#10003;' : '&#10007;') + ' AWS Credentials: ';
						html += d.credentials.valid ? 'Valid' : (d.credentials.error || 'Invalid');
						html += '</div>';

						html += '<div class="health-item ' + (d.sender.verified ? 'health-pass' : 'health-fail') + '">';
						html += (d.sender.verified ? '&#10003;' : '&#10007;') + ' Sender Identity: ';
						html += d.sender.verified ? 'Verified' : (d.sender.error || 'Not verified');
						html += '</div>';

						html += '<div class="health-item ' + (d.overall ? 'health-pass' : 'health-fail') + '">';
						html += '<strong>' + (d.overall ? '&#10003; Ready to send' : '&#10007; Not ready') + '</strong>';
						html += '</div>';

						html += '</div>';

						resultEl.className = d.overall ? 'success' : 'error';
						resultEl.innerHTML = html;
						resultEl.style.display = 'block';
					})
					.catch(function (err) {
						showResult(resultEl, leastudiosMailer.strings.error + ' ' + err.message, 'error');
					})
					.finally(function () {
						healthBtn.disabled = false;
					});
			});
		}
	});
})();
