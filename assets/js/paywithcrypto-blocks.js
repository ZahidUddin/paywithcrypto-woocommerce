(function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settingsApi = window.wc && window.wc.wcSettings;
	var element = window.wp && window.wp.element;
	var htmlEntities = window.wp && window.wp.htmlEntities;

	if (!registry || !settingsApi || !element) {
		return;
	}

	var settings = settingsApi.getSetting('paywithcrypto_data', {});
	var decode = htmlEntities && htmlEntities.decodeEntities ? htmlEntities.decodeEntities : function (value) { return value; };
	var label = decode(settings.title || 'Pay with Crypto');
	var description = decode(settings.description || 'Pay securely using crypto wallet transfer.');

	var Content = function (props) {
		void props;

		var children = [];
		if (description) {
			children.push(element.createElement('p', { key: 'description' }, description));
		}

		return element.createElement('div', null, children);
	};

	registry.registerPaymentMethod({
		name: 'paywithcrypto',
		label: label,
		content: element.createElement(Content, null),
		edit: element.createElement(Content, null),
		canMakePayment: function () { return true; },
		ariaLabel: label,
		supports: {
			features: settings.supports || ['products']
		}
	});
}());
