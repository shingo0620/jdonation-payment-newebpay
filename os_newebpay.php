<?php
/**
 * @version        5.6.0
 * @package        Joomla
 * @subpackage     Joom Donation
 * @author         Tuan Pham Ngoc
 * @copyright      Copyright (C) 2009 - 2019 Ossolution Team
 * @license        GNU/GPL, see LICENSE.php
 */
defined('_JEXEC') or die;

class os_newebpay extends OSFPayment
{
	/**
	 * Constructor functions, init some parameter
	 *
	 * @param JRegistry $params
	 * @param array     $config
	 */
	public function __construct($params, $config = array())
	{
		parent::__construct($params, $config);
		$this->mode = $params->get('newebpay_mode');
		$this->merchantID = $params->get('newebpay_merchantid');
		$this->hashKey = $params->get('newebpay_hashkey');
		$this->hashIV = $params->get('newebpay_hashiv');
	}

	/**
	 * Process payment
	 *
	 */
	function processPayment($row, $data)
	{
		if ($this->mode === '1') {
			$this->url = 'https://core.newebpay.com/MPG/mpg_gateway';
		} else {
			$this->url = 'https://ccore.newebpay.com/MPG/mpg_gateway';
		}

		$app    = JFactory::getApplication();
		$Itemid = $app->input->getInt('Itemid');
		$config = DonationHelper::getConfig();
		DonationHelper::sendEmails($row, $config);
		
		$siteUrl = JUri::base();
		$cancelUrl = $siteUrl . 'index.php?option=com_jdonation&view=cancel' . ($row->campaign_id > 0 ? '&campaign_id=' . $row->campaign_id : '') . '&Itemid=' . $Itemid;
		$notifyUrl = $siteUrl . 'index.php?option=com_jdonation&task=payment_confirm&payment_method=os_newebpay';
		// $returnUrl = JUri::getInstance()->toString(array('scheme', 'user', 'pass', 'host')) . JRoute::_(DonationHelperRoute::getDonationCompleteRoute($row->id, $row->campaign_id, $Itemid), false);
		$returnUrl = $siteUrl . 'index.php?option=com_newebpayresult&task=verifyPayment';
		
		$tradeInfoArray = [
			'MerchantID' => $this->merchantID, //商店代號
			'RespondType' => 'String', //回傳格式
			'TimeStamp' => time(), //時間戳記
			'Version' => '1.5',
			'MerchantOrderNo' => $row->id,
			'Amt' => $data['gateway_amount'],
			'ItemDesc' => $data['item_name'],
			'LoginType' => '0',
			"Email" => $data['email'],
			"NotifyURL" => $notifyUrl, //幕後
			"ReturnURL" => $returnUrl, //幕前(線上)
			"ClientBackURL" => $cancelUrl, //取消交易
			// "CustomerURL" => '' //幕前(線下)
		];

		//交易資料經 AES 加密後取得 TradeInfo
		$TradeInfo = $this->create_mpg_aes_encrypt($tradeInfoArray, $this->hashKey, $this->hashIV); 
		$TradeSha = $this->aes_sha256_str($TradeInfo, $this->hashKey, $this->hashIV);

		$this->setParameter('MerchantID', $this->merchantID);
		$this->setParameter('TradeInfo', $TradeInfo);
		$this->setParameter('TradeSha', $TradeSha);
		$this->setParameter('Version', '1.5');
		$this->renderRedirectForm();
	}

	/**
	 * Check to see whether this payment gateway support recurring payment
	 *
	 */
	public function getEnableRecurring()
	{
		return 1;
	}

	/**
	 * Process recurring payment
	 *
	 * @param object $row
	 * @param array  $data
	 */
	public function processRecurringPayment($row, $data)
	{
		if ($this->mode === '1') {
			$this->url = 'https://core.newebpay.com/MPG/period';
		} else {
			$this->url = 'https://ccore.spgateway.com/MPG/period';
		}

		$app    = JFactory::getApplication();
		$Itemid = $app->input->getInt('Itemid');
		$config = DonationHelper::getConfig();
		DonationHelper::sendEmails($row, $config);
		
		$siteUrl = JUri::base();
		$cancelUrl = $siteUrl . 'index.php?option=com_jdonation&view=cancel' . ($row->campaign_id > 0 ? '&campaign_id=' . $row->campaign_id : '') . '&Itemid=' . $Itemid;
		$notifyUrl = $siteUrl . 'index.php?option=com_jdonation&task=recurring_donation_confirm&payment_method=os_newebpay';
		// $returnUrl = JUri::getInstance()->toString(array('scheme', 'user', 'pass', 'host')) . JRoute::_(DonationHelperRoute::getDonationCompleteRoute($row->id, $row->campaign_id, $Itemid), false);
		$returnUrl = $siteUrl . 'index.php?option=com_newebpayresult&task=verifyRecurringPayment';

		if ($row->r_times > 0 && $row->r_times < 100){
			$periodTimes = $row->r_times;
		} else {
			throw new Exception('期數超出限制');
		}

		switch ($row->r_frequency){
			case 'd':
				$periodPoint = '1';
				$periodType = 'D';
				throw new Exception("目前不支援每日付款");
				break;
			case 'w' :
				$periodPoint = date('N');
				$periodType = 'W';
				break;
			case 'b' :
				$periodPoint = '14';
				$periodType = 'D';
				throw new Exception("目前不支援雙週付款");
				break;
			case 'm' :
				$periodPoint = $this->params->get('newebpay_period_month_point'); // 固定每月x號付款
				$periodPoint = str_pad($periodPoint, 2, '0', STR_PAD_LEFT); // 補0至兩位數
				$periodType = 'M';
				break;
			case 'q' :
				$periodPoint = '90';
				$periodType = 'D';
				break;
			case 's' :
				$periodPoint = '180';
				$periodType = 'D';
				break;
			case 'a' :
				$periodPoint = date('m').date('d');
				$periodType = 'Y';
				break;
		}

		$periodStartType = 1;
		if (($periodType === 'M' && date('d') === $periodPoint) || $periodType === 'Y') {
			// 授權日期正好是扣款日或如果是定期定額年費, 則馬上扣款第一期
			$periodStartType = 2;
		}

		$tradeInfoArray = [
			'RespondType' => 'JSON', //回傳格式
			'TimeStamp' => time(), //時間戳記
			'Version' => '1.0',
			'MerOrderNo' => $row->id,
			'ProdDesc' => $data['item_name'],
			'PeriodAmt' => $data['gateway_amount'],
			'PeriodType' => $periodType,
			'PeriodPoint' => $periodPoint,
			'PeriodStartType' => $periodStartType,
			'PeriodTimes' => $periodTimes,
			"ReturnURL" => $returnUrl, //幕前(線上)
			"PayerEmail" => $data['email'],
			"NotifyURL" => $notifyUrl, //幕後
			"BackURL" => $cancelUrl, //取消交易
		];

		$PostData_ = $this->create_mpg_aes_encrypt($tradeInfoArray, $this->hashKey, $this->hashIV);
		$this->setParameter('MerchantID_', $this->merchantID);
		$this->setParameter('PostData_', $PostData_);
		$this->renderRedirectForm();
	}

	/**
	 * Verify payment
	 *
	 * @return bool
	 */
	public function verifyPayment()	{
		$tradeInfo = $this->validate();
		if ($tradeInfo) {
			$id = $tradeInfo['MerchantOrderNo'];
			$row = JTable::getInstance('jdonation', 'Table');
			$row->load($id);
			
			if ($tradeInfo['Amt'] < 0) {
				return false;
			}
			if (!$row->id) {
				return false;
			}
			if ($tradeInfo['Amt'] != $row->amount ) {
				return false;
			}
			if (!$row->published){
				$this->onPaymentSuccess($row, $tradeInfo['TradeNo']);
			}
		}

		return false;
	}

	public function verifyRecurringPayment() {
		$result = $this->validateRecurring();
		if ($result) {
			$row = JTable::getInstance('jdonation', 'Table');
			$id = $result['MerchantOrderNo'];
			$row->load($id);
			if (!$row->id){
				return false;
			}
			if ($result['PeriodAmt'] != $row->amount) {
				// 金額不符
				return false;
			}

			if ($result['RespondCode'] !== '00') {
				// 授權失敗
				// 第一次授權就失敗, 不理他
				// 第二期開始後授權失敗, 新增付款失敗記錄
				if ($row->payment_made > 0)
				{
					$row                = clone $row;
					$row->id            = 0;
					$row->donation_type = 'I';
					$row->created_date  = gmdate('Y-m-d H:i:s');
					$row->comment = $this->errorMessage;
					$row->published = 0;
					$row->store();
				}
				$row->comment = $this->errorMessage;
				$row->store();
				return false;
			}
			
			if (array_key_exists('AuthTimes', $result)) {
				// 第一次授權, 可能爲10元驗證或是第一期款項
				if (!$row->published){
					$this->onPaymentSuccess($row, $result['TradeNo']);
				}

				$dateArray = explode(',', $result['DateArray']);
				$row->comment = $result['DateArray'];
				if ($dateArray[0] === date('Y-m-d')) {
					// 授權日同時收取第一期款項
					$row->payment_made++;
					$row->store();
				}
			} else { // 每次授權完成
				// 更新初次授權訂單中的付款次數記錄
				$row->payment_made++;
				$row->store();
				// 新增付款記錄
				if ($row->payment_made > 0)
				{
					$row                = clone $row;
					$row->id            = 0;
					$row->donation_type = 'I';
					$row->created_date  = gmdate('Y-m-d H:i:s');
					$this->onPaymentSuccess($row, $result['TradeNo']);
				}
			}
			return true;
		}
		return false;
	}

	/**
	 *MPG aes加密
		*
		* @access private
		* @param array $parameter ,string $key, string $iv
		* @version 1.4
		* @return string
		*/
	private function create_mpg_aes_encrypt ($parameter = "" , $key = "", $iv = "") {
			$return_str = '';
			if (!empty($parameter)) {
				//將參數經過 URL ENCODED QUERY STRING
				// ksort($parameter);
				$return_str = http_build_query($parameter);
			}
			return trim( bin2hex( openssl_encrypt(
				$this->addpadding($return_str),
				'aes-256-cbc',
				$key,
				OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING,
				$iv
			)));
	}
	
	private function addpadding($string, $blocksize = 32) {
			$len = strlen($string);
			$pad = $blocksize - ($len % $blocksize);
			$string .= str_repeat(chr($pad), $pad);
			return $string;
	}

		/**
	 *MPG sha256加密
		*
		* @access private
		* @param string $str ,string $key, string $iv
		* @version 1.4
		* @return string
		*/
	private function aes_sha256_str($str, $key = "", $iv = "") {
			return strtoupper(hash("sha256", 'HashKey='.$key.'&'.$str.'&HashIV='.$iv));
	}

	/**
	 *MPG aes解密
		*
		* @access private
		* @param array $parameter ,string $key, string $iv
		* @version 1.4
		* @return string
		*/
	private function create_aes_decrypt($parameter = "", $key = "", $iv = "") {
		$dec_data = explode('&',$this->strippadding( openssl_decrypt( 
			hex2bin($parameter),
			'AES-256-CBC',
			$key,
			OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING,
			$iv
		)));
		return $dec_data;
	}
		
	private function strippadding($string) {
		$slast = ord(substr($string, -1));
		$slastc = chr($slast);
		$pcheck = substr($string, -$slast);
		if (preg_match("/$slastc{" . $slast . "}/", $string)) {
		$string = substr($string, 0, strlen($string) - $slast);
		return $string;
		} else {
		return false;
		}
	}

	private function validate() {
		$this->notificationData = $_POST;
		if ($this->notificationData['Status'] !== 'SUCCESS') {
			error_log(var_export($this->notificationData, true));
			error_log('Status not match');
			return false;
		}
		if ($this->notificationData['MerchantID'] !== $this->merchantID) {
			error_log(var_export($this->notificationData, true));
			error_log('MerchantID not match');
			return false;
		}
		if ($this->notificationData['TradeSha'] !== $this->aes_sha256_str($this->notificationData['TradeInfo'], $this->hashKey, $this->hashIV)) {
			error_log(var_export($this->notificationData, true));
			error_log('SHA not match');
			return false;
		}

		$decryptData = $this->create_aes_decrypt($this->notificationData['TradeInfo'], $this->hashKey, $this->hashIV);
		foreach ($decryptData as $index => $value) {
			$trans_data = explode('=', $value);
			$tradeInfo[$trans_data[0]] = $trans_data[1];
		}
		
		if ($tradeInfo['Status'] !== 'SUCCESS') {
			error_log(var_export($tradeInfo, true));
			error_log('TradeInfo->status not match');
			return false;
		}
		if ($tradeInfo['MerchantID'] !== $this->merchantID) {
			error_log(var_export($tradeInfo, true));
			error_log('TradeInfo->merchantID not match');
			return false;
		}
		
		return $tradeInfo;
	}

	private function validateRecurring() {
		$this->notificationData = $_POST;
		$decryptData = $this->create_aes_decrypt($this->notificationData['Period'], $this->hashKey, $this->hashIV);
		$period = json_decode($decryptData[0], JSON_NUMERIC_CHECK);
		
		echo '<pre>' . var_export($period, true) . '</pre>';
		if ($period['Status'] !== 'SUCCESS') {
			error_log('Period->status not match');
			error_log(var_export($period, true));
			$this->errorMessage = $period['Message'];
			// return false; // 爲了記錄付款失敗, 	不回傳false
		}
		$result = $period['Result'];

		if ($result['MerchantID'] !== $this->merchantID) {
			error_log('Period->Result->merchantID not match');
			error_log('Period->Result->MerchantID: ' . $result['MerchantID'] . ', it should be: ' . $this->merchantID, true);
			error_log(var_export($period, true));
			return false;
		}

		return $result;
	}
}
