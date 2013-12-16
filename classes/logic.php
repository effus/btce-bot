<?


/** --------------------------------------------------------------------------------------------------------------------
 * Class Logic
 */
class Logic {

    private $api /** @var BTCeAPI $api */;
    private $storage /** @var Storage $storage */;
    private $strategy /** @var StrategyConf $strategy */;
    private $pairs /** @var TradePairs $pairs */;
    private $funds; /** @var Funds $funds */
    private $weights = array();


    function __construct() {
        $this->storage = Loader::storage();
        $this->api = Loader::api($this->storage->data->key, $this->storage->data->secret);
        $this->strategy = new StrategyConf();
        $this->pairs = new TradePairs($this->storage->data->pairs);
        $this->funds = new Funds($this->storage->data->funds);
    }

    /**
     * @param array $params
     */
    public function init($params) {
        $this->strategy->baseCoin = $params['baseCoin'];
        $this->strategy->expire_fund = $params['expire_fund'];
        $this->strategy->expire_pairs = $params['expire_pairs'];
        $this->strategy->expire_pairs_life = $params['expire_pairs_life'];
        $this->strategy->min_fund_amount = $params['min_fund_amount'];
        $this->strategy->diff_sell = $params['diffs_sell'];
        $this->strategy->diff_buy = $params['diffs_buy'];
        $this->strategy->capture_count_sell = $params['capture_count']['sell'];
        $this->strategy->capture_count_buy = $params['capture_count']['buy'];
        $this->funds->operationCoin = new OperationCoin(
            (string)$this->strategy->baseCoin,
            $this->strategy->baseCoin->amount - $this->strategy->min_fund_amount,
            new Coin((string)$this->strategy->baseCoin,0)
        );
    }

    public function run() {
        try {
            while(true) {

                $this->sleepSec(20);
            }
        } catch (BtceLogicException $e) {
            log_msg('Logic exception ['.$e->getCode().'] >> '.$e->getMessage());
        }
    }


    private function getDiff($c1,$c2) {
        return  number_format(round($c1-$c2,5),5,'.','');
    }

    /**
     * @param $amount
     * @param $price
     * @param $fee
     * @return float
     */
    static function getOrderResult($amount,$price,$fee) {
        $val = $amount * $price;
        $fee = $val * $fee * 0.01;
        return $val - $fee;
    }

    /**
     * @param Pair $pair
     * @param $amount
     * @throws BTCeAPIException
     */
    private function orderSell(Pair $pair,$amount) {
        log_msg(sprintf("[MAKE SELL ORDER] amount:%f, price:%f",$amount,$pair->sell));
        $this->weights[$pair->code]['sell'] = 0;
        $operationCode = $pair->coin_b->code;
        $opFund = $this->funds->$operationCode;
        /** @var Coin $opFund */
        $this->funds->operationCoin = new OperationCoin($operationCode,$opFund->amount,new Coin($operationCode,$pair->sell));
        $order = $this->api->makeOrder($amount,(string)$pair->code,BTCeAPI::DIRECTION_SELL,$pair->sell);
        if (!isset($order['return']) && !isset($order['return']['order_id'])) {
            throw new BTCeAPIException('makeOrder bad result');
        }
        try {
            $this->expectOrder($order['return']['order_id'],60*15);
        } catch (BtceLogicException $e) {
            if ($e->getCode() == BtceLogicException::ORDER_TIMEOUT) {
                $this->api->cancelOrder($order['return']['order_id']);
            }
        }
        return;
    }


    /**
     * @param Pair $pair
     * @param $amount
     * @throws BTCeAPIException
     */
    private function orderBuy(Pair $pair,$amount) {
        log_msg(sprintf("[MAKE BUY ORDER] amount:%f, price:%f",$amount,$pair->buy));
        $this->weights[$pair->code]['buy'] = 0;
        $operationCode = $pair->coin_a->code;
        $opFund = $this->funds->$operationCode;
        /** @var Coin $opFund */
        $this->funds->operationCoin = new OperationCoin($operationCode,$opFund->amount,new Coin($operationCode,$pair->buy));
        $order = $this->api->makeOrder($amount,(string)$pair->code,BTCeAPI::DIRECTION_BUY,$pair->sell);
        if (!isset($order['return']) && !isset($order['return']['order_id'])) {
            throw new BTCeAPIException('makeOrder bad result');
        }
        try {
            $this->expectOrder($order['return']['order_id'],60*15);
        } catch (BtceLogicException $e) {
            if ($e->getCode() == BtceLogicException::ORDER_TIMEOUT) {
                $this->api->cancelOrder($order['return']['order_id']);
            }
        }

    }

    /**
     * @param $idOrder
     * @param $timeOut
     * @return bool
     * @throws BtceLogicException
     */
    private function expectOrder($idOrder,$timeOut) {
        $timeNow = time();
        $timeEnd = $timeNow + $timeOut;
        while(true) {

            log_msg('expectOrder: check order history');
            try {
                $order = $this->api->getOrderFromHistory($idOrder);
            } catch (BTCeAPIErrorException $e) {
                log_msg('expectOrder: getOrderFromHistory result error');
            }
            if (isset($order['order_id'])) {
                return true;
            }

            if (time() > $timeEnd) {
                $this->api->cancelOrder($idOrder);
                throw new BtceLogicException('Order expecting timeout',BtceLogicException::ORDER_TIMEOUT);
            }

            $this->sleepSec(30);
        }
    }

    /**
     * @param Coin $coin
     * @param      $amount
     * @return float
     * @throws BtceLogicException
     */
    private function getPriceInBaseCoin(Coin $coin, $amount) {
        $conversion = StrategyConf::NONE;
        // NEEDLE_BASE -> buy BASE,
        // BASE_NEEDLE -> sell NEEDLE
        $pairCode = $this->strategy->baseCoin->code.'_'.$coin->code;
        if (!isset($this->pairs[$pairCode])) {
            $pairCode = $coin->code.'_'.$this->strategy->baseCoin->code;
            if (!isset($this->pairs[$pairCode])) {
                if ($this->strategy->baseCoin->code == $coin->code) {
                    $conversion = StrategyConf::NONE;
                } else
                    throw new BtceLogicException('getPriceInBaseCoin >> unknown pairCode: '.$pairCode,BtceLogicException::UNKNOWN_PAIR);
            } else
                $conversion = StrategyConf::SELL;
        } else
            $conversion = StrategyConf::BUY;
        $pair = &$this->pairs[$pairCode]; /** @var Pair $pair */
        if (!$pair->enabled || $pair->updated < time()-60*20) {
            throw new BtceLogicException('getPriceInBaseCoin >> pair disabled or expired: '.$pairCode,BtceLogicException::REQUIRE_UPDATE_PRICE);
        }
        if ($conversion == StrategyConf::SELL) {
            return $amount * $pair->sell * $pair->fee * 0.01;
        } else if ($conversion == StrategyConf::BUY && $pair->buy > 0) {
            return ($amount / $pair->buy) * $pair->fee * 0.01;
        } else {
            return $amount;
        }

    }

    /**
     * @param int $sec
     */
    private function sleepSec($sec) {
        log_msg('waiting '.$sec.' seconds...');
        sleep($sec);
    }

    /**
     * @return bool
     * @throws BtceLogicException
     */
    private function allowCompare() {
        $operationAmount = $this->funds->operationCoin->amount;
        $baseCoinCode = (string)$this->strategy->baseCoin;
        $baseCoinFund = $this->funds->$baseCoinCode;
        if ($baseCoinFund->amount <= 0) {
            throw new BtceLogicException('empty base coin fund', BtceLogicException::EMPTY_BASE_FUND);
        }
        if ($baseCoinFund->amount <= $this->strategy->min_fund_amount) {
            throw new BtceLogicException('minimal base coin fund', BtceLogicException::EMPTY_BASE_FUND);
        }
        if ($baseCoinFund->code == $this->funds->operationCoin->code) {
            $this->funds->operationCoin->amount = $baseCoinFund->amount - $this->strategy->min_fund_amount;
        }
        if ($this->funds->operationCoin->amount != $operationAmount) {
            log_msg('Operation coins updated: '.$this->funds->operationCoin->infoString());
        }
        if ($this->funds->operationCoin->amount == 0) {
            log_msg('Operation amount is 0');
            $this->funds->operationCoin->active = false;
            $searchFund = $this->searchOperationFunds(); /** @var $searchFund Coin */
            if (!$searchFund) {
                log_msg('No more funds.');
                $this->sleepSec(60*15);
                throw new BtceLogicException('No more available funds',BtceLogicException::NO_AVAILABLE_FUNDS);
            } else {
                $this->changeOperationCoin($this->funds->operationCoin,$searchFund);
                throw new BtceLogicException('Refresh required',BtceLogicException::REQUIRE_UPDATE_PRICE);
            }
        } else {
            $this->funds->operationCoin->active = true;
        }

        $this->refreshPairs();

        if ($this->pairs->preflife + $this->strategy->expire_pairs_life > time()) {
            return true;
        }
    }

    /**
     * @return bool
     */
    private function refreshFunds() {
        if ($this->funds->updated + $this->strategy->expire_fund < time()) {
            $this->funds->load($this->api);
            $this->storage->data->funds = $this->funds->export();
            $this->storage->save();
        }
        return true;
    }

    /**
     * @return bool
     */
    private function refreshPairs() {
        if ($this->pairs->updated + $this->strategy->expire_pairs < time()) {
            log_msg('pairs expired, refresh...');
            $this->pairs->setPrevPair();
            $this->pairs->load($this->api);
            $this->storage->data->pairs = $this->pairs->export();
            $this->storage->save();
        }
        return true;
    }

    private function searchOperationFunds() {

    }

    private function changeOperationCoin(Coin $coinFrom, Coin $coinTo) {
        return true;
    }

    private function runWeightStrategy() {

        $this->refreshFunds();

        $baseCoinCode = (string)$this->strategy->baseCoin;

        // can we make operations?
        $pairAllowCompare = false;
        try {
            $pairAllowCompare = $this->allowCompare();
        } catch (BtceLogicException $e) {
            log_msg('compare blocked: '.$e->getMessage());
            if ($e->getCode() == BtceLogicException::REQUIRE_UPDATE_PRICE) {
                continue;
            } elseif ($e->getCode() == BtceLogicException::NO_AVAILABLE_FUNDS) {
                $this->sleepSec(60*15);
                continue;
            }
        }

        if ($pairAllowCompare) {
            log_msg("-------------------");
            log_msg("Base coin\t".$this->strategy->baseCoin);
            log_msg("Operation coin\t".$this->funds->operationCoin);
            log_msg(sprintf("Operation amount\t%f",$this->funds->operationCoin->amount));

            // for our dance we need only pairs with operation coin type
            $lookPairs = Coin::getPairKeys((string)$this->funds->operationCoin);
            if ($lookPairs) {
                foreach($lookPairs as $_pair_code) {
                    if (isset($this->pairs->list[$_pair_code]) && $this->pairs->prev[$_pair_code]) {
                        $pair = &$this->pairs->list[$_pair_code];/** @var Pair $pair */
                        $pairPrev = &$this->pairs->prev[$_pair_code]; /** @var Pair $pairPrev */

                        if (!$pair->enabled)
                            continue;

                        if (!isset($this->weights[$_pair_code])) {
                            $this->weights[$_pair_code] = array(
                                'sell'  => 0,
                                'buy'   => 0
                            );
                        }

                        $diff = 0;
                        if ($pair->coin_a->code == $baseCoinCode) {
                            $lookAt = StrategyConf::SELL; // look at sell prices (we need they increases)
                            log_msg("----------- Pair: $_pair_code / look at:\t".$lookAt);
                            $diff = $this->getDiff($pair->sell,$pairPrev->sell);

                            log_msg("Sell    was                 now                 diff          order         ");
                            log_msg("       ".
                                str_pad('1 '.$pairPrev->coin_a->code.' =',20,' ',STR_PAD_RIGHT).
                                str_pad('1 '.$pair->coin_a->code.' =',20,' ',STR_PAD_RIGHT).
                                str_pad('',14,' ',STR_PAD_RIGHT).
                                str_pad($this->funds->operationCoin->amount.' '.$pairPrev->coin_a->code.' =',14,' ',STR_PAD_RIGHT)
                            );
                            log_msg("       ".
                                str_pad(sprintf("%f",$pairPrev->sell).' '.$pairPrev->coin_b->code,20,' ',STR_PAD_RIGHT).
                                str_pad(sprintf("%f",$pair->sell).' '.$pair->coin_b->code,20,' ',STR_PAD_RIGHT).
                                str_pad($diff.' '.$pair->coin_b->code,14,' ',STR_PAD_RIGHT).
                                str_pad($this->getOrderResult($this->funds->operationCoin->amount,$pair->sell,$pair->fee).' '.$pairPrev->coin_b->code,14,' ',STR_PAD_RIGHT)
                            );

                            if (!isset($this->strategy->diff_sell[$_pair_code])) {
                                log_msg('no sell strategy for pair: '.$_pair_code);
                                $this->pairs->list[$_pair_code]->enabled = false;
                                continue;
                            }
                            log_msg(sprintf("Strategy diff:\t%f / %f",$this->strategy->diff_sell[$_pair_code],$diff));

                        } else if ($pair->coin_b->code == $baseCoinCode) {
                            $lookAt = StrategyConf::BUY; // look at buy prices (we need they decreases)
                            log_msg("----------- Pair: $_pair_code / look at:\t".$lookAt);
                            $diff = $this->getDiff($pair->buy,$pairPrev->buy);

                            log_msg("Buy   was                 now                 diff          order         ");
                            log_msg("       ".
                                str_pad('1 '.$pairPrev->coin_a->code.' =',20,' ',STR_PAD_RIGHT).
                                str_pad('1 '.$pair->coin_a->code.' =',20,' ',STR_PAD_RIGHT).
                                str_pad('',14,' ',STR_PAD_RIGHT).
                                str_pad($this->funds->operationCoin->amount.' '.$pairPrev->coin_a->code.' =',14,' ',STR_PAD_RIGHT)
                            );
                            log_msg("       ".
                                str_pad(sprintf("%f",$pairPrev->buy).' '.$pairPrev->coin_b->code,20,' ',STR_PAD_RIGHT).
                                str_pad(sprintf("%f",$pair->buy).' '.$pair->coin_b->code,20,' ',STR_PAD_RIGHT).
                                str_pad($diff.' '.$pair->coin_b->code,14,' ',STR_PAD_RIGHT).
                                str_pad($this->getOrderResult($this->funds->operationCoin->amount,$pair->buy,$pair->fee).' '.$pairPrev->coin_b->code,14,' ',STR_PAD_RIGHT)
                            );

                            if (!isset($this->strategy->diff_buy[$_pair_code])) {
                                log_msg('no buy strategy for pair: '.$_pair_code);
                                $this->pairs->list[$_pair_code]->enabled = false;
                                continue;
                            }
                            log_msg(sprintf("Strategy diff:\t%f / %f",$this->strategy->diff_buy[$_pair_code],$diff));

                        } else {
                            $this->pairs->list[$_pair_code]->refreshRequired = false;
                            $this->pairs->list[$_pair_code]->enabled = false;
                            continue;
                        }

                        $doOrderOperations = false;
                        $this->pairs->list[$_pair_code]->refreshRequired = true;

                        if ($lookAt == StrategyConf::SELL) {
                            if ($this->weights[$_pair_code]['sell'] == $this->strategy->capture_count_sell+1) {
                                log_msg('Sell weight: MAX');
                            } else {
                                if ($diff > $this->strategy->diff_sell[$_pair_code]) {
                                    log_msg('[CAPTURE]');
                                    log_msg('Sell diff: was ['.$pairPrev->sell.'], now ['.$pair->sell.'], diff = '.$diff);
                                    $this->weights[$_pair_code]['sell']++;
                                } else if ($diff < 0 && $this->weights[$_pair_code]['sell'] > 0) {
                                    $this->weights[$_pair_code]['sell']--;
                                }
                                log_msg('Sell weight: '.$this->weights[$_pair_code]['sell']);
                                if ($this->weights[$_pair_code]['sell'] == $this->strategy->capture_count_sell) {
                                    // make order operation SELL
                                    $orderResult = $this->getOrderResult($this->funds->operationCoin->amount,$pair->sell,$pair->fee);
                                    log_msg('[MAKE ORDER] sell:'.$this->funds->operationCoin->amount.' with price:'.$pair->sell.', fee:'.$pair->fee.', result:'.$orderResult);
                                    try {
                                        $this->orderSell($pair,$this->funds->operationCoin->amount);
                                    } catch (BtceLibException $e) {
                                    }

                                    $doOrderOperations = true;
                                }
                            }

                        } elseif ($lookAt == StrategyConf::BUY) {
                            if ($this->weights[$_pair_code]['buy'] == $this->strategy->capture_count_buy+1) {
                                log_msg('Buy weight: MAX');
                            } else {
                                if ( $diff*-1 > $this->strategy->diff_buy[$_pair_code]) {
                                    log_msg('[CAPTURE]');
                                    log_msg('Buy diff: was ['.$pairPrev->buy.'], now ['.$pair->buy.'], diff = '.$diff);
                                    $this->weights[$_pair_code]['buy']++;
                                } else if ($diff > 0 && $this->weights[$_pair_code]['buy'] > 0) {
                                    $this->weights[$_pair_code]['buy']--;
                                }
                                log_msg('Buy weight: '.$this->weights[$_pair_code]['buy']);
                                if ($this->weights[$_pair_code]['buy'] == $this->strategy->capture_count_buy) {
                                    // make order operation BUY
                                    $this->orderBuy($pair,$this->funds->operationCoin->amount);
                                    $doOrderOperations = true;
                                }
                            }

                        }

                        if ($doOrderOperations) {
                            foreach($this->pairs->list[$pair] as $_pair) {
                                $_pair->refreshRequired = true;
                            }
                            $this->storage->data->pairs = $this->pairs->export();
                            $this->storage->data->funds = $this->funds->export();
                            $this->storage->save();
                            log_msg('operation coin is: '.$this->funds->operationCoin->infoString());
                        }
                    } else {
                        log_msg("fail to load pair: ".$_pair_code);
                    }
                }
            }
        }
    }

    private function runVectorStrategy() {
        /**
         * 1) last 12 orders or 18 minutes
         * 2) 6 parts of list
         * 3) calculate sum of sells and buys, count of sell and buy orders for each part
         * 4) compare with rules
         * 5) define vector type: DNO, PIK, SPAD, POJDEM, STORM, SILENCE
         * 6) DNO -> buy, PIK -> sell, other -> wait
         */
    }
}