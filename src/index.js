const { __ } = window.wp.i18n;
const { decodeEntities } = window.wp.htmlEntities;
const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;

/**
 * Build a payment method for the block checkout from the data its PHP integration provides.
 *
 * @param {string} name               Payment method name, the same as the gateway ID.
 * @param {Object} settings           Data from get_payment_method_data().
 * @param {string} defaultLabel       Label when the merchant has not set a title.
 * @param {string} defaultDescription Description when the merchant has not set one.
 */
const buildPaymentMethod = ( name, settings, defaultLabel, defaultDescription ) => {
    const label = decodeEntities( settings.title ) || defaultLabel;

    /**
     * Content component
     */
    const Content = () => {
        return decodeEntities( settings.description || defaultDescription );
    };

    /**
     * Label component
     *
     * @param {*} props Props from payment API.
     */
    const Label = ( props ) => {
        const { PaymentMethodLabel } = props.components;
        const icons = Array.isArray( settings.icons ) ? settings.icons : [];
        return (
            <span style={ { display: 'flex', alignItems: 'center', width: '100%' } }>
                <PaymentMethodLabel text={ label } />
                <span style={ { display: 'flex', flexWrap: 'wrap', justifyContent: 'flex-end', alignItems: 'center', gap: '4px', marginLeft: 'auto' } }>
                    { icons.map( ( { name: iconName, label: iconLabel, url } ) => (
                        <img
                            key={ iconName }
                            src={ url }
                            alt={ decodeEntities( iconLabel || iconName ) }
                            loading="lazy"
                            decoding="async"
                            style={ { height: '24px', width: 'auto' } }
                        />
                    ) ) }
                </span>
            </span>
        );
    };

    return {
        name,
        label: <Label />,
        content: <Content />,
        edit: <Content />,
        canMakePayment: () => true,
        ariaLabel: label,
        supports: {
            features: settings.supports,
        },
    };
};

registerPaymentMethod(
    buildPaymentMethod(
        'straumur',
        getSetting( 'straumur_data', {} ),
        __( 'Straumur Payments', 'straumur-payments-for-woocommerce' ),
        'Secure payment via Straumur Hosted checkout'
    )
);

// Straumur Kortalán is its own option. Its data is only present when the merchant has switched
// it on, and the store only lists it for baskets Kortalán will finance.
const kortalanSettings = getSetting( 'straumur_kortalan_data', null );

if ( kortalanSettings ) {
    registerPaymentMethod(
        buildPaymentMethod(
            'straumur_kortalan',
            kortalanSettings,
            'Straumur Kortalán',
            ''
        )
    );
}
