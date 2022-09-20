<?php
/**
 * Packeta shipping method class.
 *
 * @package Packetery
 */

declare( strict_types=1 );

namespace Packetery\Module;

use Packetery\Module\Carrier;
use PacketeryLatte\Engine;
use PacketeryNette\DI\Container;
use PacketeryNette\Http\Request;
use WC_Shipping_Flat_Rate;

/**
 * Packeta shipping method class.
 */
class ShippingMethod extends WC_Shipping_Flat_Rate {

	public const PACKETERY_METHOD_ID = 'packetery_shipping_method';
	public const SLUG_SETTINGS = 'wc-settings';

	/**
	 * Options.
	 *
	 * @var false|mixed|null
	 */
	private $options;

	/**
	 * DI container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Checkout object.
	 *
	 * @var Checkout
	 */
	private $checkout;

	/**
	 * CarrierRepository
	 *
	 * @var Carrier\Repository|null
	 */
	private $carrierRepository;

	/**
	 * Latte engine
	 *
	 * @var Engine|null
	 */
	private $latteEngine;

	/**
	 * Http request.
	 *
	 * @var Request|null
	 */
	private $httpRequest;

	/**
	 * Carrier options page.
	 *
	 * @var Carrier\OptionsPage|null
	 */
	private $carrierOptionsPage;
	/**
	 * @var mixed|null
	 */
	private $form;
	/**
	 * @var mixed|null
	 */
	private $formTemplate;
	/**
	 * @var \Packetery\Core\Entity\Carrier|null
	 */
	private $carrier;

	/**
	 * Constructor for Packeta shipping class
	 *
	 * @param int $instance_id Shipping method instance id.
	 */
	public function __construct( int $instance_id = 0 ) {
		parent::__construct();
		$this->id                 = self::PACKETERY_METHOD_ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Packeta', 'packeta' );
		$this->method_description = __( 'Allows to choose one of Packeta delivery methods', 'packeta' );
		$this->enabled            = 'yes'; // This can be added as a setting.
		$this->supports           = [
			// 'zones',
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		];
		// Title property is filled in init method.
		$this->init();

		$this->container         = CompatibilityBridge::getContainer();
		$this->checkout          = $this->container->getByType( Checkout::class );
		$this->httpRequest       = $this->container->getByType( Request::class );
		$this->carrierRepository = $this->container->getByType( Carrier\Repository::class );

		// Called in WC_Shipping_Method during update_option.
		add_filter( 'woocommerce_shipping_' . $this->id . '_instance_settings_values', [
			$this,
			'saveCustomSettings'
		], 10, 2 );
		$this->options = get_option( sprintf( 'woocommerce_%s_%s_settings', $this->id, $this->instance_id ) );

		// todo 999 move to get_admin_options_html if not used in saveCustomSettings
		$form = $formTemplate = $carrier = null;
		if ( $this->options && $this->options['packetery_shipping_method'] ) {
			$carrier = $this->carrierRepository->getAnyById( $this->options['packetery_shipping_method'] );
			if ( null !== $carrier ) {
				$post                     = $this->httpRequest->getPost();
				$postData                 = ( isset( $post['data'] ) ? self::fixBrokenPostData( $post['data'] ) : null );
				$this->carrierOptionsPage = $this->container->getByType( Carrier\OptionsPage::class );
				[ $formTemplate, $form ] = $this->carrierOptionsPage->getCarrierTemplateData( $postData, $carrier );
			}
		}

		$this->form         = $form;
		$this->formTemplate = $formTemplate;
		$this->carrier      = $carrier;
	}

	/**
	 * Return admin options as a html string.
	 *
	 * @return string
	 */
	public function get_admin_options_html(): string {
		$this->latteEngine = $this->container->getByType( Engine::class );

		/* We don't want to add default fields. */
		if ( $this->instance_id ) {
			$settingsHtml = $this->generate_settings_html( $this->get_instance_form_fields(), false );
		} else {
			$settingsHtml = $this->generate_settings_html( $this->get_form_fields(), false );
		}

		$availableCarriers = $this->carrierRepository->getCarriersForShippingRate( $this->get_rate_id() );

		$latteParams = [
			'settingsHtml'      => $settingsHtml,
			'availableCarriers' => $availableCarriers,
			'options'           => $this->options,
			'jsUrl'             => Plugin::buildAssetUrl( 'public/admin-country-carrier-modal.js' ),

			'globalCurrency' => get_woocommerce_currency_symbol(),

			'carrier_data' => [
				'form'         => $this->form,
				'formTemplate' => $this->formTemplate,
				'carrier'      => $this->carrier,
			],

			'translations' => [
				'selectedShippingMethod'                 => __( 'Selected shipping method', 'packeta' ),
				'pleaseSelect'                           => __( 'please select', 'packeta' ),
				//'helpTip' => 'todo 999 nejaka napoveda k selectu',
				// todo 999 sjednotit s OptionsPage, pokud by zustalo oboji
				'delete'                                 => __( 'Delete', 'packeta' ),
				'weightRules'                            => __( 'Weight rules', 'packeta' ),
				'addWeightRule'                          => __( 'Add weight rule', 'packeta' ),
				'codSurchargeRules'                      => __( 'COD surcharge rules', 'packeta' ),
				'addCodSurchargeRule'                    => __( 'Add COD surcharge rule', 'packeta' ),
				'afterExceedingThisAmountShippingIsFree' => __( 'After exceeding this amount, shipping is free.', 'packeta' ),
				'addressValidationDescription'           => __( 'Customer address validation.', 'packeta' ),
			],
		];

		return $this->latteEngine->renderToString( PACKETERY_PLUGIN_DIR . '/template/carrier/carrier-modal.latte', $latteParams );
	}

	/**
	 * Function to calculate shipping fee.
	 * Triggered by cart contents change, country change.
	 *
	 * @param array $package Order information.
	 *
	 * @return void
	 */
	public function calculate_shipping( $package = [] ): void {
		$customRates = $this->checkout->getShippingRates();
		foreach ( $customRates as $customRate ) {
			$this->add_rate( $customRate );
		}
	}

	/**
	 * Saves method's settings.
	 *
	 * @param mixed $settings Settings.
	 *
	 * @return mixed
	 */
	public function saveCustomSettings( $settings ) {
		$post = $this->httpRequest->getPost();
		if ( isset( $post['data'] ) ) {
			// "Old storage"
			// todo 999 nejaka forma validace bez add_settings_error
			$post['data'] = self::fixBrokenPostData( $post['data'] );
			if ( isset( $post['data']['weight_limits'] ) ) {
				/*
				if ( null === $this->carrierOptionsPage ) {
					$this->carrierOptionsPage = $this->container->getByType( Carrier\OptionsPage::class );
				}
				*/
				$this->carrierOptionsPage->updateOptionsModal( $post['data'] );
			}

			// "WC storage"
			$settings['packetery_shipping_method'] = $post['data']['packetery_shipping_method'];
		}

		return $settings;
	}

	private static function fixBrokenPostData( array $postData ): array {
		foreach ( $postData as $key => $value ) {
			$matches = [];
			if ( preg_match( '/^(.+)\[([^\]]+)$/', $key, $matches ) ) {
				$postData[ $matches[1] ] = [ $matches[2] => $value ];
				unset( $postData[ $key ] );
			}
		}

		return $postData;
	}

}
