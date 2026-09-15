<?php
/**
 * Canonical section definition.
 *
 * @package WCCheckoutSuite
 */

declare( strict_types = 1 );

namespace WCCheckoutSuite\Domain\Sections;

/**
 * The historical name of a container, kept for the code that reads it.
 *
 * The document called this a *section* while the checkout was the only place it could
 * live. `roadmap/WC-CheckoutSuite-Especificacao-Completa-com-Referencias-Visuais` §3.2
 * renamed the concept to **container**, because it now belongs to one of seven
 * destinations and carries each one's presentation.
 *
 * The behaviour lives in {@see ContainerDefinition}; this subclass exists so the two
 * dozen readers that still say `SectionDefinition` keep working while they migrate. New
 * code should ask for `ContainerDefinition` and get the same object.
 */
final class SectionDefinition extends ContainerDefinition {
}
