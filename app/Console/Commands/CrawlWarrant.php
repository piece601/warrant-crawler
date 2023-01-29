<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutput;

class CrawlWarrant extends Command
{
    const URL = 'https://www.warrantwin.com.tw/eyuanta/ws/GetWarData.ashx';
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crawl {stock?} {period?} {percentage?}';

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
        $stockNumber = $this->argument('stock') ?? "\$TWT";
        $pricePercentage = $this->argument('percentage') ?? '100';
        $period =  $this->argument('period') ?? '100';
        $payload = json_encode($this->getPayload($stockNumber, $pricePercentage, $period));
        $resource = Http::asForm()->post(self::URL, [
            'data' => $payload
        ]);
        $table = new Table(new ConsoleOutput());
        $table->setHeaders([
            '類型',
            'stock',
            'name',
            '總價',
            '成交價',
            '委賣價',
            '量',
            '剩餘天數',
            '湊一張',
            '風險',
            '張價',
            '成槓',
            '實槓',
        ]);

        $resource = $this->parserResponse($resource->body());
        foreach ($resource as $stock) {

            // if ($stock['FLD_LEVERAGE'] < 7) {
            //     continue;
            // }
            // 履約價 > 股價 1.1 倍
            // if ((double)$stock['FLD_N_STRIKE_PRC'] > (double)$stock['FLD_OBJ_TXN_PRICE'] * 1.2) {
            //     continue;
            // }
            // 總價
            $actualPrice = round($stock['actualPrice'], 2);

            $table->addRow([
                $stock['FLD_OPTION_TYPE'],
                $stock['FLD_WAR_ID'],
                $stock['FLD_WAR_NM'],
                $actualPrice,
                $stock['FLD_WAR_TXN_PRICE'],
                $stock['FLD_WAR_SELL_PRICE'],
                $stock['FLD_WAR_TXN_VOLUME'],
                $stock['FLD_PERIOD'],
                round(1/(double)$stock['FLD_N_UND_CONVER'], 1),
                round($stock['risk'], 2),
                $stock['ticketPrice'],
                $stock['leverage'],
                $stock['FLD_LEVERAGE'],
            ]);
        }

        $table->render();
    }

    private function parserResponse(string $data) : array
    {
        $data = json_decode($data, true);
        $data = $data['result'];
        foreach ($data as $key => &$row) {
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

            $row['ticketPrice'] = round($sellPrice / $row['FLD_N_UND_CONVER']);
        }
        unset($row);

        usort($data, function ($prev, $next) {
            // return $prev['FLD_LEVERAGE'] > $next['FLD_LEVERAGE'] ? 1 : -1;
            return abs(1 - $prev['FLD_LEVERAGE'] / $prev['leverage']) > abs(1 - $next['FLD_LEVERAGE'] / $next['leverage']) ? -1 : 1;
            // return $prev['actualPrice'] > $next['actualPrice'] ? -1 : 1;
        });
        return $data;
    }

    private function getPayload(
        string $stockNumber,
        string $pricePercentage,
        string $period
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
