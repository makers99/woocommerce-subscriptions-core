<?php
/**
 * Subscriptions Coupon Class
 *
 * Mirrors a few functions in the WC_Cart class to handle subscription-specific discounts
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Coupon
 * @category	Class
 * @author		Max Rice
 * @since		1.2
 */
class WC_Subscriptions_Coupon {

	/**
	 * The meta key used for the number of renewals.
	 *
	 * @var string
	 */
	protected static $coupons_renewals = '_wcs_number_payments';

	/** @var string error message for invalid subscription coupons */
	public static $coupon_error;

	/**
	 * Stores the coupons not applied to a given calculation (so they can be applied later)
	 *
	 * @since 1.3.5
	 */
	private static $removed_coupons = array();

	/**
	 * Set up the class, including it's hooks & filters, when the file is loaded.
	 *
	 * @since 1.2
	 **/
	public static function init() {

		// Add custom coupon types
		add_filter( 'woocommerce_coupon_discount_types', __CLASS__ . '::add_discount_types' );

		// Handle discounts
		add_filter( 'woocommerce_coupon_get_discount_amount', __CLASS__ . '::get_discount_amount', 10, 5 );

		// Validate subscription coupons
		add_filter( 'woocommerce_coupon_is_valid', __CLASS__ . '::validate_subscription_coupon', 10, 3 );

		// Remove coupons which don't apply to certain cart calculations
		add_action( 'woocommerce_before_calculate_totals', __CLASS__ . '::remove_coupons', 10 );

		// Add our recurring product coupon types to the list of coupon types that apply to individual products
		add_filter( 'woocommerce_product_coupon_types', __CLASS__ . '::filter_product_coupon_types', 10, 1 );

		if ( ! is_admin() ) {
			// WC 3.0 only sets a coupon type if it is a pre-defined supported type, so we need to temporarily add our pseudo types. We don't want to add these on admin pages.
			add_filter( 'woocommerce_coupon_discount_types', __CLASS__ . '::add_pseudo_coupon_types' );
		}

		add_filter( 'woocommerce_cart_totals_coupon_label', __CLASS__ . '::get_pseudo_coupon_label', 10, 2 );

		// Hook recurring coupon functionality.
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_add_recurring_coupon_hooks' ) );
	}

	/**
	 * Maybe add Recurring Coupon functionality.
	 *
	 * WC 3.2 added many API enhancements, especially around coupons. It would be very challenging to implement
	 * this functionality in older versions of WC, so we require 3.2+ to enable this.
	 *
	 * @author Jeremy Pry
	 */
	public static function maybe_add_recurring_coupon_hooks() {
		if ( WC_Subscriptions::is_woocommerce_pre( '3.2' ) ) {
			return;
		}

		// Add custom coupon fields.
		add_action( 'woocommerce_coupon_options', array( __CLASS__, 'add_coupon_fields' ), 10 );
		add_action( 'woocommerce_coupon_options_save', array( __CLASS__, 'save_coupon_fields' ), 10 );

		// Filter the available payment gateways.
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'gateways_subscription_amount_changes' ), 20 );

		// Check coupons when a subscription is renewed.
		add_action( 'woocommerce_subscription_payment_complete', array( __CLASS__, 'check_coupon_usages' ) );

		// Add info to the Coupons list table.
		add_action( 'manage_shop_coupon_posts_custom_column', array( __CLASS__, 'add_limit_to_list_table' ), 20, 2 );
	}

	/**
	 * Add discount types
	 *
	 * @since 1.2
	 */
	public static function add_discount_types( $discount_types ) {

		return array_merge(
			$discount_types,
			array(
				'sign_up_fee'         => __( 'Sign Up Fee Discount', 'woocommerce-subscriptions' ),
				'sign_up_fee_percent' => __( 'Sign Up Fee % Discount', 'woocommerce-subscriptions' ),
				'recurring_fee'       => __( 'Recurring Product Discount', 'woocommerce-subscriptions' ),
				'recurring_percent'   => __( 'Recurring Product % Discount', 'woocommerce-subscriptions' ),
			)
		);
	}

	/**
	 * Get the discount amount for Subscriptions coupon types
	 *
	 * @since 2.0.10
	 */
	public static function get_discount_amount( $discount, $discounting_amount, $item, $single, $coupon ) {

		if ( is_a( $item, 'WC_Order_Item' ) ) { // WC 3.2 support for applying coupons to line items via admin edit subscription|order screen
			$discount = self::get_discount_amount_for_line_item( $item, $discount, $discounting_amount, $single, $coupon );
		} else {
			$discount = self::get_discount_amount_for_cart_item( $item, $discount, $discounting_amount, $single, $coupon );
		}

		return $discount;
	}

	/**
	 * Get the discount amount which applies for a cart item for subscription coupon types
	 *
	 * @since 2.2.13
	 * @param array $cart_item
	 * @param float $discount the original discount amount
	 * @param float $discounting_amount the cart item price/total which the coupon should apply to
	 * @param boolean $single True if discounting a single qty item, false if it's the line
	 * @param WC_Coupon $coupon
	 * @return float the discount amount which applies to the cart item
	 */
	public static function get_discount_amount_for_cart_item( $cart_item, $discount, $discounting_amount, $single, $coupon ) {

		$coupon_type = wcs_get_coupon_property( $coupon, 'discount_type' );

		// Only deal with subscriptions coupon types which apply to cart items
		if ( ! in_array( $coupon_type, array( 'recurring_fee', 'recurring_percent', 'sign_up_fee', 'sign_up_fee_percent', 'renewal_fee', 'renewal_percent', 'renewal_cart' ) ) ) {
			return $discount;
		}

		// If not a subscription product return the default discount
		if ( ! wcs_cart_contains_renewal() && ! WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
			return $discount;
		}
		// But if cart contains a renewal, we need to handle both subscription products and manually added non-susbscription products that could be part of a subscription
		if ( wcs_cart_contains_renewal() && ! self::is_subsbcription_renewal_line_item( $cart_item['data'], $cart_item ) ) {
			return $discount;
		}

		// Set our starting discount amount to 0
		$discount_amount = 0;

		// Item quantity
		$cart_item_qty = is_null( $cart_item ) ? 1 : $cart_item['quantity'];

		// Get calculation type
		$calculation_type = WC_Subscriptions_Cart::get_calculation_type();

		// Set the defaults for our logic checks to false
		$apply_recurring_coupon = $apply_recurring_percent_coupon = $apply_initial_coupon = $apply_initial_percent_coupon = $apply_renewal_cart_coupon = false;

		// Check if we're applying any recurring discounts to recurring total calculations
		if ( 'recurring_total' == $calculation_type ) {
			$apply_recurring_coupon         = ( 'recurring_fee' == $coupon_type ) ? true : false;
			$apply_recurring_percent_coupon = ( 'recurring_percent' == $coupon_type ) ? true : false;
		}

		// Check if we're applying any initial discounts
		if ( 'none' == $calculation_type ) {

			// If all items have a free trial we don't need to apply recurring coupons to the initial total
			if ( ! WC_Subscriptions_Cart::all_cart_items_have_free_trial() ) {

				if ( 'recurring_fee' == $coupon_type ) {
					$apply_initial_coupon = true;
				}

				if ( 'recurring_percent' == $coupon_type ) {
					$apply_initial_percent_coupon = true;
				}
			}

			// Apply sign-up discounts
			if ( WC_Subscriptions_Product::get_sign_up_fee( $cart_item['data'] ) > 0 ) {

				if ( 'sign_up_fee' == $coupon_type ) {
					$apply_initial_coupon = true;
				}

				if ( 'sign_up_fee_percent' == $coupon_type ) {
					$apply_initial_percent_coupon = true;
				}

				// Only Sign up fee coupons apply to sign up fees, adjust the discounting_amount accordingly
				if ( in_array( $coupon_type, array( 'sign_up_fee', 'sign_up_fee_percent' ) ) ) {
					$discounting_amount = WC_Subscriptions_Product::get_sign_up_fee( $cart_item['data'] );
				} else {
					$discounting_amount -= WC_Subscriptions_Product::get_sign_up_fee( $cart_item['data'] );
				}
			}

			// Apply renewal discounts
			if ( 'renewal_fee' == $coupon_type ) {
				$apply_recurring_coupon = true;
			}
			if ( 'renewal_percent' == $coupon_type ) {
				$apply_recurring_percent_coupon = true;
			}
			if ( 'renewal_cart' == $coupon_type ) {
				$apply_renewal_cart_coupon = true;
			}
		}

		// Calculate our discount
		if ( $apply_recurring_coupon || $apply_initial_coupon ) {

			// Recurring coupons only apply when there is no free trial (carts can have a mix of free trial and non free trial items)
			if ( $apply_initial_coupon && 'recurring_fee' == $coupon_type && WC_Subscriptions_Product::get_trial_length( $cart_item['data'] ) > 0 ) {
				$discounting_amount = 0;
			}

			$discount_amount = min( wcs_get_coupon_property( $coupon, 'coupon_amount' ), $discounting_amount );
			$discount_amount = $single ? $discount_amount : $discount_amount * $cart_item_qty;

		} elseif ( $apply_recurring_percent_coupon ) {

			$discount_amount = ( $discounting_amount / 100 ) * wcs_get_coupon_property( $coupon, 'coupon_amount' );

		} elseif ( $apply_initial_percent_coupon ) {

			// Recurring coupons only apply when there is no free trial (carts can have a mix of free trial and non free trial items)
			if ( 'recurring_percent' == $coupon_type && WC_Subscriptions_Product::get_trial_length( $cart_item['data'] ) > 0 ) {
				$discounting_amount = 0;
			}

			$discount_amount = ( $discounting_amount / 100 ) * wcs_get_coupon_property( $coupon, 'coupon_amount' );

		} elseif ( $apply_renewal_cart_coupon ) {

			/**
			 * See WC Core fixed_cart coupons - we need to divide the discount between rows based on their price in proportion to the subtotal.
			 * This is so rows with different tax rates get a fair discount, and so rows with no price (free) don't get discounted.
			 *
			 * BUT... we also need the subtotal to exclude non renewal products, so user the renewal subtotal
			 */
			$discount_percent = ( $discounting_amount * $cart_item['quantity'] ) / self::get_renewal_subtotal( wcs_get_coupon_property( $coupon, 'code' ) );

			$discount_amount = ( wcs_get_coupon_property( $coupon, 'coupon_amount' ) * $discount_percent ) / $cart_item_qty;
		}

		// Round - consistent with WC approach
		$discount_amount = round( $discount_amount, wcs_get_rounding_precision() );

		return $discount_amount;
	}

	/**
	 * Get the discount amount which applies for a line item for subscription coupon types
	 *
	 * Uses methods and data structures introduced in WC 3.0.
	 *
	 * @since 2.2.13
	 * @param WC_Order_Item $line_item
	 * @param float $discount the original discount amount
	 * @param float $discounting_amount the line item price/total
	 * @param boolean $single True if discounting a single qty item, false if it's the line
	 * @param WC_Coupon $coupon
	 * @return float the discount amount which applies to the line item
	 */
	public static function get_discount_amount_for_line_item( $line_item, $discount, $discounting_amount, $single, $coupon ) {

		if ( ! is_callable( array( $line_item, 'get_order' ) ) ) {
			return $discount;
		}

		$coupon_type = wcs_get_coupon_property( $coupon, 'discount_type' );
		$order       = $line_item->get_order();
		$product     = $line_item->get_product();

		// Recurring coupons can be applied to subscriptions, any renewal line item or subscription products in other order types
		if ( in_array( $coupon_type, array( 'recurring_fee', 'recurring_percent' ) ) && ( wcs_is_subscription( $order ) || wcs_order_contains_renewal( $order ) || WC_Subscriptions_Product::is_subscription( $product ) ) ) {
			if ( 'recurring_fee' === $coupon_type ) {
				$discount = min( $coupon->get_amount(), $discounting_amount );
				$discount = $single ? $discount : $discount * $line_item->get_quantity();
			} else { // recurring_percent
				$discount = (float) $coupon->get_amount() * ( $discounting_amount / 100 );
			}
		// Sign-up fee coupons apply to parent order line items which are subscription products and have a signup fee
		} elseif ( in_array( $coupon_type, array( 'sign_up_fee', 'sign_up_fee_percent' ) ) && WC_Subscriptions_Product::is_subscription( $product ) && wcs_order_contains_subscription( $order, 'parent' ) && 0 !== WC_Subscriptions_Product::get_sign_up_fee( $product ) ) {
			if ( 'sign_up_fee' === $coupon_type ) {
				$discount = min( $coupon->get_amount(), WC_Subscriptions_Product::get_sign_up_fee( $product ) );
				$discount = $single ? $discount : $discount * $line_item->get_quantity();
			} else { // sign_up_fee_percent
				$discount = (float) $coupon->get_amount() * ( WC_Subscriptions_Product::get_sign_up_fee( $product ) / 100 );
			}
		}

		return $discount;
	}

	/**
	 * Determine if the cart contains a discount code of a given coupon type.
	 *
	 * Used internally for checking if a WooCommerce discount coupon ('core') has been applied, or for if a specific
	 * subscription coupon type, like 'recurring_fee' or 'sign_up_fee', has been applied.
	 *
	 * @param string $coupon_type Any available coupon type or a special keyword referring to a class of coupons. Can be:
	 *  - 'any' to check for any type of discount
	 *  - 'core' for any core WooCommerce coupon
	 *  - 'recurring_fee' for the recurring amount subscription coupon
	 *  - 'sign_up_fee' for the sign-up fee subscription coupon
	 *
	 * @since 1.3.5
	 */
	public static function cart_contains_discount( $coupon_type = 'any' ) {

		$contains_discount = false;
		$core_coupons = array( 'fixed_product', 'percent_product', 'fixed_cart', 'percent' );

		if ( WC()->cart->applied_coupons ) {

			foreach ( WC()->cart->applied_coupons as $code ) {

				$coupon           = new WC_Coupon( $code );
				$cart_coupon_type = wcs_get_coupon_property( $coupon, 'discount_type' );

				if ( 'any' == $coupon_type || $coupon_type == $cart_coupon_type || ( 'core' == $coupon_type && in_array( $cart_coupon_type, $core_coupons ) ) ) {
					$contains_discount = true;
					break;
				}
			}
		}

		return $contains_discount;
	}

	/**
	 * Check if a subscription coupon is valid before applying
	 *
	 * @param boolean $valid
	 * @param WC_Coupon $coupon
	 * @param WC_Discounts $discount Added in WC 3.2 the WC_Discounts object contains information about the coupon being applied to either carts or orders - Optional
	 * @return boolean Whether the coupon is valid or not
	 * @since 1.2
	 */
	public static function validate_subscription_coupon( $valid, $coupon, $discount = null ) {

		if ( ! apply_filters( 'woocommerce_subscriptions_validate_coupon_type', true, $coupon, $valid ) ) {
			return $valid;
		}

		if ( is_a( $discount, 'WC_Discounts' ) ) { // WC 3.2+
			$discount_items = $discount->get_items();

			if ( is_array( $discount_items ) && ! empty( $discount_items ) ) {
				$item = reset( $discount_items );

				if ( isset( $item->object ) && is_a( $item->object, 'WC_Order_Item' ) ) {
					$valid = self::validate_subscription_coupon_for_order( $valid, $coupon, $item->object->get_order() );
				} else {
					$valid = self::validate_subscription_coupon_for_cart( $valid, $coupon );
				}
			}
		} else {
			$valid = self::validate_subscription_coupon_for_cart( $valid, $coupon );
		}

		return $valid;
	}

	/**
	 * Check if a subscription coupon is valid for the cart.
	 *
	 * @since 2.2.13
	 * @param boolean $valid
	 * @param WC_Coupon $coupon
	 * @return bool whether the coupon is valid
	 */
	public static function validate_subscription_coupon_for_cart( $valid, $coupon ) {
		self::$coupon_error = '';
		$coupon_type        = wcs_get_coupon_property( $coupon, 'discount_type' );

		// ignore non-subscription coupons
		if ( ! in_array( $coupon_type, array( 'recurring_fee', 'sign_up_fee', 'recurring_percent', 'sign_up_fee_percent', 'renewal_fee', 'renewal_percent', 'renewal_cart' ) ) ) {

			// but make sure there is actually something for the coupon to be applied to (i.e. not a free trial)
			if ( ( wcs_cart_contains_renewal() || WC_Subscriptions_Cart::cart_contains_subscription() ) && 0 == WC()->cart->subtotal ) {
				self::$coupon_error = __( 'Sorry, this coupon is only valid for an initial payment and the cart does not require an initial payment.', 'woocommerce-subscriptions' );
			}
		} else {

			// prevent subscription coupons from being applied to renewal payments
			if ( wcs_cart_contains_renewal() && ! in_array( $coupon_type, array( 'renewal_fee', 'renewal_percent', 'renewal_cart' ) ) ) {
				self::$coupon_error = __( 'Sorry, this coupon is only valid for new subscriptions.', 'woocommerce-subscriptions' );
			}

			// prevent subscription coupons from being applied to non-subscription products
			if ( ! wcs_cart_contains_renewal() && ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
				self::$coupon_error = __( 'Sorry, this coupon is only valid for subscription products.', 'woocommerce-subscriptions' );
			}

			// prevent subscription renewal coupons from being applied to non renewal payments
			if ( ! wcs_cart_contains_renewal() && in_array( $coupon_type, array( 'renewal_fee', 'renewal_percent', 'renewal_cart' ) ) ) {
				// translators: 1$: coupon code that is being removed
				self::$coupon_error = sprintf( __( 'Sorry, the "%1$s" coupon is only valid for renewals.', 'woocommerce-subscriptions' ), wcs_get_coupon_property( $coupon, 'code' ) );
			}

			// prevent sign up fee coupons from being applied to subscriptions without a sign up fee
			if ( 0 == WC_Subscriptions_Cart::get_cart_subscription_sign_up_fee() && in_array( $coupon_type, array( 'sign_up_fee', 'sign_up_fee_percent' ) ) ) {
				self::$coupon_error = __( 'Sorry, this coupon is only valid for subscription products with a sign-up fee.', 'woocommerce-subscriptions' );
			}
		}

		if ( ! empty( self::$coupon_error ) ) {
			$valid = false;
			add_filter( 'woocommerce_coupon_error', __CLASS__ . '::add_coupon_error', 10 );
		}

		return $valid;
	}

	/**
	 * Check if a subscription coupon is valid for an order/subscription.
	 *
	 * @since 2.2.13
	 * @param WC_Coupon $coupon The subscription coupon being validated. Can accept recurring_fee, recurring_percent, sign_up_fee or sign_up_fee_percent coupon types.
	 * @param WC_Order|WC_Subscription $order The order or subscription object to which the coupon is being applied
	 * @return bool whether the coupon is valid
	 */
	public static function validate_subscription_coupon_for_order( $valid, $coupon, $order ) {
		$coupon_type   = wcs_get_coupon_property( $coupon, 'discount_type' );
		$error_message = '';

		// Recurring coupons can be applied to subscriptions and renewal orders
		if ( in_array( $coupon_type, array( 'recurring_fee', 'recurring_percent' ) ) && ! ( wcs_is_subscription( $order ) || wcs_order_contains_subscription( $order, 'any' ) ) ) {
			$error_message = __( 'Sorry, recurring coupons can only be applied to subscriptions or subscription orders.', 'woocommerce-subscriptions' );
		// Sign-up fee coupons can be applied to parent orders which contain subscription products with at least one sign up fee
		} elseif ( in_array( $coupon_type, array( 'sign_up_fee', 'sign_up_fee_percent' ) ) && ! ( wcs_order_contains_subscription( $order, 'parent' ) || 0 !== WC_Subscriptions_Order::get_sign_up_fee( $order ) ) ) {
			// translators: placeholder is coupon code
			$error_message = sprintf( __( 'Sorry, "%s" can only be applied to subscription parent orders which contain a product with signup fees.', 'woocommerce-subscriptions' ), wcs_get_coupon_property( $coupon, 'code' ) );
		// Only recurring coupons can be applied to subscriptions
		} elseif ( ! in_array( $coupon_type, array( 'recurring_fee', 'recurring_percent' ) ) && wcs_is_subscription( $order ) ) {
			$error_message = __( 'Sorry, only recurring coupons can only be applied to subscriptions.', 'woocommerce-subscriptions' );
		}

		if ( ! empty( $error_message ) ) {
			throw new Exception( $error_message );
		}

		return $valid;
	}

	/**
	 * Returns a subscription coupon-specific error if validation failed
	 *
	 * @since 1.2
	 */
	public static function add_coupon_error( $error ) {

		if ( self::$coupon_error ) {
			return self::$coupon_error;
		} else {
			return $error;
		}

	}

	/**
	 * Sets which coupons should be applied for this calculation.
	 *
	 * This function is hooked to "woocommerce_before_calculate_totals" so that WC will calculate a subscription
	 * product's total based on the total of it's price per period and sign up fee (if any).
	 *
	 * @since 1.3.5
	 *
	 * @param WC_Cart $cart
	 */
	public static function remove_coupons( $cart ) {
		static $coupon_types = array(
			'recurring_fee'     => 1,
			'recurring_percent' => 1,
		);

		$calculation_type = WC_Subscriptions_Cart::get_calculation_type();

		// Only hook when totals are being calculated completely (on cart & checkout pages)
		if ( 'none' === $calculation_type || ! WC_Subscriptions_Cart::cart_contains_subscription() || ( ! is_checkout() && ! is_cart() && ! defined( 'WOOCOMMERCE_CHECKOUT' ) && ! defined( 'WOOCOMMERCE_CART' ) ) ) {
			return;
		}


		$discount_totals = $cart->get_coupon_discount_totals();
		$applied_coupons = $cart->get_applied_coupons();
		if ( empty( $applied_coupons ) ) {
			return;
		}

		// If we're calculating a sign-up fee or recurring fee only amount, remove irrelevant coupons
		foreach ( $applied_coupons as $coupon_code ) {
			$coupon      = new WC_Coupon( $coupon_code );
			$coupon_type = wcs_get_coupon_property( $coupon, 'discount_type' );
			if ( ! isset( $coupon_types[ $coupon_type ] ) ) {
				$cart->remove_coupon( $coupon_code );
				continue;
			}

			if ( 'recurring_total' === $calculation_type ) {
				// Special handling for a single payment coupon.
				$payments    = self::get_coupon_limit( $coupon_code );
				$coupon_used = isset( $discount_totals[ $coupon_code ] ) && $discount_totals[ $coupon_code ] > 0;
				if ( 1 === $payments && $coupon_used ) {
					$cart->remove_coupon( $coupon_code );
				}

				continue;
			}

			if ( ! WC_Subscriptions_Cart::all_cart_items_have_free_trial() ) {
				continue;
			}

			$cart->remove_coupon( $coupon_code );
		}
	}

	/**
	 * Add our recurring product coupon types to the list of coupon types that apply to individual products.
	 * Used to control which validation rules will apply.
	 *
	 * @param array $product_coupon_types
	 * @return array $product_coupon_types
	 */
	public static function filter_product_coupon_types( $product_coupon_types ) {

		if ( is_array( $product_coupon_types ) ) {
			$product_coupon_types = array_merge( $product_coupon_types, array( 'recurring_fee', 'recurring_percent', 'sign_up_fee', 'sign_up_fee_percent', 'renewal_fee', 'renewal_percent', 'renewal_cart' ) );
		}

		return $product_coupon_types;
	}

	/**
	 * Get subtotals for a renewal subscription so that our pseudo renewal_cart discounts can be applied correctly even if other items have been added to the cart
	 *
	 * @param  string $code coupon code
	 * @return array subtotal
	 * @since 2.0.10
	 */
	private static function get_renewal_subtotal( $code ) {

		$renewal_coupons = WC()->session->get( 'wcs_renewal_coupons' );

		if ( empty( $renewal_coupons ) ) {
			return false;
		}

		$subtotal = 0;

		foreach ( $renewal_coupons as $order_id => $coupons ) {

			foreach ( $coupons as $coupon_code => $coupon_properties ) {

				if ( $coupon_code == $code ) {

					if ( $order = wc_get_order( $order_id ) ) {
						$subtotal = $order->get_subtotal();
					}
					break;
				}
			}
		}

		return $subtotal;
	}

	/**
	 * Check if a product is a renewal order line item (rather than a "susbscription") - to pick up non-subsbcription products added a subscription manually
	 *
	 * @param int|WC_Product $product_id
	 * @param array $cart_item
	 * @param WC_Cart $cart The WooCommerce cart object.
	 * @return boolean whether a product is a renewal order line item
	 * @since 2.0.10
	 */
	private static function is_subsbcription_renewal_line_item( $product_id, $cart_item ) {

		$is_subscription_line_item = false;

		if ( is_object( $product_id ) ) {
			$product_id = $product_id->get_id();
		}

		if ( ! empty( $cart_item['subscription_renewal'] ) ) {
			if ( $subscription = wcs_get_subscription( $cart_item['subscription_renewal']['subscription_id'] ) ) {
				foreach ( $subscription->get_items() as $item ) {
					$item_product_id = wcs_get_canonical_product_id( $item );
					if ( ! empty( $item_product_id ) && $item_product_id == $product_id ) {
						$is_subscription_line_item = true;
					}
				}
			}
		}

		return apply_filters( 'woocommerce_is_subscription_renewal_line_item', $is_subscription_line_item, $product_id, $cart_item );
	}

	/**
	 * Add our pseudo renewal coupon types to the list of supported types.
	 *
	 * @param array $coupon_types
	 * @return array supported coupon types
	 * @since 2.2
	 */
	public static function add_pseudo_coupon_types( $coupon_types ) {
		return array_merge(
			$coupon_types,
			array(
				'renewal_percent' => __( 'Renewal % discount', 'woocommerce-subscriptions' ),
				'renewal_fee'     => __( 'Renewal product discount', 'woocommerce-subscriptions' ),
				'renewal_cart'    => __( 'Renewal cart discount', 'woocommerce-subscriptions' ),
			)
		);
	}

	/**
	 * Filter the default coupon cart label for renewal pseudo coupons
	 *
	 * @param  string $label
	 * @param  WC_Coupon $coupon
	 * @return string
	 * @since 2.2.8
	 */
	public static function get_pseudo_coupon_label( $label, $coupon ) {

		// If the coupon is one of our pseudo coupons, rather than displaying "Coupon: discount_renewal" display a nicer label.
		if ( 'renewal_cart' === wcs_get_coupon_property( $coupon, 'discount_type' ) ) {
			$label = esc_html( __( 'Renewal Discount', 'woocommerce-subscriptions' ) );
		}

		return $label;
	}

	/**
	 * Determine whether the cart contains a recurring coupon with set number of renewals.
	 *
	 * @author Jeremy Pry
	 * @return bool
	 */
	public static function cart_contains_limited_recurring_coupon() {
		$has_coupon      = false;
		$applied_coupons = isset( WC()->cart->applied_coupons ) ? WC()->cart->applied_coupons : array();
		foreach ( $applied_coupons as $code ) {
			if ( self::coupon_is_limited( $code ) ) {
				$has_coupon = true;
				break;
			}
		}

		return $has_coupon;
	}

	/**
	 * Determine if a given order has a limited use coupon.
	 *
	 * @author Jeremy Pry
	 *
	 * @param WC_Order|WC_Subscription $order
	 *
	 * @return bool
	 */
	public static function order_has_limited_recurring_coupon( $order ) {
		$has_coupon = false;
		$coupons    = $order->get_used_coupons();
		foreach ( $coupons as $code ) {
			if ( self::coupon_is_limited( $code ) ) {
				$has_coupon = true;
				break;
			}
		}

		return $has_coupon;
	}

	/**
	 * Determine if a given coupon is limited to a certain number of renewals.
	 *
	 * @author Jeremy Pry
	 *
	 * @param string $code The coupon code.
	 *
	 * @return bool
	 */
	public static function coupon_is_limited( $code ) {
		return (bool) self::get_coupon_limit( $code );
	}

	/**
	 * Get the number of renewals for a limited coupon.
	 *
	 * @author Jeremy Pry
	 *
	 * @param string $code The coupon code.
	 *
	 * @return false|int False for non-recurring coupons, or the limit number for recurring coupons.
	 *                   A value of 0 is for unlimited usage.
	 */
	public static function get_coupon_limit( $code ) {
		static $subscription_coupons = array(
			'recurring_fee'     => 1,
			'recurring_percent' => 1,
		);
		static $virtual_coupons = array(
			'renewal_cart'    => 1,
			'renewal_fee'     => 1,
			'renewal_percent' => 1,
		);

		// Retrieve the coupon data.
		$coupon      = new WC_Coupon( $code );
		$coupon_type = $coupon->get_discount_type();

		// If we have a virtual coupon, attempt to get the original coupon.
		if ( isset( $virtual_coupons[ $coupon_type ] ) ) {
			$coupon      = self::map_virtual_coupon( $coupon );
			$coupon_type = $coupon->get_discount_type();
		}

		$limited = $coupon->get_meta( self::$coupons_renewals );

		return isset( $subscription_coupons[ $coupon_type ] ) ? intval( $limited ) : false;
	}

	/**
	 * Get a normal coupon from one of our virtual coupons.
	 *
	 * This is necessary when manually processing a renewal to ensure that we are correctly
	 * identifying limited payment coupons.
	 *
	 * @author Jeremy Pry
	 *
	 * @param WC_Coupon $coupon The virtual coupon.
	 *
	 * @return WC_Coupon The original coupon.
	 */
	private static function map_virtual_coupon( $coupon ) {
		add_filter( 'woocommerce_get_shop_coupon_data', '__return_false', 100 );
		$coupon = new WC_Coupon( $coupon->get_code() );
		remove_filter( 'woocommerce_get_shop_coupon_data', '__return_false', 100 );

		return $coupon;
	}

	/**
	 * Limit payment gateways to those that support changing subscription amounts.
	 *
	 * @author Jeremy Pry
	 *
	 * @param WC_Payment_Gateway[] $gateways The current available gateways.
	 *
	 * @return WC_Payment_Gateway[],
	 */
	private static function limit_gateways_subscription_amount_changes( $gateways ) {
		foreach ( $gateways as $index => $gateway ) {
			if ( $gateway->supports( 'subscriptions' ) && ! $gateway->supports( 'subscription_amount_changes' ) ) {
				unset( $gateways[ $index ] );
			}
		}

		return $gateways;
	}

	/**
	 * Filter the available gateways when there is a recurring coupon.
	 *
	 * @author Jeremy Pry
	 *
	 * @param WC_Payment_Gateway[] $gateways The available payment gateways.
	 *
	 * @return array The filtered payment gateways.
	 */
	public static function gateways_subscription_amount_changes( $gateways ) {
		// If there are already no gateways, bail early.
		if ( empty( $gateways ) ) {
			return $gateways;
		}

		// See if this is a request to change payment for an existing subscription.
		$change_payment     = isset( $_GET['change_payment_method'] ) ? wc_clean( $_GET['change_payment_method'] ) : 0;
		$has_limited_coupon = false;
		if ( $change_payment && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'] ) ) {
			$subscription       = wcs_get_subscription( $change_payment );
			$has_limited_coupon = self::order_has_limited_recurring_coupon( $subscription );
		}

		// If the cart doesn't have a limited coupon, and a change payment doesn't have a limited coupon, bail early.
		if ( ! self::cart_contains_limited_recurring_coupon() && ! $has_limited_coupon ) {
			return $gateways;
		}

		// If we got this far, we should limit the gateways as needed.
		$gateways = self::limit_gateways_subscription_amount_changes( $gateways );

		// If there are no gateways now, it's because of the coupon. Filter the 'no available payment methods' message.
		if ( empty( $gateways ) ) {
			add_filter( 'woocommerce_no_available_payment_methods_message', array( __CLASS__, 'no_available_payment_methods_message' ), 20 );
		}

		return $gateways;
	}

	/**
	 * Filter the message for when no payment gateways are available.
	 *
	 * @author Jeremy Pry
	 *
	 * @param string $message The current message indicating there are no payment methods available..
	 *
	 * @return string The filtered message indicating there are no payment methods available.
	 */
	public static function no_available_payment_methods_message( $message ) {
		return __( 'Sorry, it seems there are no available payment methods which support the recurring coupon you are using. Please contact us if you require assistance or wish to make alternate arrangements.', 'woocommerce-subscriptions' );
	}

	/**
	 * Add custom fields to the coupon data form.
	 *
	 * @see    WC_Meta_Box_Coupon_Data::output()
	 * @author Jeremy Pry
	 *
	 * @param int $id The coupon ID.
	 */
	public static function add_coupon_fields( $id ) {
		$coupon = new WC_Coupon( $id );
		woocommerce_wp_text_input( array(
			'id'          => 'wcs_number_payments',
			'label'       => __( 'Active for x payments', 'woocommerce-subscriptions' ),
			'placeholder' => __( 'Unlimited payments', 'woocommerce-subscriptions' ),
			'description' => __( 'Coupon will be limited to the given number of payments. It will then be automatically removed from the subscription. "Payments" also includes the initial subscription payment.', 'woocommerce-subscriptions' ),
			'desc_tip'    => true,
			'data_type'   => 'decimal',
			'value'       => $coupon->get_meta( self::$coupons_renewals ),
		) );
	}

	/**
	 * Save our custom coupon fields.
	 *
	 * @see    WC_Meta_Box_Coupon_Data::save()
	 * @author Jeremy Pry
	 *
	 * @param int $post_id
	 */
	public static function save_coupon_fields( $post_id ) {
		// Check the nonce (again).
		if ( empty( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( $_POST['woocommerce_meta_nonce'], 'woocommerce_save_data' ) ) {
			return;
		}

		$coupon = new WC_Coupon( $post_id );
		$coupon->add_meta_data( self::$coupons_renewals, wc_clean( $_POST['wcs_number_payments'] ), true );
		$coupon->save();
	}

	/**
	 * Determine how many subscriptions the coupon has been applied to.
	 *
	 * @author Jeremy Pry
	 *
	 * @param WC_Subscription $subscription The current subscription.
	 */
	public static function check_coupon_usages( $subscription ) {
		// If there aren't any coupons, there's nothing to do.
		$coupons = $subscription->get_used_coupons();
		if ( empty( $coupons ) ) {
			return;
		}

		// Set up the coupons we're looking for, and an initial count.
		$limited_coupons = array();
		foreach ( $coupons as $coupon ) {
			if ( self::coupon_is_limited( $coupon ) ) {
				$limited_coupons[ $coupon ] = 0;
			}
		}

		// Don't continue if we have no limited use coupons.
		if ( empty( $limited_coupons ) ) {
			return;
		}

		// Get all related orders, and count the number of uses for each coupon.
		$related = $subscription->get_related_orders( 'all' );

		/** @var WC_Order $order */
		foreach ( $related as $id => $order ) {
			// Unpaid orders don't count as usages.
			if ( $order->needs_payment() ) {
				continue;
			}

			/*
			 * If the order has been refunded, treat coupon as unused. We'll consider the order to be
			 * refunded when there is a non-null refund amount, and the order total equals the refund amount.
			 *
			 * The use of == instead of === is deliberate, to account for differences in amount formatting.
			 */
			$refunded = $order->get_total_refunded();
			$total    = $order->get_total();
			if ( null !== $refunded && $total == $refunded ) {
				continue;
			}

			// If there was nothing discounted, then consider the coupon unused.
			if ( ! $order->get_discount_total() ) {
				continue;
			}

			// Check for limited coupons, and add them to the count if the provide a discount.
			$used_coupons = $order->get_items( 'coupon' );

			/** @var WC_Order_Item_Coupon $used_coupon */
			foreach ( $used_coupons as $used_coupon ) {
				if ( isset( $limited_coupons[ $used_coupon->get_code() ] ) && $used_coupon->get_discount() ) {
					$limited_coupons[ $used_coupon->get_code() ]++;
				}
			}
		}

		// Check each coupon to see if it needs to be removed.
		foreach ( $limited_coupons as $coupon => $count ) {
			$coupon_object = new WC_Coupon( $coupon );
			if ( $coupon_object->get_meta( self::$coupons_renewals ) <= $count ) {
				$subscription->remove_coupon( $coupon );
				$subscription->add_order_note( sprintf(
					_n(
						'Limited use coupon "%1$s" removed from subscription. It has been used %2$d time.',
						'Limited use coupon "%1$s" removed from subscription. It has been used %2$d times.',
						$count,
						'woocommerce-subscriptions'
					),
					$coupon,
					number_format_i18n( $count )
				) );
			}
		}
	}

	/**
	 * Add our limited coupon data to the Coupon list table.
	 *
	 * @author Jeremy Pry
	 *
	 * @param string $column_name The name of the current column in the table.
	 * @param int    $post_id     The coupon post ID.
	 */
	public static function add_limit_to_list_table( $column_name, $post_id ) {
		if ( 'usage' !== $column_name ) {
			return;
		}

		$limit = self::get_coupon_limit( wc_get_coupon_code_by_id( $post_id ) );
		if ( false === $limit ) {
			return;
		}

		echo '<br>';
		if ( $limit ) {
			echo esc_html( sprintf(
				/* translators: %d refers to the number of renewals the coupon can be used for. */
				_n( 'Active for %d payment', 'Active for %d payments', $limit, 'woocommerce-subscriptions' ),
				number_format_i18n( $limit )
			) );
		} else {
			esc_html_e( 'Active for unlimited payments', 'woocommerce-subscriptions' );
		}
	}

	/* Deprecated */

	/**
	 * Apply sign up fee or recurring fee discount
	 *
	 * @since 1.2
	 */
	public static function apply_subscription_discount( $original_price, $cart_item, $cart ) {
		_deprecated_function( __METHOD__, '2.0.10', 'Have moved to filtering on "woocommerce_coupon_get_discount_amount" to return discount amount. See: '. __CLASS__ .'::get_discount_amount()' );

		if ( ! WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
			return $original_price;
		}

		$price = $calculation_price = $original_price;

		$calculation_type = WC_Subscriptions_Cart::get_calculation_type();

		if ( ! empty( $cart->applied_coupons ) ) {

			foreach ( $cart->applied_coupons as $coupon_code ) {

				$coupon        = new WC_Coupon( $coupon_code );
				$coupon_type   = wcs_get_coupon_property( $coupon, 'discount_type' );
				$coupon_amount = wcs_get_coupon_property( $coupon, 'coupon_amount' );

				// Pre 2.5 is_valid_for_product() does not use wc_get_product_coupon_types()
				if ( WC_Subscriptions::is_woocommerce_pre( '2.5' ) ) {
					$is_valid_for_product = true;
				} else {
					$is_valid_for_product = $coupon->is_valid_for_product( $cart_item['data'], $cart_item );
				}

				if ( $coupon->apply_before_tax() && $coupon->is_valid() && $is_valid_for_product ) {

					$apply_recurring_coupon = $apply_recurring_percent_coupon = $apply_initial_coupon = $apply_initial_percent_coupon = false;

					// Apply recurring fee discounts to recurring total calculations
					if ( 'recurring_total' == $calculation_type ) {
						$apply_recurring_coupon         = ( 'recurring_fee' == $coupon_type ) ? true : false;
						$apply_recurring_percent_coupon = ( 'recurring_percent' == $coupon_type ) ? true : false;
					}

					if ( 'none' == $calculation_type ) {

						// If all items have a free trial we don't need to apply recurring coupons to the initial total
						if ( ! WC_Subscriptions_Cart::all_cart_items_have_free_trial() ) {

							if ( 'recurring_fee' == $coupon_type ) {
								$apply_initial_coupon = true;
							}

							if ( 'recurring_percent' == $coupon_type ) {
								$apply_initial_percent_coupon = true;
							}
						}

						// Apply sign-up discounts to initial total
						if ( WC_Subscriptions_Product::get_sign_up_fee( $cart_item['data'] ) > 0 ) {

							if ( 'sign_up_fee' == $coupon_type ) {
								$apply_initial_coupon = true;
							}

							if ( 'sign_up_fee_percent' == $coupon_type ) {
								$apply_initial_percent_coupon = true;
							}

							$calculation_price = WC_Subscriptions_Product::get_sign_up_fee( $cart_item['data'] );
						}
					}

					if ( $apply_recurring_coupon || $apply_initial_coupon ) {

						$discount_amount = ( $calculation_price < $coupon_amount ) ? $calculation_price : $coupon_amount;

						// Recurring coupons only apply when there is no free trial (carts can have a mix of free trial and non free trial items)
						if ( $apply_initial_coupon && 'recurring_fee' == $coupon_type && WC_Subscriptions_Product::get_trial_length( $cart_item['data'] ) > 0 ) {
							$discount_amount = 0;
						}

						$cart->discount_cart = $cart->discount_cart + ( $discount_amount * $cart_item['quantity'] );
						$cart = self::increase_coupon_discount_amount( $cart, $coupon_code, $discount_amount * $cart_item['quantity'] );

						$price = $price - $discount_amount;

					} elseif ( $apply_recurring_percent_coupon ) {

						$discount_amount = round( ( $calculation_price / 100 ) * $coupon_amount, WC()->cart->dp );

						$cart->discount_cart = $cart->discount_cart + ( $discount_amount * $cart_item['quantity'] );
						$cart = self::increase_coupon_discount_amount( $cart, $coupon_code, $discount_amount * $cart_item['quantity'] );

						$price = $price - $discount_amount;

					} elseif ( $apply_initial_percent_coupon ) {

						// Recurring coupons only apply when there is no free trial (carts can have a mix of free trial and non free trial items)
						if ( 'recurring_percent' == $coupon_type && 0 == WC_Subscriptions_Product::get_trial_length( $cart_item['data'] ) ) {
							$amount_to_discount = WC_Subscriptions_Product::get_price( $cart_item['data'] );
						} else {
							$amount_to_discount = 0;
						}

						// Sign up fee coupons only apply to sign up fees
						if ( 'sign_up_fee_percent' == $coupon_type ) {
							$amount_to_discount = WC_Subscriptions_Product::get_sign_up_fee( $cart_item['data'] );
						}

						$discount_amount = round( ( $amount_to_discount / 100 ) * $coupon_amount, WC()->cart->dp );

						$cart->discount_cart = $cart->discount_cart + $discount_amount * $cart_item['quantity'];
						$cart = self::increase_coupon_discount_amount( $cart, $coupon_code, $discount_amount * $cart_item['quantity'] );

						$price = $price - $discount_amount;
					}
				}
			}

			if ( $price < 0 ) {
				$price = 0;
			}
		}

		return $price;
	}

	/**
	 * Store how much discount each coupon grants.
	 *
	 * @since 2.0
	 * @param WC_Cart $cart The WooCommerce cart object.
	 * @param mixed $code
	 * @param mixed $amount
	 * @return WC_Cart $cart
	 */
	public static function increase_coupon_discount_amount( $cart, $code, $amount ) {
		_deprecated_function( __METHOD__, '2.0.10' );

		if ( empty( $cart->coupon_discount_amounts[ $code ] ) ) {
			$cart->coupon_discount_amounts[ $code ] = 0;
		}

		$cart->coupon_discount_amounts[ $code ] += $amount;

		return $cart;
	}

	/**
	 * Restores discount coupons which had been removed for special subscription calculations.
	 *
	 * @since 1.3.5
	 */
	public static function restore_coupons( $cart ) {
		_deprecated_function( __METHOD__, '2.0' );

		if ( ! empty( self::$removed_coupons ) ) {

			// Can't use $cart->add_dicount here as it calls calculate_totals()
			$cart->applied_coupons = array_merge( $cart->applied_coupons, self::$removed_coupons );

			if ( isset( $cart->coupons ) ) { // WC 2.3+
				$cart->coupons = $cart->get_coupons();
			}

			self::$removed_coupons = array();
		}
	}

	/**
	 * Apply sign up fee or recurring fee discount before tax is calculated
	 *
	 * @since 1.2
	 */
	public static function apply_subscription_discount_before_tax( $original_price, $cart_item, $cart ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ .'::apply_subscription_discount( $original_price, $cart_item, $cart )' );
		return self::apply_subscription_discount( $original_price, $cart_item, $cart );
	}

	/**
	 * Apply sign up fee or recurring fee discount after tax is calculated
	 *
	 * @since 1.2
	 * @version 1.3.6
	 */
	public static function apply_subscription_discount_after_tax( $coupon, $cart_item, $price ) {
		_deprecated_function( __METHOD__, '2.0', 'WooCommerce 2.3 removed after tax discounts. Use ' . __CLASS__ .'::apply_subscription_discount( $original_price, $cart_item, $cart )' );
	}
}

WC_Subscriptions_Coupon::init();
