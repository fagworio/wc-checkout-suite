<?php
/**
 * A state an order can wait in.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Statuses;

/**
 * One custom order status, as §12.3 configures it.
 *
 * A status is a **state**, and it is not a command. §12.4 is the rule this class is shaped by: a
 * status change must never charge anything, because a status can be changed by hand, by an import
 * or by a webhook, and a store that charged on a state change would charge by accident. So the
 * object carries what a merchant sees and who may see it — a name, a colour, a label for the
 * customer, whether it appears in their account and in the e-mails, whether staff may move an order
 * into it by hand — and **nothing a gateway reads**. There is no payment field to set, and that
 * absence is the design rather than an omission.
 *
 * Three decisions are worth stating.
 *
 * 1. **The identifier is permanent and is not the label.** §12.6: "IDs permanentes não devem ser
 *    derivados de label mutável de forma que renomear crie outro estado." The identifier is
 *    assigned once — from the name when the status is created, and from the flow that asked for it
 *    when it is migrated — and renaming the label changes the label and nothing else. Orders that
 *    are already in the state stay in it.
 * 2. **A pre-payment status declares itself as one.** §12.5: "Análise pendente deve permanecer como
 *    não pago." The flag is what makes that a property of the configuration instead of a habit, and
 *    the registry is what holds it: a status that declares itself pre-payment is never registered
 *    as a paid status, whatever else changes.
 * 3. **`manual_actions` is about hands, not about money.** It says whether staff may move an order
 *    into this state from the order screen. It is not an approval and it is not a capture.
 *
 * @see \ROADMAP.md section 12
 */
final class OrderStatus {

	/**
	 * Longest identifier that fits beside WooCommerce's own prefix.
	 *
	 * A status is registered as `wc-<id>`, and the post status column holds twenty characters, so
	 * the identifier has at most seventeen. The legacy review states are checked against the same
	 * bound, which is why they were derived to be short.
	 */
	public const MAX_ID = 17;

	/**
	 * Statuses WooCommerce owns, which a custom status may not shadow.
	 *
	 * @var array<int, string>
	 */
	public const RESERVED = array(
		'pending',
		'processing',
		'on-hold',
		'completed',
		'cancelled',
		'refunded',
		'failed',
		'checkout-draft',
		'auto-draft',
		'draft',
		'trash',
	);

	/**
	 * Constructor.
	 *
	 * @param string               $id              Permanent identifier, without the `wc-` prefix.
	 * @param string               $label           Name the merchant sees.
	 * @param string               $customer_label  Name the customer sees.
	 * @param string               $colour          Hex colour the admin list paints it with.
	 * @param bool                 $active          Whether it is registered at all.
	 * @param bool                 $show_customer   Whether the customer sees it on their order.
	 * @param bool                 $show_emails     Whether it appears in order emails.
	 * @param bool                 $manual          Whether staff may move an order into it by hand.
	 * @param bool                 $prepayment      Whether it is a state before payment.
	 * @param string               $description     Note for staff.
	 * @param array<string, mixed> $extra           Keys the store wrote that this build does not know.
	 */
	public function __construct(
		private string $id = '',
		private string $label = '',
		private string $customer_label = '',
		private string $colour = '',
		private bool $active = true,
		private bool $show_customer = true,
		private bool $show_emails = true,
		private bool $manual = true,
		private bool $prepayment = false,
		private string $description = '',
		private array $extra = array()
	) {
	}

	/**
	 * Builds one status from a stored array.
	 *
	 * Keys this build does not know are carried rather than dropped: a configuration written by a
	 * later version, or by an extension through the filter, must survive being read and written
	 * back by this one.
	 *
	 * @param array<string, mixed> $data Raw status.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$known = array(
			'id',
			'label',
			'customer_label',
			'colour',
			'active',
			'show_customer',
			'show_emails',
			'manual',
			'prepayment',
			'description',
		);

		return new self(
			isset( $data['id'] ) ? (string) $data['id'] : '',
			isset( $data['label'] ) ? (string) $data['label'] : '',
			isset( $data['customer_label'] ) ? (string) $data['customer_label'] : '',
			isset( $data['colour'] ) ? (string) $data['colour'] : '',
			! isset( $data['active'] ) || (bool) $data['active'],
			! isset( $data['show_customer'] ) || (bool) $data['show_customer'],
			! isset( $data['show_emails'] ) || (bool) $data['show_emails'],
			! isset( $data['manual'] ) || (bool) $data['manual'],
			! empty( $data['prepayment'] ),
			isset( $data['description'] ) ? (string) $data['description'] : '',
			array_diff_key( $data, array_flip( $known ) )
		);
	}

	/**
	 * The identifier a new status gets from the name it was given.
	 *
	 * Readable, because a merchant who exports a configuration should be able to recognise their
	 * own states in it, and numbered when it collides. It is derived from the name **once**: the
	 * identifier is stored, and every later call renames the label and nothing else.
	 *
	 * @param string             $label Name the merchant typed.
	 * @param array<int, string> $taken Identifiers already in use.
	 * @return string
	 */
	public static function unique_id( string $label, array $taken = array() ): string {
		$base = strtolower( self::without_accents( $label ) );
		$base = (string) preg_replace( '/[^a-z0-9]+/', '_', $base );
		$base = trim( $base, '_' );
		$base = substr( $base, 0, self::MAX_ID );
		$base = '' !== $base ? $base : 'estado';

		if ( ! in_array( $base, $taken, true ) ) {
			return $base;
		}

		$suffix = 2;

		while ( in_array( substr( $base, 0, self::MAX_ID - 2 ) . '_' . $suffix, $taken, true ) ) {
			++$suffix;
		}

		return substr( $base, 0, self::MAX_ID - 2 ) . '_' . $suffix;
	}

	/**
	 * The letters of the languages this store is written in, folded to their plain form.
	 *
	 * Deliberately a table and not WordPress's `remove_accents()`, for the reason the browser half
	 * of the editor needs the same answer: the screen proposes an identifier from the name the
	 * merchant types, the server stores one, and the two have to agree. `remove_accents()` exists
	 * only in PHP, and a rule that produced one identifier in the browser and another on the server
	 * would show the merchant a key their status does not have.
	 *
	 * Anything outside the table is not a letter an identifier can hold, and the pattern that
	 * follows turns it into a separator.
	 *
	 * @param string $label Name the merchant typed.
	 * @return string
	 */
	private static function without_accents( string $label ): string {
		$table = array(
			'á' => 'a',
			'à' => 'a',
			'â' => 'a',
			'ã' => 'a',
			'ä' => 'a',
			'å' => 'a',
			'Á' => 'A',
			'À' => 'A',
			'Â' => 'A',
			'Ã' => 'A',
			'Ä' => 'A',
			'Å' => 'A',
			'ç' => 'c',
			'Ç' => 'C',
			'é' => 'e',
			'è' => 'e',
			'ê' => 'e',
			'ë' => 'e',
			'É' => 'E',
			'È' => 'E',
			'Ê' => 'E',
			'Ë' => 'E',
			'í' => 'i',
			'ì' => 'i',
			'î' => 'i',
			'ï' => 'i',
			'Í' => 'I',
			'Ì' => 'I',
			'Î' => 'I',
			'Ï' => 'I',
			'ñ' => 'n',
			'Ñ' => 'N',
			'ó' => 'o',
			'ò' => 'o',
			'ô' => 'o',
			'õ' => 'o',
			'ö' => 'o',
			'Ó' => 'O',
			'Ò' => 'O',
			'Ô' => 'O',
			'Õ' => 'O',
			'Ö' => 'O',
			'ú' => 'u',
			'ù' => 'u',
			'û' => 'u',
			'ü' => 'u',
			'Ú' => 'U',
			'Ù' => 'U',
			'Û' => 'U',
			'Ü' => 'U',
			'ý' => 'y',
			'ÿ' => 'y',
			'Ý' => 'Y',
		);

		return strtr( $label, $table );
	}

	/**
	 * Whether an identifier is one WooCommerce owns.
	 *
	 * @param string $id Identifier.
	 * @return bool
	 */
	public static function is_reserved( string $id ): bool {
		return in_array( strtolower( $id ), self::RESERVED, true );
	}

	/**
	 * Permanent identifier, without the `wc-` prefix.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The post status WooCommerce knows it by.
	 *
	 * @return string
	 */
	public function key(): string {
		return 'wc-' . $this->id;
	}

	/**
	 * Name the merchant sees.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Name the customer sees, falling back to the merchant's own.
	 *
	 * A store that never wrote a customer label is a store that wants the same words on both sides,
	 * and an empty string on the customer's page would be a state with no name.
	 *
	 * @return string
	 */
	public function customer_label(): string {
		return '' !== $this->customer_label ? $this->customer_label : $this->label;
	}

	/**
	 * Colour the admin list paints it with.
	 *
	 * @return string
	 */
	public function colour(): string {
		return $this->colour;
	}

	/**
	 * Whether it is registered at all.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return $this->active;
	}

	/**
	 * Whether the customer sees it on their order.
	 *
	 * @return bool
	 */
	public function shows_customer(): bool {
		return $this->show_customer;
	}

	/**
	 * Whether it appears in order emails.
	 *
	 * @return bool
	 */
	public function shows_emails(): bool {
		return $this->show_emails;
	}

	/**
	 * Whether staff may move an order into it by hand.
	 *
	 * @return bool
	 */
	public function allows_manual(): bool {
		return $this->manual;
	}

	/**
	 * Whether it is a state an order waits in before it is paid.
	 *
	 * @return bool
	 */
	public function is_prepayment(): bool {
		return $this->prepayment;
	}

	/**
	 * Note for staff.
	 *
	 * @return string
	 */
	public function description(): string {
		return $this->description;
	}

	/**
	 * Exports the status as an array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array_merge(
			array(
				'id'             => $this->id,
				'label'          => $this->label,
				'customer_label' => $this->customer_label,
				'colour'         => $this->colour,
				'active'         => $this->active,
				'show_customer'  => $this->show_customer,
				'show_emails'    => $this->show_emails,
				'manual'         => $this->manual,
				'prepayment'     => $this->prepayment,
				'description'    => $this->description,
			),
			$this->extra
		);
	}
}
