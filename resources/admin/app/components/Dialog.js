/**
 * Modal dialog.
 *
 * Built on the native `<dialog>` element so focus containment, the Escape key
 * and focus restoration to the opener come from the platform instead of from a
 * hand written trap that would drift. Closing is never silent: the caller owns
 * the open state and is told through `onClose`.
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Counter used to give every dialog instance a unique accessible name target.
 *
 * @type {number}
 */
let dialogCounter = 0;

/**
 * @param {Object}                  props          Component properties.
 * @param {boolean}                 props.open     Whether the dialog is open.
 * @param {string}                  props.title    Accessible title.
 * @param {(...args: any[]) => any} props.onClose  Called when the dialog asks to close.
 * @param {*}                       props.children Dialog body.
 * @param {*}                       [props.footer] Optional footer content.
 */
export default function Dialog( { open, title, onClose, children, footer } ) {
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

	return (
		<dialog
			ref={ ref }
			className="wccs-dialog"
			aria-labelledby={ titleId }
			onCancel={ ( event ) => {
				// Escape is handled here rather than by the browser so the caller
				// can refuse to close, for example while a save is in flight.
				event.preventDefault();
				onClose();
			} }
		>
			<div className="wccs-dialog__header">
				<h2 className="wccs-dialog__title" id={ titleId }>
					{ title }
				</h2>
				<button
					type="button"
					className="wccs-icon-button wccs-dialog__close"
					aria-label={ __( 'Close dialog', 'wc-checkoutsuite' ) }
					onClick={ onClose }
				>
					<span aria-hidden="true">×</span>
				</button>
			</div>

			<div className="wccs-dialog__body">{ children }</div>

			{ footer ? (
				<div className="wccs-dialog__footer">{ footer }</div>
			) : null }
		</dialog>
	);
}
