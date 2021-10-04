<?php
/**
 * Class Page
 *
 * @package Packetery\Options
 */

declare( strict_types=1 );

namespace Packetery\Module\Options;

use Packetery\Module\FormFactory;
use PacketeryLatte\Engine;
use PacketeryNette\Forms\Form;

/**
 * Class Page
 *
 * @package Packetery\Options
 */
class Page {

	/**
	 * PacketeryLatte_engine.
	 *
	 * @var Engine PacketeryLatte engine.
	 */
	private $latte_engine;

	/**
	 * Options Provider
	 *
	 * @var Provider
	 */
	private $optionsProvider;

	/**
	 * Form factory.
	 *
	 * @var FormFactory Form factory.
	 */
	private $formFactory;

	/**
	 * Plugin constructor.
	 *
	 * @param Engine      $latte_engine PacketeryLatte_engine.
	 * @param Provider    $optionsProvider Options provider.
	 * @param FormFactory $formFactory Form factory.
	 */
	public function __construct( Engine $latte_engine, Provider $optionsProvider, FormFactory $formFactory ) {
		$this->latte_engine    = $latte_engine;
		$this->optionsProvider = $optionsProvider;
		$this->formFactory     = $formFactory;
	}

	/**
	 * Registers WP callbacks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_menu_page(
			__( 'Settings', 'packetery' ),
			__( 'Packeta', 'packetery' ),
			'manage_options',
			'packeta-options',
			array(
				$this,
				'render',
			),
			'dashicons-schedule',
			55 // todo Move item to last position in menu.
		);
	}

	/**
	 * Creates settings form.
	 *
	 * @return Form
	 */
	private function create_form(): Form {
		$form = $this->formFactory->create();
		$form->setAction( 'options.php' );

		$container = $form->addContainer( 'packetery' );
		$container->addText( 'api_password', __( 'API password', 'packetery' ) )
					->setRequired()
					->addRule( $form::PATTERN, __( 'API password must be 32 characters long and must contain valid characters!', 'packetery' ), '[a-z\d]{32}' );
		$container->addText( 'sender', __( 'Sender', 'packetery' ) )
					->setRequired();

		$availableFormats = $this->optionsProvider->getLabelFormats();
		$labelFormats     = array_filter( array_combine( array_keys( $availableFormats ), array_column( $availableFormats, 'name' ) ) );
		$container->addSelect(
			'packeta_label_format',
			__( 'Packeta Label Format', 'packetery' ),
			$labelFormats
		)->checkDefaultValue( false );

		// TODO: remove in another ticket.
		$container->addSelect(
			'carrier_label_format',
			__( 'Carrier Label Format', 'packetery' ),
			array(
				'A6 on A4' => __( '105x148 mm (A6) label on a page of size 210x297 mm (A4)', 'packetery' ),
				'A6 on A6' => __( '105x148 mm (A6) label on a page of the same size (offset argument is ignored for this format)', 'packetery' ),
			)
		)->checkDefaultValue( false );

		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		$enabledGateways = [];
		foreach ( $gateways as $gateway ) {
			$enabledGateways[ $gateway->id ] = $gateway->title;
		}
		$container->addSelect(
			'cod_payment_method',
			__( 'Payment method that represents cash on delivery', 'packetery' ),
			$enabledGateways
		)->checkDefaultValue( false );

		$container->addCheckbox(
			'allow_label_emailing',
			__( 'Allow Label Emailing', 'packetery' )
		);

		if ( $this->optionsProvider->has_any() ) {
			$container->setDefaults( $this->optionsProvider->data_to_array() );
		}

		return $form;
	}

	/**
	 *  Admin_init callback.
	 */
	public function admin_init(): void {
		register_setting( 'packetery', 'packetery', array( $this, 'options_validate' ) );
		add_settings_section( 'packetery_main', __( 'Main Settings', 'packetery' ), '', 'packeta-options' );
	}

	/**
	 * Validates options.
	 *
	 * @param array $options Packetery_options.
	 *
	 * @return array
	 */
	public function options_validate( $options ): array {
		$form = $this->create_form();
		$form['packetery']->setValues( $options );
		if ( $form->isValid() === false ) {
			foreach ( $form['packetery']->getControls() as $control ) {
				if ( $control->hasErrors() === false ) {
					continue;
				}

				add_settings_error( $control->getCaption(), esc_attr( $control->getName() ), $control->getError() );
				$options[ $control->getName() ] = '';
			}
		}

		$api_password = $form['packetery']['api_password'];
		if ( $api_password->hasErrors() === false ) {
			$api_pass           = $api_password->getValue();
			$options['api_key'] = substr( $api_pass, 0, 16 );
		} else {
			$options['api_key'] = '';
		}

		return $options;
	}

	/**
	 *  Renders page.
	 */
	public function render(): void {
		$latteParams = [ 'form' => $this->create_form() ];
		if ( ! extension_loaded( 'soap' ) ) {
			$latteParams['error'] = __( 'This plugin requires an active SOAP library for proper operation. Contact your web hosting administrator.', 'packetery' );
		}
		$this->latte_engine->render( PACKETERY_PLUGIN_DIR . '/template/options/page.latte', $latteParams );
	}
}