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

	const Content = () => {
		const parts = [];
		if ( settings.is_sandbox ) {
			parts.push(
				createElement( 'div', {
					key: 'sandbox',
					style: {
						background: '#fff3cd',
						borderLeft: '4px solid #ffc107',
						color: '#856404',
						padding: '8px 12px',
						marginBottom: '8px',
						borderRadius: '4px',
						fontSize: '13px',
					},
				}, '⚠ Sandbox Mode — No real money will be charged.' )
			);
		}
		if ( descriptionText ) {
			parts.push( createElement( 'div', { key: 'desc' }, descriptionText ) );
		}
		return parts.length ? createElement( 'div', null, ...parts ) : null;
	};

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
