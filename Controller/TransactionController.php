<?php

namespace Kek\CTPaymentBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Kek\CTPaymentBundle\Event\FilterResponseEvent;
use Kek\CTPaymentBundle\KekCTPaymentEvents;
use Symfony\Component\HttpFoundation\Response;

class TransactionController extends Controller
{
    /**
     * @Route("/ctpayment/step1")
     * @Template()
     */
    public function step1Action()
    {
        $soapClient = new \SoapClient('https://test.ctpaiement.com/ws2014/PaymentTransactionV2200.wsdl',
            [
                'location' => 'http://localhost:8080',
                'trace' => true,
                'exceptions' => true,
            ]
        );

        $response = $soapClient->__soapCall('redirectNewSession', [
            'RedirectNewSession' => [
                'companyNumber' => '00214',
                'merchantNumber' => '00999240',
                'customerNumber' => '00000000',
                'amount' => $this->getRequest()->query->get('amount'),
                'billNumber' => $this->getRequest()->query->get('billNumber'),
                'originalBillNumber' => '            ',
                'inputType' => 'I',
                'merchantTerminalNumber' => '     ',
                'languageCode' => $this->getRequest()->query->get('lang') === 'fr' ? 'F' : 'E',
                'currencyCode' => 'CAD',
                'operatorID' => '00000000',
                'successURL' => $this->generateUrl('kek_ctpayment_transaction_step2', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'failureURL' => $this->generateUrl('kek_ctpayment_transaction_step2', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'eMail' => 'fangers@groupemsi.com',
            ],
        ]);

        if ($response->returnCode === '5000') {
            $url = 'https://test.ctpaiement.com/redirect/Redirect?SecureID='.$response->secureID.'&SecureTYPE=GET';

            return $this->redirect($url);
        } else {
            die('ctpayment step 1 failed');
        }
    }

    /**
     * @Route("/ctpayment/step2")
     * @Template()
     */
    public function step2Action()
    {
        // get transaction params

        $soapClient = new \SoapClient('https://test.ctpaiement.com/ws2014/PaymentTransactionV2200.wsdl',
            [
                'location' => 'http://localhost:8080',
                'trace' => true,
                'exceptions' => true,
            ]
        );

        $transactionParams = $soapClient->__soapCall('redirectSessionParameters', [
            'redirectSessionParameters' => [
                'sessionID' => $this->getRequest()->query->get('SecureID'),
            ],
        ]);

        if (trim($transactionParams->returnCode) !== '00') {
            die('transaction bad return code: '.trim($transactionParams->returnCode));

            // FAILURE

            $response = new Response('transaction failure');

            $event = new FilterResponseEvent($transactionParams, $this->getRequest(), $response);
            $this->get('event_dispatcher')->dispatch(KekCTPaymentEvents::TRANSACTION_FAILURE, $event);

            return $event->getResponse();
        }

        // acknowledge transaction

        $soapClient = new \SoapClient('https://test.ctpaiement.com/ws2014/PaymentTransactionV2200.wsdl',
            [
                'location' => 'http://localhost:8080',
                'trace' => true,
                'exceptions' => true,
            ]
        );

        $ack = $soapClient->__soapCall('ack', [
            'ack' => [
                'transactionNumber' => $transactionParams->trxNumber,
            ],
        ]);

        if ($ack->returnCode !== 'true') {
            die('transaction acknowledge failed');
        }

        // SUCCESS!

        $response = new Response('transaction success');

        $event = new FilterResponseEvent($transactionParams, $this->getRequest(), $response);
        $this->get('event_dispatcher')->dispatch(KekCTPaymentEvents::TRANSACTION_SUCCESS, $event);

        return $event->getResponse();
    }
}