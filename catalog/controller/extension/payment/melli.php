<?php
class ControllerExtensionPaymentMelli extends Controller {
	public function index() {
		$this->load->language('extension/payment/melli');
		
		$data['text_connect'] = $this->language->get('text_connect');
		$data['text_loading'] = $this->language->get('text_loading');
		$data['text_wait'] = $this->language->get('text_wait');
		
    	$data['button_confirm'] = $this->language->get('button_confirm');

		return $this->load->view('extension/payment/melli', $data);
	}

	public function confirm() {
		$this->load->language('extension/payment/melli');
		
		$this->load->model('checkout/order');
		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
		
		$amount = $this->get_price_in_rail($order_info);
		
		$data['return'] = $this->url->link('checkout/success', '', true);
		$data['cancel_return'] = $this->url->link('checkout/payment', '', true);
		$data['back'] = $this->url->link('checkout/payment', '', true);
		
		$merchant_id = $this->config->get('payment_melli_merchant_id');  	//Required
		$terminal_id = $this->config->get('payment_melli_terminal_id');  	//Required
		$terminal_key = $this->config->get('payment_melli_terminal_key');  	//Required

		$order_id = $this->session->data['order_id'];

		$data['order_id'] = $order_id;

		$redirect = $this->url->link('extension/payment/melli/callback', '', true);  // Required

		$sign_data = $this->sadad_encrypt($terminal_id . ';' . $order_id. ';' . $amount, $terminal_key);

		$parameters = array(
				'MerchantID' => $merchant_id,
				'TerminalId' => $terminal_id,
				'Amount' => $amount,
				'OrderId' => $order_id,
				'LocalDateTime' => date('Ymdhis'),
				'ReturnUrl' => $redirect,
				'SignData' => $sign_data,
		);

		$result = $this->sadad_call_api('https://sadad.shaparak.ir/VPG/api/v0/Request/PaymentRequest', $parameters);
		if ($result != false) {
			if ($result->ResCode == 0) {
				$data['action'] = 'https://sadad.shaparak.ir/VPG/Purchase?Token=' . $result->Token;
				$json['success']= $data['action'];
			} else {
				$json = array();
				$json['error']= $result->Description;
			}
		} else {
			$json = array();
			$json['error']= $this->language->get('error_cant_connect');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function callback() {
		if ($this->session->data['payment_method']['code'] == 'melli') {
			$this->load->language('extension/payment/melli');

			$this->document->setTitle($this->language->get('text_title'));
			
			$data['heading_title'] = $this->language->get('text_title');
			$data['results'] = "";
			
			$data['breadcrumbs'] = array();
			$data['breadcrumbs'][] = array(
				'text' => $this->language->get('text_home'), 
				'href' => $this->url->link('common/home', '', true)
			);
			$data['breadcrumbs'][] = array(
				'text' => $this->language->get('text_title'), 
				'href' => $this->url->link('extension/payment/melli/callback', '', true)
			);

			try {
				if ($this->request->post['OrderId'] && $this->request->post['token']) {
					if ($this->request->post['ResCode'] == "0") {
						$token = $_POST['token'];

						if (isset($this->session->data['order_id'])) {
							$order_id = $this->session->data['order_id'];
						} else {
							$order_id = 0;
						}

						$this->load->model('checkout/order');
						$order_info = $this->model_checkout_order->getOrder($order_id);

						if (!$order_info)
							throw new Exception($this->language->get('error_order_id'));

						//verify payment
						$parameters = array(
							'Token' => $token,
							'SignData' => self::sadad_encrypt($token, $this->config->get('payment_melli_terminal_key')),
						);

						$result = $this->sadad_call_api('https://sadad.shaparak.ir/VPG/api/v0/Advice/Verify', $parameters);
						if ($result != false) {
							if ($result->ResCode == 0) {
								$comment = $this->language->get('text_transaction') . $result->SystemTraceNo;
								$comment .= '<br/>' . $this->language->get('text_transaction_reference') . $result->RetrivalRefNo;

								$this->model_checkout_order->addOrderHistory($order_id, $this->config->get('payment_melli_order_status_id'), $comment, true);

								$data['error_warning'] = NULL;

								$data['system_trace_no'] = $result->SystemTraceNo;
								$data['retrival_ref_no'] = $result->RetrivalRefNo;

								$data['button_continue'] = $this->language->get('button_complete');
								$data['continue'] = $this->url->link('checkout/success');

							} else {
								$data['error_warning'] = $this->language->get('error_payment');
							}
						} else {
							$data['error_warning'] = $this->language->get('error_payment');
						}

					} else {
						$data['error_warning'] = $this->language->get('error_payment');
					}

				} else {
					$data['error_warning'] = $this->language->get('error_data');
				}
			} catch (Exception $e) {
				$data['error_warning'] = $e->getMessage();
				$data['button_continue'] = $this->language->get('button_view_cart');
				$data['continue'] = $this->url->link('checkout/cart');
			}


			$data['column_left'] = $this->load->controller('common/column_left');
			$data['column_right'] = $this->load->controller('common/column_right');
			$data['content_top'] = $this->load->controller('common/content_top');
			$data['content_bottom'] = $this->load->controller('common/content_bottom');
			$data['footer'] = $this->load->controller('common/footer');
			$data['header'] = $this->load->controller('common/header');

			$this->response->setOutput($this->load->view('extension/payment/melli_confirm', $data));
		}
	}

	//Create sign data(Tripledes(ECB,PKCS7)) using mcrypt
	private function mcrypt_encrypt_pkcs7($str, $key) {
		$block = mcrypt_get_block_size("tripledes", "ecb");
		$pad = $block - (strlen($str) % $block);
		$str .= str_repeat(chr($pad), $pad);
		$ciphertext = mcrypt_encrypt("tripledes", $key, $str,"ecb");
		return base64_encode($ciphertext);
	}

	//Create sign data(Tripledes(ECB,PKCS7)) using openssl
	private function openssl_encrypt_pkcs7($key, $data) {
		$ivlen = openssl_cipher_iv_length('des-ede3');
		$iv = openssl_random_pseudo_bytes($ivlen);
		$encData = openssl_encrypt($data, 'des-ede3', $key, 0, $iv);
		return $encData;
	}


	private function sadad_encrypt($data, $key) {
		$key = base64_decode($key);
		if( function_exists('openssl_encrypt') ) {
			return $this->openssl_encrypt_pkcs7($key, $data);
		} elseif( function_exists('mcrypt_encrypt') ) {
			return $this->mcrypt_encrypt_pkcs7($data, $key);
		} else {
			require_once 'TripleDES.php';
			$cipher = new Crypt_TripleDES();
			return $cipher->letsEncrypt($key, $data);
		}

	}


    private function sadad_call_api($url, $data = false) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json; charset=utf-8'));
        curl_setopt($ch, CURLOPT_POST, 1);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $result = curl_exec($ch);
        curl_close($ch);
        return !empty($result) ? json_decode($result) : false;
    }

    private function get_price_in_rail($order_info) {
        $amount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
        $currency = $order_info['currency_code'];
        $rate = 0;
        if ($currency == 'RLS') {
            $rate = 1;
        } elseif ($currency == 'TOM') {
            $rate = 10;
        }
        return $amount * $rate;
    }

}
?>
