<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Tappay\Blocks;

use MoksaWeb\Mowc\Modules\Shared\Blocks\AbstractMowcBlocksMethod;
use MoksaWeb\Mowc\Modules\Tappay\Api\Helper;

defined( 'ABSPATH' ) || exit;

final class TappayBlocksMethod extends AbstractMowcBlocksMethod {

	protected function provider_slug(): string {
		return 'tappay';
	}

	public function is_active(): bool {
		if ( ! Helper::has_credentials() ) {
			return false;
		}
		return parent::is_active();
	}

	protected function extra_script_handles(): array {
		$sdk_handle = 'mo-tappay-sdk';
		if ( ! wp_script_is( $sdk_handle, 'registered' ) ) {
			wp_register_script( $sdk_handle, Helper::SDK_URL, [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion,WordPress.WP.EnqueuedResourceParameters.NotInFooter
		}
		return [ $sdk_handle ];
	}

	protected function payment_method_data_extra( array $base ): array {
		$base['appId']  = (int) Helper::app_id();
		$base['appKey'] = Helper::app_key();
		$base['env']    = Helper::sdk_env();
		return $base;
	}
}
