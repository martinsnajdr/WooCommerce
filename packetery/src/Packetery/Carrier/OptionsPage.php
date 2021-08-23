<?php
/**
 * Class OptionsPage
 *
 * @package Packetery\Options
 */

declare( strict_types=1 );

namespace Packetery\Carrier;

use Nette\Forms\Form;
use Nette\Http\Request;
use Packetery\FormFactory;

/**
 * Class OptionsPage
 *
 * @package Packetery\Options
 */
class OptionsPage {

	/**
	 * Latte_engine.
	 *
	 * @var \Latte\Engine Latte engine.
	 */
	private $latteEngine;

	/**
	 * Carrier repository.
	 *
	 * @var Repository Carrier repository.
	 */
	private $carrierRepository;

	/**
	 * Form factory.
	 *
	 * @var FormFactory Form factory.
	 */
	private $formFactory;

	/**
	 * Nette Request.
	 *
	 * @var Request Nette Request.
	 */
	private $httpRequest;

	/**
	 * Internal pickup points.
	 *
	 * @var string[] Internal pickup points.
	 */
	private $zpointCarriers;

	/**
	 * Plugin constructor.
	 *
	 * @param \Latte\Engine $latteEngine Latte_engine.
	 * @param Repository    $carrierRepository Carrier repository.
	 * @param FormFactory   $formFactory Form factory.
	 * @param Request       $httpRequest Nette Request.
	 */
	public function __construct( \Latte\Engine $latteEngine, Repository $carrierRepository, FormFactory $formFactory, Request $httpRequest ) {
		$this->latteEngine       = $latteEngine;
		$this->carrierRepository = $carrierRepository;
		$this->formFactory       = $formFactory;
		$this->httpRequest       = $httpRequest;
		$this->zpointCarriers    = [
			'cz' => [
				'id'   => 'zpointcz',
				'name' => __( 'CZ Packeta pickup points', 'packetery' ),
			],
			'sk' => [
				'id'   => 'zpointsk',
				'name' => __( 'SK Packeta pickup points', 'packetery' ),
			],
			'hu' => [
				'id'   => 'zpointhu',
				'name' => __( 'HU Packeta pickup points', 'packetery' ),
			],
			'ro' => [
				'id'   => 'zpointro',
				'name' => __( 'RO Packeta pickup points', 'packetery' ),
			],
		];
	}

	/**
	 * Registers WP callbacks.
	 */
	public function register(): void {
		add_submenu_page(
			'packeta-options',
			__( 'Carrier settings', 'packetery' ),
			__( 'Carrier settings', 'packetery' ),
			'manage_options',
			'packeta-country',
			array(
				$this,
				'render',
			),
			10
		);
	}

	/**
	 * Creates settings form.
	 *
	 * @param array $carrierData Carrier data.
	 *
	 * @return \Nette\Forms\Form
	 */
	private function createForm( array $carrierData ): \Nette\Forms\Form {
		$optionId = 'packetery_carrier_' . $carrierData['id'];

		$form = $this->formFactory->create( $optionId );
		$form->setAction( $this->httpRequest->getUrl() );

		$form->addCheckbox(
			'active',
			__( 'Active carrier', 'packetery' )
		);

		$form->addText( 'name', __( 'Display name', 'packetery' ) )
			->setRequired();

		$weightLimits = $form->addContainer( 'weight_limits' );
		if ( empty( $carrierData['weight_limits'] ) ) {
			$this->addWeightLimit( $weightLimits, 0 );
		} else {
			foreach ( $carrierData['weight_limits'] as $index => $limit ) {
				$this->addWeightLimit( $weightLimits, $index );
			}
		}

		$surchargeLimits = $form->addContainer( 'surcharge_limits' );
		if ( empty( $carrierData['surcharge_limits'] ) ) {
			$this->addSurchargeLimit( $surchargeLimits, 0 );
		} else {
			foreach ( $carrierData['surcharge_limits'] as $index => $limit ) {
				$this->addSurchargeLimit( $surchargeLimits, $index );
			}
		}

		$item = $form->addText( 'free_shipping_limit', __( 'Free shipping limit', 'packetery' ) );
		$item->addRule( $form::FLOAT, __( 'Please enter a valid decimal number.', 'packetery' ) );
		$form->addHidden( 'id' )->setRequired();

		$form->onValidate[] = [ $this, 'validateOptions' ];
		$form->onSuccess[]  = [ $this, 'updateOptions' ];

		$carrierOptions       = get_option( $optionId );
		$carrierOptions['id'] = $carrierData['id'];
		if ( empty( $carrierOptions['name'] ) ) {
			$carrierOptions['name'] = $carrierData['name'];
		}
		$form->setDefaults( $carrierOptions );

		return $form;
	}

	/**
	 * Validates options.
	 *
	 * @param Form $form Form.
	 */
	public function validateOptions( Form $form ): void {
		$options = $form->getValues( 'array' );

		$this->validateLimits( $form, $options, 'weight_limits' );
		$this->checkOverlapping(
			$form,
			$options,
			'weight_limits',
			'weight',
			__( 'Weight rules are overlapping, fix it please.', 'packetery' )
		);
		$this->validateLimits( $form, $options, 'surcharge_limits' );
		$this->checkOverlapping(
			$form,
			$options,
			'surcharge_limits',
			'order_price',
			__( 'Surcharge rules are overlapping, fix it please.', 'packetery' )
		);
	}

	/**
	 * Saves carrier options. onSuccess callback.
	 *
	 * @param Form $form Form.
	 *
	 * @return void
	 */
	public function updateOptions( Form $form ): void {
		$options = $form->getValues( 'array' );

		$options = $this->mergeNewLimits( $options, 'weight_limits' );
		$options = $this->sortLimits( $options, 'weight_limits', 'weight' );
		$options = $this->mergeNewLimits( $options, 'surcharge_limits' );
		$options = $this->sortLimits( $options, 'surcharge_limits', 'order_price' );

		update_option( 'packetery_carrier_' . $options['id'], $options );
		if ( wp_safe_redirect( $this->httpRequest->getUrl(), 303 ) ) {
			exit;
		}
	}

	/**
	 *  Renders page.
	 */
	public function render(): void {
		$countryIso = $this->httpRequest->getQuery( 'code' );
		if ( $countryIso ) {

			$countryCarriers = $this->carrierRepository->getByCountry( $countryIso );
			// Add PP carriers for 'cz', 'sk', 'hu', 'ro'.
			if ( ! empty( $this->zpointCarriers[ $countryIso ] ) ) {
				array_unshift( $countryCarriers, $this->zpointCarriers[ $countryIso ] );
			}

			$carriersData = array();
			$post = $this->httpRequest->getPost();
			foreach ( $countryCarriers as $carrierData ) {
				if ( ! empty( $post ) && $post['id'] === $carrierData['id'] ) {
					$form = $this->createForm( $post );
				} else {
					$options = get_option( 'packetery_carrier_' . $carrierData['id'] );
					if ( false !== $options ) {
						$carrierData += $options;
					}
					$form = $this->createForm( $carrierData );
				}

				if ( $form->isSubmitted() ) {
					$form->fireEvents();
				}

				$carriersData[] = array(
					'form' => $form,
					'data' => $carrierData,
				);
			}

			$this->latteEngine->render(
				PACKETERY_PLUGIN_DIR . '/template/carrier/country.latte',
				array(
					'forms'       => $carriersData,
					'country_iso' => $countryIso,
				)
			);
		} else {
			// TODO: countries overview - fix in PES-263, CountryListingPage class.
		}
	}

	/**
	 * Transforms new_ keys to common numeric.
	 *
	 * @param array  $options Options to merge.
	 * @param string $limitsContainer Container id.
	 *
	 * @return array
	 */
	private function mergeNewLimits( array $options, string $limitsContainer ): array {
		$newOptions = array();
		if ( isset( $options[ $limitsContainer ] ) ) {
			foreach ( $options[ $limitsContainer ] as $key => $option ) {
				$keys = array_keys( $option );
				if ( $option[ $keys[0] ] && $option[ $keys[1] ] ) {
					if ( is_int( $key ) ) {
						$newOptions[ $key ] = $option;
					}
					if ( 0 === strpos( (string) $key, 'new_' ) ) {
						$newOptions[] = $option;
					}
				}
			}
			$options[ $limitsContainer ] = $newOptions;
		}

		return $options;
	}

	/**
	 * Validates limits.
	 *
	 * @param Form   $form Form.
	 * @param array  $options Options to merge.
	 * @param string $limitsContainer Container id.
	 *
	 * @return void
	 */
	private function validateLimits( Form $form, array $options, string $limitsContainer ): void {
		if ( isset( $options[ $limitsContainer ] ) ) {
			foreach ( $options[ $limitsContainer ] as $key => $option ) {
				$keys = array_keys( $option );
				if (
					( ! empty( $option[ $keys[0] ] ) && empty( $option[ $keys[1] ] ) ) ||
					( empty( $option[ $keys[0] ] ) && ! empty( $option[ $keys[1] ] ) )
				) {
					// TODO: JS validation.
					$errorMessage = __( 'Please fill in both values for each rule.', 'packetery' );
					$form[$limitsContainer][$key][$keys[0]]->addError( $errorMessage );
				}
			}
		}
	}

	/**
	 * Checks rules overlapping.
	 *
	 * @param Form   $form Form.
	 * @param array  $options Form data.
	 * @param string $limitsContainer Container id.
	 * @param string $limitKey Rule id.
	 * @param string $overlappingMessage Error message.
	 *
	 * @return void
	 */
	private function checkOverlapping( Form $form, array $options, string $limitsContainer, string $limitKey, string $overlappingMessage ): void {
		$limits = array_column( $options[ $limitsContainer ], $limitKey );
		if ( count( array_unique( $limits, SORT_NUMERIC ) ) !== count( $limits ) ) {
			add_settings_error( $limitsContainer, $limitsContainer, esc_attr( $overlappingMessage ) );
			$form->addError( $overlappingMessage );
		}
	}

	/**
	 * Sorts rules.
	 *
	 * @param array  $options Form data.
	 * @param string $limitsContainer Container id.
	 * @param string $limitKey Rule id.
	 *
	 * @return array
	 */
	private function sortLimits( array $options, string $limitsContainer, string $limitKey ): array {
		$limits = array_column( $options[ $limitsContainer ], $limitKey );
		array_multisort( $limits, SORT_ASC, $options[ $limitsContainer ] );

		return $options;
	}

	/**
	 * Adds limit fields to form.
	 *
	 * @param \Nette\Forms\Container $weightLimits Container.
	 * @param int|string             $index Index.
	 *
	 * @return void
	 */
	private function addWeightLimit( \Nette\Forms\Container $weightLimits, $index ): void {
		$limit = $weightLimits->addContainer( (string) $index );
		$item  = $limit->addText( 'weight', __( 'Weight up to (kg)', 'packetery' ) );
		$item->setRequired();
		$item->addRule( Form::FLOAT, __( 'Please enter a valid decimal number.', 'packetery' ) );
		$item = $limit->addText( 'price', __( 'Price', 'packetery' ) );
		$item->setRequired();
		$item->addRule( Form::FLOAT, __( 'Please enter a valid decimal number.', 'packetery' ) );
	}

	/**
	 * Adds limit fields to form.
	 *
	 * @param \Nette\Forms\Container $surchargeLimits Container.
	 * @param int|string             $index Index.
	 *
	 * @return void
	 */
	private function addSurchargeLimit( \Nette\Forms\Container $surchargeLimits, $index ): void {
		$limit = $surchargeLimits->addContainer( (string) $index );
		$item  = $limit->addText( 'order_price', __( 'Order price up to', 'packetery' ) );
		$item->addRule( Form::FLOAT, __( 'Please enter a valid decimal number.', 'packetery' ) );
		$item = $limit->addText( 'surcharge', __( 'Surcharge', 'packetery' ) );
		$item->addRule( Form::FLOAT, __( 'Please enter a valid decimal number.', 'packetery' ) );
	}

}