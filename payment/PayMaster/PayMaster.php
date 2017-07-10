<?php
require_once(__DIR__ . '/lib/PayMaster.php');
require_once('api/Simpla.php');

class PayMaster extends Simpla
{
    public function checkout_form($order_id, $button_text = null)
    {
        $order = $this->orders->get_order((int)$order_id);
        $payment_method = $this->payment->get_payment_method($order->payment_method_id);
        $payment_settings = $this->payment->get_payment_settings($payment_method->id);

        $paymaster = new PayMaster\PayMaster();
        $paymaster->setMerchantId($payment_settings['merchant_id']);
        $paymaster->setSignMethod($payment_settings['paymaster_sign_method']);

        $order_id = $order->id;

        //Неправильная сумма заказа - вылезает без учета доставки поэтому система заворачивает оплату
        $amount = $this->money->convert($order->total_price + $order->delivery_price, $payment_method->currency_id, false);
        $description = 'Оплата заказа №' . $order_id;
        $notify_url = $this->config->root_url . '/payment/PayMaster/callback.php';
        $success_url = $this->config->root_url . '/order/' . $order->url;
        $fail_url = $this->config->root_url . '/order/' . $order->url;

        return $paymaster->getForm($order_id, $amount, $description, $notify_url, $success_url, $fail_url, $payment_settings['paymaster_vat_products'], $payment_settings['paymaster_vat_delivery']);
    }
}