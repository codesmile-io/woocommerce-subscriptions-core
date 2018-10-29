<?php
/**
 * Manage the process of deleting, adding, assigning default payment tokens associated with automatic subscriptions
 *
 * @package  WooCommerce Subscriptions
 * @category Class
 * @author   Prospress
 * @since    2.2.7
 */
class WCS_My_Account_Payment_Methods {

	/**
	 * Initialize filters and hooks for class.
	 *
	 * @since 2.2.7
	 */
	public static function init() {
		// Only hook class functions if the payment token object exists
		if ( ! class_exists( 'WC_Payment_Token' ) ) {
			return;
		}

		add_filter( 'woocommerce_payment_methods_list_item', array( __CLASS__, 'flag_subscription_payment_token_deletions' ), 10, 2 );
		add_action( 'woocommerce_payment_token_deleted',array( __CLASS__, 'maybe_update_subscriptions_payment_meta' ), 10, 2 );
		add_action( 'woocommerce_payment_token_set_default', array( __CLASS__, 'display_default_payment_token_change_notice' ), 10, 2 );
		add_action( 'wp', array( __CLASS__, 'update_subscription_tokens' ) );

	}

	/**
	 * Add additional query args to delete token URLs which are being used for subscription automatic payments.
	 *
	 * @param  array data about the token including a list of actions which can be triggered by the customer from their my account page
	 * @param  WC_Payment_Token payment token object
	 * @return array payment token data
	 * @since  2.2.7
	 */
	public static function flag_subscription_payment_token_deletions( $payment_token_data, $payment_token ) {

		if ( $payment_token instanceof WC_Payment_Token && isset( $payment_token_data['actions']['delete']['url'] ) ) {

			if ( 0 < count( WCS_Payment_Tokens::get_subscriptions_by_token( $payment_token ) ) ) {
				if ( WCS_Payment_Tokens::customer_has_alternative_token( $payment_token ) ) {
					$delete_subscription_token_args = array(
						'delete_subscription_token' => $payment_token->get_id(),
						'wcs_nonce'                 => wp_create_nonce( 'delete_subscription_token_' . $payment_token->get_id() ),
					);

					$payment_token_data['actions']['delete']['url'] = add_query_arg( $delete_subscription_token_args, $payment_token_data['actions']['delete']['url'] );
				} else {
					// Cannot delete a token used for active subscriptions where there is no alternative
					unset( $payment_token_data['actions']['delete'] );
				}
			}
		}

		return $payment_token_data;
	}

	/**
	 * Update subscriptions using a deleted token to use a new token. Subscriptions with the
	 * old token value stored in post meta will be updated using the same meta key to use the
	 * new token value.
	 *
	 * @param int $deleted_token_id The deleted token id.
	 * @param WC_Payment_Token $deleted_token The deleted token object.
	 * @since 2.2.7
	 */
	public static function maybe_update_subscriptions_payment_meta( $deleted_token_id, $deleted_token ) {
		if ( ! isset( $_GET['delete_subscription_token'] ) || empty( $_GET['wcs_nonce'] ) || ! wp_verify_nonce( $_GET['wcs_nonce'], 'delete_subscription_token_' . $_GET['delete_subscription_token'] ) ) {
			return;
		}

		// init payment gateways
		WC()->payment_gateways();

		$new_token = WCS_Payment_Tokens::get_customers_alternative_token( $deleted_token );

		if ( empty( $new_token ) ) {
			$notice = esc_html__( 'The deleted payment method was used for automatic subscription payments, we couldn\'t find an alternative token payment method token to change your subscriptions to.', 'woocommerce-subscriptions' );
			wc_add_notice( $notice, 'error' );
			return;
		}

		$subscriptions = WCS_Payment_Tokens::get_subscriptions_by_token( $deleted_token );

		if ( empty( $subscriptions ) ) {
			return;
		}

		foreach ( $subscriptions as $subscription ) {
			if ( empty( $subscription ) ) {
				continue;
			}

			if ( WCS_Payment_Tokens::update_subscription_token( $subscription, $new_token, $deleted_token ) ) {
				$subscription->add_order_note( sprintf( _x( 'Payment method meta updated after customer deleted a token from their My Account page. Payment meta changed from %1$s to %2$s', 'used in subscription note', 'woocommerce-subscriptions' ), $deleted_token->get_token(), $new_token->get_token() ) );
			}
		}

		// translators: $1: the token/credit card label, 2$-3$: opening and closing strong and link tags
		$notice = sprintf( esc_html__( 'The deleted payment method was used for automatic subscription payments. To avoid failed renewal payments in future the subscriptions using this payment method have been updated to use your %1$s. To change the payment method of individual subscriptions go to your %2$sMy Account > Subscriptions%3$s page.', 'woocommerce-subscriptions' ),
			self::get_token_label( $new_token ),
			'<a href="' . esc_url( wc_get_account_endpoint_url( get_option( 'woocommerce_myaccount_subscriptions_endpoint', 'subscriptions' ) ) ) . '"><strong>',
			'</strong></a>'
		);

		wc_add_notice( $notice , 'notice' );
	}

	/**
	 * Get a WC_Payment_Token label. eg Visa ending in 1234
	 *
	 * @param  WC_Payment_Token payment token object
	 * @return string WC_Payment_Token label
	 * @since  2.2.7
	 */
	public static function get_token_label( $token ) {

		if ( method_exists( $token, 'get_last4' ) && $token->get_last4() ) {
			$label = sprintf( __( '%s ending in %s', 'woocommerce-subscriptions' ), esc_html( wc_get_credit_card_type_label( $token->get_card_type() ) ), esc_html( $token->get_last4() ) );
		} else {
			$label = esc_html( wc_get_credit_card_type_label( $token->get_card_type() ) );
		}

		return $label;
	}

	/**
	 * Display a notice when a customer sets a new default token notifying them of what this means for their subscriptions.
	 *
	 * @param int $default_token_id The default token id.
	 * @param WC_Payment_Token $default_token The default token object.
	 * @since 2.3.3
	 */
	public static function display_default_payment_token_change_notice( $default_token_id, $default_token ) {
		$display_notice  = false;
		$customer_tokens = WCS_Payment_Tokens::get_customer_tokens( $default_token->get_gateway_id(), $default_token->get_user_id() );
		unset( $customer_tokens[ $default_token_id ] );

		// Check if there are subscriptions for one of the customer's other tokens.
		foreach ( $customer_tokens as $token ) {
			if ( count( WCS_Payment_Tokens::get_subscriptions_by_token( $token ) ) > 0 ) {
				$display_notice = true;
				break;
			}
		}

		if ( ! $display_notice ) {
			return;
		}

		$notice = sprintf( esc_html__( 'Would you like to update your subscriptions to use this new payment method - %1$s?%2$sYes%4$s | %3$sNo%4$s', 'woocommerce-subscriptions' ),
			self::get_token_label( $default_token ),
			'</br><a href="' . esc_url( add_query_arg( array(
				'update-subscription-tokens' => 'true',
				'token-id'                   => $default_token_id,
				'_wcsnonce'                  => wp_create_nonce( 'wcs-update-subscription-tokens' ),
			), wc_get_account_endpoint_url( 'payment-methods' ) ) ) . '"><strong>',
			'<a href=""><strong>',
			'</strong></a>'
		);

		wc_add_notice( $notice , 'notice' );
	}

	/**
	 * Update the customer's subscription tokens if they opted to from their My Account page.
	 *
	 * @since 2.3.3
	 */
	public static function update_subscription_tokens() {
		if ( ! isset( $_GET['update-subscription-tokens'], $_GET['token-id'],  $_GET['_wcsnonce'] ) || ! wp_verify_nonce( $_GET['_wcsnonce'], 'wcs-update-subscription-tokens' ) ) {
			return;
		}

		// init payment gateways
		WC()->payment_gateways();

		$default_token_id = $_GET['token-id'];
		$default_token    = WC_Payment_Tokens::get( $default_token_id );

		if ( ! $default_token ) {
			return;
		}

		$tokens = WCS_Payment_Tokens::get_customer_tokens( $default_token->get_gateway_id(), $default_token->get_user_id() );
		unset( $tokens[ $default_token_id ] );

		foreach ( $tokens as $old_token ) {
			foreach ( WCS_Payment_Tokens::get_subscriptions_by_token( $old_token ) as $subscription ) {
				if ( ! empty( $subscription ) && WCS_Payment_Tokens::update_subscription_token( $subscription, $default_token, $old_token ) ) {
					$subscription->add_order_note( sprintf( _x( 'Payment method meta updated after customer changed their default token and opted to update their subscriptions. Payment meta changed from %1$s to %2$s', 'used in subscription note', 'woocommerce-subscriptions' ), $old_token->get_token(), $default_token->get_token() ) );
				}
			}
		}

		wp_redirect( remove_query_arg( array( 'update-subscription-tokens', 'token-id', '_wcsnonce' ) ) );
		exit();
	}

	/**
	 * Get subscriptions by a WC_Payment_Token. All automatic subscriptions with the token's payment method,
	 * customer id and token value stored in post meta will be returned.
	 *
	 * @since  2.2.7
	 * @deprecated 2.5.0
	 */
	public static function get_subscriptions_by_token( $payment_token ) {
		_deprecated_function( __METHOD__, '2.5.0', 'WCS_Payment_Tokens::get_subscriptions_by_token()' );
		return WCS_Payment_Tokens::get_subscriptions_by_token( $payment_token );
	}

	/**
	 * Get a list of customer payment tokens. Caches results to avoid multiple database queries per request
	 *
	 * @since  2.2.7
	 * @deprecated 2.5.0
	 */
	public static function get_customer_tokens( $gateway_id = '', $customer_id = '' ) {
		_deprecated_function( __METHOD__, '2.5.0', 'WCS_Payment_Tokens::get_customer_tokens()' );
		return WCS_Payment_Tokens::get_customer_tokens( $gateway_id, $customer_id );
	}

	/**
	 * Get the customer's alternative token.
	 *
	 * @since  2.2.7
	 * @deprecated 2.5.0
	 */
	public static function get_customers_alternative_token( $token ) {
		_deprecated_function( __METHOD__, '2.5.0', 'WCS_Payment_Tokens::get_customers_alternative_token()' );
		return WCS_Payment_Tokens::get_customers_alternative_token( $token );
	}

	/**
	 * Determine if the customer has an alternative token.
	 *
	 * @since  2.2.7
	 * @deprecated 2.5.0
	 */
	public static function customer_has_alternative_token( $token ) {
		_deprecated_function( __METHOD__, '2.5.0', 'WCS_Payment_Tokens::customer_has_alternative_token()' );
		return WCS_Payment_Tokens::customer_has_alternative_token( $token );
	}
}
