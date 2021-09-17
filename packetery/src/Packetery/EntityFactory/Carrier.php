<?php
/**
 * Class Carrier.
 *
 * @package Packetery\EntityFactory
 */

namespace Packetery\EntityFactory;

use Packetery\Entity;

/**
 * Class Carrier.
 *
 * @package Packetery\EntityFactory
 */
class Carrier {

	/**
	 * Carrier factory.
	 *
	 * @param array $dbResult Data from db.
	 *
	 * @return Entity\Carrier
	 */
	public function create( array $dbResult ): Entity\Carrier {
		return new Entity\Carrier(
			(int) $dbResult['id'],
			$dbResult['name'],
			(bool) $dbResult['is_pickup_points'],
			(bool) $dbResult['has_carrier_direct_label'],
			(bool) $dbResult['separate_house_number'],
			(bool) $dbResult['customs_declarations'],
			(bool) $dbResult['requires_email'],
			(bool) $dbResult['requires_phone'],
			(bool) $dbResult['requires_size'],
			! (bool) $dbResult['disallows_cod'],
			$dbResult['country'],
			$dbResult['currency'],
			(bool) $dbResult['max_weight'],
			(bool) $dbResult['deleted']
		);
	}
}
