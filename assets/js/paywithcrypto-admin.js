(function ($) {
	'use strict';

	var config = window.PWCAdmin || {};
	var i18n = config.i18n || {};

	$('#pwc-test-connection').on('click', function () {
		var button = $(this);
		var spinner = $('#pwc-test-connection-spinner');
		var status = $('#pwc-connection-status');
		var message = $('#pwc-connection-message');
		var details = $('#pwc-connection-details');

		button.prop('disabled', true);
		spinner.addClass('is-active');
		status.text(i18n.testing || 'Testing...').css('color', '#996800');
		message.text(i18n.testingMessage || 'Testing PayWithCrypto from this server...');

		$.post(config.ajaxUrl || window.ajaxurl, {
			action: 'pwc_test_connection',
			nonce: config.nonce || ''
		}).done(function (response) {
			var data = response && response.data ? response.data : {};
			var ok = !!(response && response.success && data.ok);

			status.text(ok ? (i18n.connected || 'Connected') : (i18n.failed || 'Failed')).css('color', ok ? '#008a20' : '#b32d2e');
			message.text(data.message || i18n.noDetails || 'No response details were returned.');
			$('[data-pwc-test-field="verdict"]').text(data.verdict || 'unknown');
			$('[data-pwc-test-field="http_code"]').text(data.http_code || '0');
			$('[data-pwc-test-field="app_key"]').text(data.app_key || '');
			$('[data-pwc-test-field="checked_at"]').text(data.checked_at || '');

			if (data.endpoint) {
				$('#pwc-connection-endpoint').text(data.endpoint);
			}

			details.show();
		}).fail(function (xhr) {
			var errorMessage = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : i18n.requestFailed || 'The connection test request failed in WordPress admin.';

			status.text(i18n.failed || 'Failed').css('color', '#b32d2e');
			message.text(errorMessage);
		}).always(function () {
			button.prop('disabled', false);
			spinner.removeClass('is-active');
		});
	});
}(jQuery));
