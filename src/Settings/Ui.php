<?php

declare( strict_types=1 );

namespace MoksaWeb\Mowc\Settings;

defined( 'ABSPATH' ) || exit;

final class Ui {

	private static bool $css_printed = false;

	public static function print_styles(): void {
		if ( self::$css_printed ) {
			return;
		}
		self::$css_printed = true;
		?>
		<style>
			.mowp-shell { max-width: 980px; }

			.mowp-shell .mowp-intro {
				position: relative;
				margin: 0 0 20px;
				padding: 0 0 14px;
				background: transparent;
				border: 0;
			}
			.mowp-shell .mowp-intro h2 {
				position: relative;
				display: inline-block;
				margin: 0 0 6px;
				padding: 0;
				font-size: 20px;
				font-weight: 700;
				color: #0f172a;
				line-height: 1.3;
			}
			.mowp-shell .mowp-intro h2::after {
				content: "";
				position: absolute;
				left: 0; bottom: -8px;
				width: 100%;
				height: 2px;
				background: linear-gradient(90deg, #f97316 0%, rgba(249,115,22,0) 100%);
				border-radius: 1px;
			}
			.mowp-shell .mowp-intro p {
				margin: 14px 0 0;
				color: #64748b;
				font-size: 13.5px;
				line-height: 1.6;
				max-width: 720px;
			}

			.mowp-shell .mowp-category { margin-bottom: 28px; }
			.mowp-shell .mowp-category__title { margin: 0 0 12px; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: #50575e; }
			.mowp-shell .mowp-list { display: grid; gap: 10px; grid-template-columns: 1fr; }

			.mowp-shell .mowp-card { display: grid; grid-template-columns: 1fr auto; align-items: center; gap: 18px; padding: 16px 18px; background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; transition: border-color .15s ease, box-shadow .15s ease; }
			.mowp-shell .mowp-card.is-on { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1 inset; }
			.mowp-shell .mowp-card__head { display: flex; align-items: baseline; flex-wrap: wrap; gap: 10px; }
			.mowp-shell .mowp-card__name { font-size: 15px; font-weight: 600; color: #1d2327; }
			.mowp-shell .mowp-card__tagline { font-size: 12px; color: #646970; }
			.mowp-shell .mowp-card__methods { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 4px; }
			.mowp-shell .mowp-chip { display: inline-block; padding: 2px 8px; background: #f0f0f1; color: #1d2327; border-radius: 11px; font-size: 11px; line-height: 18px; }
			.mowp-shell .mowp-card__action { display: flex; align-items: center; gap: 14px; }
			.mowp-shell .mowp-link { color: #2271b1; text-decoration: none; font-size: 12px; white-space: nowrap; }
			.mowp-shell .mowp-link:hover { text-decoration: underline; }

			.mowp-shell .mowp-toggle { position: relative; display: inline-block; width: 40px; height: 22px; flex: 0 0 auto; }
			.mowp-shell .mowp-toggle input { opacity: 0; width: 0; height: 0; }
			.mowp-shell .mowp-toggle__slider { position: absolute; cursor: pointer; inset: 0; background: #c3c4c7; border-radius: 22px; transition: background .15s ease; }
			.mowp-shell .mowp-toggle__slider::before { content: ""; position: absolute; height: 16px; width: 16px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: transform .15s ease; box-shadow: 0 1px 2px rgba(0,0,0,.15); }
			.mowp-shell .mowp-toggle input:checked + .mowp-toggle__slider { background: #2271b1; }
			.mowp-shell .mowp-toggle input:checked + .mowp-toggle__slider::before { transform: translateX(18px); }
			.mowp-shell .mowp-toggle input:focus-visible + .mowp-toggle__slider { outline: 2px solid #2271b1; outline-offset: 2px; }

			/* subsection banner — 跟 .mowp-intro 同設計：純文字標題 + 橘色 accent line */
			.mowp-subsection-banner { position: relative; margin: 0 0 20px; padding: 0 0 14px; }
			.mowp-subsection-banner__head { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; }
			.mowp-subsection-banner__title { position: relative; display: inline-block; margin: 0; padding: 0; font-size: 20px; font-weight: 700; color: #0f172a; line-height: 1.3; }
			.mowp-subsection-banner__title::after { content: ""; position: absolute; left: 0; bottom: -8px; width: 100%; height: 2px; background: linear-gradient(90deg, #f97316 0%, rgba(249,115,22,0) 100%); border-radius: 1px; }
			.mowp-subsection-banner__status { display: inline-flex; align-items: center; gap: 4px; padding: 2px 10px; border-radius: 11px; font-size: 12px; line-height: 18px; font-weight: 500; }
			.mowp-subsection-banner__status.is-on { background: #d1fae5; color: #065f46; }
			.mowp-subsection-banner__status.is-off { background: #fee2e2; color: #991b1b; }

			.mowp-shell h2 { margin: 28px 0 4px; padding: 0; background: transparent; border: none; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: #50575e; }
			.mowp-shell h2:first-of-type { margin-top: 8px; }
			.mowp-shell h2 + p { margin: 0 0 12px; color: #646970; font-size: 13px; }
			.mowp-shell .form-table { margin: 0 0 18px; }
			.mowp-shell .form-table th { padding-left: 0; }
		</style>
		<?php
	}

	public static function open_shell(): void {
		self::print_styles();
		echo '<div class="mowp-shell">';
	}

	public static function close_shell(): void {
		echo '</div>';
	}

	public static function intro( string $title, string $description ): void {
		?>
		<div class="mowp-intro">
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
		<section class="mowp-category">
			<h3 class="mowp-category__title"><?php echo esc_html( $title ); ?></h3>
			<div class="mowp-list">
				<?php foreach ( $cards as $card ) {
					self::card( $card );
				} ?>
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
		<div class="mowp-card<?php echo $enabled ? ' is-on' : ''; ?>">
			<div class="mowp-card__main">
				<div class="mowp-card__head">
					<span class="mowp-card__name"><?php echo esc_html( $name ); ?></span>
					<?php if ( $tagline !== '' ) : ?>
						<span class="mowp-card__tagline"><?php echo esc_html( $tagline ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( $methods !== [] ) : ?>
					<div class="mowp-card__methods">
						<?php foreach ( $methods as $method ) : ?>
							<span class="mowp-chip"><?php echo esc_html( (string) $method ); ?></span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<div class="mowp-card__action">
				<?php if ( $enabled && $settings_url !== '' ) : ?>
					<a class="mowp-link" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( '設定 →', 'mo-ectools' ); ?></a>
				<?php endif; ?>
				<?php if ( $toggle_name !== '' ) : ?>
					<label class="mowp-toggle">
						<input type="checkbox" name="<?php echo esc_attr( $toggle_name ); ?>" value="yes"<?php checked( $enabled ); ?> />
						<span class="mowp-toggle__slider"></span>
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
		<div class="mowp-subsection-banner">
			<div class="mowp-subsection-banner__head">
				<h2 class="mowp-subsection-banner__title"><?php echo esc_html( $name ); ?></h2>
				<?php if ( $enabled ) : ?>
					<span class="mowp-subsection-banner__status is-on">✓ <?php esc_html_e( '已啟用', 'mo-ectools' ); ?></span>
				<?php else : ?>
					<span class="mowp-subsection-banner__status is-off">✗ <?php esc_html_e( '已停用', 'mo-ectools' ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
