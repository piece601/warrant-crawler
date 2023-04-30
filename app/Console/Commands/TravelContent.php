<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Http;
use Illuminate\Console\Command;

class TravelContent extends Command
{
    protected $token =
        '';
    protected $userAgent =
        'Mozilla/5.0 (iPhone; CPU iPhone OS 16_4_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Html5Plus/1.0 (Immersed/44) uni-app';
    protected $longitude = '';
    protected $latitude = '';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'travel {more?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $list = $this->getAwardList();
        $this->markAwardList($list);
    }

    protected function getAwardList()
    {
        return Http::withHeaders([
            'User-Agent' => $this->userAgent,
            'accessToken' => 'Bearer ' . $this->token
        ])
        ->accept('application/json')
        ->get('https://tcapp.tripellet.com/api/app/customer/award_activity?minLongitude=137.3872919082642&maxLongitude=143.3872919082642&minLatitude=32.77325759103725&maxLatitude=38.77325759103725')
        ->json()['data']['list'];
    }

    protected function markAwardList(array $list)
    {
        foreach ($list as $award) {
            if ($moreId = $this->argument('more')) {
                if ($award['id'] <= $moreId) {
                    continue;
                }
            }

            $response = $this->markAward(
                $award['longitude'],
                $award['latitude'],
                $award['id']
            );

            if ($response['code'] != 0) {
                continue;
            }

            printf('AwardId: %s, ActivityName: %s, Points: %s%s', $award['id'], $award['activityName'], $award['points'], PHP_EOL);
            usleep(rand(200000, 1000000));
        }
    }

    protected function markAward(string $longitude, string $latitude, string $awardId)
    {
        return Http::withHeaders([
            'User-Agent' => $this->userAgent,
            'accessToken' => 'Bearer ' . $this->token
        ])
        ->accept('application/json')
        ->post('https://tcapp.tripellet.com/api/app/customer/award_activity/mark', [
            "awardActivityId" => $awardId,
            "latitude" => $latitude,
            "longitude" => $longitude,
        ])->json();
    }
}
