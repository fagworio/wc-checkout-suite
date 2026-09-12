/**
 * Modal dialog, as the design draws it.
 *
 * Built on the native `<dialog>` element so focus containment, the Escape key and focus
 * restoration to the opener come from the platform instead of from a hand written trap
 * that would drift. Closing is never silent: the caller owns the open state and is told
 * through `onClose`.
 *
 * The markup is the prototype's — a head with a small caps line, a title and an optional
 * subtitle, a scrolling body and a footer — so every dialog the suite opens shares one
 * frame instead of each one drawing its own.
 *
 * @package
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Icon } from '../design/icons';

/**
 * Counter used to give every dialog instance a unique accessible name target.
 *
 * @type {number}
 */
let dialogCounter = 0;

/**
 * @param {Object}                  props            Component properties.
 * @param {boolean}                 props.open       Whether the dialog is open.
 * @param {string}                  props.title      Accessible title.
 * @param {(...args: any[]) => any} props.onClose    Called when the dialog asks to close.
 * @param {*}                       props.children   Dialog body.
 * @param {*}                       [props.footer]   Optional footer content.
 * @param {string}                  [props.eyebrow]  Small caps line above the title.
 * @param {string}                  [props.subtitle] Line under the title.
 * @param {string}                  [props.size]     `picker`, `confirm` or `publish`.
 */
export default function Dialog( {
	open,
	title,
	onClose,
	children,
	footer,
	eyebrow = '',
	subtitle = '',
	size = '',
} ) {
	/** @type {{ current: HTMLDialogElement|null }} */
	const ref = useRef( null );
	const [ titleId ] = useState(
		() => `wccs-dialog-title-${ ++dialogCounter }`
	);

	useEffect( () => {
		const node = ref.current;

		if ( ! node ) {
			return;
		}

		if ( open && ! node.open && 'function' === typeof node.showModal ) {
			node.showModal();
		} else if ( ! open && node.open ) {
			node.close();
		}
	}, [ open ] );

	// The prototype's dialog classes carry the widths its three dialogs use; the
	// generic frame needs none of them, so an unnamed size adds nothing.
	const className =
		'' === size ? 'wccs-dialog' : `wccs-dialog ${ size }-dialog`;

	return (
		<dialog
			ref={ ref }
			className={ className }
			aria-labelledby={ titleId }
			onCancel={ ( event ) => {
				// Escape is handled here rather than by the browser so the caller
				// can refuse to close, for example while a save is in flight.
				event.preventDefault();
				onClose();
			} }
		>
			<div className="dialog-head">
				<div>
					{ '' !== eyebrow ? (
						<div className="eyebrow">{ eyebrow }</div>
					) : null }
					<h2 id={ titleId }>{ title }</h2>
					{ '' !== subtitle ? <p>{ subtitle }</p> : null }
				</div>
				<button
					type="button"
					className="icon-btn"
					aria-label={ __( 'Fechar', 'wc-checkoutsuite' ) }
					onClick={ onClose }
				>
					<Icon name="close" />
				</button>
			</div>

			<div className="dialog-body">{ children }</div>

			{ footer ? <div className="dialog-footer">{ footer }</div> : null }
		</dialog>
	);
}
