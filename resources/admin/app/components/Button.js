/**
 * Button.
 *
 * A native `<button>` with a variant, never a styled div: keyboard activation,
 * focus handling and form semantics come from the element itself. A busy button
 * stays focusable and announces its state instead of being silently disabled,
 * because disabling the control under the pointer moves focus to nowhere.
 */

import { __ } from '@wordpress/i18n';

/**
 * Every property has a default or is genuinely optional, so they are all marked
 * optional: a JSDoc parameter without brackets is required, and the type check
 * would otherwise demand props the component does not need.
 *
 * @param {Object}                           props             Component properties.
 * @param {('primary'|'secondary'|'danger')} [props.variant]   `primary`, `secondary` or `danger`.
 * @param {('default'|'small')}              [props.size]      `default` or `small`.
 * @param {boolean}                          [props.busy]      Whether the action is in progress.
 * @param {boolean}                          [props.disabled]  Whether the button is unavailable.
 * @param {('button'|'submit'|'reset')}      [props.type]      Button type.
 * @param {(...args: any[]) => any}          [props.onClick]   Click handler.
 * @param {*}                                props.children    Button content.
 * @param {string}                           [props.className] Additional class names.
 */
export default function Button( {
	variant = 'secondary',
	size = 'default',
	busy = false,
	disabled = false,
	type = 'button',
	onClick,
	children,
	className = '',
} ) {
	const classes = [
		'wccs-button',
		`wccs-button--${ variant }`,
		'small' === size ? 'wccs-button--small' : '',
		busy ? 'is-busy' : '',
		className,
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<button
			type={ type }
			className={ classes }
			disabled={ disabled }
			aria-busy={ busy ? 'true' : undefined }
			onClick={ onClick }
		>
			<span className="wccs-button__label">{ children }</span>
			{ busy ? (
				<span className="wccs-screen-reader-text">
					{ __( '(working…)', 'wc-checkoutsuite' ) }
				</span>
			) : null }
		</button>
	);
}
