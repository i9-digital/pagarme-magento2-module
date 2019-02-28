<?php

namespace MundiPagg\MundiPagg\Concrete;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order\Payment\Transaction\Repository;
use Mundipagg\Core\Kernel\Abstractions\AbstractDataService;
use Mundipagg\Core\Kernel\Aggregates\Order;
use Mundipagg\Core\Kernel\ValueObjects\Id\ChargeId;

class Magento2DataService extends AbstractDataService
{
    public function updateAcquirerData(Order $order)
    {
        $platformOrder = $order->getPlatformOrder()->getPlatformOrder();

        $objectManager = ObjectManager::getInstance();
        $transactionRepository = $objectManager->get(Repository::class);
        $lastTransId = $platformOrder->getPayment()->getLastTransId();
        $paymentId = $platformOrder->getPayment()->getEntityId();
        $orderId = $platformOrder->getPayment()->getParentId();

        $transactionAuth = $transactionRepository->getByTransactionId(
            str_replace('-capture', '', $lastTransId),
            $paymentId,
            $orderId
        );

        $additionalInfo = [];
        if ($transactionAuth !== false) {
            $currentCharges = $order->getCharges();

            foreach($currentCharges as $charge) {
                $baseKey = $this->getChargeBaseKey($transactionAuth, $charge);
                if ($baseKey === null) {
                    continue;
                }

                $lastMundipaggTransaction = $charge->getLastTransaction();

                $additionalInfo[$baseKey . '_acquirer_nsu'] =
                    $lastMundipaggTransaction->getAcquirerNsu();

                $additionalInfo[$baseKey . '_acquirer_tid'] =
                    $lastMundipaggTransaction->getAcquirerTid();

                $additionalInfo[$baseKey . '_acquirer_auth_code'] =
                    $lastMundipaggTransaction->getAcquirerAuthCode();

                $additionalInfo[$baseKey . '_acquirer_name'] =
                    $lastMundipaggTransaction->getAcquirerName();

                $additionalInfo[$baseKey . '_acquirer_message'] =
                    $lastMundipaggTransaction->getAcquirerMessage();

                $additionalInfo[$baseKey . '_brand'] =
                    $lastMundipaggTransaction->getBrand();

                $additionalInfo[$baseKey . '_installments'] =
                    $lastMundipaggTransaction->getInstallments();
            }

            $this->createCaptureTransaction(
                $platformOrder,
                $transactionAuth,
                $additionalInfo
            );
        }
    }

    private function getChargeBaseKey($transactionAuth, $charge)
    {
        $orderCreationResponse =
            $transactionAuth->getAdditionalInformation('mundipagg_payment_module_api_response');

        if ($orderCreationResponse === null) {
            return null;
        }

        $orderCreationResponse = json_decode($orderCreationResponse);

        $authCharges = $orderCreationResponse->charges;

        $outdatedCharge = null;
        foreach ($authCharges as $authCharge) {
            if ($charge->getMundipaggId()->equals(new ChargeId($authCharge->id)))
            {
                $outdatedCharge = $authCharge;
            }
        }

        if ($outdatedCharge === null) {
            return null;
        }

        try {
            //if it have no nsu, then it isn't a credit_card transaction;
            $lastNsu = $outdatedCharge->last_transaction->acquirer_nsu;
        }catch (\Throwable $e) {
            return null;
        }

        $additionalInformation = $transactionAuth->getAdditionalInformation();
        foreach ($additionalInformation as $key => $value) {
            if ($value == $lastNsu) {
                return str_replace('_acquirer_nsu', '', $key);
            }
        }

        return null;
    }

    private function createCaptureTransaction($order, $transactionAuth, $additionalInformation)
    {
        $objectManager = ObjectManager::getInstance();
        $transactionRepository = $objectManager->get(Repository::class);

        /** @var Order\Payment $payment */
        $payment = $order->getPayment();

        $transaction = $transactionRepository->create();
        $transaction->setParentId($transactionAuth->getTransactionId());
        $transaction->setOrderId($order->getEntityId());
        $transaction->setPaymentId($payment->getEntityId());
        $transaction->setTxnId($transactionAuth->getTxnId() . '-capture');
        $transaction->setParentTxnId($transactionAuth->getTxnId(), $transactionAuth->getTxnId() . '-capture');
        $transaction->setTxnType('capture');
        $transaction->setIsClosed(true);


        foreach ( $additionalInformation as $key => $value ) {
            $transaction->setAdditionalInformation($key, $value);
        }

        $transactionRepository->save($transaction);
    }
}