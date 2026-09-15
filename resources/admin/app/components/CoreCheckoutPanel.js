/**
 * The checkout the store already runs, offered to be managed.
 *
 * `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §6.2: the screen
 * loads the real checkout and does not start empty, showing the sections and the native
 * fields that were detected. This panel is that answer — it lists what WooCommerce runs, marks
 * what this document already manages, and adopts the rest, whole section at a time, so the
 * merchant never rebuilds a checkout that already exists.
 *
 * It says nothing when there is nothing to say: an inventory that could not be read is
 * reported where the screen reports failures, and a checkout that is fully managed has no
 * unmanaged field left to list.
 */

import { __, sprintf } from '@wordpress/i18n';

import { Badge } from './Badge';
import Button from './Button';
import { Icon } from '../design/icons';
import {
	coreCheckout,
	hasCoreCheckout,
	typeWasRemapped,
} from '../schema/coreCheckout';

/**
 * The panel.
 *
 * @param {Object}                                                               props                Component properties.
 * @param {import('../schema/types').CoreFieldInventory|null}                    props.inventory      Inventory of the store's checkout.
 * @param {import('../schema/types').SchemaDocument}                             props.document       Document being edited.
 * @param {(entry: import('../schema/types').CoreFieldEntry) => void}            props.onAdoptField   Adopt one native field.
 * @param {(section: import('../schema/coreCheckout').CoreSectionState) => void} props.onAdoptSection Adopt every field of a section.
 * @return {*} Rendered panel, or null when it has nothing to say.
 */
export default function CoreCheckoutPanel( {
	inventory,
	document,
	onAdoptField,
	onAdoptSection,
} ) {
	if ( ! hasCoreCheckout( inventory ) ) {
		return null;
	}

	const sections = coreCheckout( inventory, document ).filter( ( section ) =>
		section.fields.some( ( field ) => ! field.managed )
	);

	if ( 0 === sections.length ) {
		return null;
	}

	const pending = sections.reduce(
		( total, section ) => total + ( section.total - section.managed ),
		0
	);

	return (
		<section
			className="core-checkout"
			aria-label={ __( 'Checkout padrão', 'wc-checkoutsuite' ) }
		>
			<div className="core-checkout-head">
				<div>
					<h3>{ __( 'Checkout padrão', 'wc-checkoutsuite' ) }</h3>
					<p className="muted small">
						{ __(
							'Estes campos já existem no checkout da loja. Adotar um deles permite configurá-lo aqui sem criar uma cópia: o valor continua a ser do WooCommerce.',
							'wc-checkoutsuite'
						) }
					</p>
				</div>
				<span className="core-checkout-count">
					{ sprintf(
						/* translators: %d: number of native fields not managed yet. */
						__( '%d por adotar', 'wc-checkoutsuite' ),
						pending
					) }
				</span>
			</div>

			<div className="core-checkout-sections">
				{ sections.map( ( section ) => (
					<div
						className="core-checkout-section"
						key={ section.key }
						id={ `wccs-core-section-${ section.key }` }
					>
						<div className="core-checkout-section-head">
							<div>
								<strong>{ section.label }</strong>
								<small className="muted">
									{ sprintf(
										/* translators: 1: managed fields, 2: total fields. */
										__(
											'%1$d de %2$d gerenciados',
											'wc-checkoutsuite'
										),
										section.managed,
										section.total
									) }
								</small>
							</div>
							<Button
								size="small"
								variant="secondary"
								onClick={ () => onAdoptSection( section ) }
							>
								{ __( 'Usar esta seção', 'wc-checkoutsuite' ) }
							</Button>
						</div>

						<ul className="core-checkout-fields">
							{ section.fields.map( ( field ) => (
								<li key={ field.entry.id }>
									{ /* §6.2: a WooCommerce field says so, in words. */ }
									<Badge tone="neutral">
										{ __( 'Nativo', 'wc-checkoutsuite' ) }
									</Badge>
									<span className="core-checkout-label">
										{ field.entry.label }
									</span>
									<code>{ field.entry.id }</code>
									{ field.entry.required ? (
										<span className="muted small">
											{ __(
												'obrigatório',
												'wc-checkoutsuite'
											) }
										</span>
									) : null }
									{ /* §6.7: a property that cannot be carried over is said
									     now, not discovered later. */ }
									{ typeWasRemapped( field.entry ) ? (
										<span className="muted small">
											{ sprintf(
												/* translators: 1: native type, 2: Suite type. */
												__(
													'tipo %1$s gerenciado como %2$s',
													'wc-checkoutsuite'
												),
												field.entry.nativeType,
												field.entry.type
											) }
										</span>
									) : null }

									{ field.managed ? (
										<Badge tone="success">
											{ __(
												'Gerenciado',
												'wc-checkoutsuite'
											) }
										</Badge>
									) : (
										<button
											type="button"
											className="text-btn"
											id={ `wccs-core-adopt-${ field.entry.id }` }
											onClick={ () =>
												onAdoptField( field.entry )
											}
										>
											<Icon name="plus" />{ ' ' }
											{ __( 'Usar', 'wc-checkoutsuite' ) }
										</button>
									) }
								</li>
							) ) }
						</ul>
					</div>
				) ) }
			</div>
		</section>
	);
}
