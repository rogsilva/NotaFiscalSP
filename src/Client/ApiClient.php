<?php

namespace NotaFiscalSP\Client;

use Exception;
use NotaFiscalSP\Entities\BaseInformation;
use NotaFiscalSP\Entities\WsdlBase;
use NotaFiscalSP\Responses\BasicResponse;

class ApiClient
{
    public static function send(WsdlBase $wsdlBase, $method, BaseInformation $baseInformation)
    {
        try {
            // Load the WSDL from a local cache. The SP prefecture started
            // requiring the client certificate (mTLS) to download the WSDL,
            // and that handshake is flaky over PHP's native transport, so we
            // fetch it once via cURL (with retry) and reuse it afterwards.
            $wsdlFile = self::cachedWsdl($wsdlBase, $baseInformation);

            $client = new CurlSoapClient($wsdlFile, [
                'location'   => $wsdlBase->getEndPoint(),
                'keep_alive' => true,
                'trace'      => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
            ]);
            $client->setCertificate(
                $baseInformation->getCertificatePath(),
                $baseInformation->getCertificatePass()
            );

            $arguments = [
                $method => [
                    'VersaoSchema' => 1,
                    'MensagemXML' => $baseInformation->getXml(),
                ],
            ];

            $result = $client->__soapCall($method, $arguments, []);

            return $result->RetornoXML;
        } catch (Exception $e) {
            $response = new BasicResponse();
            $response->setSuccess(false);
            $response->setXmlInput($baseInformation->getXml());
            $response->setMessage($e);

            return $response;
        }
    }

    /**
     * Download the WSDL via cURL (presenting the client certificate) and cache
     * it on disk. Subsequent calls reuse the cached file so the unreliable
     * remote fetch only happens once.
     */
    private static function cachedWsdl(WsdlBase $wsdlBase, BaseInformation $baseInformation)
    {
        $url = $wsdlBase->getWsdl();
        $cacheFile = sys_get_temp_dir() . '/nfsp_wsdl_' . md5($url) . '.wsdl';

        if (is_file($cacheFile) && filesize($cacheFile) > 0) {
            return $cacheFile;
        }

        $wsdl = false;
        $code = 0;
        $err = '';
        for ($attempt = 1; $attempt <= 8 && ($wsdl === false || $code !== 200); $attempt++) {
            if ($attempt > 1) {
                sleep(min($attempt - 1, 3));
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSLCERT        => $baseInformation->getCertificatePath(),
                CURLOPT_FRESH_CONNECT  => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 30,
            ]);

            $wsdl = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($wsdl === false) {
                $err = curl_error($ch);
            }
            curl_close($ch);
        }

        if ($wsdl === false || $code !== 200) {
            throw new Exception("Failed to download WSDL from {$url} (http={$code} err={$err})");
        }

        file_put_contents($cacheFile, $wsdl);

        return $cacheFile;
    }
}
