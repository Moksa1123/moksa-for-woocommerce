<?php
declare( strict_types=1 );

namespace MoksaWeb\Mowc\Modules\Shared\Invoice;

use MoksaWeb\Mowc\Order\Meta\Keys;

defined( 'ABSPATH' ) || exit;

/**
 * 後台手動開立發票的可編輯表單 — 5 個發票模組共用。
 *
 * 選項受該 provider 的發票設定限制（允許捐贈 / 允許統編 / 預設載具 / 預設捐贈碼），
 * 預填顧客結帳帶來的 meta，按「開立發票」後 save() 寫回 meta，再交給各自的 Issue 開立。
 * $option_prefix 例 'moksafowo_ecpay_invoice' / 'moksafowo_ezpay_invoice'。
 */
final class AdminIssueForm {

	/** 渲染可編輯欄位（含 .moksafowo-inv-* class，由 admin metabox JS 做條件顯示 / 收集）。 */
	public static function render( \WC_Order $order, string $option_prefix ): void {
		$def_carrier = InvoiceChannels::default_carrier( $option_prefix );

		$cur_type    = (string) $order->get_meta( Keys::INVOICE_TYPE );
		$cur_carrier = (string) $order->get_meta( Keys::INVOICE_CARRIER_TYPE );
		$cur_cnum    = (string) $order->get_meta( Keys::INVOICE_CARRIER_NUM );
		$cur_ubn     = (string) $order->get_meta( Keys::INVOICE_BUYER_UBN );
		$cur_name    = (string) $order->get_meta( Keys::INVOICE_BUYER_NAME );
		$cur_donate  = (string) $order->get_meta( Keys::INVOICE_LOVE_CODE );

		$cur_type    = '' !== $cur_type ? $cur_type : 'b2c_carrier';
		$cur_carrier = '' !== $cur_carrier ? $cur_carrier : $def_carrier;

		// 發票類型選項 — 受該 provider「允許捐贈 / 允許統編」設定連動
		$type_labels = [
			'b2c_carrier' => __( '個人（載具）', 'mo-ectools' ),
			'b2b'         => __( '公司（統一編號）', 'mo-ectools' ),
			'b2c_donate'  => __( '捐贈', 'mo-ectools' ),
		];
		$type_opts   = [];
		foreach ( InvoiceChannels::enabled_types( $option_prefix ) as $t ) {
			$type_opts[ $t ] = $type_labels[ $t ];
		}
		// 帶過來的類型已被設定關閉 → fallback 個人
		if ( ! isset( $type_opts[ $cur_type ] ) ) {
			$cur_type = 'b2c_carrier';
		}

		// 載具選項 — 受該 provider 能力 + 逐項開關連動（PayNow 無會員載具等）
		$carrier_labels = [
			'member' => __( '會員載具', 'mo-ectools' ),
			'mobile' => __( '手機條碼', 'mo-ectools' ),
			'cert'   => __( '自然人憑證', 'mo-ectools' ),
			'paper'  => __( '紙本', 'mo-ectools' ),
		];
		$carrier_opts   = [];
		foreach ( InvoiceChannels::enabled_carriers( $option_prefix ) as $c ) {
			$carrier_opts[ $c ] = $carrier_labels[ $c ];
		}
		// 帶過來的載具已被停用 → 退回預設
		if ( ! isset( $carrier_opts[ $cur_carrier ] ) ) {
			$cur_carrier = $def_carrier;
		}

		// 伺服器端先算好各列初始顯示，避免重整時欄位先全部閃現再被 JS 隱藏（FOUC）。
		$hide_carrier = ( 'b2c_carrier' === $cur_type ) ? '' : 'display:none;';
		$hide_cnum    = ( 'b2c_carrier' === $cur_type && in_array( $cur_carrier, [ 'mobile', 'cert' ], true ) ) ? '' : 'display:none;';
		$hide_b2b     = ( 'b2b' === $cur_type ) ? '' : 'display:none;';
		$hide_donate  = ( 'b2c_donate' === $cur_type ) ? '' : 'display:none;';
		// 載具編號 label 也先依目前載具算好（手機條碼 / 自然人憑證），避免重整時 label 閃動。
		$cnum_label = __( '載具編號', 'mo-ectools' );
		if ( 'b2c_carrier' === $cur_type && 'mobile' === $cur_carrier ) {
			$cnum_label = __( '手機條碼（/ 開頭 + 7 碼，限 0-9 A-Z . + -）', 'mo-ectools' );
		} elseif ( 'b2c_carrier' === $cur_type && 'cert' === $cur_carrier ) {
			$cnum_label = __( '自然人憑證（2 大寫字母 + 14 碼數字）', 'mo-ectools' );
		}

		// data-saved-* = 目前訂單 meta 原值，admin JS 用來比對「表單是否有未存的修改」決定可否開立。
		echo '<div class="moksafowo-inv-issue-form"'
			. ' data-saved-type="' . esc_attr( (string) $order->get_meta( Keys::INVOICE_TYPE ) ) . '"'
			. ' data-saved-carrier="' . esc_attr( (string) $order->get_meta( Keys::INVOICE_CARRIER_TYPE ) ) . '"'
			. ' data-saved-cnum="' . esc_attr( $cur_cnum ) . '"'
			. ' data-saved-ubn="' . esc_attr( $cur_ubn ) . '"'
			. ' data-saved-name="' . esc_attr( $cur_name ) . '"'
			. ' data-saved-donate="' . esc_attr( $cur_donate ) . '">';

		echo '<p class="moksafowo-inv-row" style="margin:.2em 0;"><label><strong>' . esc_html__( '發票類型', 'mo-ectools' ) . '</strong></label>';
		echo '<select class="moksafowo-inv-type" style="display:block;width:100%;">';
		foreach ( $type_opts as $v => $label ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $cur_type, $v, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';

		echo '<p class="moksafowo-inv-row moksafowo-inv-carrier" style="margin:.4em 0;' . esc_attr( $hide_carrier ) . '"><label>' . esc_html__( '載具類型', 'mo-ectools' ) . '</label>';
		echo '<select class="moksafowo-inv-carrier-type" style="display:block;width:100%;">';
		foreach ( $carrier_opts as $v => $label ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $cur_carrier, $v, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';

		echo '<p class="moksafowo-inv-row moksafowo-inv-cnum" style="margin:.4em 0;' . esc_attr( $hide_cnum ) . '"><label class="moksafowo-inv-cnum-label">' . esc_html( $cnum_label ) . '</label>';
		echo '<input type="text" class="moksafowo-inv-carrier-num" style="display:block;width:100%;" value="' . esc_attr( $cur_cnum ) . '"></p>';

		echo '<p class="moksafowo-inv-row moksafowo-inv-ubn" style="margin:.4em 0;' . esc_attr( $hide_b2b ) . '"><label>' . esc_html__( '統一編號', 'mo-ectools' ) . '</label>';
		echo '<input type="text" class="moksafowo-inv-buyer-ubn" style="display:block;width:100%;" maxlength="8" value="' . esc_attr( $cur_ubn ) . '"></p>';

		echo '<p class="moksafowo-inv-row moksafowo-inv-name" style="margin:.4em 0;' . esc_attr( $hide_b2b ) . '"><label>' . esc_html__( '公司抬頭', 'mo-ectools' ) . '</label>';
		echo '<input type="text" class="moksafowo-inv-buyer-name" style="display:block;width:100%;" value="' . esc_attr( $cur_name ) . '"></p>';

		echo '<div class="moksafowo-inv-row moksafowo-inv-donate" style="margin:.4em 0;' . esc_attr( $hide_donate ) . '">';
		if ( InvoiceChannels::has_donate_orgs( $option_prefix ) ) {
			// 有設定捐贈單位 → 捐贈單位下拉（名稱）+ 捐贈碼唯讀（選了單位自動帶入，不開放自填）。
			echo '<label style="display:block;">' . esc_html__( '捐贈單位', 'mo-ectools' ) . '</label>';
			echo '<select class="moksafowo-inv-donate-org" style="display:block;width:100%;margin-bottom:.4em;">';
			foreach ( InvoiceChannels::donate_select_options( $option_prefix ) as $v => $label ) {
				echo '<option value="' . esc_attr( $v ) . '"' . selected( $cur_donate, $v, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
			echo '<label style="display:block;">' . esc_html__( '捐贈碼', 'mo-ectools' ) . '</label>';
			echo '<input type="text" class="moksafowo-inv-love-code" readonly style="display:block;width:100%;background:#f0f0f1;" value="' . esc_attr( $cur_donate ) . '">';
		} else {
			// 沒設定捐贈單位 → 捐贈碼開放自填。
			echo '<label style="display:block;">' . esc_html__( '捐贈碼', 'mo-ectools' ) . '</label>';
			echo '<input type="text" class="moksafowo-inv-love-code" style="display:block;width:100%;" value="' . esc_attr( $cur_donate ) . '" placeholder="' . esc_attr__( '3-7 碼愛心碼', 'mo-ectools' ) . '">';
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * 後台手動開立欄位的格式 / 檢查碼驗證（更新 / 開立前都跑），回傳錯誤訊息或 null。
	 * 用既有 Ubn::is_valid 與結帳同一套 regex，避免在 JS 重寫檢查碼演算法。
	 * 呼叫端須已 check_ajax_referer。
	 */
	public static function validate(): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce 已由呼叫端 ajax_save / ajax_issue 的 check_ajax_referer 驗證。
		if ( ! isset( $_POST['inv_type'] ) ) {
			return null;
		}
		$type = sanitize_text_field( wp_unslash( $_POST['inv_type'] ) );
		if ( 'b2b' === $type ) {
			$ubn = isset( $_POST['inv_ubn'] ) ? sanitize_text_field( wp_unslash( $_POST['inv_ubn'] ) ) : '';
			if ( ! Ubn::is_valid( $ubn ) ) {
				return __( '統一編號格式或檢查碼不正確（需 8 碼數字且檢查碼正確）。', 'mo-ectools' );
			}
		} elseif ( 'b2c_donate' === $type ) {
			$code = isset( $_POST['inv_donate'] ) ? sanitize_text_field( wp_unslash( $_POST['inv_donate'] ) ) : '';
			if ( ! preg_match( '/^([xX]\d{2,6}|\d{3,7})$/', $code ) ) {
				return __( '愛心碼格式錯誤（3-7 碼數字）。', 'mo-ectools' );
			}
		} elseif ( 'b2c_carrier' === $type ) {
			$carrier = isset( $_POST['inv_carrier'] ) ? sanitize_text_field( wp_unslash( $_POST['inv_carrier'] ) ) : '';
			$cnum    = isset( $_POST['inv_carrier_num'] ) ? sanitize_text_field( wp_unslash( $_POST['inv_carrier_num'] ) ) : '';
			if ( 'mobile' === $carrier && ! preg_match( '#^/[0-9A-Z+\-.]{7}$#', $cnum ) ) {
				return __( '手機條碼格式錯誤（/ 開頭 + 7 碼，限 0-9 A-Z . + -）。', 'mo-ectools' );
			}
			if ( 'cert' === $carrier && ! preg_match( '/^[A-Z]{2}\d{14}$/', $cnum ) ) {
				return __( '自然人憑證格式錯誤（2 大寫字母 + 14 碼數字）。', 'mo-ectools' );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return null;
	}

	/**
	 * 把後台手動表單挑的欄位寫回訂單 meta（受設定限制）。
	 * 呼叫端（ajax_issue）須已 check_ajax_referer + 權限驗證。
	 */
	public static function save( \WC_Order $order, string $option_prefix ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce 已由呼叫端 ajax_issue 的 check_ajax_referer 驗證。
		if ( ! isset( $_POST['inv_type'] ) ) {
			return; // 舊版按鈕沒帶欄位 → 沿用結帳既有 meta，不動
		}
		$enabled_types    = InvoiceChannels::enabled_types( $option_prefix );
		$enabled_carriers = InvoiceChannels::enabled_carriers( $option_prefix );

		$type = sanitize_text_field( wp_unslash( $_POST['inv_type'] ) );
		if ( ! in_array( $type, $enabled_types, true ) ) {
			$type = 'b2c_carrier'; // 被停用 / 非法 → 退回個人
		}

		$carrier = isset( $_POST['inv_carrier'] ) ? sanitize_text_field( wp_unslash( $_POST['inv_carrier'] ) ) : '';
		$cnum    = isset( $_POST['inv_carrier_num'] ) ? sanitize_text_field( wp_unslash( $_POST['inv_carrier_num'] ) ) : '';
		$ubn     = isset( $_POST['inv_ubn'] ) ? sanitize_text_field( wp_unslash( $_POST['inv_ubn'] ) ) : '';
		$name    = isset( $_POST['inv_name'] ) ? sanitize_text_field( wp_unslash( $_POST['inv_name'] ) ) : '';
		$donate  = isset( $_POST['inv_donate'] ) ? sanitize_text_field( wp_unslash( $_POST['inv_donate'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$order->update_meta_data( Keys::INVOICE_TYPE, $type );

		if ( 'b2b' === $type ) {
			$order->update_meta_data( Keys::INVOICE_BUYER_UBN, $ubn );
			$order->update_meta_data( Keys::INVOICE_BUYER_NAME, $name );
			$order->update_meta_data( Keys::INVOICE_CARRIER_TYPE, '' );
			$order->update_meta_data( Keys::INVOICE_CARRIER_NUM, '' );
			$order->update_meta_data( Keys::INVOICE_LOVE_CODE, '' );
		} elseif ( 'b2c_donate' === $type ) {
			$order->update_meta_data( Keys::INVOICE_LOVE_CODE, $donate );
			$order->update_meta_data( Keys::INVOICE_CARRIER_TYPE, '' );
			$order->update_meta_data( Keys::INVOICE_CARRIER_NUM, '' );
			$order->update_meta_data( Keys::INVOICE_BUYER_UBN, '' );
			$order->update_meta_data( Keys::INVOICE_BUYER_NAME, '' );
		} else { // b2c_carrier
			$carrier = in_array( $carrier, $enabled_carriers, true ) ? $carrier : InvoiceChannels::default_carrier( $option_prefix );
			$order->update_meta_data( Keys::INVOICE_CARRIER_TYPE, $carrier );
			$order->update_meta_data( Keys::INVOICE_CARRIER_NUM, ( 'mobile' === $carrier || 'cert' === $carrier ) ? $cnum : '' );
			$order->update_meta_data( Keys::INVOICE_BUYER_UBN, '' );
			$order->update_meta_data( Keys::INVOICE_BUYER_NAME, '' );
			$order->update_meta_data( Keys::INVOICE_LOVE_CODE, '' );
		}
		$order->save();
	}
}
