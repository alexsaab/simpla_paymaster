<?php

namespace PayMaster;

class PayMaster
{
    private $_id = 'PayMaster';
    private $_version = '1.0.0';

    public $payButtonText = 'Оплатить';

    public $merchantId;
    public $secretKey;
    public $signMethod;
    public $currency = 'RUB';


    /**
     * Сеттер установки ID продавца
     * @param $merchantId
     */
    public function setMerchantId($merchantId)
    {
        $this->merchantId = $merchantId;
    }


    /**
     * Сеттер установки секретного ключа
     * @param $secretKey
     */
    public function setSecretKey($secretKey)
    {
        $this->secretKey = $secretKey;
    }


    /**
     * Сеттер установки метода шифрования ключа и подписи
     * @param $signMethod
     */
    public function setSignMethod($signMethod = 'md5')
    {
        $this->signMethod = $signMethod;
    }

    /**
     * Получение номера заказа из POST запроса
     * @return null
     */
    public function getOrderId()
    {
        if (isset($_POST["LMI_PAYMENT_NO"])) {
            return $_POST["LMI_PAYMENT_NO"];
        } else {
            return null;
        }
    }

    /**
     * Получение суммы заказа из POST запроса
     * @return null
     */
    public function getAmount()
    {
        if (isset($_POST["LMI_PAYMENT_AMOUNT"])) {
            return $_POST["LMI_PAYMENT_AMOUNT"];
        } else {
            return null;
        }
    }


    /**
     * Получение формы при оформлении заказа, перед отправкой ее на Paymaster
     * @param $orderId
     * @param $amount
     * @param $description
     * @param $notify_url
     * @param $success_url
     * @param $fail_url
     * @param array $extra_fields
     * @return string
     */
    public function getForm($orderId, $amount, $description, $notify_url, $success_url, $fail_url, $paymaster_vat_products, $paymaster_vat_delivery, $extra_fields = array())
    {

        $simpla = new \Simpla();

	    $order = $simpla->orders->get_order(intval($orderId));

	    $method = $simpla->payment->get_payment_method(intval($order->payment_method_id));

	    if (empty($method))
		    die;

	    $settings = unserialize($method->settings);


	    $this->setSecretKey($settings['secret_key']);
	    $this->setSignMethod($settings['paymaster_sign_method']);

        $amount = number_format($amount,2,'.','');

        $fields = array(
	        'LMI_MERCHANT_ID' => $this->merchantId,
	        'LMI_PAYMENT_NO' => $orderId,
            'LMI_PAYMENT_AMOUNT' => $amount,
	        'LMI_CURRENCY' => $this->currency,
            'LMI_PAYMENT_DESC' => $description,
            'LMI_PAYMENT_NOTIFICATION_URL' => $notify_url,
            'LMI_SUCCESS_URL' => $success_url,
            'LMI_FAILURE_URL' => $fail_url,
            'SIGN' => $this->getSign($this->merchantId, $orderId, $amount, $this->currency, $this->secretKey, $this->signMethod),
        );


        $order = $simpla->orders->get_order(intval($orderId));


        foreach ($simpla->orders->get_purchases(array('order_id' => intval($orderId))) as $key=>$product) {
            $fields["LMI_SHOPPINGCART.ITEM[{$key}].NAME"] = $product->product_name;
            $fields["LMI_SHOPPINGCART.ITEM[{$key}].QTY"] = $product->amount;
            $fields["LMI_SHOPPINGCART.ITEM[{$key}].PRICE"] = $product->price;
            $fields["LMI_SHOPPINGCART.ITEM[{$key}].TAX"] = $paymaster_vat_products;
        }

        //Добавляем доставку в форму
        $key++;
        if (isset($order->delivery_price) && ($order->delivery_price > 0)) {
            $fields["LMI_SHOPPINGCART.ITEM[{$key}].NAME"] = "Доставка заказа №".$orderId;
            $fields["LMI_SHOPPINGCART.ITEM[{$key}].QTY"] = 1;
            $fields["LMI_SHOPPINGCART.ITEM[{$key}].PRICE"] = $order->delivery_price;
            $fields["LMI_SHOPPINGCART.ITEM[{$key}].TAX"] = $paymaster_vat_delivery;
        }

        $fields = array_merge($fields, $extra_fields);

        $form = '<form method="POST" action="https://paymaster.ru/Payment/Init">' . PHP_EOL;
        foreach ($fields as $key => $value) {
            $form .= '<input type="hidden" name="' . $key . '" value="' . $value . '">' . PHP_EOL;
        }
        $form .= '<input type="submit" value="' . $this->payButtonText . '">' . PHP_EOL . '</form>';

        return $form;
    }


    /**
     * Верификация
     * @return bool
     */
    public function verify()
    {

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            if ($_POST["LMI_PREREQUEST"] == "1" || $_POST["LMI_PREREQUEST"] == "2") {
                echo "YES";
                die;
            } else {
                $hash = $this->getHash($_POST["LMI_MERCHANT_ID"], $_POST["LMI_PAYMENT_NO"], $_POST["LMI_SYS_PAYMENT_ID"], $_POST["LMI_SYS_PAYMENT_DATE"], $_POST["LMI_PAYMENT_AMOUNT"], $_POST["LMI_CURRENCY"], $_POST["LMI_PAYMENT_AMOUNT"], $_POST["LMI_CURRENCY"], $_POST["LMI_PAYMENT_SYSTEM"], $_POST["LMI_SIM_MODE"], $this->secretKey, $this->signMethod);
                $sign = $this->getSign($_POST["LMI_MERCHANT_ID"], $_POST["LMI_PAYMENT_NO"], $_POST["LMI_PAYMENT_AMOUNT"], $_POST["LMI_CURRENCY"], $this->secretKey, $this->signMethod);
                if ($_POST["LMI_HASH"] == $hash) {
                    if ($_POST["SIGN"] == $sign) {
                        return true;
                    } else {
                        return false;
                    }
                }
            }
        }
        return false;
    }


    /**
     * Возвращаем HASH запроса
     * @param $merchant_id
     * @param $order_id
     * @param $amount
     * @param $lmi_currency
     * @param $secret_key
     * @param string $sign_method
     * @return string
     */
    public function getHash($LMI_MERCHANT_ID, $LMI_PAYMENT_NO, $LMI_SYS_PAYMENT_ID, $LMI_SYS_PAYMENT_DATE, $LMI_PAYMENT_AMOUNT, $LMI_CURRENCY, $LMI_PAID_AMOUNT, $LMI_PAID_CURRENCY, $LMI_PAYMENT_SYSTEM, $LMI_SIM_MODE, $SECRET, $hash_method = 'md5')
    {
        $string = $LMI_MERCHANT_ID . ";" . $LMI_PAYMENT_NO . ";" . $LMI_SYS_PAYMENT_ID . ";" . $LMI_SYS_PAYMENT_DATE . ";" . $LMI_PAYMENT_AMOUNT . ";" . $LMI_CURRENCY . ";" . $LMI_PAID_AMOUNT . ";" . $LMI_PAID_CURRENCY . ";" . $LMI_PAYMENT_SYSTEM . ";" . $LMI_SIM_MODE . ";" . $SECRET;

        $hash = base64_encode(hash($hash_method, $string, TRUE));

        return $hash;
    }


    /**
     * Возвращаем подпись
     * @param $merchant_id
     * @param $order_id
     * @param $amount
     * @param $lmi_currency
     * @param $secret_key
     * @param string $sign_method
     * @return string
     */
    public function getSign($merchant_id, $order_id, $amount, $lmi_currency, $secret_key, $sign_method = 'md5')
    {

        $plain_sign = $merchant_id . $order_id . $amount . $lmi_currency . $secret_key;
        $sign = base64_encode(hash($sign_method, $plain_sign, TRUE));

        return $sign;
    }
}
