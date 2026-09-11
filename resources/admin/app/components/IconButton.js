import { forwardRef } from '@wordpress/element';

/**
 * Icon button.
 *
 * An icon alone carries no accessible name, so this component refuses to render
 * without one. That is the difference between a row of mystery glyphs and a row
 * of controls a screen reader can announce.
 *
 * It forwards a ref to the underlying button because a component that cannot
 * participate in focus management makes focus management impossible for whoever
 * uses it: reordering a list has to put focus back on the control that moved it.
 *
 * @param {Object}  props             Component properties.
 * @param {string}  props.label       Accessible name. Required.
 * @param {string}  props.icon        Glyph or short text used as the visual.
 * @param {boolean} [props.disabled]  Whether the button is unavailable.
 * @param {*}       [props.onClick]   Click handler.
 * @param {string}  [props.className] Additional class names.
 * @param {*}       [props.ref]       Forwarded to the button element.
 * @return {*} Rendered element tree.
 */
const IconButton = forwardRef( function IconButtonInner(
	/**
	 * @type {{ label: string, icon: string, disabled?: boolean, onClick?: any, className?: string }}
	 */
	{ label, icon, disabled = false, onClick, className = '' },
	ref
) {
	if ( ! label ) {
		throw new Error(
			'IconButton requires a label: an icon alone has no accessible name.'
		);
	}

	return (
		<button
			type="button"
			ref={ ref }
			className={ [ 'wccs-icon-button', className ]
				.filter( Boolean )
				.join( ' ' ) }
			aria-label={ label }
			title={ label }
			disabled={ disabled }
			onClick={ onClick }
		>
			<span aria-hidden="true">{ icon }</span>
		</button>
	);
} );

export default IconButton;
