const { registerPaymentMethod } = window.wc.wcBlocksRegistry
const { decodeEntities } = window.wp.htmlEntities;
const { getSetting } = window.wc.wcSettings

const settings = getSetting( 'straumur_data', {} );


const label = decodeEntities( settings.title || 'Straumur' );

/**
 * Content component
 */
const Content = () => {
    return decodeEntities( settings.description || 'Greiða á öruggri greiðslusíðu hjá Straumi' );

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