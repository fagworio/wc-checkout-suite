import { __ } from '@wordpress/i18n';

/**
 * @param {Object}     props               Props.
 * @param {string}     [props.title]       Heading.
 * @param {string}     [props.description] Supporting text.
 * @param {*}          props.children      Preview content.
 * @param {() => void} [props.onOpen]      Open callback.
 * @param {string}     [props.className]   Additional class.
 * @return {*} Panel.
 */
export default function PreviewPanel( {
	title,
	description,
	children,
	onOpen,
	className = '',
} ) {
	return (
		<aside
			className={ `wccs-preview-panel ${ className }` }
			aria-label={ title ?? __( 'Prévia', 'wc-checkoutsuite' ) }
		>
			<div className="wccs-preview-panel__header">
				<div>
					<h2>{ title ?? __( 'Prévia', 'wc-checkoutsuite' ) }</h2>
					{ description ? <p>{ description }</p> : null }
				</div>
				{ onOpen ? (
					<button
						type="button"
						className="text-btn"
						onClick={ onOpen }
					>
						{ __( 'Abrir prévia', 'wc-checkoutsuite' ) }
					</button>
				) : null }
			</div>
			<div className="wccs-preview-panel__body">{ children }</div>
		</aside>
	);
}
