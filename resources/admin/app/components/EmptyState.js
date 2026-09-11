/**
 * Empty state.
 *
 * States what is missing and what to do next. An empty screen with no
 * explanation is the most common way an administration interface leaves its user
 * stuck, so the description is required rather than optional.
 */

/**
 * @param {Object} props             Component properties.
 * @param {string} props.title       Short heading.
 * @param {string} props.description What to do next.
 * @param {*}      [props.action]    Optional action element.
 * @return {*} Rendered element tree.
 */
export default function EmptyState( { title, description, action } ) {
	if ( ! title || ! description ) {
		throw new Error(
			'EmptyState requires a title and a description explaining what to do next.'
		);
	}

	return (
		<div className="wccs-empty-state">
			<p className="wccs-empty-state__title">{ title }</p>
			<p className="wccs-empty-state__description">{ description }</p>
			{ action ? (
				<div className="wccs-empty-state__action">{ action }</div>
			) : null }
		</div>
	);
}
