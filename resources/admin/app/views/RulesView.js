/**
 * The editor's contracts, as the design draws them.
 *
 * A page that states what the editor will and will not do, so a merchant is not left to
 * discover the rules by breaking them. The design draws six cards; the six here are the
 * rules this plugin actually enforces, each with the place it is enforced and the
 * document that decided it — the prototype's citations are its own planning, and copying
 * them would credit the wrong source.
 *
 * Nothing on this screen changes anything. It is the one page whose job is to be read.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';

import { Icon } from '../design/icons';

/**
 * The six contracts.
 *
 * @type {Array<{icon: string, title: string, text: string, reference: string}>}
 */
const RULES = [
	{
		icon: 'plus',
		title: __( 'Adicionar com uma chave única', 'wc-checkoutsuite' ),
		text: __(
			'Cada campo recebe um identificador permanente e uma chave de integração. A chave não pode colidir com um campo ativo nem com um arquivado, e o servidor recusa a gravação quando colide.',
			'wc-checkoutsuite'
		),
		reference: 'ADR-0001',
	},
	{
		icon: 'shield',
		title: __( 'Nativos não são apagados', 'wc-checkoutsuite' ),
		text: __(
			'Um campo da WooCommerce pode ser renomeado, reposicionado e ter a largura mudada. Não pode ser removido nem desativado em silêncio: a razão vem do servidor, que recusa mesmo quando a interface não oferece.',
			'wc-checkoutsuite'
		),
		reference: 'ROADMAP / §7',
	},
	{
		icon: 'archive',
		title: __( 'Remover significa arquivar', 'wc-checkoutsuite' ),
		text: __(
			'Um campo personalizado sai do formulário ao ser desativado, e mantém a chave, a definição e os valores já gravados nos pedidos. Restaurar não recria nada: volta a habilitar o que estava lá.',
			'wc-checkoutsuite'
		),
		reference: 'ROADMAP / §13',
	},
	{
		icon: 'branch',
		title: __( 'Dependências nunca são silenciosas', 'wc-checkoutsuite' ),
		text: __(
			'Uma regra que depende de um campo inexistente, ou de um campo que depende dela de volta, bloqueia a publicação: não existe resposta para um ciclo, e o motor não escolhe uma.',
			'wc-checkoutsuite'
		),
		reference: 'ROADMAP / §11',
	},
	{
		icon: 'layers',
		title: __( 'Rascunho não é publicado', 'wc-checkoutsuite' ),
		text: __(
			'Salvar grava o rascunho e nada mais. A loja continua a correr a revisão publicada até publicar, e o que o cliente vê é sempre a revisão que está no ar.',
			'wc-checkoutsuite'
		),
		reference: 'WCCS-019',
	},
	{
		icon: 'history',
		title: __( 'Histórico separado de desfazer', 'wc-checkoutsuite' ),
		text: __(
			'Desfazer e refazer agem nas alterações que ainda não foram salvas. Restaurar uma publicação anterior publica o conteúdo antigo como uma revisão nova: o histórico nunca é reescrito.',
			'wc-checkoutsuite'
		),
		reference: 'WCCS-065',
	},
];

/**
 * The editor's contracts.
 *
 * @param {Object}   props        Component properties.
 * @param {Function} props.onBack Called to return to the editor.
 * @return {*} Rendered element tree.
 */
export default function RulesView( { onBack } ) {
	return (
		<section
			className="view active"
			id="rulesView"
			aria-labelledby="rulesTitle"
		>
			<div className="page-heading">
				<div>
					<div className="eyebrow">
						<span className="tiny-line" />
						{ __( 'CONTRATOS DO EDITOR', 'wc-checkoutsuite' ) }
					</div>
					<h1 id="rulesTitle">
						{ __(
							'Liberdade para criar. Regras para preservar.',
							'wc-checkoutsuite'
						) }
					</h1>
					<p>
						{ __(
							'O que o editor garante, e onde cada garantia é aplicada.',
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

			<div className="rules-grid">
				{ RULES.map( ( rule ) => (
					<article className="rule-card" key={ rule.reference }>
						<Icon name={ rule.icon } />
						<h2>{ rule.title }</h2>
						<p>{ rule.text }</p>
						<span className="badge">{ rule.reference }</span>
					</article>
				) ) }
			</div>

			<div className="notice">
				<Icon name="code" />
				<p>
					<strong>
						{ __( 'Referências:', 'wc-checkoutsuite' ) }{ ' ' }
					</strong>
					{ __(
						'ADR-0001 (autoridade de armazenamento), ADR-0002 (arquivos privados) e ROADMAP.md §4, 6, 7, 8, 11, 13, 19 e 20. O Checkout Sidebar permanece fora do escopo desta versão.',
						'wc-checkoutsuite'
					) }
				</p>
			</div>
		</section>
	);
}
