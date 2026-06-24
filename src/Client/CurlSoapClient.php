<?php

namespace NotaFiscalSP\Client;

use SoapClient;
use SoapFault;

/**
 * SoapClient that performs the actual HTTP transport via cURL instead of PHP's
 * native openssl-stream transport.
 *
 * The SP prefecture WS endpoint (old IIS) negotiates the client-certificate
 * (mTLS) handshake unreliably: it frequently resets the TLS connection
 * (SSL_ERROR_SYSCALL). PHP's native stream transport tries once and gives up;
 * cURL with a short connect timeout and several retries (with backoff) rides
 * through these bad spells.
 */
class CurlSoapClient extends SoapClient
{
    const MAX_ATTEMPTS = 8;
    const CONNECT_TIMEOUT = 15;
    const REQUEST_TIMEOUT = 60;

    /** @var string PEM file (certificate + private key) */
    private $certPath;
    /** @var string */
    private $certPass;

    public function setCertificate($certPath, $certPass)
    {
        $this->certPath = $certPath;
        $this->certPass = $certPass;

        return $this;
    }

    public function __doRequest($request, $location, $action, $version, $oneWay = 0): ?string
    {
        $headers = [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "' . $action . '"',
        ];

        $response = false;
        $err = '';
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS && $response === false; $attempt++) {
            if ($attempt > 1) {
                // brief backoff to let the flaky endpoint recover
                sleep(min($attempt - 1, 3));
            }

            $ch = curl_init($location);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $request,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSLCERT        => $this->certPath,
                CURLOPT_FRESH_CONNECT  => true,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            ]);

            $response = curl_exec($ch);
            if ($response === false) {
                $err = curl_error($ch);
            }
            curl_close($ch);
        }

        if ($response === false) {
            throw new SoapFault('HTTP', 'cURL SOAP request failed after ' . self::MAX_ATTEMPTS . ' attempts: ' . $err);
        }

        return $response;
    }
}
