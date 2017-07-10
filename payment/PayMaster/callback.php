<?php

require_once(__DIR__ . '/lib/PayMaster.php');

chdir('../../');
require_once('api/Simpla.php');
$simpla = new Simpla();


$paymaster = new PayMaster\PayMaster();

$order_id = $paymaster->getOrderId();
$amount = $paymaster->getAmount();

if ($order_id === null)
    die;

$order = $simpla->orders->get_order(intval($order_id));
if (empty($order))
    die;

if ($order->paid)
    die;

$method = $simpla->payment->get_payment_method(intval($order->payment_method_id));
if (empty($method))
    die;

$settings = unserialize($method->settings);


$paymaster->setSecretKey($settings['secret_key']);
$paymaster->setSignMethod($settings['paymaster_sign_method']);


if ($paymaster->verify()) {
    if ($amount != $simpla->money->convert($order->total_price, $method->currency_id, false) || $amount <= 0) {
        die;
    }

    $purchases = $simpla->orders->get_purchases(array('order_id' => intval($order->id)));
    foreach ($purchases as $purchase) {
        $variant = $simpla->variants->get_variant(intval($purchase->variant_id));
        if (empty($variant) || (!$variant->infinity && $variant->stock < $purchase->amount)) {
            die;
        }

    }
    $simpla->orders->update_order(intval($order->id), array('paid' => 1));
    $simpla->orders->close(intval($order->id));
    $simpla->notify->email_order_user(intval($order->id));
    $simpla->notify->email_order_admin(intval($order->id));
}