<?php
/**
 * Class CartOrder.
 *
 * @package Packetery
 */

namespace Packetery\Module;

use Packetery\Core\Entity;

/**
 * Class CartOrder.
 *
 * @package Packetery
 */
class CartOrder {

	public const CARRIER_PREFIX = 'packetery_carrier_';

	public const ATTR_POINT_ID     = 'packetery_point_id';
	public const ATTR_POINT_NAME   = 'packetery_point_name';
	public const ATTR_POINT_CITY   = 'packetery_point_city';
	public const ATTR_POINT_ZIP    = 'packetery_point_zip';
	public const ATTR_POINT_STREET = 'packetery_point_street';
	public const ATTR_POINT_PLACE  = 'packetery_point_place'; // Business name of pickup point.
	public const ATTR_CARRIER_ID   = 'packetery_carrier_id';
	public const ATTR_POINT_URL    = 'packetery_point_url';

	public const ATTR_ADDRESS_IS_VALIDATED = 'packetery_address_isValidated';
	public const ATTR_ADDRESS_HOUSE_NUMBER = 'packetery_address_houseNumber';
	public const ATTR_ADDRESS_STREET       = 'packetery_address_street';
	public const ATTR_ADDRESS_CITY         = 'packetery_address_city';
	public const ATTR_ADDRESS_POST_CODE    = 'packetery_address_postCode';
	public const ATTR_ADDRESS_COUNTY       = 'packetery_address_county';
	public const ATTR_ADDRESS_COUNTRY      = 'packetery_address_country';
	public const ATTR_ADDRESS_LATITUDE     = 'packetery_address_latitude';
	public const ATTR_ADDRESS_LONGITUDE    = 'packetery_address_longitude';

	/**
	 * Pickup point attributes configuration.
	 *
	 * @var array[]
	 */
	public static $pickupPointAttrs = array(
		'id'        => array(
			'name'     => self::ATTR_POINT_ID,
			'required' => true,
		),
		'name'      => array(
			'name'     => self::ATTR_POINT_NAME,
			'required' => true,
		),
		'city'      => array(
			'name'     => self::ATTR_POINT_CITY,
			'required' => true,
		),
		'zip'       => array(
			'name'     => self::ATTR_POINT_ZIP,
			'required' => true,
		),
		'street'    => array(
			'name'     => self::ATTR_POINT_STREET,
			'required' => true,
		),
		'place'     => array(
			'name'     => self::ATTR_POINT_PLACE,
			'required' => false,
		),
		'carrierId' => array(
			'name'     => self::ATTR_CARRIER_ID,
			'required' => false,
		),
		'url'       => array(
			'name'     => self::ATTR_POINT_URL,
			'required' => false,
		),
	);

	/**
	 * Home delivery attributes configuration.
	 *
	 * @var array[]
	 */
	public static $homeDeliveryAttrs = [
		'isValidated' => [
			'name'                => self::ATTR_ADDRESS_IS_VALIDATED,
			// Name of checkout hidden form field. Must be unique in entire form.
			'isWidgetResultField' => false,
			// Is attribute included in widget result address? By default, it is.
		],
		'houseNumber' => [ // post type address field called 'houseNumber'.
			'name' => self::ATTR_ADDRESS_HOUSE_NUMBER,
		],
		'street'      => [
			'name' => self::ATTR_ADDRESS_STREET,
		],
		'city'        => [
			'name' => self::ATTR_ADDRESS_CITY,
		],
		'postCode'    => [
			'name'              => self::ATTR_ADDRESS_POST_CODE,
			'widgetResultField' => 'postcode',
			// Widget returns address object containing specified field. By default, it is the array key 'postCode', but in this case it is 'postcode'.
		],
		'county'      => [
			'name' => self::ATTR_ADDRESS_COUNTY,
		],
		'country'     => [
			'name' => self::ATTR_ADDRESS_COUNTRY,
		],
		'latitude'    => [
			'name' => self::ATTR_ADDRESS_LATITUDE,
		],
		'longitude'   => [
			'name' => self::ATTR_ADDRESS_LONGITUDE,
		],
	];

	/**
	 * Currency switcher facade.
	 *
	 * @var CurrencySwitcherFacade
	 */
	private $currencySwitcherFacade;

	/**
	 * CartOrder constructor.
	 *
	 * @param CurrencySwitcherFacade $currencySwitcherFacade Currency switcher facade.
	 */
	public function __construct( CurrencySwitcherFacade $currencySwitcherFacade ) {
		$this->currencySwitcherFacade = $currencySwitcherFacade;
	}

	/**
	 * Computes custom rate cost for carrier using cart contents.
	 *
	 * @param Carrier\Options $options Carrier options.
	 * @param float           $cartPrice Price.
	 * @param float|int       $cartWeight Weight.
	 * @param bool            $isFreeShippingCouponApplied Is free shipping coupon applied?.
	 *
	 * @return ?float
	 */
	public function getShippingRateCost(
		Carrier\Options $options,
		float $cartPrice,
		$cartWeight,
		bool $isFreeShippingCouponApplied
	): ?float {
		$cost           = null;
		$carrierOptions = $options->toArray();

		if ( isset( $carrierOptions['weight_limits'] ) ) {
			foreach ( $carrierOptions['weight_limits'] as $weightLimit ) {
				if ( $cartWeight <= $weightLimit['weight'] ) {
					$cost = $weightLimit['price'];
					break;
				}
			}
		}

		if ( null === $cost ) {
			return null;
		}

		if ( $carrierOptions['free_shipping_limit'] ) {
			$freeShippingLimit = $this->currencySwitcherFacade->getConvertedPrice( $carrierOptions['free_shipping_limit'] );
			if ( $cartPrice >= $freeShippingLimit ) {
				$cost = 0;
			}
		}

		if ( 0 !== $cost && $isFreeShippingCouponApplied && $options->hasCouponFreeShippingActive() ) {
			$cost = 0;
		}

		// WooCommerce currency-switcher.com compatibility.
		return (float) $cost;
	}

	/**
	 * Tells if free shipping coupon is applied.
	 *
	 * @param \WC_Cart|\WC_Order $cartOrOrder CartOrder.
	 *
	 * @return bool
	 */
	public function isFreeShippingCouponApplied( $cartOrOrder ): bool {
		$coupons = $cartOrOrder->get_coupons();
		foreach ( $coupons as $coupon ) {
			if ( $coupon->get_free_shipping() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Gets carrier id from chosen shipping method.
	 *
	 * @param string $chosenMethod Chosen shipping method.
	 *
	 * @return string|null
	 */
	public function getCarrierId( string $chosenMethod ): ?string {
		$branchServiceId = $this->getExtendedBranchServiceId( $chosenMethod );
		if ( null === $branchServiceId ) {
			return null;
		}

		if ( strpos( $branchServiceId, 'zpoint' ) === 0 ) {
			return Carrier\Repository::INTERNAL_PICKUP_POINTS_ID;
		}

		return $branchServiceId;
	}

	/**
	 * Gets feed ID or artificially created ID for internal purposes.
	 *
	 * @param string $chosenMethod Chosen method.
	 *
	 * @return string|null
	 */
	public function getExtendedBranchServiceId( string $chosenMethod ): ?string {
		if ( ! $this->isPacketeryOrder( $chosenMethod ) ) {
			return null;
		}

		return str_replace( self::CARRIER_PREFIX, '', $chosenMethod );
	}

	/**
	 * Checks if chosen shipping method is one of packetery.
	 *
	 * @param string $chosenMethod Chosen shipping method.
	 *
	 * @return bool
	 */
	public function isPacketeryOrder( string $chosenMethod ): bool {
		$chosenMethod = $this->getShortenedRateId( $chosenMethod );
		return ( strpos( $chosenMethod, self::CARRIER_PREFIX ) === 0 );
	}

	/**
	 * Gets ShippingRate's ID of extended id.
	 *
	 * @param string $chosenMethod Chosen shipping method.
	 *
	 * @return string
	 */
	public function getShortenedRateId( string $chosenMethod ): string {
		return str_replace( ShippingMethod::PACKETERY_METHOD_ID . ':', '', $chosenMethod );
	}

	/**
	 * Updates order entity from props to save.
	 *
	 * @param Entity\Order $orderEntity Order entity.
	 * @param array        $propsToSave Props to save.
	 *
	 * @return void
	 */
	public function updateOrderEntityFromPropsToSave( Entity\Order $orderEntity, array $propsToSave ): void {
		$orderEntityPickupPoint = $orderEntity->getPickupPoint();
		if ( null === $orderEntityPickupPoint ) {
			$orderEntityPickupPoint = new Entity\PickupPoint();
		}

		foreach ( $propsToSave as $attrName => $attrValue ) {
			switch ( $attrName ) {
				case self::ATTR_CARRIER_ID:
					$orderEntity->setCarrierId( $attrValue );
					break;
				case self::ATTR_POINT_ID:
					$orderEntityPickupPoint->setId( $attrValue );
					break;
				case self::ATTR_POINT_NAME:
					$orderEntityPickupPoint->setName( $attrValue );
					break;
				case self::ATTR_POINT_URL:
					$orderEntityPickupPoint->setUrl( $attrValue );
					break;
				case self::ATTR_POINT_STREET:
					$orderEntityPickupPoint->setStreet( $attrValue );
					break;
				case self::ATTR_POINT_ZIP:
					$orderEntityPickupPoint->setZip( $attrValue );
					break;
				case self::ATTR_POINT_CITY:
					$orderEntityPickupPoint->setCity( $attrValue );
					break;
			}
		}

		$orderEntity->setPickupPoint( $orderEntityPickupPoint );
	}

	/**
	 * Update order shipping.
	 *
	 * @param \WC_Order $wcOrder       WC Order.
	 * @param string    $attributeName Attribute name.
	 * @param string    $value         Value.
	 *
	 * @return void
	 * @throws \WC_Data_Exception When shipping input is invalid.
	 */
	public function updateShippingAddressProperty( \WC_Order $wcOrder, string $attributeName, string $value ): void {
		if ( self::ATTR_POINT_STREET === $attributeName ) {
			$wcOrder->set_shipping_address_1( $value );
			$wcOrder->set_shipping_address_2( '' );
		}
		if ( self::ATTR_POINT_PLACE === $attributeName ) {
			$wcOrder->set_shipping_company( $value );
		}
		if ( self::ATTR_POINT_CITY === $attributeName ) {
			$wcOrder->set_shipping_city( $value );
		}
		if ( self::ATTR_POINT_ZIP === $attributeName ) {
			$wcOrder->set_shipping_postcode( $value );
		}
	}

}
