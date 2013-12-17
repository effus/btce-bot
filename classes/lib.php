<?php
/**
 * Created by PhpStorm.
 * User: Effus
 * Date: 01.12.13
 * Time: 22:56
 */

/**
 * @param $msg
 * @param bool $critical
 */
function log_msg($msg,$critical=false) {
    echo date('Y.m.d H:i:s')."\t".$msg.PHP_EOL;
    if ($critical)
        die();
}

/**
 * Class BTCeAPI
 */
class BTCeAPI {

    const DIRECTION_BUY = 'buy';
    const DIRECTION_SELL = 'sell';
    protected $public_api = 'https://btc-e.com/api/2/';

    protected $api_key;
    protected $api_secret;
    protected $noonce;
    protected $RETRY_FLAG = false;

    public function __construct($api_key, $api_secret, $base_noonce = false) {
        $this->api_key = $api_key;
        $this->api_secret = $api_secret;
        if($base_noonce === false) {
            $this->noonce = time();
        } else {
            $this->noonce = $base_noonce;
        }
    }

    /**
     * Get the noonce
     * @global type $sql_conx
     * @return type
     */
    protected function getnoonce() {
        $this->noonce++;
        return array(0.05, $this->noonce);
    }

    /**
     * Call the API
     * @staticvar null $ch
     * @param type $method
     * @param type $req
     * @return type
     * @throws Exception
     */
    public function apiQuery($method, $req = array()) {
        $req['method'] = $method;
        $mt = $this->getnoonce();
        $req['nonce'] = $mt[1];

        // generate the POST data string
        $post_data = http_build_query($req, '', '&');

        // Generate the keyed hash value to post
        $sign = hash_hmac("sha512", $post_data, $this->api_secret);

        // Add to the headers
        $headers = array(
            'Sign: '.$sign,
            'Key: '.$this->api_key,
        );

        // Create a CURL Handler for use
        $ch = null;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; effus/btce-bot; fork marinu666/PHP-btce-api');
        curl_setopt($ch, CURLOPT_URL, 'https://btc-e.com/tapi/');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        // Send API Request
        $res = curl_exec($ch);

        // Check for failure & Clean-up curl handler
        if($res === false) {
            $e = curl_error($ch);
            curl_close($ch);
            throw new BTCeAPIFailureException('Could not get reply: '.$e);
        } else {
            curl_close($ch);
        }

        // Decode the JSON
        $result = json_decode($res, true);
        // is it valid JSON?
        if(!$result) {
            throw new BTCeAPIInvalidJSONException('Invalid data received, please make sure connection is working and requested API exists');
        }

        // Recover from an incorrect noonce
        if(isset($result['error']) === true) {
            if(strpos($result['error'], 'nonce') > -1 && $this->RETRY_FLAG === false) {
                $matches = array();
                $k = preg_match('/:([0-9])+,/', $result['error'], $matches);
                $this->RETRY_FLAG = true;
                trigger_error("Nonce we sent ({$this->noonce}) is invalid, retrying request with server returned nonce: ({$matches[1]})!");
                $this->noonce = $matches[1];
                return $this->apiQuery($method, $req);
            } else {
                throw new BTCeAPIErrorException('API Error Message: '.$result['error'].". Response: ".print_r($result, true));
            }
        }
        // Cool -> Return
        $this->RETRY_FLAG = false;
        return $result;
    }

    /**
     * Retrieve some JSON
     * @param type $URL
     * @return type
     */
    protected function retrieveJSON($URL) {
        $opts = array('http' =>
            array(
                'method'  => 'GET',
                'timeout' => 10
            )
        );
        $context  = stream_context_create($opts);
        $feed = file_get_contents($URL, false, $context);
        $json = json_decode($feed, true);
        return $json;
    }

    /**
     * @param $amount
     * @param $pair
     * @param $direction
     * @param $price
     * @return type
     * @throws BTCeAPIInvalidParameterException
     */
    public function makeOrder($amount, $pair, $direction, $price) {
        echo "Api:makeOrder >> amount($amount), pair($pair), direction($direction), price($price)".PHP_EOL;
        if($direction == self::DIRECTION_BUY || $direction == self::DIRECTION_SELL) {
            $data = $this->apiQuery("Trade"
                ,array(
                    'pair' => $pair,
                    'type' => $direction,
                    'rate' => $price,
                    'amount' => $amount
                )
            );
            return $data;
        } else {
            throw new BTCeAPIInvalidParameterException('Expected constant from '.__CLASS__.'::DIRECTION_BUY or '.__CLASS__.'::DIRECTION_SELL. Found: '.$direction);
        }
    }

    /**
     * @param $orderId
     * @param $pair
     * @return mixed
     * @throws BTCeAPIErrorException
     */
    public function checkPastOrder($orderId,$pair) {
        $data1 = $this->apiQuery("OrderList"
            ,array(
                'from_id' => $orderId,
                'to_id' => $orderId,
                'active' => 0
            ));
        $data2 = $this->apiQuery("ActiveOrders"
            ,array(
                'pair' => $pair,
            ));
        if ($data1['success'] == "0" && $data2['success'] == "0") {
            throw new BTCeAPIErrorException("Error: ".$data1['error'].' / '.$data2['error']);
        }
        if (isset($data1['return'][$orderId])) {
            return  $data1['return'][$orderId];
        }
        if (isset($data2['return'][$orderId])) {
            return  $data2['return'][$orderId];
        }
        throw new BTCeAPIErrorException("Error: unknown orderId ".$orderId);
    }

    /**
     * Public API: Retrieve the Fee for a currency pair
     * @param string $pair
     * @return array
     */
    public function getPairFee($pair) {
        return $this->retrieveJSON($this->public_api.$pair."/fee");
    }

    /**
     * Public API: Retrieve the Ticker for a currency pair
     * @param string $pair
     * @return array
     */
    public function getPairTicker($pair) {
        return $this->retrieveJSON($this->public_api.$pair."/ticker");
    }

    /**
     * Public API: Retrieve the Trades for a currency pair
     * @param string $pair
     * @return array
     */
    public function getPairTrades($pair) {
        return $this->retrieveJSON($this->public_api.$pair."/trades");
    }

    /**
     * Public API: Retrieve the Depth for a currency pair
     * @param string $pair
     * @return array
     */
    public function getPairDepth($pair) {
        return $this->retrieveJSON($this->public_api.$pair."/depth");
    }

    /**
     * @param $orderId
     * @throws BTCeAPIErrorException
     */
    public function getOrderFromHistory($orderId) {
        $data = $this->apiQuery("TradeHistory"
            ,array(
                'from_id' => $orderId,
                'end_id' => $orderId,
            ));
        if ($data['success'] == "0") {
            throw new BTCeAPIErrorException("Error: ".$data['error']);
        }
        log_msg('getOrderFromHistory >> result: '.print_r($data,true));
        return $data['return'][$orderId];
    }

    /**
     * @param $orderId
     * @return bool
     * @throws BTCeAPIErrorException
     */
    public function cancelOrder($orderId) {
        $data = $this->apiQuery("CancelOrder"
            ,array(
                'order_id' => $orderId
            ));
        if ($data['success'] == "0") {
            throw new BTCeAPIErrorException("Error: ".$data['error']);
        }
        return true;
    }
}



