<?php
/**
 * Class CreatePacketMapper.
 *
 * @package Packetery
 */

declare( strict_types=1 );

namespace Packetery\Core\Api\Soap;

use Packetery\Core\Entity;
use Packetery\Core\Helper;

/**
 * Class CreatePacketMapper.
 *
 * @package Packetery
 */
class CreatePacketMapper {

	/**
	 * CreatePacketMapper constructor.
	 */
	public function __construct() {

	}

	/**
	 * Maps order data to CreatePacket structure.
	 *
	 * @param Entity\Order $order Order entity.
	 * @param string       $deliverOnFormat Order entity.
	 *
	 * @return array
	 */
	public function fromOrderToArray( Entity\Order $order, string $deliverOnFormat ): array {
		$createPacketData = [
			// Required attributes.
			'number'       => ( $order->getCustomNumber() ?? $order->getNumber() ),
			'name'         => $order->getName(),
			'surname'      => $order->getSurname(),
			'value'        => $order->getValue(),
			'weight'       => $order->getFinalWeight(),
			'addressId'    => $order->getPickupPointOrCarrierId(),
			'eshop'        => $order->getEshop(),
			// Optional attributes.
			'adultContent' => (int) $order->containsAdultContent(),
			'cod'          => $order->getCod(),
			'currency'     => $order->getCurrency(),
			'email'        => $order->getEmail(),
			'note'         => $order->getNote(),
			'phone'        => $order->getPhone(),
			'deliverOn'    => Helper::getStringFromDateTime( $order->getDeliverOn(), Helper::DATEPICKER_FORMAT ),
		];

		$pickupPoint = $order->getPickupPoint();
		if ( null !== $pickupPoint && $order->isExternalCarrier() ) {
			$createPacketData['carrierPickupPoint'] = $pickupPoint->getId();
		}

		if ( $order->isHomeDelivery() ) {
			$address = $order->getDeliveryAddress();
			if ( null !== $address ) {
				$createPacketData['street'] = $address->getStreet();
				$createPacketData['city']   = $address->getCity();
				$createPacketData['zip']    = $address->getZip();
				if ( $address->getHouseNumber() ) {
					$createPacketData['houseNumber'] = $address->getHouseNumber();
				}
			}
		}

		$carrier = $order->getCarrier();
		if ( null !== $carrier && $carrier->requiresSize() ) {
			$size = $order->getSize();
			if ( null !== $size ) {
				$createPacketData['size'] = [
					'length' => $size->getLength(),
					'width'  => $size->getWidth(),
					'height' => $size->getHeight(),
				];
			}
		}

		return $createPacketData;
	}

}
