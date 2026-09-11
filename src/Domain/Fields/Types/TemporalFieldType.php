<?php
/**
 * Date and time field types.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Fields\Types;

use DateTimeImmutable;
use WCCheckoutSuite\Domain\Fields\AbstractFieldType;
use WCCheckoutSuite\Domain\Fields\FieldContext;
use WCCheckoutSuite\Domain\Fields\ValidationResult;

/**
 * Temporal types: date, time and datetime.
 *
 * `date` is a plain `YYYY-MM-DD` string and is never converted to UTC, because a
 * birth date is not an instant. `datetime` records an instant and therefore
 * carries explicit offset semantics.
 *
 * @see \ROADMAP.md section 5
 */
final class TemporalFieldType extends AbstractFieldType {

	/**
	 * Canonical format per type key.
	 *
	 * @var array<string, string>
	 */
	private const FORMATS = array(
		'date'     => 'Y-m-d',
		'time'     => 'H:i',
		'datetime' => 'Y-m-d\TH:i',
	);

	/**
	 * Constructor.
	 *
	 * @param string $key   One of `date`, `time`, `datetime`.
	 * @param string $label Translatable label.
	 */
	public function __construct(
		private string $key,
		private string $label
	) {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Canonical format of this type.
	 *
	 * @return string
	 */
	public function format(): string {
		return self::FORMATS[ $this->key ] ?? 'Y-m-d';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function valueSchema(): array {
		return array(
			'type'   => 'string',
			'format' => $this->format(),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function settingsSchema(): array {
		return array(
			'min' => array(
				'type'      => 'string',
				'maxLength' => 32,
			),
			'max' => array(
				'type'      => 'string',
				'maxLength' => 32,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array
	 */
	public function supports(): array {
		return array(
			'value'       => true,
			'multiple'    => false,
			'maskable'    => true,
			'conditional' => true,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed        $value Value to normalize or validate.
	 * @param FieldContext $context Trusted server-side context.
	 * @return mixed
	 */
	public function normalize( mixed $value, FieldContext $context ): mixed {
		return is_string( $value ) ? trim( $value ) : $value;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed        $value Value to normalize or validate.
	 * @param FieldContext $context Trusted server-side context.
	 * @return ValidationResult
	 */
	public function validate( mixed $value, FieldContext $context ): ValidationResult {
		if ( self::is_absent( $value ) ) {
			return ValidationResult::valid();
		}

		if ( ! is_string( $value ) ) {
			return ValidationResult::invalid(
				'invalid_type',
				__( 'This field expects a date or time value.', 'wc-checkoutsuite' )
			);
		}

		$parsed = DateTimeImmutable::createFromFormat( '!' . $this->format(), $value );

		if ( false === $parsed || $parsed->format( $this->format() ) !== $value ) {
			return ValidationResult::invalid(
				'invalid_format',
				sprintf(
					/* translators: %s: expected format */
					__( 'Please use the format %s.', 'wc-checkoutsuite' ),
					$this->format()
				),
				array( 'format' => $this->format() )
			);
		}

		if ( 'time' !== $this->key ) {
			$min = $context->setting( 'min' );
			$max = $context->setting( 'max' );

			if ( is_string( $min ) && '' !== $min && $value < $min ) {
				return ValidationResult::invalid(
					'before_minimum',
					sprintf(
						/* translators: %s: earliest accepted value */
						__( 'Please choose a value on or after %s.', 'wc-checkoutsuite' ),
						$min
					),
					array( 'min' => $min )
				);
			}

			if ( is_string( $max ) && '' !== $max && $value > $max ) {
				return ValidationResult::invalid(
					'after_maximum',
					sprintf(
						/* translators: %s: latest accepted value */
						__( 'Please choose a value on or before %s.', 'wc-checkoutsuite' ),
						$max
					),
					array( 'max' => $max )
				);
			}
		}

		return ValidationResult::valid();
	}
}
