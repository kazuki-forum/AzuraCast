<?php

namespace Functional;

use Codeception\Util\Shared\Asserts;

class Api_Stations_ReportsCest extends CestAbstract
{
    use Asserts;

    /**
     * @before setupComplete
     * @before login
     */
    public function viewReports(\FunctionalTester $I): void
    {
        $I->wantTo('View various station reports via API.');

        $station = $this->getTestStation();
        $uriBase = '/api/station/' . $station->getId();

        $I->sendGet($uriBase . '/reports/overview/charts');

        $I->seeResponseCodeIs(200);

        $I->sendGet($uriBase . '/reports/overview/best-and-worst');

        $I->seeResponseCodeIs(200);

        $I->sendGet($uriBase . '/reports/overview/most-played');

        $I->seeResponseCodeIs(200);
    }

    /**
     * @before setupComplete
     * @before login
     */
    public function downloadListenerReportsCsv(\FunctionalTester $I): void
    {
        $I->wantTo('Download station listener report CSV via API.');

        $station = $this->getTestStation();
        $uriBase = '/api/station/' . $station->getId();

        $startDateTime = (new \DateTime())->sub(\DateInterval::createFromDateString('30 days'));
        $endDateTime = new \DateTime();

        $requestUrl = $uriBase . '/listeners?' . http_build_query(
                [
                    'format' => 'csv',
                    'start' => $startDateTime->format('Y-m-d\TH:i:s.v\Z'),
                    'end' => $endDateTime->format('Y-m-d\TH:i:s.v\Z'),
                ]
            );

        $csvHeaders = [
            'IP',
            'Start Time',
            'End Time',
            'Seconds Connected',
            'User Agent',
            'Mount Type',
            'Mount Name',
            'Device: Client',
            'Device: Is Mobile',
            'Device: Is Browser',
            'Device: Is Bot',
            'Device: Browser Family',
            'Device: OS Family',
            'Location: Description',
            'Location: Country',
            'Location: Region',
            'Location: City',
            'Location: Latitude',
            'Location: Longitude',
        ];

        $this->testReportCsv($I, $requestUrl, $csvHeaders);
    }

    /**
     * @before setupComplete
     * @before login
     */
    public function downloadHistoryReportCsv(\FunctionalTester $I): void
    {
        $I->wantTo('Download station timeline report CSV via API.');

        $station = $this->getTestStation();
        $uriBase = '/api/station/' . $station->getId();

        $startDateTime = (new \DateTime())->sub(\DateInterval::createFromDateString('30 days'));
        $endDateTime = new \DateTime();

        $requestUrl = $uriBase . '/history?' . http_build_query(
                [
                    'format' => 'csv',
                    'start' => $startDateTime->format('Y-m-d\TH:i:s.v\Z'),
                    'end' => $endDateTime->format('Y-m-d\TH:i:s.v\Z'),
                ]
            );

        $csvHeaders = [
            'Date',
            'Time',
            'Listeners',
            'Delta',
            'Track',
            'Artist',
            'Playlist',
            'Streamer',
        ];

        $this->testReportCsv($I, $requestUrl, $csvHeaders);
    }

    /**
     * @before setupComplete
     * @before login
     */
    public function downloadPerformanceReportCsv(\FunctionalTester $I): void
    {
        $I->wantTo('Download station song impact CSV via API.');

        $station = $this->getTestStation();
        $uriBase = '/api/station/' . $station->getId();
        $requestUrl = $uriBase . '/reports/performance?format=csv';

        $csvHeaders = [
            'Song Title',
            'Song Artist',
            'Filename',
            'Length',
            'Current Playlist',
            'Delta Joins',
            'Delta Losses',
            'Delta Total',
            'Play Count',
            'Play Percentage',
            'Weighted Ratio',
        ];

        $this->testReportCsv($I, $requestUrl, $csvHeaders);
    }

    protected function testReportCsv(
        \FunctionalTester $I,
        string $url,
        array $headerFields
    ): void {
        $I->sendGet($url);

        $response = $I->grabResponse();

        $responseCsv = str_getcsv($response);

        $this->assertIsArray($responseCsv);
        $this->assertTrue(
            count($responseCsv) > 0,
            'CSV is not empty'
        );

        foreach ($headerFields as $csvHeaderField) {
            $this->assertContains(
                $csvHeaderField,
                $responseCsv,
                'CSV has header field'
            );
        }
    }
}
