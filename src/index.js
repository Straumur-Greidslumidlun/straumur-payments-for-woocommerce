const { __, sprintf } = window.wp.i18n;
const { decodeEntities } = window.wp.htmlEntities;
const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;


const settings = getSetting( 'straumur_data', {} );

const defaultLabel = __(
    'Straumur Payments',
    'woo-gutenberg-products-block'
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
    return <PaymentMethodLabel text={ label } />;
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