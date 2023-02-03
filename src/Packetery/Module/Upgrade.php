<?php
/**
 * Class Upgrade.
 *
 * @package Packetery\Module
 */

declare( strict_types=1 );

namespace Packetery\Module;

use ActionScheduler_Store;
use Packetery\Core;
use Packetery\Core\Log\ILogger;
use Packetery\Core\Log\Record;
use Packetery\Module\Upgrade\Version_1_4_2;
use PacketeryLatte\Engine;

/**
 * Class Upgrade.
 */
class Upgrade {

	private const MIGRATION_TRANSIENT   = 'packeta_installing';
	private const ACTIONSCHEDULER_GROUP = 'packeta_upgrade';

	private const HOOK_MIGRATE_CARRIER_IDS               = 'packeta_migrateCarrierIds';
	private const HOOK_COLUMN_ADD_API_ERROR_MESSAGE      = 'packeta_addColumnApiErrorMessage';
	private const HOOK_COLUMN_ADD_API_ERROR_MESSAGE_DATE = 'packeta_addColumnApiErrorMessageDate';
	private const HOOK_COLUMN_ADD_DELIVER_ON             = 'packeta_addDeliverOnColumn';
	private const HOOK_CLEAR_CRON_CARRIERS_HOOK          = 'packeta_clearCronCarriersHook';

	const POST_TYPE_VALIDATED_ADDRESS = 'packetery_address';

	const META_LENGTH           = 'packetery_length';
	const META_WIDTH            = 'packetery_width';
	const META_HEIGHT           = 'packetery_height';
	const META_PACKET_STATUS    = 'packetery_packet_status';
	const META_WEIGHT           = 'packetery_weight';
	const META_CARRIER_ID       = 'packetery_carrier_id';
	const META_IS_EXPORTED      = 'packetery_is_exported';
	const META_IS_LABEL_PRINTED = 'packetery_is_label_printed';
	const META_CARRIER_NUMBER   = 'packetery_carrier_number';
	const META_PACKET_ID        = 'packetery_packet_id';
	const META_POINT_ID         = 'packetery_point_id';
	const META_POINT_NAME       = 'packetery_point_name';
	const META_POINT_CITY       = 'packetery_point_city';
	const META_POINT_ZIP        = 'packetery_point_zip';
	const META_POINT_STREET     = 'packetery_point_street';
	const META_POINT_URL        = 'packetery_point_url';

	/**
	 * Order repository.
	 *
	 * @var Order\Repository
	 */
	private $orderRepository;

	/**
	 * Message manager.
	 *
	 * @var MessageManager
	 */
	private $messageManager;

	/**
	 * Logger.
	 *
	 * @var ILogger
	 */
	private $logger;

	/**
	 * Log repository.
	 *
	 * @var Log\Repository
	 */
	private $logRepository;

	/**
	 * Carrier repository.
	 *
	 * @var Carrier\Repository
	 */
	private $carrierRepository;

	/**
	 * WpdbAdapter.
	 *
	 * @var WpdbAdapter
	 */
	private $wpdbAdapter;

	/**
	 * Latte engine.
	 *
	 * @var Engine
	 */
	private $latteEngine;

	/**
	 * Constructor.
	 *
	 * @param Order\Repository   $orderRepository   Order repository.
	 * @param MessageManager     $messageManager    Message manager.
	 * @param ILogger            $logger            Logger.
	 * @param Log\Repository     $logRepository     Log repository.
	 * @param WpdbAdapter        $wpdbAdapter       WpdbAdapter.
	 * @param Carrier\Repository $carrierRepository Carrier repository.
	 * @param Engine             $latteEngine       Latte engine.
	 */
	public function __construct(
		Order\Repository $orderRepository,
		MessageManager $messageManager,
		ILogger $logger,
		Log\Repository $logRepository,
		WpdbAdapter $wpdbAdapter,
		Carrier\Repository $carrierRepository,
		Engine $latteEngine
	) {
		$this->orderRepository   = $orderRepository;
		$this->messageManager    = $messageManager;
		$this->logger            = $logger;
		$this->logRepository     = $logRepository;
		$this->wpdbAdapter       = $wpdbAdapter;
		$this->carrierRepository = $carrierRepository;
		$this->latteEngine       = $latteEngine;
	}

	/**
	 * Checks previous plugin version and runs upgrade if needed.
	 * https://www.sitepoint.com/wordpress-plugin-updates-right-way/
	 *
	 * @return void
	 */
	public function check(): void {
		$oldVersion = get_option( 'packetery_version' );
		if ( Plugin::VERSION === $oldVersion ) {
			return;
		}

		// Legacy synchronous part start. TODO make asynchronous.
		$this->createCarrierTable();
		$this->createOrderTable();

		// If no previous version detected, no upgrade will be run.
		if ( $oldVersion && version_compare( $oldVersion, '1.2.0', '<' ) ) {
			$logEntries = get_posts(
				[
					'post_type'   => 'packetery_log',
					'post_status' => 'any',
					'nopaging'    => true,
					'fields'      => 'ids',
				]
			);
			foreach ( $logEntries as $logEntryId ) {
				wp_delete_post( $logEntryId, true );
			}

			unregister_post_type( 'packetery_log' );

			$this->migrateWpOrderMetadata();
			$addressEntries = get_posts(
				[
					'post_type'   => self::POST_TYPE_VALIDATED_ADDRESS,
					'post_status' => 'any',
					'nopaging'    => true,
					'fields'      => 'ids',
				]
			);
			foreach ( $addressEntries as $addressEntryId ) {
				wp_delete_post( $addressEntryId, true );
			}

			unregister_post_type( self::POST_TYPE_VALIDATED_ADDRESS );
			update_option( 'packetery_version', '1.2.0' );
		}

		if ( $oldVersion && version_compare( $oldVersion, '1.2.6', '<' ) ) {
			$this->orderRepository->deleteOrphans();
			update_option( 'packetery_version', '1.2.6' );
		}

		if ( $oldVersion && version_compare( $oldVersion, '1.4', '<' ) ) {
			$this->logRepository->addOrderIdColumn();
			$this->orderRepository->addAdultContentColumn();
			$this->orderRepository->addValueColumn();
			$this->orderRepository->addCodColumn();
			update_option( 'packetery_version', '1.4' );
		}

		if ( $oldVersion && version_compare( $oldVersion, '1.4.2', '<' ) ) {
			$version_1_4_2 = new Version_1_4_2( $this->wpdbAdapter );
			$version_1_4_2->run();
		}

		// Asynchronous part start.
		if ( $this->isInstalling() ) {
			return;
		}
		set_transient( self::MIGRATION_TRANSIENT, 'yes' );

		// TODO: change version to target version.
		$nextVersion = '1.4';

		add_action(
			self::HOOK_COLUMN_ADD_API_ERROR_MESSAGE,
			function () {
				$this->orderRepository->addColumnApiErrorMessage();
				$this->scheduleIfNotScheduled( self::HOOK_COLUMN_ADD_API_ERROR_MESSAGE_DATE );
			}
		);
		add_action(
			self::HOOK_COLUMN_ADD_API_ERROR_MESSAGE_DATE,
			function () {
				$this->orderRepository->addColumnApiErrorMessageDate();
				$this->scheduleIfNotScheduled( self::HOOK_COLUMN_ADD_DELIVER_ON );
			}
		);
		add_action(
			self::HOOK_COLUMN_ADD_DELIVER_ON,
			function () {
				$this->orderRepository->addDeliverOnColumn();
				$this->scheduleIfNotScheduled( self::HOOK_CLEAR_CRON_CARRIERS_HOOK );
			}
		);
		add_action(
			self::HOOK_CLEAR_CRON_CARRIERS_HOOK,
			function () {
				wp_clear_scheduled_hook( CronService::CRON_CARRIERS_HOOK );
				$this->scheduleIfNotScheduled( self::HOOK_MIGRATE_CARRIER_IDS );
			}
		);
		add_action(
			self::HOOK_MIGRATE_CARRIER_IDS,
			function () use ( $nextVersion ) {
				$this->orderRepository->migrateCarrierIdsOfPickupPointOrders();
				update_option( 'packetery_version', $nextVersion );
			}
		);

		if ( $oldVersion && version_compare( $oldVersion, $nextVersion, '<' ) ) {
			$this->scheduleIfNotScheduled( self::HOOK_COLUMN_ADD_API_ERROR_MESSAGE );
		}

		if ( ! $this->hasUnfinishedActions() ) {
			delete_transient( self::MIGRATION_TRANSIENT );
			update_option( 'packetery_version', Plugin::VERSION );
		}
	}

	/**
	 * Checks if there are some asynchronously planned upgrade tasks.
	 *
	 * @return bool
	 */
	private function hasUnfinishedActions(): bool {
		$actions = as_get_scheduled_actions(
			[
				'group'  => self::ACTIONSCHEDULER_GROUP,
				'status' => [
					ActionScheduler_Store::STATUS_PENDING,
					ActionScheduler_Store::STATUS_RUNNING,
				],
			],
			ARRAY_A
		);
		if ( ! empty( $actions ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if upgrade process is running.
	 *
	 * @return bool
	 */
	public function isInstalling(): bool {
		return ( 'yes' === get_transient( self::MIGRATION_TRANSIENT ) );
	}

	/**
	 * Schedules asynchronous task, if was not scheduled before.
	 *
	 * @param string $hookName Hook to schedule name.
	 *
	 * @return void
	 */
	private function scheduleIfNotScheduled( string $hookName ): void {
		if ( ! as_has_scheduled_action(
			$hookName,
			[
				// All except canceled.
				'status' => [
					ActionScheduler_Store::STATUS_PENDING,
					ActionScheduler_Store::STATUS_RUNNING,
					ActionScheduler_Store::STATUS_COMPLETE,
					ActionScheduler_Store::STATUS_FAILED,
				],
			],
			self::ACTIONSCHEDULER_GROUP
		) ) {
			as_schedule_single_action( time(), $hookName, [], self::ACTIONSCHEDULER_GROUP );
		}
	}

	/**
	 * Print installing notice.
	 *
	 * @return void
	 */
	public function echoInstallingNotice(): void {
		$this->latteEngine->render(
			PACKETERY_PLUGIN_DIR . '/template/admin-notice.latte',
			[
				'message'      => [
					'type'    => 'warning',
					'message' => __( 'Packeta plugin upgrade is in progress. Wait for it to complete to use it fully.', 'packeta' ),
				],
				'logo'         => Plugin::buildAssetUrl( 'public/packeta.svg' ),
				'translations' => [
					'packeta' => __( 'Packeta', 'packeta' ),
				],
			]
		);
	}

	/**
	 * Migrates WP order metadata.
	 *
	 * @return void
	 */
	private function migrateWpOrderMetadata(): void {
		$this->createOrderTable();

		// Did not work when called from plugins_loaded hook.
		$orders = wc_get_orders(
			[
				'packetery_all' => '1',
				'nopaging'      => true,
			]
		);

		foreach ( $orders as $order ) {
			$carrierId   = $this->carrierRepository->getFixedCarrierId(
				$this->getMetaAsNullableString( $order, self::META_CARRIER_ID ),
				strtolower( $order->get_shipping_country() )
			);
			$orderEntity = new Core\Entity\Order(
				(string) $order->get_id(),
				$this->carrierRepository->getAnyById( $carrierId )
			);
			$order->delete_meta_data( self::META_CARRIER_ID );

			$orderEntity->setWeight( $this->getMetaAsNullableFloat( $order, self::META_WEIGHT ) );
			$order->delete_meta_data( self::META_WEIGHT );

			$orderEntity->setPacketStatus( $this->getMetaAsNullableString( $order, self::META_PACKET_STATUS ) );
			$order->delete_meta_data( self::META_PACKET_STATUS );

			$orderEntity->setIsExported( (bool) $this->getMetaAsNullableString( $order, self::META_IS_EXPORTED ) );
			$order->delete_meta_data( self::META_IS_EXPORTED );

			$orderEntity->setIsLabelPrinted( (bool) $this->getMetaAsNullableString( $order, self::META_IS_LABEL_PRINTED ) );
			$order->delete_meta_data( self::META_IS_LABEL_PRINTED );

			$orderEntity->setCarrierNumber( $this->getMetaAsNullableString( $order, self::META_CARRIER_NUMBER ) );
			$order->delete_meta_data( self::META_CARRIER_NUMBER );

			$orderEntity->setPacketId( $this->getMetaAsNullableString( $order, self::META_PACKET_ID ) );
			$order->delete_meta_data( self::META_PACKET_ID );

			$orderEntity->setSize(
				new Core\Entity\Size(
					$this->getMetaAsNullableFloat( $order, self::META_LENGTH ),
					$this->getMetaAsNullableFloat( $order, self::META_WIDTH ),
					$this->getMetaAsNullableFloat( $order, self::META_HEIGHT )
				)
			);
			$order->delete_meta_data( self::META_LENGTH );
			$order->delete_meta_data( self::META_WIDTH );
			$order->delete_meta_data( self::META_HEIGHT );

			if ( null !== $this->getMetaAsNullableString( $order, self::META_POINT_ID ) ) {
				$orderEntity->setPickupPoint(
					new Core\Entity\PickupPoint(
						$this->getMetaAsNullableString( $order, self::META_POINT_ID ),
						$this->getMetaAsNullableString( $order, self::META_POINT_NAME ),
						$this->getMetaAsNullableString( $order, self::META_POINT_CITY ),
						$this->getMetaAsNullableString( $order, self::META_POINT_ZIP ),
						$this->getMetaAsNullableString( $order, self::META_POINT_STREET ),
						$this->getMetaAsNullableString( $order, self::META_POINT_URL )
					)
				);
			}
			$order->delete_meta_data( self::META_POINT_ID );
			$order->delete_meta_data( self::META_POINT_NAME );
			$order->delete_meta_data( self::META_POINT_CITY );
			$order->delete_meta_data( self::META_POINT_ZIP );
			$order->delete_meta_data( self::META_POINT_STREET );
			$order->delete_meta_data( self::META_POINT_URL );

			$validatedAddressId = $this->getValidatedAddressIdByOrderId( (int) $order->get_id() );
			if ( $validatedAddressId ) {
				$validatedAddress = $this->createAddressFromPostId( $validatedAddressId );
				$orderEntity->setAddressValidated( true );
				$orderEntity->setDeliveryAddress( $validatedAddress );
			}

			$this->orderRepository->save( $orderEntity );
			$order->save_meta_data();
		}
	}

	/**
	 * Creates active widget address using woocommerce order id.
	 *
	 * @param int $addressId Address ID.
	 *
	 * @return Core\Entity\Address|null
	 */
	public function createAddressFromPostId( int $addressId ): ?Core\Entity\Address {
		$address = new Core\Entity\Address(
			get_post_meta( $addressId, 'street', true ),
			get_post_meta( $addressId, 'city', true ),
			get_post_meta( $addressId, 'postCode', true )
		);
		$address->setHouseNumber( get_post_meta( $addressId, 'houseNumber', true ) );
		$address->setCounty( get_post_meta( $addressId, 'county', true ) );
		$address->setLongitude( get_post_meta( $addressId, 'longitude', true ) );
		$address->setLatitude( get_post_meta( $addressId, 'latitude', true ) );

		return $address;
	}

	/**
	 * Gets active address.
	 *
	 * @param int $orderId Order ID.
	 *
	 * @return int|null
	 */
	public function getValidatedAddressIdByOrderId( int $orderId ): ?int {
		$postIds = get_posts(
			[
				'post_type'   => self::POST_TYPE_VALIDATED_ADDRESS,
				'post_status' => 'any',
				'nopaging'    => true,
				'numberposts' => 1,
				'fields'      => 'ids',
				'post_parent' => $orderId,
			]
		);

		if ( empty( $postIds ) ) {
			return null;
		}

		return (int) array_shift( $postIds );
	}

	/**
	 * Gets meta property of order as string.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Meta order key.
	 *
	 * @return string|null
	 */
	private function getMetaAsNullableString( \WC_Order $order, string $key ): ?string {
		$value = $order->get_meta( $key, true );
		return ( ( null !== $value && '' !== $value ) ? (string) $value : null );
	}

	/**
	 * Gets meta property of order as float.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Meta order key.
	 *
	 * @return float|null
	 */
	private function getMetaAsNullableFloat( \WC_Order $order, string $key ): ?float {
		$value = $order->get_meta( $key, true );
		return ( ( null !== $value && '' !== $value ) ? (float) $value : null );
	}

	/**
	 * Transforms custom query variable to meta query.
	 *
	 * @param array $queryVars Query vars.
	 * @param array $get Input values.
	 *
	 * @return array
	 */
	public function handleCustomQueryVar( array $queryVars, array $get ): array {
		$metaQuery = $this->addQueryVars( ( $queryVars['meta_query'] ?? [] ), $get );
		if ( $metaQuery ) {
			// @codingStandardsIgnoreStart
			$queryVars['meta_query'] = $metaQuery;
			// @codingStandardsIgnoreEnd
		}

		return $queryVars;
	}

	/**
	 * Adds query vars to fetch order list.
	 *
	 * @param array $queryVars Query vars.
	 * @param array $get Get parameters.
	 *
	 * @return array
	 */
	private function addQueryVars( array $queryVars, array $get ): array {
		if ( ! empty( $get['packetery_all'] ) ) {
			$queryVars[] = [
				'key'     => self::META_CARRIER_ID,
				'compare' => 'EXISTS',
			];
			$queryVars[] = [
				'key'     => self::META_CARRIER_ID,
				'value'   => '',
				'compare' => '!=',
			];
		}

		return $queryVars;
	}

	/**
	 * Creates carrier table.
	 *
	 * @return void
	 */
	private function createCarrierTable(): void {
		$this->logRepository->createTable();
		$createResult = $this->carrierRepository->createTable();
		if ( false === $createResult ) {
			$lastError = $this->wpdbAdapter->getLastWpdbError();
			$this->messageManager->flash_message( __( 'Database carrier table was not created, you can find more information in Packeta log.', 'packeta' ), MessageManager::TYPE_ERROR );

			$record         = new Record();
			$record->action = Record::ACTION_CARRIER_TABLE_NOT_CREATED;
			$record->status = Record::STATUS_ERROR;
			$record->title  = __( 'Database carrier table was not created.', 'packeta' );
			$record->params = [
				'errorMessage' => $lastError,
			];
			$this->logger->add( $record );
		}
	}

	/**
	 * Creates order table.
	 *
	 * @return void
	 */
	private function createOrderTable(): void {
		$createResult = $this->orderRepository->createTable();
		if ( false === $createResult ) {
			$lastError = $this->wpdbAdapter->getLastWpdbError();
			$this->messageManager->flash_message( __( 'Database order table was not created, you can find more information in Packeta log.', 'packeta' ), MessageManager::TYPE_ERROR );

			$record         = new Record();
			$record->action = Record::ACTION_ORDER_TABLE_NOT_CREATED;
			$record->status = Record::STATUS_ERROR;
			$record->title  = __( 'Database order table was not created.', 'packeta' );
			$record->params = [
				'errorMessage' => $lastError,
			];
			$this->logger->add( $record );
		}
	}

}
