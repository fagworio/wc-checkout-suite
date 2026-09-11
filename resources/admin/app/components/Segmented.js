/**
 * Segmented control.
 *
 * A single-choice control for a small, always-visible set of options, where the
 * alternatives should stay readable instead of hiding behind a dropdown. Used
 * by the preview to pick device, adapter and context.
 *
 * Built from real `button` elements in a labelled `group`, so every option is
 * reachable and operable by keyboard and screen readers without a roving
 * tabindex: each option reports its own state through `aria-pressed`.
 */

/**
 * @param {Object}  props           Component properties.
 * @param {string}  props.label     Accessible name of the group.
 * @param {*[]}     props.options   Options as `{ id, label, description? }`.
 * @param {string}  props.value     Identifier of the selected option.
 * @param {*}       props.onChange  Called with the identifier to select.
 * @param {boolean} [props.compact] Renders a denser variant.
 * @return {*} Rendered element tree.
 */
export default function Segmented( {
	label,
	options,
	value,
	onChange,
	compact = false,
} ) {
	if ( ! Array.isArray( options ) || options.length === 0 ) {
		return null;
	}

	const className = compact
		? 'wccs-segmented wccs-segmented--compact'
		: 'wccs-segmented';

	return (
		<div className={ className } role="group" aria-label={ label }>
			{ options.map( ( option ) => {
				const selected = option.id === value;

				return (
					<button
						key={ option.id }
						type="button"
						className="wccs-segmented__option"
						aria-pressed={ selected }
						title={ option.description }
						onClick={ () => onChange( option.id ) }
					>
						{ option.label }
					</button>
				);
			} ) }
		</div>
	);
}
