<?php
/**
 * Blocks checkout registration.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Checkout\Blocks;

use WCCheckoutSuite\Checkout\Classic\PublishedDocument;
use WCCheckoutSuite\Domain\Fields\FieldDefinition;
use WCCheckoutSuite\Domain\Sections\SectionDefinition;

/**
 * Registers the published schema with the Blocks checkout.
 *
 * The adapter decides what can be registered; this class does the registering, and
 * it is small on purpose. Three things are worth stating rather than leaving to be
 * read off the code:
 *
 * 1. **The hook is `woocommerce_blocks_loaded`.** The registration function defers
 *    itself to that action when it has not run yet, so calling it earlier is not
 *    an error — but a plugin that fires its report before the service exists is
 *    reporting on nothing, and an extension that registers before WooCommerce has
 *    built its checkout fields is an extension whose fields may or may not exist
 *    depending on load order.
 *
 * 2. **A registration that throws does not take the checkout down.** The
 *    registration function throws when WooCommerce refuses a field, and WooCommerce
 *    can refuse one for reasons this adapter cannot know — another plugin having
 *    taken the identifier, a filter changing the field. The failure is caught,
 *    reported with its message, and the remaining fields are registered: a
 *    checkout missing one field is a checkout, and a fatal error is not.
 *
 * 3. **The report is fired, not stored.** `wccs_blocks_adapter_report` carries what
 *    was registered and what was refused with the reason, the same way the classic
 *    adapter reports. Nothing is written anywhere: the published document already
 *    says what the merchant asked for, and a second copy of that decision is a
 *    second thing to keep in step.
 *
 * @see \ROADMAP.md sections 8 and 11
 */
final class BlocksCheckout {

	/**
	 * Action carrying the adapter's report.
	 */
	public const REPORT_ACTION = 'wccs_blocks_adapter_report';

	/**
	 * Registers the hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'woocommerce_blocks_loaded', array( self::class, 'run' ), 20 );
	}

	/**
	 * Hook callback.
	 *
	 * Separate from {@see self::apply()} because an action callback returns
	 * nothing: a registered action's answer travels in the report, and a callback
	 * that returned an array would be relying on WordPress to ignore it.
	 *
	 * @return void
	 */
	public static function run(): void {
		self::apply();
	}

	/**
	 * Registers the published fields the Blocks checkout can render.
	 *
	 * The document is handed to the adapter **through the model**, not as it was stored.
	 * An omitted `storage` means "with the order" to `FieldDefinition` — and it is what
	 * every other reader of the same document sees, the renderer and the Store API
	 * extension included. Handing over the raw array instead made the adapter read an
	 * absent scope as "not stored" and refuse the field, so the same configuration was
	 * valid to one reader and invalid to another, which is the difference between a field
	 * appearing at the checkout and disappearing from it. It is the mistake
	 * {@see \WCCheckoutSuite\Domain\Sections\SectionValidator::validate_references()}
	 * already records for the section key.
	 *
	 * @return array{registered: array<int, string>, refused: array<int, array{field: string, code: string, reason: string}>}
	 */
	public static function apply(): array {
		$document = PublishedDocument::read();

		$definitions = array();
		$sections    = array();

		foreach ( $document->fields() as $raw ) {
			if ( is_array( $raw ) ) {
				$definitions[] = FieldDefinition::from_array( $raw )->to_array();
			}
		}

		foreach ( $document->sections() as $raw ) {
			if ( is_array( $raw ) ) {
				$sections[] = SectionDefinition::from_array( $raw )->to_array();
			}
		}

		$translated = ( new BlocksAdapter() )->apply( $definitions, $sections, (int) wc_get_price_decimals() );

		$registered = array();
		$refused    = $translated['report'];

		if ( ! BlocksAdapter::is_available() ) {
			$refused[] = array(
				'field'  => '',
				'code'   => 'blocks_unavailable',
				'reason' => 'The Blocks additional-fields API is not available on this request, so nothing was registered.',
			);

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant is the prefixed name, kept in one place.
			do_action( self::REPORT_ACTION, $registered, $refused );

			return array(
				'registered' => $registered,
				'refused'    => $refused,
			);
		}

		foreach ( $translated['registrations'] as $registration ) {
			try {
				woocommerce_register_additional_checkout_field( $registration );
				$registered[] = (string) $registration['id'];
			} catch ( \Throwable $error ) {
				$refused[] = array(
					'field'  => (string) $registration['id'],
					'code'   => 'registration_refused',
					'reason' => $error->getMessage(),
				);
			}
		}

		/**
		 * Reports what the Blocks adapter registered, and what it could not.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string>                                                   $registered Identifiers registered.
		 * @param array<int, array{field: string, code: string, reason: string}>        $refused    Refusals with their reasons.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant is the prefixed name, kept in one place.
		do_action( self::REPORT_ACTION, $registered, $refused );

		return array(
			'registered' => $registered,
			'refused'    => $refused,
		);
	}
}
