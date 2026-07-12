<?php

declare( strict_types=1 );

namespace Moksafowo\Settings;

defined( 'ABSPATH' ) || exit;

final class Ui {

	/**
	 * Shell CSS 已改走 enqueue（SettingsPage::enqueue_assets 載入
	 * assets/admin/settings-shell.css）。保留此 method 讓既有呼叫端不破，no-op。
	 */
	public static function print_styles(): void {
	}

	public static function open_shell(): void {
		self::print_styles();
		echo '<div class="moksafowo-shell">';
	}

	public static function close_shell(): void {
		echo '</div>';
	}

	public static function intro( string $title, string $description ): void {
		?>
		<div class="moksafowo-intro">
			<h2><?php echo esc_html( $title ); ?></h2>
			<?php if ( $description !== '' ) : ?>
				<p><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function category_group( string $title, array $cards ): void {
		if ( $cards === [] ) {
			return;
		}
		?>
		<section class="moksafowo-category">
			<h3 class="moksafowo-category__title"><?php echo esc_html( $title ); ?></h3>
			<div class="moksafowo-list">
				<?php
				foreach ( $cards as $card ) {
					self::card( $card );
				}
				?>
			</div>
		</section>
		<?php
	}

	public static function card( array $card ): void {
		$name         = (string) ( $card['name'] ?? '' );
		$tagline      = (string) ( $card['tagline'] ?? '' );
		$methods      = (array) ( $card['methods'] ?? [] );
		$enabled      = (bool) ( $card['enabled'] ?? false );
		$toggle_name  = (string) ( $card['toggle_name'] ?? '' );
		$settings_url = (string) ( $card['settings_url'] ?? '' );
		?>
		<div class="moksafowo-card<?php echo $enabled ? ' is-on' : ''; ?>">
			<div class="moksafowo-card__main">
				<div class="moksafowo-card__head">
					<span class="moksafowo-card__name"><?php echo esc_html( $name ); ?></span>
					<?php if ( $tagline !== '' ) : ?>
						<span class="moksafowo-card__tagline"><?php echo esc_html( $tagline ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( $methods !== [] ) : ?>
					<div class="moksafowo-card__methods">
						<?php foreach ( $methods as $method ) : ?>
							<span class="moksafowo-chip"><?php echo esc_html( (string) $method ); ?></span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<div class="moksafowo-card__action">
				<?php if ( $enabled && $settings_url !== '' ) : ?>
					<a class="moksafowo-link" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( '設定 →', 'mo-ectools' ); ?></a>
				<?php endif; ?>
				<?php if ( $toggle_name !== '' ) : ?>
					<label class="moksafowo-toggle">
						<input type="checkbox" name="<?php echo esc_attr( $toggle_name ); ?>" value="yes"<?php checked( $enabled ); ?> />
						<span class="moksafowo-toggle__slider"></span>
					</label>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public static function subsection_banner( string $name, bool $enabled, string $back_url ): void {
		self::print_styles();
		unset( $back_url );
		?>
		<div class="moksafowo-subsection-banner">
			<div class="moksafowo-subsection-banner__head">
				<h2 class="moksafowo-subsection-banner__title"><?php echo esc_html( $name ); ?></h2>
				<?php if ( $enabled ) : ?>
					<span class="moksafowo-subsection-banner__status is-on">✓ <?php esc_html_e( '已啟用', 'mo-ectools' ); ?></span>
				<?php else : ?>
					<span class="moksafowo-subsection-banner__status is-off">✗ <?php esc_html_e( '已停用', 'mo-ectools' ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
