const { __, sprintf } = window.wp.i18n;
const { decodeEntities } = window.wp.htmlEntities;
const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;


const settings = getSetting( 'straumur_data', {} );

const defaultLabel = __(
    'Straumur Payments',
    'straumur-payments-for-woocommerce'
);

const label = decodeEntities( settings.title ) || defaultLabel;
/**
 * Content component
 */
const Content = () => {
    return decodeEntities( settings.description || 'Secure payment via Straumur Hosted checkout' );

};
/**
 * Label component

 * @param {*} props Props from payment API.
 */
const Label = ( props ) => {
    const { PaymentMethodLabel } = props.components;
    const icons = settings.icons || {};
    return (
        <span style={ { display: 'flex', alignItems: 'center', width: '100%' } }>
            <PaymentMethodLabel text={ label } />
            <span style={ { display: 'flex', alignItems: 'center', gap: '4px', marginLeft: 'auto' } }>
                { Object.entries( icons ).map( ( [ name, url ] ) => (
                    <img
                        key={ name }
                        src={ url }
                        alt={ name }
                        style={ { height: '24px', width: 'auto' } }
                    />
                ) ) }
            </span>
        </span>
    );
};


const Straumur = {
    name: "straumur",
    label: <Label />,
    content: <Content />,
    edit: <Content />,
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports,
    },
};

registerPaymentMethod( Straumur );