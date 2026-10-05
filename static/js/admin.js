/**
 * Unyson+ → Short Links admin: copy buttons, slug Generate + live availability
 * check (REST), the disabled-fallback toggle, and fw.confirm() on destructive
 * row actions.
 */
(function ($) {
	'use strict';

	var cfg = window.fwShortLinks || {};

	function api(path) {
		return fetch(cfg.restUrl + path, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		}).then(function (r) {
			if (!r.ok) {
				throw new Error(r.status);
			}
			return r.json();
		});
	}

	function copy(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		// http:// admin (local installs): the old way still works.
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild(ta);
		ta.select();
		document.execCommand('copy');
		document.body.removeChild(ta);
		return Promise.resolve();
	}

	$(function () {
		var $root = $('.fw-ext-short-links');

		if (!$root.length) {
			return;
		}

		$root.on('click', '.fw-sl-copy', function () {
			var $btn = $(this),
				label = $btn.text();

			copy(String($btn.data('copy'))).then(function () {
				$btn.addClass('is-copied').text(cfg.l10n.copied);
				setTimeout(function () {
					$btn.removeClass('is-copied').text(label);
				}, 1400);
			});
		});

		$root.on('click', '.fw-sl-confirm', function (e) {
			e.preventDefault();
			var href = this.href;
			fw.confirm($(this).data('confirm'), function () {
				window.location.href = href;
			}, { severity: 'warning' });
		});

		// Disabled → show the fallback field.
		var $form = $root.find('.fw-sl-form');

		function syncStatus() {
			$form.toggleClass('is-disabled', $form.find('input[name="status"]:checked').val() === 'disabled');
		}

		$form.on('change', 'input[name="status"]', syncStatus);
		syncStatus();

		// Slug: Generate + debounced availability check.
		var $slug = $form.find('#fw-sl-slug'),
			$status = $form.find('.fw-sl-slug__status'),
			hint = $status.text(),
			timer = null,
			seq = 0;

		function setStatus(text, state) {
			$status.text(text).removeClass('is-ok is-bad').addClass(state ? 'is-' + state : '');
		}

		function check() {
			var value = $.trim($slug.val()),
				mine = ++seq;

			if (!value || value.toLowerCase() === String($slug.data('original')).toLowerCase()) {
				setStatus(hint, '');
				return;
			}

			setStatus(cfg.l10n.checking, '');

			api('slug?check=' + encodeURIComponent(value)).then(function (res) {
				if (mine !== seq) {
					return; // a newer keystroke won
				}
				if (res.available) {
					setStatus(cfg.l10n.available + ' — ' + res.short_url, 'ok');
				} else {
					setStatus(res.problem, 'bad');
				}
			}).catch(function () {
				setStatus(cfg.l10n.failed, 'bad');
			});
		}

		$slug.on('input', function () {
			clearTimeout(timer);
			timer = setTimeout(check, 350);
		});

		// Import runner: one chunk per request until the job reports "done".
		var $job = $root.find('.fw-sl-job'),
			running = false;

		function fmt(s) {
			var args = Array.prototype.slice.call(arguments, 1), i = 0;
			return String(s).replace(/%(\d+)\$s|%s/g, function (m, n) {
				return n ? args[n - 1] : args[i++];
			});
		}

		function step() {
			$.post(cfg.ajaxUrl, { action: cfg.stepAction, nonce: cfg.stepNonce, job: $job.data('job') })
				.done(function (res) {
					if (!res || !res.success) {
						running = false;
						$job.find('.fw-sl-job__status').addClass('fw-sl-bad').text(res && res.data ? res.data.message : cfg.l10n.failed);
						return;
					}

					var d = res.data, $bar = $job.find('progress');
					$bar.attr('max', Math.max(1, d.total)).val(d.done);

					if (d.phase === 'links' || d.phase === 'review') {
						$job.find('.fw-sl-job__status').text(fmt(cfg.l10n.importing, d.done.toLocaleString(), d.total.toLocaleString()));
					} else if (d.phase === 'clicks') {
						$bar.removeAttr('value');
						$job.find('.fw-sl-job__status').text(fmt(cfg.l10n.clicks, d.result.clicks.toLocaleString()));
					} else if (d.phase === 'finishing') {
						$job.find('.fw-sl-job__status').text(cfg.l10n.finishing);
					}

					if (d.phase === 'done') {
						running = false;
						window.location.reload(); // the server renders the summary
						return;
					}

					step();
				})
				.fail(function () {
					running = false;
					$job.find('.fw-sl-job__status').addClass('fw-sl-bad').text(cfg.l10n.failed);
				});
		}

		$job.on('click', '.fw-sl-job__start', function () {
			running = true;
			$job.find('.fw-sl-job__actions').prop('hidden', true);
			$job.find('.fw-sl-job__progress').prop('hidden', false);
			step();
		});

		// A job left mid-way (reload, closed tab) picks up where it stopped.
		if ($job.length && ['links', 'clicks', 'finishing'].indexOf(String($job.data('phase'))) !== -1) {
			running = true;
			step();
		}

		window.addEventListener('beforeunload', function (e) {
			if (running) {
				e.preventDefault();
				e.returnValue = cfg.l10n.leave;
			}
		});

		$form.on('click', '.fw-sl-generate', function () {
			var $btn = $(this).prop('disabled', true);

			api('slug').then(function (res) {
				$slug.val(res.slug);
				setStatus(cfg.l10n.available + ' — ' + res.short_url, 'ok');
			}).catch(function () {
				setStatus(cfg.l10n.failed, 'bad');
			}).then(function () {
				$btn.prop('disabled', false);
			});
		});
	});
})(jQuery);
