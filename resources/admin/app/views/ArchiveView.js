/**
 * Archived fields, as the design draws them.
 *
 * A field that leaves the form does not leave the store: it is disabled, which keeps its
 * definition, its key and every value already written on an order. This screen is where
 * those fields are listed and where one comes back.
 *
 * The design's words describe its own demonstration — "restaurar devolve o campo como
 * desativado" — and the plugin's behaviour is the other way round: a field is archived
 * *by* being disabled, and restoring enables it again. The copy below says what this
 * store does, because a screen that describes a demonstration is a screen that lies to
 * the merchant reading it.
 *
 * @package
 */

import { __, sprintf } from '@wordpress/i18n';

import { Icon } from '../design/icons';
import { typeGlyph } from '../design/typeGlyph';

/**
 * Archived fields.
 *
 * @param {Object}                                      props           Component properties.
 * @param {Array<any>}                                  props.fields    Every field in the document.
 * @param {import('../schema/types').FieldCatalog|null} props.catalog   Field catalogue.
 * @param {Function}                                    props.onRestore Called with the field to bring back.
 * @param {Function}                                    props.onBack    Called to return to the editor.
 * @return {*} Rendered element tree.
 */
export default function ArchiveView( { fields, catalog, onRestore, onBack } ) {
	// A WooCommerce field cannot be archived: it is not this plugin's to remove, and the
	// guard refuses it. Only the merchant's own fields appear here.
	const archived = fields.filter(
		( /** @type {any} */ field ) =>
			! field.enabled && 'core' !== field.origin
	);

	/**
	 * The label of a section, for the row's summary line.
	 *
	 * @param {string} id Section identifier.
	 * @return {string} Label.
	 */
	const sectionLabel = ( id ) =>
		( catalog?.sectionLocations ?? [] ).find(
			( /** @type {any} */ entry ) => entry.value === id
		)?.label ?? id;

	return (
		<section
			className="view active"
			id="archiveView"
			aria-labelledby="archiveTitle"
		>
			<div className="page-heading">
				<div>
					<div className="eyebrow">
						<span className="tiny-line" />
						{ __( 'HISTÓRICO PRESERVADO', 'wc-checkoutsuite' ) }
					</div>
					<h1 id="archiveTitle">
						{ __(
							'Fora do formulário. Não do histórico.',
							'wc-checkoutsuite'
						) }
					</h1>
					<p>
						{ __(
							'Restaure um campo personalizado sem trocar a chave nem recriar a definição.',
							'wc-checkoutsuite'
						) }
					</p>
				</div>
				<button
					type="button"
					className="btn"
					onClick={ () => onBack?.() }
				>
					<Icon name="back" />
					{ __( 'Voltar ao editor', 'wc-checkoutsuite' ) }
				</button>
			</div>

			<div className="notice">
				<Icon name="info" />
				<p>
					{ __(
						'Arquivar desativa o campo: ele sai do formulário e mantém a chave, a definição e os valores já gravados nos pedidos. Restaurar volta a habilitá-lo.',
						'wc-checkoutsuite'
					) }
				</p>
			</div>

			<div className="panel">
				{ 0 === archived.length ? (
					<div className="empty-state">
						<Icon name="archive" />
						<h3>
							{ __(
								'Nenhum campo arquivado.',
								'wc-checkoutsuite'
							) }
						</h3>
						<p>
							{ __(
								'Ao remover um campo personalizado do checkout, ele aparece aqui. A exclusão permanente de dados não faz parte do editor.',
								'wc-checkoutsuite'
							) }
						</p>
						<button
							type="button"
							className="btn"
							onClick={ () => onBack?.() }
						>
							{ __( 'Voltar aos campos', 'wc-checkoutsuite' ) }
						</button>
					</div>
				) : (
					archived.map( ( /** @type {any} */ field ) => (
						<div className="archive-row" key={ field.id }>
							<span className="field-glyph" aria-hidden="true">
								{ typeGlyph( field.type ) }
							</span>
							<div>
								<strong>{ field.label }</strong>
								<code>{ field.id }</code>
								<p>
									{ sprintf(
										/* translators: 1: section label, 2: type key. */
										__(
											'%1$s · %2$s · definição preservada',
											'wc-checkoutsuite'
										),
										sectionLabel( field.section ),
										catalog?.types?.[ field.type ]?.label ??
											field.type
									) }
								</p>
							</div>
							<span className="badge">
								{ __( 'Arquivado', 'wc-checkoutsuite' ) }
							</span>
							<button
								type="button"
								className="btn"
								onClick={ () => onRestore( field.id ) }
							>
								<Icon name="history" />
								{ __( 'Restaurar', 'wc-checkoutsuite' ) }
							</button>
						</div>
					) )
				) }
			</div>
		</section>
	);
}
