<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CrawlWarrant extends Command
{
    const URL = 'https://www.warrantwin.com.tw/eyuanta/ws/GetWarData.ashx';
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crawl {stock} {percentage?} {period?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'crawl the warrant sort of price';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $stockNumber = $this->argument('stock');
        $pricePercentage = $this->argument('percentage') ?? '30';
        $period =  $this->argument('period') ?? '100';
        $payload = json_encode($this->getPayload($stockNumber, $pricePercentage, $period));
        $resource = Http::asForm()->post(self::URL, [
            'data' => $payload
        ]);
        echo $this->getTitle();
        $resource = $this->parserResponse($resource->body());
        foreach ($resource as $stock) {
            echo $stock['FLD_WAR_ID'] . "\t";
            echo $stock['FLD_WAR_NM'] . "\t";
            echo round($stock['actualPrice'], 2) . "\t";
            echo $stock['FLD_WAR_TXN_PRICE'] . "\t";
            echo $stock['FLD_WAR_SELL_PRICE'] . "\t";
            echo $stock['FLD_WAR_TXN_VOLUME'] . "\t";
            echo $stock['FLD_PERIOD'] . "\t";
            echo round(1/(double)$stock['FLD_N_UND_CONVER'], 1) . "\t";
            echo $stock['leverage'] . "\t";
            echo $stock['leveragePerRisk'] . "\t";
            echo $stock['leveragePerActualPrice'] . "\t";
            echo round($stock['risk'], 2) . PHP_EOL;
        }
        echo $this->getTitle();
    }

    private function getTitle()
    {
        return "#stock\tname\t總價\t成交價\t委賣價\t量\t剩餘天數\t湊一張\t倍率\t倍率風險比\t倍率總價比\t風險" . PHP_EOL;
    }

    private function parserResponse(string $data) : array
    {
        $data = json_decode($data, true);
        $data = $data['result'];
        foreach ($data as $key => &$row) {
            if ($row['FLD_OPTION_TYPE'] == '歐式') {
                unset($data[$key]);
                continue;
            }
            // 成交價
            $sellPrice = (double)$row['FLD_WAR_TXN_PRICE'];
            if (empty($sellPrice)) {
                // 委賣價
                $sellPrice = (double)$row['FLD_WAR_SELL_PRICE'];
            }

            if (empty($sellPrice)) {
                unset($data[$key]);
                continue;
            }

            $sellPrice = (double)$sellPrice;
            // 行使比例
            $rate = (double)$row['FLD_N_UND_CONVER'];
            // 當前股價
            $stockPrice = (double)$row['FLD_OBJ_TXN_PRICE'];
            // 履約價
            $agreement = (double)$row['FLD_N_STRIKE_PRC'];
            // 倍率
            $leverage = $stockPrice / ($sellPrice / $rate);
            $row['leverage'] = round($leverage, 4);
            // 實質價格
            $row['actualPrice'] = $agreement + $sellPrice / $rate;
            // 價差
            $priceDiff = $row['actualPrice'] - $stockPrice;
            // 風險 每一天所承擔的價差
            $row['risk'] = $risk = $priceDiff / $row['FLD_PERIOD'];
            // 倍率風險比
            $row['leveragePerRisk'] = round($leverage / $risk, 4);
            // 倍率總價比
            $row['leveragePerActualPrice'] = round($leverage / $row['actualPrice'], 4);
        }
        unset($row);

        usort($data, function ($prev, $next) {
            return $prev['risk'] > $next['risk'] ? -1 : 1;
        });
        return $data;
    }

    private function getPayload(
        string $stockNumber,
        string $pricePercentage = '15',
        string $period = '180'
    ) {
        return [
            'callback' => '01',
            'factor' => [
                'columns' => $this->getColumns(),
                'condition' => [
                    $this->getStockCondition($stockNumber),
                    $this->getWarType(),
                    $this->getPriceRange($pricePercentage),
                    $this->getPeriod($period)
                ],
                'orderby' => $this->getOrderBy()
            ],
            'format' => 'JSON',
            'pagination' => [
                'page' => 1,
                'row' => 999
            ]
        ];
    }

    private function getOrderBy()
    {
        return [
            'agtfirst' => '',
            'field' => 'FLD_N_STRIKE_PRC',
            'sort' => 'ASC'
        ];
    }

    private function getStockCondition(string $stockNumber)
    {
        return [
            'field' => 'FLD_UND_ID',
            'values' => [$stockNumber]
        ];
    }

    private function getWarType()
    {
        return [
            'field' => 'FLD_WAR_TYPE',
            'values' => [
                '1',
            ]
        ];
    }

    private function getPriceRange(string $percentage)
    {
        return [
            'field' => 'FLD_IN_OUT_DECIMAL',
            'left' => '-' . $percentage,
            'right' => $percentage
        ];
    }

    private function getPeriod(string $period = '180')
    {
        return [
            'field' => 'FLD_PERIOD',
            'left' => $period,
            'right' => ''
        ];
    }

    private function getColumns()
    {
        return [
            "FLD_WAR_ID",
            "FLD_WAR_NM",
            "FLD_WAR_TYPE",
            "FLD_UND_ID",
            "FLD_UND_NM",
            "FLD_OBJ_TXN_PRICE",
            "FLD_OBJ_UP_DN",
            "FLD_OBJ_UP_DN_RATE",
            "FLD_WAR_UP_DN",
            "FLD_WAR_UP_DN_RATE",
            "FLD_WAR_TXN_PRICE",
            "FLD_WAR_TXN_VOLUME",
            "FLD_WAR_TTL_VOLUME",
            "FLD_WAR_TTL_VALUE",
            "FLD_WAR_BUY_PRICE",
            "FLD_WAR_BUY_VOLUME",
            "FLD_WAR_SELL_PRICE",
            "FLD_WAR_SELL_VOLUME",
            "FLD_DUR_START",
            "FLD_LAST_TXN",
            "FLD_DUR_END",
            "FLD_OPTION_TYPE",
            "FLD_N_ISSUE_UNIT",
            "FLD_OUT_TOT_BAL_VOL",
            "FLD_OUT_VOL_RATE",
            "FLD_N_STRIKE_PRC",
            "FLD_N_UND_CONVER",
            "FLD_CHECK_PRC",
            "FLD_PERIOD",
            "FLD_IV_CLOSE_PRICE",
            "FLD_IV_BUY_PRICE",
            "FLD_IV_SELL_PRICE",
            "FLD_DELTA",
            "FLD_THETA",
            "FLD_IN_OUT",
            "FLD_LEVERAGE",
            "FLD_BUY_SELL_RATE",
            "FLD_N_LIMIT_PRC",
            "FLD_FIN_EXP",
            "FLD_FIN_EXP_RATIO",
            "FLD_PFR",
            "FLD_PFR_PCT"
        ];
    }
}
