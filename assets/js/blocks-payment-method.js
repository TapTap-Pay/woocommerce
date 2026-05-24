/**
 * Registers the TapTap Pay payment method with the WooCommerce
 * Blocks checkout registry. Server-side counterpart lives in
 * includes/BlocksGateway.php — keep the `name` here in sync with
 * Plugin::GATEWAY_ID on PHP.
 */
( ( wc, wp ) => {
	if ( ! wc || ! wc.wcBlocksRegistry || ! wc.wcSettings ) {
		// Blocks runtime hasn't booted yet — happens on legacy
		// shortcode checkout pages that incidentally load our script.
		// The legacy Gateway class already covers those; nothing to do.
		return;
	}

	const { registerPaymentMethod } = wc.wcBlocksRegistry;
	const { getPaymentMethodData } = wc.wcSettings;
	const { createElement } = wp.element;
	const { decodeEntities } = wp.htmlEntities;

	const PAYMENT_METHOD_ID = 'taptap_pay';
	const settings = getPaymentMethodData( PAYMENT_METHOD_ID ) || {};

	const label = decodeEntities( settings.title || 'TapTap Pay' );
	const descriptionText = decodeEntities( settings.description || '' );

	const Label = () => {
		if ( ! settings.icon ) {
			return createElement( 'span', null, label );
		}
		return createElement(
			'span',
			{ style: { display: 'inline-flex', alignItems: 'center', gap: '8px' } },
			createElement( 'img', {
				src: settings.icon,
				alt: '',
				style: { maxHeight: '24px', width: 'auto' },
			} ),
			createElement( 'span', null, label )
		);
	};

	const Content = () =>
		descriptionText
			? createElement( 'div', null, descriptionText )
			: null;

	registerPaymentMethod( {
		name: PAYMENT_METHOD_ID,
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: () => true,
		ariaLabel: label,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )( window.wc, window.wp );
