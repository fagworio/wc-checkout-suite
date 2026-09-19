/**
 * Presentation boundary for the checkout editor.
 *
 * The screen still owns the document, composition, selection and operations.
 * This component only gives the checkout workspace an explicit visual identity
 * so later layout work can evolve without moving those contracts.
 *
 * @param {Object} props              Component properties.
 * @param {string} props.checkoutName Active checkout name.
 * @param {string} props.checkoutKind Active checkout type label.
 * @param {*}      props.children     Header actions supplied by the owning view.
 * @return {*}                         Rendered workspace heading.
 */
export default function CheckoutWorkspaceView( {
	checkoutName,
	checkoutKind,
	children,
} ) {
	return (
		<div
			className="page-heading wccs-checkout-workspace-view"
			role="region"
			aria-labelledby="checkoutWorkspaceTitle"
		>
			<div>
				<div className="eyebrow">
					<span className="tiny-line" />
					Checkout
				</div>
				<h1 id="checkoutWorkspaceTitle" aria-label={ checkoutName }>
					{ checkoutName }
					<span className="heading-dot">.</span>
				</h1>
				<span className="badge wccs-checkout-workspace-kind">
					{ checkoutKind }
				</span>
			</div>
			<div className="wccs-checkout-workspace-actions">{ children }</div>
		</div>
	);
}
