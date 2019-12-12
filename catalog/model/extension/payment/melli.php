<?php 
class ModelExtensionPaymentMelli extends Model {
  	public function getMethod($address) {
		$this->load->language('extension/payment/melli');

		if ($this->config->get('payment_melli_status')) {
      		$status = true;
      	} else {
			$status = false;
		}

		$method_data = array();
		
		if ($status) {
      		$method_data = array( 
        		'code'       => 'melli',
        		'title'      => $this->language->get('text_title'),
				'terms'      => '',
				'sort_order' => $this->config->get('payment_melli_sort_order')
      		);
    	}
		
    	return $method_data;
  	}
}
?>