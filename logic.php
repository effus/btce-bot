<?php
/**
 * User: effus
 * Date: 02.12.13
 * Time: 12:24
 * logic.php
 */

/**
 * Class Coin
 */
class Coin {

    const CODE_USD = 'usd';
    const CODE_RUR = 'rur';
    const CODE_EUR = 'eur';
    const CODE_BTC = 'btc';
    const CODE_LTC = 'ltc';
    const CODE_NMC = 'nmc';
    const CODE_NVC = 'nvc';
    const CODE_TRC = 'trc';
    const CODE_PPC = 'ppc';
    const CODE_FTC = 'ftc';
    const CODE_XPM = 'xpm';

    public $code;
    public $amount;
    public $active=false;

    function __construct($code,$amount=0) {
        $this->code = $code;
        $this->amount = $amount;
        if ($this->amount > 0) {
            $this->active = true;
        }
    }
    function __toString() {
        return $this->code;
    }

    static function getPairKeys($code) {
        $keys = array(
            self::CODE_BTC  => array(
                self::CODE_BTC.'_'.self::CODE_RUR,
                self::CODE_BTC.'_'.self::CODE_USD,
                self::CODE_BTC.'_'.self::CODE_EUR,
                self::CODE_LTC.'_'.self::CODE_BTC,
                self::CODE_LTC.'_'.self::CODE_USD,
                self::CODE_LTC.'_'.self::CODE_RUR,
                self::CODE_LTC.'_'.self::CODE_EUR,
                self::CODE_NMC.'_'.self::CODE_BTC,
                self::CODE_NMC.'_'.self::CODE_USD,
                self::CODE_NVC.'_'.self::CODE_BTC,
                self::CODE_NVC.'_'.self::CODE_USD,
                self::CODE_USD.'_'.self::CODE_RUR,
                self::CODE_EUR.'_'.self::CODE_USD,
                self::CODE_TRC.'_'.self::CODE_BTC,
                self::CODE_PPC.'_'.self::CODE_BTC,
                self::CODE_PPC.'_'.self::CODE_USD,
                self::CODE_FTC.'_'.self::CODE_BTC,
                self::CODE_XPM.'_'.self::CODE_BTC
            )
        );
        if (isset($keys[$code])) {
            return $keys[$code];
        }
    }

    /**
     * @return string
     */
    public function infoString() {
        return $this->code.' '.$this->amount.' ['.(int)$this->active.']';
    }
}

/**
 * Class Pair
 */
class Pair {
    public $coin_a;
    public $coin_b;
    public $time;
    public $updated;
    public $fee;
    public $enabled;
    public $high;
    public $low;
    public $avg;
    public $vol;
    public $vol_cur;
    public $last;
    public $buy;
    public $sell;
    public $tradeList;
    public $depthList;

    /**
     * @param $ca
     * @param $cb
     */
    function __construct($ca,$cb) {
        $this->coin_a = new Coin($ca);
        $this->coin_b = new Coin($cb);
        $this->code = $this->getPairName();
        $this->time = time();
        $this->fee = 0;
        $this->enabled = true;
        $this->tradeList = array();
        $this->depthList = array();
        $this->refreshRequired = true;
    }

    /**
     * @return string
     */
    private function getPairName() {
        return $this->coin_a.'_'.$this->coin_b;
    }
}

/**
 * Class TradePairs
 */
class TradePairs {

    private $api; /** @var $api BTCeAPI */

    private $pairEnable = array(
        array(Coin::CODE_BTC,Coin::CODE_USD),
        array(Coin::CODE_BTC,Coin::CODE_RUR),
        array(Coin::CODE_BTC,Coin::CODE_EUR),
        array(Coin::CODE_LTC,Coin::CODE_BTC),
        array(Coin::CODE_LTC,Coin::CODE_USD),
        array(Coin::CODE_LTC,Coin::CODE_RUR),
        array(Coin::CODE_LTC,Coin::CODE_EUR),
        array(Coin::CODE_NMC,Coin::CODE_BTC),
        array(Coin::CODE_NMC,Coin::CODE_USD),
        array(Coin::CODE_NVC,Coin::CODE_BTC),
        array(Coin::CODE_NVC,Coin::CODE_USD),
        array(Coin::CODE_USD,Coin::CODE_RUR),
        array(Coin::CODE_TRC,Coin::CODE_BTC),
        array(Coin::CODE_PPC,Coin::CODE_BTC),
        array(Coin::CODE_PPC,Coin::CODE_USD),
        array(Coin::CODE_FTC,Coin::CODE_BTC),
        array(Coin::CODE_XPM,Coin::CODE_BTC),
    );

    public $list = array();
    public $prev = array();

    public $updated = 0; // not exported
    public $preflife = 0;

    /**
     * @param array $storagePairs
     */
    function __construct($storagePairs=array()) {
        foreach($this->pairEnable as $item) {
            $pair = new Pair($item[0],$item[1]);
            $this->list[$pair->code] = $pair;
        }
        if (count($storagePairs)) {
            $this->import($storagePairs);
        }
    }

    /**
     * @return string
     */
    public function __toString() {
        $out = 'TradePairs /updated:'.date('Y-m-d H:i:s',$this->updated).'/'.PHP_EOL;
        foreach($this->list as $pairKey => $pair) {
            /** @var Pair $pair */
            $out .= $pairKey.': sell['.$pair->sell.'] buy['.$pair->buy.'] up['.date('Ymd H:i:s',$pair->updated).'] / refresh'.(int)$pair->refreshRequired.'/'.PHP_EOL;
        }
        return $out;
    }

    /**
     * @return array
     */
    public function export() {
        $data = array();
        foreach($this->list as $pairKey => $pair) {
            $data[$pairKey] = array(
                'coin_a'    => (string)$pair->coin_a,
                'coin_b'    => (string)$pair->coin_b,
                'time'      => $pair->time,
                'fee'       => $pair->fee,
                'enabled'   => $pair->enabled,
                'high'      => $pair->high,
                'low'       => $pair->low,
                'avg'       => $pair->avg,
                'vol'       => $pair->vol,
                'vol_cur'   => $pair->vol_cur,
                'last'      => $pair->last,
                'buy'       => $pair->buy,
                'sell'      => $pair->sell,
                'updated'   => $pair->updated
            );
        }
        $data['updated'] = $this->updated;
        return $data;
    }

    /**
     * @param array $data
     * @return bool
     */
    public function import($data=array()) {
        foreach($data as $pairKey => $pair) {
            if ($pairKey == 'updated') {
                $this->updated = $pair;
                continue;
            }
            if (isset($this->list[$pairKey])) {
                $this->list[$pairKey] = new Pair($pair['coin_a'],$pair['coin_b']);
                $obj = &$this->list[$pairKey];
                /** @var Pair $obj */
                $obj->time      = $pair['time'];
                $obj->fee       = $pair['fee'];
                $obj->enabled   = $pair['enabled'];
                $obj->high      = $pair['high'];
                $obj->low       = $pair['low'];
                $obj->avg       = $pair['avg'];
                $obj->vol       = $pair['vol'];
                $obj->vol_cur   = $pair['vol_cur'];
                $obj->last      = $pair['last'];
                $obj->buy       = $pair['buy'];
                $obj->sell      = $pair['sell'];
                $obj->updated   = $pair['updated'];
                $obj->refreshRequired = true;
            }
        }
        return true;
    }

    /**
     * @param BTCeAPI $api
     * @return bool
     */
    public function load(BTCeAPI &$api) {
        $this->api = $api;
        foreach($this->list as $pairKey => $pair) {
            /** @var Pair $pair */
            if ($pair->refreshRequired) {
                $start_time = microtime(true);
                try {
                    $this->loadFee($pairKey);
                    $this->loadTicker($pairKey);
                } catch (BtceLogicException $e) {
                    $this->list[$pairKey]->enabled = false;
                    log_msg('TradePairs:load >> error:'.$e->getMessage());
                }
                $end_time = microtime(true);
                log_msg('TradePairs:load >> '.$pairKey.'; time:'.(number_format($end_time-$start_time,4)) );
            }
        }
        $this->updated = time();
        return true;
    }

    /**
     * @param $pairCode
     * @throws BtceLogicException
     */
    private function loadFee($pairCode) {
        if (isset($this->list[$pairCode])) {
            if ($this->list[$pairCode]->enabled) {
                $data = $this->api->getPairFee($pairCode);
                if (isset($data['trade'])) {
                    $this->list[$pairCode]->fee = $data['trade'];
                } else {
                    throw new BtceLogicException(BtceLogicException::$messages[BtceLogicException::BAD_FEE],BtceLogicException::BAD_FEE);
                }
            }
        }
    }

    /**
     * @param $pairCode
     * @throws BtceLogicException
     */
    private function loadTicker($pairCode) {
        if (isset($this->list[$pairCode])) {
            if ($this->list[$pairCode]->enabled) {
                $data = $this->api->getPairTicker($pairCode);
                if (isset($data['ticker'])) {
                    $ticker = $data['ticker'];
                    $this->list[$pairCode]->high = $ticker['high'];
                    $this->list[$pairCode]->low = $ticker['low'];
                    $this->list[$pairCode]->avg = $ticker['avg'];
                    $this->list[$pairCode]->vol = $ticker['vol'];
                    $this->list[$pairCode]->vol_cur = $ticker['vol_cur'];
                    $this->list[$pairCode]->last = $ticker['last'];
                    $this->list[$pairCode]->buy = $ticker['buy'];
                    $this->list[$pairCode]->sell = $ticker['sell'];
                    $this->list[$pairCode]->updated = $ticker['updated'];
                } else {
                    throw new BtceLogicException(BtceLogicException::$messages[BtceLogicException::BAD_TICKER],BtceLogicException::BAD_TICKER);
                }
            }
        }
    }

    /**
     * @param $pairCode
     */
    public function loadTrades($pairCode) {
        if (isset($this->list[$pairCode])) {
            if ($this->list[$pairCode]->enabled) {
                $data = $this->api->getPairTrades($pairCode);
                $this->list[$pairCode]->tradeList = $data;
            }
        }
    }

    /**
     * @param $pairCode
     */
    public function loadPairDepth($pairCode) {
        if (isset($this->list[$pairCode])) {
            if ($this->list[$pairCode]->enabled) {
                $data = $this->api->getPairDepth($pairCode);
                $this->list[$pairCode]->depthList = $data;
            }
        }
    }

    public function setPrevPair() {
        $this->prev = array();
        foreach($this->list as $key=>$item) {
            $this->prev[$key] = clone $item;
        }
        $this->preflife = $this->updated;
    }
}


/**
 * Class StorageFunds
 */
class Funds {
    public $usd;
    public $btc;
    public $ltc;
    public $nmc;
    public $rur;
    public $eur;
    public $nvc;
    public $trc;
    public $ppc;
    public $ftc;
    public $xpm;
    public $updated = 0;

    public $operationCoin; /** @var $operationCoin Coin */

    /**
     * @param $dataArr
     */
    function __construct($dataArr) {
        $this->import($dataArr);
    }

    /**
     * @param $dataArr
     * @return bool
     */
    public function import($dataArr) {
        $this->extract($dataArr,Coin::CODE_USD);
        $this->extract($dataArr,Coin::CODE_BTC);
        $this->extract($dataArr,Coin::CODE_LTC);
        $this->extract($dataArr,Coin::CODE_NMC);
        $this->extract($dataArr,Coin::CODE_RUR);
        $this->extract($dataArr,Coin::CODE_EUR);
        $this->extract($dataArr,Coin::CODE_NVC);
        $this->extract($dataArr,Coin::CODE_TRC);
        $this->extract($dataArr,Coin::CODE_PPC);
        $this->extract($dataArr,Coin::CODE_FTC);
        $this->extract($dataArr,Coin::CODE_XPM);
        if (isset($dataArr['updated']))
            $this->updated = $dataArr['updated'];
        else
            $this->updated = 0;
        if (isset($dataArr['opercoin'])) {
            $code = $dataArr['opercoin'];
            $coin = $this->$code;
            /** @var Coin $coin */
            $this->operationCoin = new Coin($dataArr['opercoin'],$coin->amount);
        }
        return true;
    }

    /**
     * @param $arr
     * @param $code
     * @return bool
     */
    private function extract(&$arr,$code) {
        if (isset($arr[$code])) {
            $this->$code = new Coin($code,$arr[$code]);
            return true;
        }
    }

    /**
     * @return array
     */
    public function export() {
        return array(
            Coin::CODE_USD  => $this->usd,
            Coin::CODE_BTC  => $this->btc,
            Coin::CODE_LTC  => $this->ltc,
            Coin::CODE_NMC  => $this->nmc,
            Coin::CODE_RUR  => $this->rur,
            Coin::CODE_EUR  => $this->eur,
            Coin::CODE_NVC  => $this->nvc,
            Coin::CODE_TRC  => $this->trc,
            Coin::CODE_PPC  => $this->ppc,
            Coin::CODE_FTC  => $this->ftc,
            Coin::CODE_XPM  => $this->xpm,
            'updated'       => $this->updated,
            'opercoin'      => $this->operationCoin->code,
        );
    }

    /**
     * @param BTCeAPI $api
     * @return mixed
     * @throws BTCeAPIException
     */
    public function load(BTCeAPI &$api) {
        $qRes = $api->apiQuery('getInfo');
        if (isset($qRes['return']) && isset($qRes['return']['funds'])) {
            log_msg("Connection success, server time: ".date('Y.m.d H:i:s',$qRes['return']['server_time']));
            $qRes['return']['updated'] = time();
            $this->import($qRes['return']['funds']);
        } else {
            throw new BTCeAPIException(StorageException::$messages[StorageException::NO_DATA_IN_RESULT]);
        }
        return true;
    }
}


/**
 * Class Loader
 */
class Loader {

    /**
     * @return Storage
     */
    static function storage() {
        global $argv;
        try {
            $storage = new Storage($argv[1]);
        } catch (StorageException $e) {
            if ($e->getCode() == StorageException::DATA_FILE_NOT_FOUND) {
                log_msg('Loader::storage >> no json file');
                // User input
                echo "Put your key:".PHP_EOL;
                $handle = fopen ("php://stdin","r");
                $line = fgets($handle);
                $_input_key = trim($line);
                fclose($handle);
                echo "Put your secret:".PHP_EOL;
                $handle = fopen ("php://stdin","r");
                $line = fgets($handle);
                $_input_secret = trim($line);
                fclose($handle);
                $storage = Storage::create($argv[1],$_input_key,$_input_secret);
                if (!$storage)
                    log_msg('Access denied to create file storage',true);
            } else {
                log_msg($e->getMessage(),true);
            }
        }
        return $storage;
    }

    /**
     * @param $key
     * @param $secret
     * @return BTCeAPI
     */
    static function api($key,$secret) {
        try {
            $api = new BTCeAPI($key,$secret);
            return $api;
        } catch (BtceLibException $e) {
            log_msg('Connection failed: '.$e->getMessage(),true);
        }

    }
}



/**
 * Class StrategyConf
 */
class StrategyConf {

    const SELL = 'sell';
    const BUY  = 'buy';
    const WAIT = 'wait';

    public $baseCoin; /** @var Coin $baseCoin */
    public $expire_fund;
    public $expire_pairs;
    public $expire_pairs_life;
    public $min_fund_amount;
    public $diff_sell;
    public $diff_buy;
    public $capture_count_sell;
    public $capture_count_buy;


    /**
     * @return array
     */
    public function export() {
        $out = array(
            'baseCoin'  => $this->baseCoin->code
        );
        return $out;
    }
}


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
    public function init($params = array()) {
        $this->strategy->baseCoin = $params['baseCoin'];
        $this->strategy->expire_fund = $params['expire_fund'];
        $this->strategy->expire_pairs = $params['expire_pairs'];
        $this->strategy->expire_pairs_life = $params['expire_pairs_life'];
        $this->strategy->min_fund_amount = $params['min_fund_amount'];
        $this->strategy->diff_sell = $params['diffs_sell'];
        $this->strategy->diff_buy = $params['diffs_buy'];
        $this->strategy->capture_count_sell = $params['capture_count']['sell'];
        $this->strategy->capture_count_buy = $params['capture_count']['buy'];
        $this->funds->operationCoin = new Coin((string)$this->strategy->baseCoin, $this->strategy->baseCoin->amount - $this->strategy->min_fund_amount);
    }

    public function run() {
        try {
            while(true) {
                $pairAllowCompare = false;
                $baseCoinCode = (string)$this->strategy->baseCoin;
                $baseFund = $this->funds->$baseCoinCode;
                $operationAmount = $this->funds->operationCoin->amount;
                if (!$baseFund || $this->funds->updated + $this->strategy->expire_fund < time()) {
                    $this->funds->load($this->api);
                    $this->storage->data->funds = $this->funds->export();
                    $this->storage->save();
                }
                $baseCoinFund = $this->funds->$baseCoinCode;
                /** @var Coin $baseCoinFund */
                if ($baseCoinFund->amount <= 0) {
                    throw new BtceLogicException('empty base coin funds', BtceLogicException::EMPTY_FUNDS);
                }
                if ($baseCoinFund->amount <= $this->strategy->min_fund_amount) {
                    throw new BtceLogicException('minimal base coin funds', BtceLogicException::EMPTY_FUNDS);
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
                } else {
                    $this->funds->operationCoin->active = true;
                }

                if ($this->pairs->updated + $this->strategy->expire_pairs < time()) {
                    log_msg('pairs expired, refresh...');
                    $this->pairs->setPrevPair();
                    $this->pairs->load($this->api);
                    $this->storage->data->pairs = $this->pairs->export();
                    $this->storage->save();
                    if ($this->pairs->preflife + $this->strategy->expire_pairs_life > time()) {
                        $pairAllowCompare = true;
                    }
                }
                if ($pairAllowCompare) {
                    log_msg('apply strategy ...');
                    log_msg("-------------------");
                    log_msg("Base coin\t".$this->strategy->baseCoin);
                    $BaseCoinCode = (string)$this->strategy->baseCoin;
                    $lookPairs = Coin::getPairKeys($BaseCoinCode);

                    if ($lookPairs) {

                        log_msg(sprintf('Operation amount: %f',$operationAmount));
                        foreach($lookPairs as $_pair_code) {
                            if (isset($this->pairs->list[$_pair_code]) && $this->pairs->prev[$_pair_code]) {
                                $pair = &$this->pairs->list[$_pair_code];/** @var Pair $pair */
                                $pairPrev = &$this->pairs->prev[$_pair_code]; /** @var Pair $pairPrev */
                                log_msg("----------- Pair: $_pair_code ---------------");

                                if (!isset($this->weights[$_pair_code])) {
                                    $this->weights[$_pair_code] = array(
                                        'sell'  => 0,
                                        'buy'   => 0
                                    );
                                }

                                $diff = 0;
                                if ($pair->coin_a->code == $BaseCoinCode) {
                                    $lookAt = StrategyConf::SELL; // look at sell prices (we need they increases)
                                    log_msg("Look at:\t".$lookAt);
                                    $diff = $this->getDiff($pair->sell,$pairPrev->sell);

                                    log_msg("Buy    was                 now                 diff          order         ");
                                    log_msg("       ".
                                        str_pad('1 '.$pairPrev->coin_a->code.' =',20,' ',STR_PAD_RIGHT).
                                        str_pad('1 '.$pair->coin_a->code.' =',20,' ',STR_PAD_RIGHT).
                                        str_pad('',14,' ',STR_PAD_RIGHT).
                                        str_pad($operationAmount.' '.$pairPrev->coin_a->code.' =',14,' ',STR_PAD_RIGHT)
                                    );
                                    log_msg("       ".
                                        str_pad(sprintf("%f",$pairPrev->sell).' '.$pairPrev->coin_b->code,20,' ',STR_PAD_RIGHT).
                                        str_pad(sprintf("%f",$pairPrev->sell).' '.$pair->coin_b->code,20,' ',STR_PAD_RIGHT).
                                        str_pad($diff.' '.$pair->coin_b->code,14,' ',STR_PAD_RIGHT).
                                        str_pad($this->getOrderResult($operationAmount,$pair->sell,$pair->fee).' '.$pairPrev->coin_b->code,14,' ',STR_PAD_RIGHT)
                                    );

                                    if (!isset($this->strategy->diff_sell[$_pair_code])) {
                                        log_msg('no sell strategy for pair: '.$_pair_code);
                                        continue;
                                    }
                                    log_msg(sprintf("Strategy diff:\t%f / %f",$this->strategy->diff_sell[$_pair_code],$diff));

                                } else if ($pair->coin_b->code == $BaseCoinCode) {
                                    $lookAt = StrategyConf::BUY; // look at buy prices (we need they decreases)
                                    log_msg("Look at:\t".$lookAt);
                                    $diff = $this->getDiff($pair->buy,$pairPrev->buy);

                                    log_msg("Sell   was                 now                 diff          order         ");
                                    log_msg("       ".
                                        str_pad('1 '.$pairPrev->coin_a->code.' =',20,' ',STR_PAD_RIGHT).
                                        str_pad('1 '.$pair->coin_a->code.' =',20,' ',STR_PAD_RIGHT).
                                        str_pad('',14,' ',STR_PAD_RIGHT).
                                        str_pad($operationAmount.' '.$pairPrev->coin_a->code.' =',14,' ',STR_PAD_RIGHT)
                                    );
                                    log_msg("       ".
                                        str_pad(sprintf("%f",$pairPrev->buy).' '.$pairPrev->coin_b->code,20,' ',STR_PAD_RIGHT).
                                        str_pad(sprintf("%f",$pairPrev->buy).' '.$pair->coin_b->code,20,' ',STR_PAD_RIGHT).
                                        str_pad($diff.' '.$pair->coin_b->code,14,' ',STR_PAD_RIGHT).
                                        str_pad($this->getOrderResult($operationAmount,$pair->buy,$pair->fee).' '.$pairPrev->coin_b->code,14,' ',STR_PAD_RIGHT)
                                    );

                                    if (!isset($this->strategy->diff_buy[$_pair_code])) {
                                        log_msg('no buy strategy for pair: '.$_pair_code);
                                        continue;
                                    }
                                    log_msg(sprintf("Strategy diff:\t%f / %f",$this->strategy->diff_buy[$_pair_code],$diff));

                                } else {
                                    $this->pairs->list[$_pair_code]->refreshRequired = false;
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
                                            // make order operation
                                            $orderResult = $this->getOrderResult($operationAmount,$pair->sell,$pair->fee);
                                            log_msg('[MAKE ORDER] sell:'.$operationAmount.' with price:'.$pair->sell.', fee:'.$pair->fee.', result:'.$orderResult);
                                            $this->weights[$_pair_code]['sell'] = $this->strategy->capture_count_sell+1;
                                            $operationCode = $pair->coin_b->code;
                                            $opFund = $this->funds->$operationCode;
                                            /** @var Coin $opFund */
                                            $this->funds->operationCoin = new Coin($operationCode,$opFund->amount);
                                            $doOrderOperations = true;
                                            log_msg('operation coin is: '.$this->funds->operationCoin->infoString());
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
                                            // make order operation
                                            $orderResult = $this->getOrderResult($operationAmount,$pair->buy,$pair->fee);
                                            log_msg('[MAKE ORDER] buy:'.$operationAmount.' with price:'.$pair->buy.', fee:'.$pair->fee.', result:'.$orderResult);
                                            $this->weights[$_pair_code]['buy'] = $this->strategy->capture_count_buy+1;
                                            $operationCode = $pair->coin_a->code;
                                            $opFund = $this->funds->$operationCode;
                                            /** @var Coin $opFund */
                                            $this->funds->operationCoin = new Coin($operationCode,$opFund->amount);
                                            $doOrderOperations = true;
                                            log_msg('operation coin is: '.$this->funds->operationCoin->infoString());
                                        }
                                    }

                                }

                                if ($doOrderOperations) {
                                    $this->storage->data->pairs = $this->pairs->export();
                                    $this->storage->data->funds = $this->funds->export();
                                    $this->storage->save();
                                }
                            } else {
                                log_msg("fail to load pair: ".$_pair_code);
                            }
                        }
                    }
                }
                log_msg('waiting 20 seconds...');
                sleep(20);
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
    private function getOrderResult($amount,$price,$fee) {
        $val = $amount * $price;
        $fee = $val * $fee * 0.01;
        return $val - $fee;
    }
}