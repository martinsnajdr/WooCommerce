<?php
/**
 * Class FormValidators
 *
 * @package Packetery\Module
 */

declare(strict_types=1);

namespace Packetery\Module;

use PacketeryVendor\Nette\Forms\Controls\BaseControl;

/**
 * Class FormValidators
 *
 * @package Packetery\Module
 */
class FormValidators {

	/**
	 * Tests if input value is greater than argument.
	 *
	 * @param \PacketeryVendor\Nette\Forms\Controls\BaseControl $input Form input.
	 * @param float                                      $arg Validation argument.
	 *
	 * @return bool
	 */
	public static function greaterThan( BaseControl $input, float $arg ): bool {
		return $input->getValue() > $arg;
	}
}
