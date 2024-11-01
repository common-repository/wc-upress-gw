<?php
/**
 * Plugin Name: uPress Payment Gateway
 * Description: uPress Payment Gateway is a simple plugin which allows any user to start receiving credit card payments in a couple of button clicks.
 * Version: 1.7.8
 * Author: uPress
 * Author URI: https://www.upress.co.il
 * Text Domain: wc-upress-gw
 * Domain Path: /languages
 *
 * WC tested up to: 4.0
 *
 * License: GPL version 2 or later
 */

function wc_upress_gw_load_textdomain() {
	load_plugin_textdomain( 'wc-upress-gw', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'init', 'wc_upress_gw_load_textdomain' );

function wc_upress_gw_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		if ( is_admin() ) {
			function wc_upress_gw_woocommerce_inactive_notice() {
				?>
                <div class="notice notice-error">
                    <p><?php _e( 'uPress Payment Gateway requires Woocommerce to be installed and active.', 'wc-upress-gw' ); ?></p>
                </div>
				<?php
			}

			add_action( 'admin_notices', 'wc_upress_gw_woocommerce_inactive_notice' );
		}

		return;
	}

	define( 'WC_UPRESS_GW_PLUGIN_DIR', plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) . '/' );
	define( 'WC_UPRESS_GW_GATEWAY_URL', 'https://icom.yaad.net/p3/' );
	define( 'WC_UPRESS_GW_GATEWAY_URL2', 'https://pay.leumicard.co.il/p/' );

	/**
	 * WC_Urpess_Max
	 */
	class WC_Urpess_Max extends WC_Payment_Gateway {
		/**
		 * @var array
		 */
		protected $langForYADPAY;
		/**
		 * @var array
		 */
		protected $currencyForYADPAY;

		function __construct() {
			// Register plugin information
			$this->id           = 'upress-max';
			$this->has_fields   = true;
			$this->method_title = __( 'uPress Payment Gateway', 'wc-upress-gw' );
			/* translators: %s: Link to WC system status page */
			$this->method_description = __( 'Secure credit card payments with MAX gateway.', 'wc-upress-gw' );

			// Create plugin fields and settings
			$this->init_form_fields();
			$this->init_settings();

			// Lang and Cur
			$this->langForYADPAY     = [
				'USD'     => 'ENG',
				'EUR'     => 'ENG',
				'GBP'     => 'ENG',
				'ILS'     => 'HEB',
				'default' => 'HEB',
			];
			$this->currencyForYADPAY = [ 'ILS' => 1, 'USD' => 2, 'EUR' => 3, 'GBP' => 4, 'default' => 1 ];

			// Get setting values
			foreach ( $this->settings as $key => $val ) {
				$this->$key = $val;
			}

			// Add hooks
			add_action( 'woocommerce_receipt_upress-max', [ $this, 'receipt_page' ] );
			add_action( 'woocommerce_update_options_payment_gateways', [ $this, 'process_admin_options' ] );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [
				$this,
				'process_admin_options',
			] );
			add_action( 'woocommerce_api_wc_urpess_max', [ $this, 'check_response' ] );
			add_action( 'woocommerce_api_wc_iframe_urpess_max', [ $this, 'iframe_form' ] );
			add_action( 'woocommerce_before_checkout_form', [ $this, 'action_woocommerce_before_checkout_form' ] );
		}

		public function get_registration_link() {
			$registration_url = get_transient( 'wc_upress_gw_registration_link' );
			if ( ! $registration_url ) {
				$url = 'https://my.upress.io/api/max/generate-registration-url?host=' . get_site_url();
				$response = wp_safe_remote_get( $url, ['user-agent' => 'WC_Urpess_Max'] );
				$registration_url = wp_remote_retrieve_body( $response );

				if( ! filter_var( $registration_url, FILTER_VALIDATE_URL) ) {
					$registration_url = '';
				}

				set_transient( 'wc_upress_gw_registration_link', $registration_url, HOUR_IN_SECONDS );
			}

			return $registration_url;
		}

		public function action_woocommerce_before_checkout_form() {
			if ( isset( $_GET['errorYAD'] ) && $_GET['errorYAD'] > 0 ) {
				wc_print_notice( __( 'Something went wrong while making MAX payment!', 'wc-upress-gw' ), 'error' );
			}
		}

		public function getFormFromOrderID( $order_id ) {
			$order           = new WC_Order( $order_id );
			$total           = $order->get_total();
			$currencyCurrent = get_woocommerce_currency();
			$langCurrent     = get_woocommerce_currency();


			if ( $this->settings['languageuse'] == "AUTO" ) {
				if ( array_key_exists( $langCurrent, $this->langForYADPAY ) ) {
					$varLangToSend = $this->langForYADPAY[ $langCurrent ];
				} else {
					$varLangToSend = $this->langForYADPAY['default'];
				}
			} else {
				$varLangToSend = $this->settings['languageuse'];
			}


			if ( array_key_exists( $currencyCurrent, $this->currencyForYADPAY ) ) {
				$varCurToSend = $this->currencyForYADPAY[ $currencyCurrent ];
			} else {
				$varCurToSend = $this->currencyForYADPAY['default'];
			}
			$products = $order->get_items();


			$itemArray = "";
			$_pf       = new WC_Product_Factory();
			foreach ( $products as $key => $product ) {
				$item_id   = $product['product_id'];
				$product2  = $_pf->get_product( $item_id );
				$name      = $product['name'];
				$price     = $product2->get_price();
				$qty       = $product['qty'];
				$itemArray .= "[" . $item_id . "~" . $name . "~" . $qty . "~" . $price . "]";
			}


			$get_total_shipping = $order->get_shipping_total();
			if ( $get_total_shipping > 0 ) {
				$itemArray .= "[0~Shipping~1~" . $get_total_shipping . "]";
			}

			$get_total_discount = $order->get_total_discount();
			if ( $get_total_discount > 0 ) {
				$itemArray .= "[1~Discount~-1~" . $get_total_discount . "]";
			}
			$url_gateway = WC_UPRESS_GW_GATEWAY_URL2;
			if ( strpos( $this->settings['termno'], '88' ) === false ) {
				$url_gateway = WC_UPRESS_GW_GATEWAY_URL;
			}


			$returnForm = '<form name="uPressMAX" id="uPressMAX" action="' . $url_gateway . '" method="post" >
				  <input type="hidden" value="pay" name="action" >
				  <input type="hidden" value="' . $this->settings['termno'] . '" name="Masof" >
				  <input type="hidden" value="' . $total . '" name="Amount" >
				  <input type="hidden" value="' . $order_id . '" name="Info" >
				  <input type="hidden" value="' . $order_id . '" name="Order" >
				  <input type="hidden" value="True" name="sendemail" >
				  <input type="hidden" value="True" name="Sign" >
			 	  <input type="hidden" value="' . $varLangToSend . '" name="PageLang" >
				  <input type="hidden" value="' . $varCurToSend . '" name="Coin" >
				  <input type="hidden" value="' . $order->get_billing_last_name() . '" name="ClientLName" >
				  <input type="hidden" value="' . $order->get_billing_first_name() . '" name="ClientName" >
				  <input type="hidden" value="' . $order->get_billing_address_1() . " " . $order->get_billing_address_2() . '" name="street" >
				  <input type="hidden" value="' . $order->get_billing_city() . '" name="city" >
				  <input type="hidden" value="' . $order->get_billing_postcode() . '" name="zip" >
				  <input type="hidden" value="' . $order->get_billing_phone() . '" name="phone" >
				  <input type="hidden" value="' . $order->get_billing_phone() . '" name="cell" >
				  <input type="hidden" value="' . $order->get_billing_email() . '" name="email" >
                  <input type="hidden" value="True" name="SendHesh" >
                  <input type="hidden" value="True" name="BOF" >
                  <input type="hidden" value="True" name="pageTimeOut" >

				  <input type="hidden" value="True" name="UTF8" >
				  <input type="hidden" value="True" name="UTF8out" >';

			if ( $varLangToSend == "ENG" ) {
				$returnForm .= '<input type="hidden" name="UserId" value="000000000">';
			}

			if ( $this->settings['pritim'] && $this->settings['pritim'] == "true" ) {
				$returnForm .= '<input type="hidden" value="True" name="SendHesh" > ';
				$returnForm .= '<input type="hidden" value="' . $itemArray . '" name="heshDesc" > ';
				$returnForm .= '<input type="hidden" value="True" name="Pritim" > ';
			}

			if ( $this->settings['paymentinstallments'] ) {
				$returnForm .= '<input type="hidden" value="' . $this->settings['paymentinstallments'] . '" name="Tash" >';
			}

			if ( $this->settings['template'] ) {
				$returnForm .= '<input type="hidden" value="' . $this->settings['template'] . '" name="tmp" >';
			}

			if ( $this->settings['postpone'] && $this->settings['postpone'] == "true" ) {
				$returnForm .= '<input type="hidden" value="True" name="Postpone" >';
			}

			$returnForm .= '</form>
				<script>
					window.onload = function(){
					  document.forms["uPressMAX"].submit()
					}
                </script>';

			return $returnForm;
		}

		public function iframe_form() {
			$order_id = intval( $_GET['order_id'] );
			echo $this->getFormFromOrderID( $order_id );

			exit;
		}


		/**
		 * Initialize Gateway Settings Form Fields.
		 */
		function init_form_fields() {
			$this->form_fields = [
				'enabled'             => [
					'title'       => __( 'Enable/Disable', 'wc-upress-gw' ),
					'label'       => __( 'Enable Gateway', 'wc-upress-gw' ),
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no',
				],
				'title'               => [
					'title'       => __( 'Title', 'wc-upress-gw' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'wc-upress-gw' ),
					'default'     => __( 'uPress Payment Gateway', 'wc-upress-gw' ),
				],
				'description'         => [
					'title'       => __( 'Description', 'wc-upress-gw' ),
					'type'        => 'textarea',
					'description' => __( 'This controls the description which the user sees during checkout.', 'wc-upress-gw' ),
					/* translators: %s: Accepted credit card types image */
					'default'     => sprintf( __( 'Secure Credit Card Payment %s', 'wc-upres-gw' ), '<img src="' . WC_UPRESS_GW_PLUGIN_DIR . 'images/upress-max-cards.png">' ),
				],
				'signature'           => [
					'title'       => __( 'API Key / Password Signature', 'wc-upress-gw' ),
					'type'        => 'text',
					'description' => __( 'This is the API Signature. You will got from MAX.', 'wc-upress-gw' ),
					'default'     => '',
				],
				'termno'              => [
					'title'       => __( 'Terminal Number', 'wc-upress-gw' ),
					'type'        => 'text',
					'description' => __( 'This is the your Term No. You will got from MAX.', 'wc-upress-gw' ),
					'default'     => '',
				],
				'paymentinstallments' => [
					'title'       => __( 'Payment Installments', 'wc-upress-gw' ),
					'type'        => 'text',
					'description' => '',
					'default'     => '1',
					'disable'     => true,
				],
				'postpone'            => [
					'title'       => __( 'Delayed Payment', 'wc-upress-gw' ),
					'type'        => 'select',
					'description' => '',
					'options'     => [
						'false' => __( 'No', 'wc-upress-gw' ),
						'true'  => __( 'Yes', 'wc-upress-gw' ),
					],
					'default'     => 'False',

				],
				'template'            => [
					'title'       => __( 'Template', 'wc-upress-gw' ),
					'type'        => 'select',
					'description' => sprintf( __( 'Templates shown at <a href="%1$s" target="_blank" rel="noopener nofollow">%1$s</a>', 'wc-upress-gw' ), 'http://max.upress.co.il/billing-templates' ),
					'options'     => [
						''  => '0',
						'1' => '1',
						'2' => '2',
						'3' => '3',
						'4' => '4',
						'5' => '5',
						'6' => '6',
						'7' => '7',
					],
					'default'     => '',
				],
				'pritim'              => [
					'title'       => __( 'Show Items', 'wc-upress-gw' ),
					'type'        => 'select',
					'description' => '',
					'options'     => [
						'false' => __( 'No', 'wc-upress-gw' ),
						'true'  => __( 'Yes', 'wc-upress-gw' ),
					],
					'default'     => 'false',
				],
				'languageuse'         => [
					'title'       => __( 'Language', 'wc-upress-gw' ),
					'type'        => 'select',
					'description' => '',
					'options'     => [
						'HEB'  => __( 'Hebrew', 'wc-upress-gw' ),
						'ENG'  => __( 'English', 'wc-upress-gw' ),
						'AUTO' => __( 'Automatic', 'wc-upress-gw' ),
					],
					'default'     => 'HEB',
				],
				'moduleworking'       => [
					'title'       => __( 'Type', 'wc-upress-gw' ),
					'type'        => 'select',
					'description' => '',
					'options'     => [
						'iframe' => 'Iframe',
						'form'   => 'Redirect',
					],
					'default'     => 'form',
				],
				'iframewidth'         => [
					'title'       => __( 'Iframe Width', 'wc-upress-gw' ),
					'type'        => 'text',
					'description' => '',
					'default'     => '1000',
					'disable'     => true,
				],
				'iframeheight'        => [
					'title'       => __( 'Iframe Height', 'wc-upress-gw' ),
					'type'        => 'text',
					'description' => '',
					'default'     => '800',
					'disable'     => true,
				],
			];
		}


		/**
		 * UI - Admin Panel Options
		 */
		function admin_options() {
			if ( $this->is_valid_for_use() ) {
				?>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h3><?php _e( 'MAX Payment Gateway by uPress', 'wc-upress-gw' ); ?></h3>
                        <p><?php _e( 'Accept all major credit cards directly on your WooCommerce site in a seamless and secure checkout environment with MAX.', 'wc-upress-gw' ); ?></p>
                    </div>

                    <p style="display: flex; justify-content: space-between; align-items: center;">
                        <img src="<?php echo WC_UPRESS_GW_PLUGIN_DIR; ?>/images/upress.svg" height="48"
                             style="margin: 0 8px;" alt="" />
                        <img src="<?php echo WC_UPRESS_GW_PLUGIN_DIR; ?>/images/max-logo.svg" height="32"
                             style="margin: 0 8px;" alt="" />
                    </p>
                </div>

				<?php $max_registration_link = $this->get_registration_link(); ?>
				<?php if( ! empty( $max_registration_link ) && empty( $this->settings['termno'] ) ) : ?>
                    <style>
                        .button.max-button {
                            background: #1d1c64;
                            border-color: #1d1c64;
                            color: #fff;
                            padding: 4px 18px;
                            border-radius: 100px;
                            transition: all 0.1s;
                        }
                        .button.max-button:hover {
                            background: #2285C7;
                            border-color: #2285C7;
                            color: #fff;
                        }
                    </style>
                <div style="margin: 16px 0; padding: 24px 16px; background: #CBDBE9; text-align: center; border-radius: 4px;">
                    <a href="<?php echo $this->get_registration_link(); ?>" target="_blank" class="max-button button button-secondary">
	                    <?php _e( 'Join MAX Business', 'wc-upress-gw' ); ?>
                    </a>

	                <p style="font-size: 14px; margin: 16px 0 0;">
	                    <?php _e( 'After the registration process MAX will automatically open your payment terminal and all settings will auto-update* for your website.', 'wc-upress-gw' ); ?>
	                </p>
	                <p style="font-size: 11px; margin: 0; color: #495969;">
	                    <?php _e( '* Automatic settings update available for uPress customers only', 'wc-upress-gw' ); ?>
	                </p>
                </div>
				<?php else : ?>
					<p>
						<?php
						_e( 'Return URL for MAX: ', 'wc-upress-gw' );
						echo "<code>" . WC()->api_request_url( 'WC_Urpess_Max' ) . "</code>";
						?>
					</p>
				<?php endif; ?>

                <table class="form-table">
					<?php $this->generate_settings_html(); ?>
                </table>


				<?php
			} else {
				?>
                <div class="inline error">
                    <p>
                        <strong><?php _e( 'Gateway Disabled', 'wc-upress-gw' ); ?></strong>:
						<?php _e( 'uPress Payment Gateway does not support your store currency.', 'wc-upress-gw' ); ?>
                    </p>
                </div>
				<?php
			}
		}

		/**
		 * Process the payment and return the result.
		 *
		 * @param $order_id
		 *
		 * @return array
		 */
		function process_payment( $order_id ) {
			$order = new WC_Order( $order_id );

			return [
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url( true ),
			];
		}

		/**
		 * Check for Yaadpay IPN Response
		 */
		public function check_response() {
			$checkout_url = wc_get_checkout_url() . '?errorYAD=1';
			$order_id = isset( $_GET['Order'] ) ? wc_sanitize_order_id( $_GET['Order'] ) : 0;
			$ccode = isset( $_GET['CCode'] ) ? intval( $_GET['CCode'] ) : -1;

			if ( $order_id > 0 && ( $ccode == 0 || $ccode == 800 ) && $this->check_signature() ) {
				$order          = wc_get_order( $order_id );
				$order_complete = $this->process_order_status( $order, sanitize_text_field( $_GET['Id'] ), "APPROVED" );
				if ( $order_complete ) {
					// Return thank you page redirect

					if ( $this->settings['moduleworking'] && $this->settings['moduleworking'] == "iframe" ) {
						?>
                        <script>
                            window.top.location.href = '<?php echo $this->get_return_url( $order ); ?>';
                        </script>
						<?php
					} else {
						wp_redirect( $this->get_return_url( $order ) );

					}
				} else {
					if ( $this->settings['moduleworking'] && $this->settings['moduleworking'] == "iframe" ) {
						?>
                        <script>
                            window.top.location.href = '<?php echo $checkout_url; ?>';
                        </script>
						<?php
					} else {
						wp_redirect( $checkout_url );

					}
				}
			} else {
				if ( $this->settings['moduleworking'] && $this->settings['moduleworking'] == "iframe" ) {
					?>
                    <script>
                        window.top.location.href = '<?php echo $checkout_url; ?>';
                    </script>
					<?php
				} else {
					wp_redirect( $checkout_url );
				}
			}
			exit;
		}

		/**
		 * @param WC_Order $order
		 * @param string $payment_id
		 * @param string $status
		 *
		 * @return bool
		 */
		public function process_order_status( $order, $payment_id, $status ) {
			if ( 'APPROVED' == $status ) {
				// Payment complete
				$order->payment_complete( $payment_id );

				// Add order note
				$order->add_order_note( sprintf( __( 'MAX payment approved (ID: %s)', 'wc-upress-gw' ), $payment_id ) );

				// Remove cart
				WC()->cart->empty_cart();

				return true;
			}

			return false;
		}

		/**
		 * Receipt Page
		 *
		 * @param $order
		 */
		function receipt_page( $order ) {
			if ( $this->is_valid_for_use() ) {
				// echo '<p>'.__('Thank you for your order, please click the button below to pay with Yaadpay.', 'woothemes').'</p>';
				echo $this->generate_form( $order );
			} else {
				?>
                <div class="inline error"><p>
                        <strong><?php _e( 'Gateway error', 'wc-upress-gw' ); ?></strong>:
						<?php _e( 'Please try another payment module.', 'wc-upress-gw' ); ?>
                    </p></div>
				<?php
			}
		}

		/**
		 * Generate yaadpay button link
		 *
		 * @param $order_id
		 */
		public function generate_form( $order_id ) {
			if ( $this->settings['moduleworking'] && $this->settings['moduleworking'] == "iframe" ) { ?>
                <iframe
                        src="<?php echo WC()->api_request_url( 'WC_Iframe_Urpess_Max' ); ?>?order_id=<?php echo $order_id; ?>"
                        width="<?php echo $this->settings['iframewidth']; ?>"
                        height="<?php echo $this->settings['iframeheight']; ?>" id="chekout_frame" name="chekout_frame"
                        style="border:none; max-width:100%; margin:0 auto;">
                </iframe>
				<?php
			} else {
				echo $this->getFormFromOrderID( $order_id );
			}
		}

		/**
		 *Payments check Signature
		 */
		private function check_signature() {
			$deal     = sanitize_text_field( $_GET['Id'] ); // עסקה 'מס
			$CCode    = sanitize_text_field( $_GET['CCode'] ); // משבא תשובה 'מס
			$Amount   = sanitize_text_field( $_GET['Amount'] ); // סכום
			$ACode    = sanitize_text_field( $_GET['ACode'] ); //
			$token    = sanitize_text_field( $_GET['Order'] ); // token
			$fullname = sanitize_text_field( $_GET['Fild1'] ); // משפחה ושם פרטי שם
			$email    = sanitize_text_field( $_GET['Fild2'] ); // מייל כתובת
			$phone    = sanitize_text_field( $_GET['Fild3'] ); // טלפון
			$Sign     = sanitize_text_field( $_GET['Sign'] ); // דיגיטלית חתימה

			$sign_array = [
				'Id'     => $deal,
				'CCode'  => $CCode,
				'Amount' => $Amount,
				'ACode'  => $ACode,
				'Order'  => $token,
				'Fild1'  => rawurlencode( $fullname ),
				'Fild2'  => rawurlencode( $email ),
				'Fild3'  => rawurlencode( $phone ),
			];

			$string = '';
			foreach ( $sign_array as $key => $val ) {
				$string .= $key . '=' . $val . '&';
			}
			$string = substr( $string, 0, - 1 );

			$verify = hash_hmac( 'SHA256', $string, $this->settings['signature'] );
			if ( $verify == $Sign ) // good !!!
			{
				return true;
			} else {
				return false;
			}
		}

		/**
		 * Check if this gateway is enabled and available in the user's country
		 *
		 * @return bool
		 */
		public function is_valid_for_use() {
			return in_array( get_woocommerce_currency(), apply_filters( 'woocommerce_upress_gw_supported_currencies', [
				'NONE',
				'ILS',
				'USD',
				'EUR',
				'GBP',
			] ) );
		}
	}


	/**
	 * Add the gateway to woocommerce
	 *
	 * @param array $methods
	 *
	 * @return array
	 */
	function wc_upress_gw_add_gateway( $methods ) {
		$methods[] = 'WC_Urpess_Max';

		return $methods;
	}

	add_filter( 'woocommerce_payment_gateways', 'wc_upress_gw_add_gateway' );


	/**
	 * Display the settings & registration link notice
	 */
	function wc_upress_gw_admin_notices() {
        $payment_gateway = WC()->payment_gateways()->payment_gateways();
        if ( ! isset( $payment_gateway['upress-max'] ) ) {
            return;
        }

        /** @var WC_Urpess_Max $payment_gateway */
        $payment_gateway = $payment_gateway['upress-max'];

        // confirmations
        $max_response =  json_decode( str_replace("'", '"', get_site_option( 'wc_upress_gw_max_response' ) ), true );
        if ( null !== $max_response ) {
            $update_options = array_diff_key( $max_response, ['stauts' => 1, 'description' => 1]);
            foreach ($update_options as $option => $value) {
                $payment_gateway->update_option( $option, $value );
            }

            delete_site_option( 'wc_upress_gw_max_response' );

            $type = $max_response['stauts'] == 3 ? 'error' : 'success';
            ?>
            <div class="notice notice-<?php echo $type; ?>">
                <p>
                    <strong><?php _e( 'uPress Payment Gateway Response:', 'wc-upress-gw' ); ?></strong>
                    <br>
                    <?php echo $max_response['description']; ?>
                </p>
            </div>
            <?php
        }

        // configuration notice
        if ( 3 !== count( array_intersect_assoc( $_GET, [ 'page' => 'wc-settings', 'tab' => 'checkout', 'section' => 'upress-max' ] ) ) ) {
            if ( ! empty( $payment_gateway->settings['termno'] ) ) {
                return;
            }

            $registration_url = $payment_gateway->get_registration_link();

            $settings_url = add_query_arg(
                [
                    'page'    => 'wc-settings',
                    'tab'     => 'checkout',
                    'section' => 'upress-max',
                ],
                admin_url( 'admin.php' )
            );

            ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php _e( 'uPress Payment Gateway is not configured yet', 'wc-upress-gw' ); ?></strong>
                    <br>
                    <a href="<?php echo $registration_url; ?>" target="_blank">
                        <?php _e( 'Register with MAX Business', 'wc-upress-gw' ); ?>
                    </a> | <a href="<?php echo $settings_url; ?>">
                        <?php _e( 'Configure uPress Payment Gateway', 'wc-upress-gw' ); ?>
                    </a>
                </p>
            </div>
            <?php
        }
    }

    add_action( 'admin_notices', 'wc_upress_gw_admin_notices' );
}

add_action( 'plugins_loaded', 'wc_upress_gw_init', 0 );
