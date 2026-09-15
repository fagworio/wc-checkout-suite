/**
 * Condition builder.
 *
 * Edits the rule that decides whether a field appears, and does three things the
 * acceptance for WCCS-032 asks for by name: it writes rules the merchant can read
 * back, it says what is wrong with the rule in words, and it shows what the rule
 * decides under values the merchant supplies.
 *
 * Three decisions shape the component:
 *
 * 1. **The vocabulary is read, never written.** The sources and the operators
 *    come from the server, which reads them from the classes the validator itself
 *    reads, so this editor cannot offer a choice the server would refuse. The one
 *    rule ADR-0007 sets for a closed vocabulary is that the editor and the
 *    validator read the same list, and the way to honour it is not to have a
 *    second list here.
 *
 * 2. **The preview is filled in by the merchant.** Guessing that the cart holds
 *    R$ 200 would render a preview of a store that does not exist, so no input is
 *    pre-filled and only the sources the rule actually reads are asked for.
 *
 * 3. **What cannot be known here is not guessed.** Whether a rule names a field
 *    that exists and whether two fields depend on each other are properties of the
 *    whole document; those are checked where the document is validated, and the
 *    editor reports what it can see without inventing a second opinion.
 *
 * The component performs no request and holds no copy of the document: it writes
 * only through `onChange`, which the screen turns into a document operation, so
 * the unsaved-work guard and the undo history keep working.
 *
 * @see ROADMAP.md section 11
 * @see docs/adr/ADR-0007-closed-vocabularies.md
 */

import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Button from './Button';
import IconButton from './IconButton';
import Notice from './Notice';
import Segmented from './Segmented';
import { SelectField, TextField } from './controls';
import {
	addChildAt,
	childrenOf,
	describe,
	emptyGroup,
	emptyLeaf,
	firstOperatorFor,
	groupKind,
	isGroup,
	issues,
	operatorOf,
	operatorsFor,
	preview,
	removeAt,
	replaceAt,
	sourceOf,
} from '../schema/conditions';

/**
 * The rule a stored conditions document carries, or null.
 *
 * A field's document is `{ visible: <rule> }` and `{}` means the field is always
 * shown. Anything that is not a group and does not name a source and an operator
 * is read as no rule rather than rendered as a broken form: every stored value is
 * one the validator accepted or nothing at all, so a third possibility is damage,
 * and a builder that displayed it would be offering to save it back.
 *
 * A profile stores its rule **bare** — the tree itself and nothing around it
 * (§3.4, §3.5). The envelope names what a rule decides: a field decides visibility,
 * a profile decides which checkout runs. The tree, the operators and the sources are
 * the same ones, which is what §14 means by one dialect of rules.
 *
 * @param {Record<string, any>} document Stored conditions document.
 * @param {string}              envelope Key the rule sits under, or an empty string for bare.
 * @return {import('../schema/conditions').ConditionNode|null} Rule, or null.
 */
function ruleOf( document, envelope ) {
	let raw = document ?? null;

	if ( null !== raw && '' !== envelope ) {
		raw = raw[ envelope ] ?? null;
	}

	if ( ! raw || 'object' !== typeof raw || Array.isArray( raw ) ) {
		return null;
	}

	if ( isGroup( raw ) ) {
		return raw;
	}

	if ( 'string' === typeof raw.source && 'string' === typeof raw.operator ) {
		return raw;
	}

	return null;
}

/**
 * Every comparison in a rule, in the order the rule reads them.
 *
 * @param {import('../schema/conditions').ConditionNode} node Root node.
 * @return {import('../schema/conditions').ConditionLeaf[]} Leaves.
 */
function leaves( node ) {
	if ( ! isGroup( node ) ) {
		return [ node ];
	}

	return childrenOf( node ).reduce(
		( found, child ) => found.concat( leaves( child ) ),
		/** @type {import('../schema/conditions').ConditionLeaf[]} */ ( [] )
	);
}

/**
 * The kind of input a comparison value needs.
 *
 * Derived from what the operator declares it can be compared against, because
 * that declaration is what the validator checks: a list operator gets a list
 * input, a numeric comparison gets a number input, and a boolean source compared
 * with a matching operator gets a yes/no choice rather than a box where either
 * `true` or `True` might be typed.
 *
 * @param {import('../schema/conditions').ConditionOperator} operator Operator.
 * @param {import('../schema/conditions').ConditionSource}   [source] Source.
 * @return {('text'|'number'|'boolean'|'list')} Input kind.
 */
function valueKind( operator, source ) {
	const types = operator.valueTypes ?? [];

	if ( types.includes( 'list' ) ) {
		return 'list';
	}

	if ( 1 === types.length && types.includes( 'number' ) ) {
		return 'number';
	}

	if ( source && 'boolean' === source.type && types.includes( 'boolean' ) ) {
		return 'boolean';
	}

	return 'text';
}

/**
 * Reads a typed input back into the value a rule stores.
 *
 * An empty box is `undefined` and not `''` for the kinds where emptiness has no
 * meaning: "is greater than nothing" is a rule nobody meant to write, and the
 * validator refuses it, so the editor lets it be absent instead of storing a
 * value that pretends to be one.
 *
 * @param {string} raw  What the input holds.
 * @param {string} kind Input kind.
 * @return {any} Stored value.
 */
function parseValue( raw, kind ) {
	if ( 'list' === kind ) {
		return raw
			.split( ',' )
			.map( ( entry ) => entry.trim() )
			.filter( Boolean );
	}

	if ( 'boolean' === kind ) {
		if ( 'true' === raw ) {
			return true;
		}

		if ( 'false' === raw ) {
			return false;
		}

		return undefined;
	}

	if ( 'number' === kind ) {
		if ( '' === raw.trim() ) {
			return undefined;
		}

		const parsed = Number( raw );

		return Number.isNaN( parsed ) ? raw : parsed;
	}

	return raw;
}

/**
 * Renders a value for an input.
 *
 * @param {any}    value Value.
 * @param {string} kind  Input kind.
 * @return {string} Text for the input.
 */
function formatForInput( value, kind ) {
	if ( 'boolean' === kind ) {
		if ( true === value ) {
			return 'true';
		}

		return false === value ? 'false' : '';
	}

	if ( 'list' === kind ) {
		return Array.isArray( value ) ? value.join( ', ' ) : '';
	}

	if ( null === value || undefined === value ) {
		return '';
	}

	return String( value );
}

/**
 * Condition builder.
 *
 * @param {Object}                                             props              Component properties.
 * @param {Record<string, any>}                                props.value        Stored conditions document.
 * @param {import('../schema/types').ConditionVocabularyShape} [props.vocabulary] Operators and sources, as published.
 * @param {string}                                             [props.fieldId]    Identifier of the field being edited.
 * @param {Array<{id: string, label: string}>}                 [props.fields]     Fields a rule may read.
 * @param {(conditions: Record<string, any>) => void}          props.onChange     Called with the next document.
 * @param {string}                                             [props.envelope]   Key the rule sits under; empty for a bare tree.
 * @return {*} Rendered element tree.
 */
export default function ConditionBuilder( {
	value,
	vocabulary = {},
	fieldId = '',
	fields = [],
	onChange,
	envelope = 'visible',
} ) {
	const document = value && 'object' === typeof value ? value : {};
	const rule = ruleOf( document, envelope );
	const ready =
		( vocabulary.operators ?? [] ).length > 0 &&
		( vocabulary.sources ?? [] ).length > 0;

	const [ context, setContext ] = useState(
		/** @type {import('../schema/conditions').PreviewContext} */ ( {} )
	);

	const fieldLabel = useMemo(
		() => ( /** @type {string} */ id ) =>
			fields.find( ( entry ) => entry.id === id )?.label || id,
		[ fields ]
	);

	const messages = useMemo(
		() =>
			rule
				? issues( rule, vocabulary, {
						fieldId,
						fieldLabel,
						...( fields.length
							? {
									knownFields: fields.map(
										( entry ) => entry.id
									),
							  }
							: {} ),
				  } )
				: [],
		[ rule, vocabulary, fieldId, fields, fieldLabel ]
	);

	const asked = useMemo( () => {
		/** @type {string[]} */
		const sources = [];
		/** @type {string[]} */
		const referenced = [];

		if ( rule ) {
			for ( const leaf of leaves( rule ) ) {
				if ( 'field' === leaf.source ) {
					if ( leaf.field && ! referenced.includes( leaf.field ) ) {
						referenced.push( leaf.field );
					}
				} else if ( ! sources.includes( leaf.source ) ) {
					sources.push( leaf.source );
				}
			}
		}

		return { sources, referenced };
	}, [ rule ] );

	const result = useMemo(
		() => ( rule ? preview( rule, context, vocabulary ) : null ),
		[ rule, context, vocabulary ]
	);

	/**
	 * Writes a rule, or clears it.
	 *
	 * The document is copied rather than replaced so a key the editor does not own
	 * — `evaluator`, which pins the engine a store was written against — survives
	 * an edit that only concerns visibility.
	 *
	 * @param {import('../schema/conditions').ConditionNode|null} next Next rule.
	 * @return {void}
	 */
	const write = ( next ) => {
		// A bare tree is the tree: clearing it is an empty document, and setting it is the
		// document itself. Only an enveloped rule is copied, so a key the editor does not own
		// — `evaluator`, which pins the engine a store was written against — survives the edit.
		if ( '' === envelope ) {
			onChange( null === next ? {} : next );

			return;
		}

		const updated = { ...document };

		if ( null === next ) {
			delete updated[ envelope ];
		} else {
			updated[ envelope ] = next;
		}

		onChange( updated );
	};

	/**
	 * Replaces the node at a path.
	 *
	 * @param {number[]}                                     path Index path.
	 * @param {import('../schema/conditions').ConditionNode} next Replacement.
	 * @return {void}
	 */
	const replaceNode = ( path, next ) => {
		if ( rule ) {
			write( replaceAt( rule, path, next ) );
		}
	};

	/**
	 * Adds a child to the group at a path.
	 *
	 * @param {number[]}                                     path  Path to the group.
	 * @param {import('../schema/conditions').ConditionNode} child Child.
	 * @return {void}
	 */
	const addTo = ( path, child ) => {
		if ( rule ) {
			write( addChildAt( rule, path, child ) );
		}
	};

	/**
	 * Removes the node at a path, clearing the rule when it was the last one.
	 *
	 * @param {number[]} path Index path.
	 * @return {void}
	 */
	const removeNode = ( path ) => {
		if ( rule ) {
			write( removeAt( rule, path ) );
		}
	};

	/**
	 * One sample value, read by source key.
	 *
	 * The context is indexed by source key, which is what the evaluator reads;
	 * this accessor exists so that read is typed once instead of cast at each use.
	 *
	 * @param {string} key Source key.
	 * @return {any} Value, or undefined.
	 */
	const sampleValue = ( key ) =>
		/** @type {Record<string, any>} */ ( context )[ key ];

	/**
	 * Sets one sample value.
	 *
	 * @param {string} key   Source key.
	 * @param {any}    entry Parsed value.
	 * @return {void}
	 */
	const setSample = ( key, entry ) =>
		setContext( ( current ) => ( { ...current, [ key ]: entry } ) );

	/**
	 * Sets the sample value of a field the rule reads.
	 *
	 * @param {string} id    Field identifier.
	 * @param {any}    entry Parsed value.
	 * @return {void}
	 */
	const setFieldSample = ( id, entry ) =>
		setContext( ( current ) => ( {
			...current,
			fields: { ...( current.fields ?? {} ), [ id ]: entry },
		} ) );

	/**
	 * Renders one node of the rule.
	 *
	 * @param {import('../schema/conditions').ConditionNode} node Node.
	 * @param {number[]}                                     path Path to it.
	 * @return {*} Rendered element tree.
	 */
	const renderNode = ( node, path ) => {
		const key = 0 === path.length ? 'root' : path.join( '.' );
		const remove = (
			<IconButton
				label={ __( 'Remove this condition', 'wc-checkoutsuite' ) }
				icon="×"
				onClick={ () => removeNode( path ) }
			/>
		);

		if ( isGroup( node ) ) {
			const children = childrenOf( node );
			const kind = groupKind( node );

			return (
				<div className="wccs-conditions__group" key={ key }>
					<div className="wccs-conditions__group-head">
						<Segmented
							label={ __( 'Match', 'wc-checkoutsuite' ) }
							compact
							value={ kind }
							options={ [
								{
									id: 'all',
									label: __( 'All of', 'wc-checkoutsuite' ),
								},
								{
									id: 'any',
									label: __( 'Any of', 'wc-checkoutsuite' ),
								},
							] }
							onChange={ ( /** @type {string} */ next ) =>
								replaceNode(
									path,
									'all' === next
										? { all: children }
										: { any: children }
								)
							}
						/>
						{ 0 < path.length ? remove : null }
					</div>

					{ 0 === children.length ? (
						<p className="wccs-conditions__empty">
							{ __(
								'This group has nothing in it yet.',
								'wc-checkoutsuite'
							) }
						</p>
					) : (
						children.map( ( child, index ) =>
							renderNode( child, [ ...path, index ] )
						)
					) }

					<div className="wccs-conditions__actions">
						<Button
							size="small"
							disabled={ ! ready }
							onClick={ () =>
								addTo( path, emptyLeaf( vocabulary ) )
							}
						>
							{ __( 'Add a condition', 'wc-checkoutsuite' ) }
						</Button>
						<Button
							size="small"
							disabled={ ! ready }
							onClick={ () =>
								addTo( path, emptyGroup( vocabulary, 'any' ) )
							}
						>
							{ __( 'Add a group', 'wc-checkoutsuite' ) }
						</Button>
					</div>
				</div>
			);
		}

		const leaf = node;
		const source = sourceOf( vocabulary, leaf.source );
		const operator = operatorOf( vocabulary, leaf.operator );
		const options = operatorsFor( vocabulary, leaf.source );
		const kind = operator ? valueKind( operator, source ) : 'text';
		const id = `wccs-condition-${ key.replace( /\./g, '-' ) }`;

		// The two surfaces that depend on the leaf's own choices are built here
		// rather than inside the markup, so that each of them is one decision
		// instead of a chain of them buried in a JSX expression.
		let fieldControl = null;

		if ( source?.isReference ) {
			fieldControl =
				fields.length > 0 ? (
					<SelectField
						id={ `${ id }-field` }
						label={ __( 'Field', 'wc-checkoutsuite' ) }
						value={ leaf.field ?? '' }
						options={ [
							{
								value: '',
								label: __(
									'Choose a field…',
									'wc-checkoutsuite'
								),
							},
							...fields.map( ( entry ) => ( {
								value: entry.id,
								label: entry.label,
							} ) ),
						] }
						onChange={ (
							/** @type {{ target: { value: string } }} */ event
						) =>
							replaceNode( path, {
								...leaf,
								field: event.target.value,
							} )
						}
					/>
				) : (
					<TextField
						id={ `${ id }-field` }
						label={ __( 'Field', 'wc-checkoutsuite' ) }
						help={ __(
							'Identifier of the field this rule reads.',
							'wc-checkoutsuite'
						) }
						value={ leaf.field ?? '' }
						onChange={ (
							/** @type {{ target: { value: string } }} */ event
						) =>
							replaceNode( path, {
								...leaf,
								field: event.target.value,
							} )
						}
					/>
				);
		}

		let valueControl = null;

		if ( operator?.takesValue ) {
			valueControl =
				'boolean' === kind ? (
					<SelectField
						id={ `${ id }-value` }
						label={ __( 'Value', 'wc-checkoutsuite' ) }
						value={ formatForInput( leaf.value, kind ) }
						options={ [
							{
								value: '',
								label: __(
									'Choose a value…',
									'wc-checkoutsuite'
								),
							},
							{
								value: 'true',
								label: __( 'Yes', 'wc-checkoutsuite' ),
							},
							{
								value: 'false',
								label: __( 'No', 'wc-checkoutsuite' ),
							},
						] }
						onChange={ (
							/** @type {{ target: { value: string } }} */ event
						) =>
							replaceNode( path, {
								...leaf,
								value: parseValue( event.target.value, kind ),
							} )
						}
					/>
				) : (
					<TextField
						id={ `${ id }-value` }
						label={ __( 'Value', 'wc-checkoutsuite' ) }
						type={ 'number' === kind ? 'number' : 'text' }
						help={
							'list' === kind
								? __(
										'Separate values with commas.',
										'wc-checkoutsuite'
								  )
								: undefined
						}
						value={ formatForInput( leaf.value, kind ) }
						onChange={ (
							/** @type {{ target: { value: string } }} */ event
						) =>
							replaceNode( path, {
								...leaf,
								value: parseValue( event.target.value, kind ),
							} )
						}
					/>
				);
		}

		return (
			<div className="condition-row wccs-conditions__leaf" key={ key }>
				<div className="condition-row-head wccs-conditions__leaf-head">
					{ /* A leaf at the root is already the sentence shown above it, and
					   printing it twice would be two answers to the same question.
					   Inside a group, the sentence runs the whole rule together, so
					   each comparison says what it reads next to its own controls. */ }
					{ 0 < path.length ? (
						<p className="wccs-conditions__leaf-reading">
							{ describe( leaf, vocabulary, fieldLabel ) }
						</p>
					) : null }
					{ remove }
				</div>

				<SelectField
					id={ `${ id }-source` }
					label={ __( 'Reads', 'wc-checkoutsuite' ) }
					value={ leaf.source }
					options={ ( vocabulary.sources ?? [] ).map( ( entry ) => ( {
						value: entry.key,
						label: entry.label,
					} ) ) }
					onChange={ (
						/** @type {{ target: { value: string } }} */ event
					) => {
						const next = sourceOf( vocabulary, event.target.value );
						const first = firstOperatorFor( vocabulary, next );

						replaceNode( path, {
							source: event.target.value,
							operator: first?.key ?? '',
							...( next?.isReference
								? { field: leaf.field ?? '' }
								: {} ),
							...( first?.takesValue ? { value: '' } : {} ),
						} );
					} }
				/>

				{ fieldControl }

				<SelectField
					id={ `${ id }-operator` }
					label={ __( 'Comparison', 'wc-checkoutsuite' ) }
					value={ leaf.operator }
					options={ options.map( ( entry ) => ( {
						value: entry.key,
						label: entry.label,
					} ) ) }
					onChange={ (
						/** @type {{ target: { value: string } }} */ event
					) => {
						const next = operatorOf(
							vocabulary,
							event.target.value
						);

						replaceNode( path, {
							source: leaf.source,
							operator: event.target.value,
							...( source?.isReference
								? { field: leaf.field ?? '' }
								: {} ),
							...( next?.takesValue
								? { value: leaf.value ?? '' }
								: {} ),
						} );
					} }
				/>

				{ valueControl }
			</div>
		);
	};

	return (
		<div className="wccs-conditions">
			<p className="wccs-conditions__intro">
				{ __(
					'Choose the values that make this field appear on the checkout.',
					'wc-checkoutsuite'
				) }
			</p>

			{ ! rule ? (
				<div className="wccs-conditions__empty-state">
					<p>
						{ __(
							'This field is always shown.',
							'wc-checkoutsuite'
						) }
					</p>
					<Button
						variant="secondary"
						disabled={ ! ready }
						onClick={ () => write( emptyLeaf( vocabulary ) ) }
					>
						{ __( 'Add a condition', 'wc-checkoutsuite' ) }
					</Button>
					{ ! ready ? (
						<p className="wccs-conditions__empty">
							{ __(
								'The list of sources and operators has not been loaded yet.',
								'wc-checkoutsuite'
							) }
						</p>
					) : null }
				</div>
			) : (
				<>
					{ /* The design prints the rule the way it reads, in its own
					   result box, above the controls that compose it. */ }
					<p className="condition-result wccs-conditions__sentence">
						{ describe( rule, vocabulary, fieldLabel ) }
					</p>

					{ renderNode( rule, [] ) }

					<div className="wccs-conditions__actions">
						{ isGroup( rule ) ? null : (
							<Button
								size="small"
								disabled={ ! ready }
								onClick={ () =>
									write( {
										all: [ rule, emptyLeaf( vocabulary ) ],
									} )
								}
							>
								{ __( 'Add a condition', 'wc-checkoutsuite' ) }
							</Button>
						) }
						<Button
							variant="danger"
							size="small"
							onClick={ () => write( null ) }
						>
							{ __(
								'Always show this field',
								'wc-checkoutsuite'
							) }
						</Button>
					</div>
				</>
			) }

			{ messages.length > 0 ? (
				<Notice
					status="warning"
					title={ __(
						'This rule cannot be saved as it is',
						'wc-checkoutsuite'
					) }
				>
					<ul>
						{ messages.map( ( message ) => (
							<li key={ message }>{ message }</li>
						) ) }
					</ul>
				</Notice>
			) : null }

			{ rule ? (
				<div className="wccs-conditions__preview">
					<h3 className="wccs-conditions__preview-title">
						{ __( 'Try it', 'wc-checkoutsuite' ) }
					</h3>
					<p className="wccs-conditions__preview-help">
						{ __(
							'Fill in the values below to see what this rule decides. Nothing here is saved, and nothing is guessed for you.',
							'wc-checkoutsuite'
						) }
					</p>

					{ 0 === asked.sources.length &&
					0 === asked.referenced.length ? (
						<p className="wccs-conditions__empty">
							{ __(
								'This rule does not read anything yet.',
								'wc-checkoutsuite'
							) }
						</p>
					) : null }

					{ asked.sources.map( ( sourceKey ) => {
						const entry = sourceOf( vocabulary, sourceKey );
						const sampleId = `wccs-condition-sample-${ sourceKey }`;

						if ( 'boolean' === entry?.type ) {
							return (
								<SelectField
									key={ sourceKey }
									id={ sampleId }
									label={ entry?.label ?? sourceKey }
									value={ formatForInput(
										sampleValue( sourceKey ),
										'boolean'
									) }
									options={ [
										{
											value: '',
											label: __(
												'Not answered',
												'wc-checkoutsuite'
											),
										},
										{
											value: 'true',
											label: __(
												'Yes',
												'wc-checkoutsuite'
											),
										},
										{
											value: 'false',
											label: __(
												'No',
												'wc-checkoutsuite'
											),
										},
									] }
									onChange={ (
										/** @type {{ target: { value: string } }} */ event
									) =>
										setSample(
											sourceKey,
											parseValue(
												event.target.value,
												'boolean'
											)
										)
									}
								/>
							);
						}

						let sampleKind = 'text';

						if ( 'list' === entry?.type ) {
							sampleKind = 'list';
						} else if ( 'number' === entry?.type ) {
							sampleKind = 'number';
						}

						return (
							<TextField
								key={ sourceKey }
								id={ sampleId }
								label={ entry?.label ?? sourceKey }
								type={
									'number' === sampleKind ? 'number' : 'text'
								}
								help={
									'list' === sampleKind
										? __(
												'Separate values with commas.',
												'wc-checkoutsuite'
										  )
										: undefined
								}
								value={ formatForInput(
									sampleValue( sourceKey ),
									sampleKind
								) }
								onChange={ (
									/** @type {{ target: { value: string } }} */ event
								) =>
									setSample(
										sourceKey,
										parseValue(
											event.target.value,
											sampleKind
										)
									)
								}
							/>
						);
					} ) }

					{ asked.referenced.map( ( reference ) => (
						<TextField
							key={ reference }
							id={ `wccs-condition-sample-field-${ reference }` }
							label={ sprintf(
								/* translators: %s: field label. */
								__( 'Value of %s', 'wc-checkoutsuite' ),
								fieldLabel( reference )
							) }
							value={
								( context.fields ?? {} )[ reference ] ?? ''
							}
							onChange={ (
								/** @type {{ target: { value: string } }} */ event
							) =>
								setFieldSample( reference, event.target.value )
							}
						/>
					) ) }

					{ result ? (
						<div className="wccs-conditions__preview-result">
							<p role="status">
								{ result.matches
									? __(
											'With these values the rule matches, so the field is shown.',
											'wc-checkoutsuite'
									  )
									: __(
											'With these values the rule does not match, so the field is hidden.',
											'wc-checkoutsuite'
									  ) }
							</p>
							<ul>
								{ result.because.map( ( line ) => (
									<li key={ line }>{ line }</li>
								) ) }
							</ul>
						</div>
					) : null }
				</div>
			) : null }
		</div>
	);
}
