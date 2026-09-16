import { __ } from '@wordpress/i18n';

import { sectionCopy } from '../design/sectionMeta';
import { Icon } from '../design/icons';

/**
 * @param {Object}               props          Props.
 * @param {Array<any>}           [props.groups] Container groups.
 * @param {string}               props.active   Active container.
 * @param {(id: string) => void} props.onSelect Selection callback.
 * @param {() => void}           props.onCreate Creation callback.
 * @param {any}                  [props.words]  Destination vocabulary.
 * @param {string}               [props.label]  Accessible label.
 * @return {*} Container list.
 */
export default function ContainerList( {
	groups = [],
	active,
	onSelect,
	onCreate,
	words,
	label,
} ) {
	return (
		<aside className="wccs-container-list" aria-label={ label }>
			<div className="wccs-container-list__heading">
				<div>
					<h2>
						{ words?.many ??
							__( 'Containers', 'wc-checkoutsuite' ) }
					</h2>
					<p>
						{ __(
							'Organize os blocos e escolha onde cada campo aparece.',
							'wc-checkoutsuite'
						) }
					</p>
				</div>
			</div>
			<div className="wccs-container-list__items">
				{ groups.map( ( group ) => {
					const selected = group.section.id === active;
					const copy = sectionCopy(
						group.section,
						Boolean( group.declared )
					);

					return (
						<button
							key={ group.section.id }
							type="button"
							className={ selected ? 'active' : '' }
							aria-pressed={ selected }
							onClick={ () => onSelect( group.section.id ) }
						>
							<span
								className="wccs-container-list__icon"
								aria-hidden="true"
							>
								<Icon name={ copy.icon } />
							</span>
							<span>
								<strong>{ copy.label }</strong>
								<small>{ copy.description }</small>
							</span>
							<em>{ group.fields.length }</em>
						</button>
					);
				} ) }
			</div>
			<button
				type="button"
				className="wccs-container-list__create"
				onClick={ onCreate }
			>
				<Icon name="plus" />{ ' ' }
				{ words?.create ?? __( 'Novo container', 'wc-checkoutsuite' ) }
			</button>
		</aside>
	);
}
