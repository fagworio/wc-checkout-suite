<?php
/**
 * The store's privacy policy text, generated from what the code does.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Privacy;

use WCCheckoutSuite\Domain\Fields\DefinitionVocabulary;
use WCCheckoutSuite\Domain\Uploads\UploadRules;
use WCCheckoutSuite\Domain\Uploads\UploadService;

/**
 * What the store tells people about the data it holds, written from the vocabulary.
 *
 * WordPress shows a suggested policy to every store that uses the privacy tools, and the
 * suggestion is where a plugin states what it collects, where it is shown and how long it
 * is kept. Writing that by hand is how a policy becomes the one document nobody updates:
 * it drifts from the code the first time a value is added to a list here or a visibility
 * key changes meaning there, and it drifts in the direction that matters — the policy is
 * the thing a person reads.
 *
 * So the sentences that can be built from the vocabulary are built from it. What cannot —
 * the retention of an effective upload, which is a decision WCCS-045 made — is stated
 * from the constants that decision lives in, so the number in the policy is the number the
 * job uses.
 *
 * The last paragraph is the honest one and it is deliberate: a policy that promised
 * compliance would be a promise this plugin cannot keep, and section 14 forbids exactly
 * that — "não uma promessa genérica de conformidade".
 */
final class PrivacyPolicy {

	/**
	 * Registers the suggestion.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'suggest' ) );
	}

	/**
	 * Adds the suggested text to the privacy policy editor.
	 *
	 * @return void
	 */
	public static function suggest(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'WC CheckoutSuite', 'wc-checkoutsuite' ),
			self::text()
		);
	}

	/**
	 * The policy text.
	 *
	 * @return string
	 */
	public static function text(): string {
		$paragraphs = array();

		$paragraphs[] = sprintf(
			'<p>%s</p>',
			esc_html__( 'This store uses WC CheckoutSuite to add fields to its checkout. The fields it collects are chosen by the store, and each one declares what it holds and who may see it.', 'wc-checkoutsuite' )
		);

		$paragraphs[] = sprintf(
			'<p>%s</p>',
			esc_html__( 'A field collected at checkout is kept with the order it was given on, not on your account: changing your account does not rewrite a past order, and a past order does not change when your account does.', 'wc-checkoutsuite' )
		);

		$sensitivities = array();

		foreach ( DefinitionVocabulary::storage_sensitivities() as $sensitivity ) {
			$sensitivities[] = sprintf(
				'<strong>%s</strong>: %s',
				esc_html( $sensitivity['label'] ),
				esc_html( $sensitivity['description'] )
			);
		}

		$paragraphs[] = sprintf(
			'<p>%s</p><ul><li>%s</li></ul>',
			esc_html__( 'Each field is one of three kinds, and the difference decides what is exported and what a request to be forgotten removes:', 'wc-checkoutsuite' ),
			implode( '</li><li>', $sensitivities )
		);

		$visibilities = array();

		foreach ( DefinitionVocabulary::visibility_keys() as $visibility ) {
			$visibilities[] = sprintf(
				'<strong>%s</strong>: %s',
				esc_html( $visibility['label'] ),
				esc_html( $visibility['description'] )
			);
		}

		$paragraphs[] = sprintf(
			'<p>%s</p><ul><li>%s</li></ul>',
			esc_html__( 'Where a value is shown is a separate decision for every field:', 'wc-checkoutsuite' ),
			implode( '</li><li>', $visibilities )
		);

		$paragraphs[] = sprintf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: largest upload size in megabytes, 2: hours a temporary upload is kept. */
					__( 'A document you upload is stored outside the public part of the site and is never attached to an e-mail. Uploads are limited to %1$s MB. A document that reaches a placed order is kept while that order exists; an upload that never reached one is removed after %2$d hours.', 'wc-checkoutsuite' ),
					number_format_i18n( UploadRules::DEFAULT_MAX_BYTES / 1048576, 1 ),
					(int) ( UploadService::TTL / HOUR_IN_SECONDS )
				)
			)
		);

		$paragraphs[] = sprintf(
			'<p>%s</p>',
			esc_html__( 'You can ask this store for a copy of the data it holds about you, and you can ask it to erase it. The store\'s tools answer both, and the answer names what was erased and what is kept, and why.', 'wc-checkoutsuite' )
		);

		$paragraphs[] = sprintf(
			'<p><em>%s</em></p>',
			esc_html__( 'This text describes what the plugin does, and is offered as a suggestion for the store\'s own policy. It is not legal advice and makes no claim of conformity with any particular law; the store decides what it must tell its customers and for how long it must keep its records.', 'wc-checkoutsuite' )
		);

		return implode( "\n", $paragraphs );
	}
}
