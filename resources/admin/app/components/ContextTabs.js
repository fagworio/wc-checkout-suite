/**
 * @param {Object}               props          Props.
 * @param {Array<any>}           [props.items]  Tab items.
 * @param {string}               props.active   Active tab.
 * @param {(id: string) => void} props.onChange Tab callback.
 * @param {string}               [props.label]  Accessible label.
 * @return {*} Tabs.
 */
export default function ContextTabs( { items = [], active, onChange, label } ) {
	return (
		<div className="wccs-context-tabs" role="tablist" aria-label={ label }>
			{ items.map( ( item ) => (
				<button
					key={ item.id }
					type="button"
					role="tab"
					aria-selected={ item.id === active }
					className={ item.id === active ? 'active' : '' }
					onClick={ () => onChange( item.id ) }
				>
					{ item.icon ? (
						<span aria-hidden="true">{ item.icon }</span>
					) : null }
					{ item.label }
				</button>
			) ) }
		</div>
	);
}
