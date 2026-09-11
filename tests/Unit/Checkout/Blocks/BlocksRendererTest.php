<?php
/**
 * Blocks renderer tests.
 *
 * The renderer publishes what a component needs to draw a field the native API
 * cannot. What matters is that it publishes *only* those fields — a native field
 * published here would be drawn twice — and that it refuses one it cannot draw
 * instead of letting a customer fill a field whose value goes nowhere.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Tests\Unit\Checkout\Blocks;

use PHPUnit\Framework\TestCase;
use WCCheckoutSuite\Checkout\Blocks\BlocksAdapter;

/**
 * Classification of the field types, and the payload the components receive.
 */
final class BlocksRendererTest extends TestCase {

	/**
	 * Every rendered type is classified, and the classification says how.
	 *
	 * The phase gate asks for this over every non-file type of v1: a type is either
	 * rendered by the platform, rendered by this plugin, or explicitly restricted.
	 * A type that fell through the three would be a field nobody decided about.
	 *
	 * @return void
	 */
	public function test_every_type_has_a_decided_mode(): void {
		$types = array(
			'text'           => 'native',
			'select'         => 'native',
			'checkbox'       => 'native',
			'textarea'       => 'controlled',
			'radio'          => 'controlled',
			'multiselect'    => 'controlled',
			'checkbox-group' => 'controlled',
			'date'           => 'controlled',
			'time'           => 'controlled',
			'datetime'       => 'controlled',
			'number'         => 'restricted',
			'email'          => 'restricted',
			'url'            => 'restricted',
			'tel'            => 'restricted',
			'file'           => 'restricted',
			'heading'        => 'restricted',
			'paragraph'      => 'restricted',
			'html'           => 'restricted',
		);

		foreach ( $types as $type => $expected ) {
			self::assertSame( $expected, BlocksAdapter::mode( $type ), $type );
			self::assertNotSame( '', BlocksAdapter::reason( $type ), $type );
		}
	}

	/**
	 * The temporal fields are exactly the ones this task was asked to deliver.
	 *
	 * @return void
	 */
	public function test_the_controlled_types_are_the_ones_the_task_names(): void {
		self::assertSame(
			array( 'date', 'time', 'datetime', 'textarea', 'radio', 'multiselect', 'checkbox-group' ),
			BlocksAdapter::controlled_types()
		);
	}
}
