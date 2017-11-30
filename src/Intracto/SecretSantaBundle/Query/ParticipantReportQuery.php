<?php

namespace Intracto\SecretSantaBundle\Query;

use Doctrine\DBAL\Driver\Connection;
use Symfony\Component\Routing\Router;
use Symfony\Component\Routing\RouterInterface;

class ParticipantReportQuery
{
    /** @var Connection */
    private $dbal;
    /** @var Router */
    private $router;
    /** @var PartyReportQuery */
    private $partyReportQuery;
    /** @var FeaturedYearsQuery */
    private $featuredYearsQuery;

    /**
     * @param Connection         $dbal
     * @param Router             $router
     * @param PartyReportQuery   $partyReportQuery
     * @param FeaturedYearsQuery $featuredYearsQuery
     */
    public function __construct(
        Connection $dbal,
        RouterInterface $router,
        PartyReportQuery $partyReportQuery,
        FeaturedYearsQuery $featuredYearsQuery
    ) {
        $this->dbal = $dbal;
        $this->router = $router;
        $this->partyReportQuery = $partyReportQuery;
        $this->featuredYearsQuery = $featuredYearsQuery;
    }

    /**
     * @param \DateTime $date
     *
     * @return mixed
     */
    public function countConfirmedParticipantsUntilDate(\DateTime $date)
    {
        $query = $this->dbal->createQueryBuilder()
            ->select('count(p.id) AS confirmedParticipantCount')
            ->from('party', 'p')
            ->innerJoin('p', 'participant', 'e', 'p.id = e.party_id')
            ->where('p.sent_date < :lastDay')
            ->andWhere('e.view_date IS NOT NULL')
            ->setParameter('lastDay', $date->format('Y-m-d H:i:s'));

        return $query->execute()->fetchAll();
    }

    /**
     * @param \DateTime $date
     *
     * @return mixed
     */
    public function countDistinctParticipantsUntilDate(\DateTime $date)
    {
        $query = $this->dbal->createQueryBuilder()
            ->select('count(distinct e.email) AS distinctParticipantCount')
            ->from('party', 'p')
            ->innerJoin('p', 'participant', 'e', 'p.id = e.party_id')
            ->where('p.sent_date < :lastDay')
            ->setParameter('lastDay', $date->format('Y-m-d H:i:s'));

        return $query->execute()->fetchAll();
    }

    /**
     * @param Season $season
     *
     * @return mixed
     */
    public function queryDataForMonthlyParticipantChart(Season $season)
    {
        $query = $this->dbal->createQueryBuilder()
            ->select('count(p.id) AS accumulatedParticipantCountByMonth, p.sent_date AS month')
            ->from('party', 'p')
            ->innerJoin('p', 'participant', 'e', 'p.id = e.party_id')
            ->where('p.sent_date >= :firstDay')
            ->andWhere('p.sent_date < :lastDay')
            ->groupBy('month(p.sent_date)')
            ->orderBy('month(p.sent_date) < 4, month(p.sent_date)')
            ->setParameter('firstDay', $season->getStart()->format('Y-m-d H:i:s'))
            ->setParameter('lastDay', $season->getEnd()->format('Y-m-d H:i:s'));

        return $query->execute()->fetchAll();
    }

    /**
     * @return array
     */
    public function queryDataForYearlyParticipantChart()
    {
        $featuredYears = $this->featuredYearsQuery->getFeaturedYears();
        $participantChartData = [];

        foreach ($featuredYears['featured_years'] as $year) {
            $firstDay = \DateTime::createFromFormat('Y-m-d', $year.'-04-01')->format('Y-m-d H:i:s');
            $lastDay = \DateTime::createFromFormat('Y-m-d', $year + 1 .'-04-01')->format('Y-m-d H:i:s');

            $query = $this->dbal->createQueryBuilder()
                ->select('count(p.id) AS accumulatedParticipantCountByYear')
                ->from('party', 'p')
                ->innerJoin('p', 'participant', 'e', 'p.id = e.party_id')
                ->where('p.sent_date IS NOT NULL')
                ->andWhere('p.sent_date >= :firstDay')
                ->andWhere('p.sent_date < :lastDay')
                ->setParameter('firstDay', $firstDay)
                ->setParameter('lastDay', $lastDay);

            $chartData = $query->execute()->fetchAll();

            $participant = [
                'year' => $year,
                'participant' => $chartData,
            ];

            $participantChartData[] = $participant;
        }

        return $participantChartData;
    }

    /**
     * @param \DateTime $date
     *
     * @return array
     */
    public function queryDataForParticipantChartUntilDate(\DateTime $date)
    {
        $query = $this->dbal->createQueryBuilder()
            ->select('count(p.id) AS totalParticipantCount, p.sent_date AS month')
            ->from('party', 'p')
            ->innerJoin('p', 'participant', 'e', 'p.id = e.party_id')
            ->where('p.sent_date < :lastDay')
            ->groupBy('year(p.sent_date), month(p.sent_date)')
            ->setParameter('lastDay', $date->format('Y-m-d H:i:s'));

        $totalParticipantChartData = $query->execute()->fetchAll();

        $accumulatedParticipantCounter = 0;

        foreach ($totalParticipantChartData as &$participantCount) {
            $accumulatedParticipantCounter += $participantCount['totalParticipantCount'];
            $participantCount['totalParticipantCount'] = $accumulatedParticipantCounter;
        }

        return $totalParticipantChartData;
    }

    /**
     * @param \DateTime $date
     *
     * @return float
     */
    public function calculateAverageParticipantsPerPartyUntilDate(\DateTime $date)
    {
        $totalParties = $this->partyReportQuery->countAllPartiesUntilDate($date);
        $totalParticipants = $this->countAllParticipantsUntilDate($date);

        if ($totalParties[0]['partyCount'] != 0) {
            return implode($totalParticipants[0]) / implode($totalParties[0]);
        }

        throw new NoResultException();
    }

    /**
     * @param \DateTime $date
     *
     * @return mixed
     */
    public function countAllParticipantsUntilDate(\DateTime $date)
    {
        $query = $this->dbal->createQueryBuilder()
            ->select('count(p.id) AS totalParticipantCount')
            ->from('party', 'p')
            ->innerJoin('p', 'participant', 'e', 'p.id = e.party_id')
            ->where('p.sent_date < :lastDay')
            ->setParameter('lastDay', $date->format('Y-m-d H:i:s'));

        return $query->execute()->fetchAll();
    }

    /**
     * @param Season $season1
     * @param Season $season2
     *
     * @return mixed
     */
    public function calculateParticipantCountDifferenceBetweenSeasons(Season $season1, Season $season2)
    {
        $participantCountSeason1 = $this->countParticipants($season1);
        try {
            $participantCountSeason2 = $this->countParticipants($season2);
        } catch (\Exception $e) {
            return $participantCountSeason1;
        }

        return $participantCountSeason1 - $participantCountSeason2;
    }

    /**
     * @param Season $season
     *
     * @return mixed
     */
    public function countParticipants(Season $season)
    {
        $query = $this->dbal->createQueryBuilder()
            ->select('count(p.id) AS participant_count')
            ->from('party', 'p')
            ->innerJoin('p', 'participant', 'e', 'p.id = e.party_id')
            ->where('p.sent_date >= :firstDay')
            ->andWhere('p.sent_date < :lastDay')
            ->setParameter('firstDay', $season->getStart()->format('Y-m-d H:i:s'))
            ->setParameter('lastDay', $season->getEnd()->format('Y-m-d H:i:s'));

        $participantCount = $query->execute()->fetchAll();

        return $participantCount[0]['participant_count'];
    }

    /**
     * @param Season $season1
     * @param Season $season2
     *
     * @return mixed
     */
    public function calculateConfirmedParticipantsCountDifferenceBetweenSeasons(Season $season1, Season $season2)
    {
        $confirmedParticipantCountSeason1 = $this->countConfirmedParticipants($season1);
        try {
            $confirmedParticipantCountSeason2 = $this->countConfirmedParticipants($season2);
        } catch (\Exception $e) {
            return $confirmedParticipantCountSeason1[0]['confirmedParticipantCount'];
        }

        return $confirmedParticipantCountSeason1[0]['confirmedParticipantCount'] - $confirmedParticipantCountSeason2[0]['confirmedParticipantCount'];
    }

    /**
     * @param Season $season
     *
     * @return mixed
     */
    public function countConfirmedParticipants(Season $season)
    {
        $query = $this->dbal->createQueryBuilder()
            ->select('count(p.id) AS confirmedParticipantCount')
            ->from('party', 'p')
            ->innerJoin('p', 'participant', 'e', 'p.id = e.party_id')
            ->where('p.sent_date >= :firstDay')
            ->andWhere('p.sent_date < :lastDay')
            ->andWhere('e.view_date IS NOT NULL')
            ->setParameter('firstDay', $season->getStart()->format('Y-m-d H:i:s'))
            ->setParameter('lastDay', $season->getEnd()->format('Y-m-d H:i:s'));

        return $query->execute()->fetchAll();
    }

    /**
     * @param Season $season1
     * @param Season $season2
     *
     * @return mixed
     */
    public function calculateDistinctParticipantCountDifferenceBetweenSeasons(Season $season1, Season $season2)
    {
        $distinctParticipantCountSeason1 = $this->countDistinctParticipants($season1);
        try {
            $distinctParticipantCountSeason2 = $this->countDistinctParticipants($season2);
        } catch (\Exception $e) {
            return $distinctParticipantCountSeason1[0]['distinctParticipantCount'];
        }

        return $distinctParticipantCountSeason1[0]['distinctParticipantCount'] - $distinctParticipantCountSeason2[0]['distinctParticipantCount'];
    }

    /**
     * @param Season $season
     *
     * @return mixed
     */
    public function countDistinctParticipants(Season $season)
    {
        $query = $this->dbal->createQueryBuilder()
            ->select('count(distinct e.email) AS distinctParticipantCount')
            ->from('party', 'p')
            ->innerJoin('p', 'participant', 'e', 'p.id = e.party_id')
            ->where('p.sent_date >= :firstDay')
            ->andWhere('p.sent_date < :lastDay')
            ->setParameter('firstDay', $season->getStart()->format('Y-m-d H:i:s'))
            ->setParameter('lastDay', $season->getEnd()->format('Y-m-d H:i:s'));

        return $query->execute()->fetchAll();
    }

    /**
     * @param Season $season1
     * @param Season $season2
     *
     * @return float
     */
    public function calculateAverageParticipantsPerPartyBetweenSeasons(Season $season1, Season $season2)
    {
        $averageSeason1 = $this->calculateAverageParticipantsPerParty($season1);
        try {
            $averageSeason2 = $this->calculateAverageParticipantsPerParty($season2);
        } catch (\Exception $e) {
            return $averageSeason1;
        }

        return $averageSeason1 - $averageSeason2;
    }

    /**
     * @param Season $season
     *
     * @return float
     */
    public function calculateAverageParticipantsPerParty(Season $season)
    {
        $partyCount = $this->partyReportQuery->countParties($season);
        $participantCount = $this->countParticipants($season);

        if ($partyCount[0]['partyCount'] !== 0 || $participantCount !== 0) {
            return $participantCount / implode($partyCount[0]);
        }

        throw new NoResultException();
    }

    /**
     * @param Season $season
     * @param bool   $admin
     */
    public function fetchMailsForExport(Season $season, bool $admin)
    {
        $reusePartyBaseUrl = $this->getPartyReuseBaseUrl();

        if (true === $admin) {
            $handle = fopen('/vagrant/'.date('Y-m-d-H.i.s').'_admins.csv', 'w+');
        } else {
            $handle = fopen('/vagrant/'.date('Y-m-d-H.i.s').'_participants.csv', 'w+');
        }

        $stmt = $this->dbal->executeQuery('
            SELECT e.name, e.email, e.party_id, e.url, p.locale, p.list_url
            FROM party p
            JOIN participant e ON p.id = e.party_id
            LEFT OUTER JOIN blacklist_email b ON b.email = e.email
            WHERE p.sent_date >= :firstDay
            AND p.sent_date < :lastDay
            AND e.party_admin = :admin
            AND e.subscribed_for_updates = 1
            AND b.id is null
            /* when there are duplicate emails, fetch only the one with the highest sent_date */
            AND p.sent_date = (SELECT max(p2.sent_date) 
                FROM party p2
                JOIN participant e2 ON p2.id = e2.party_id
                LEFT OUTER JOIN blacklist_email b2 ON b2.email = e2.email
                WHERE p2.sent_date >= :firstDay
                AND p2.sent_date < :lastDay
                AND e2.party_admin = :admin
                AND e2.subscribed_for_updates = 1
                AND b2.id is null
                and e2.email = e.email
            )
            GROUP BY e.email /*filter duplicates in same party */
            ORDER BY p.id DESC',
            [
                'firstDay' => $season->getStart()->format('Y-m-d H:i:s'),
                'lastDay' => $season->getEnd()->format('Y-m-d H:i:s'),
                'admin' => ($admin ? 1 : 0),
            ]
        );

        while ($row = $stmt->fetch()) {
            $export = [
                $row['name'],
                $row['email'],
                $row['party_id'],
                $row['url'],
                $row['locale'],
            ];

            if ($admin) {
                $export[] = $reusePartyBaseUrl.$row['list_url'];
            }

            fputcsv(
                $handle,
                $export,
                ','
            );
        }

        fclose($handle);
    }

    private function getPartyReuseBaseUrl()
    {
        $url = $this->router->generate(
            'party_reuse',
            ['listurl' => '1'],
            true
        );

        // URL was generated for party 1, strip the 1 to get the base URL
        return substr($url, 0, -1);
    }

    /**
     * @param $listUrl
     *
     * @return array
     */
    public function fetchDataForPartyUpdateMail($listUrl)
    {
        $party = $this->dbal->createQueryBuilder()
            ->select('p.*, e.name AS adminName')
            ->from('party', 'p')
            ->innerJoin('p', 'participant', 'e', 'p.id = e.party_id')
            ->where('p.list_url = :listurl')
            ->andWhere('e.party_admin = 1')
            ->setParameter(':listurl', $listUrl);
        $participantCount = $this->dbal->createQueryBuilder()
            ->select('count(e.id) AS participantCount')
            ->from('party', 'p')
            ->innerJoin('p', 'participant', 'e', 'p.id = e.party_id')
            ->where('p.list_url = :listurl')
            ->setParameter(':listurl', $listUrl);
        $wishlistCount = $this->dbal->createQueryBuilder()
            ->select('count(e.id) AS wishlistCount')
            ->from('party', 'p')
            ->innerJoin('p', 'participant', 'e', 'p.id = e.party_id')
            ->where('p.list_url = :listurl')
            ->andWhere('wishlist_updated = 1')
            ->setParameter(':listurl', $listUrl);
        $viewedCount = $this->dbal->createQueryBuilder()
            ->select('count(e.id) AS viewedCount')
            ->from('party', 'p')
            ->innerJoin('p', 'participant', 'e', 'p.id = e.party_id')
            ->where('p.list_url = :listurl')
            ->andWhere('view_date is not null')
            ->setParameter(':listurl', $listUrl);

        return [
            'party' => $party->execute()->fetchAll(),
            'participantCount' => $participantCount->execute()->fetchAll(),
            'wishlistCount' => $wishlistCount->execute()->fetchAll(),
            'viewedCount' => $viewedCount->execute()->fetchAll(),
        ];
    }

    /**
     * @param $participantId
     *
     * @return mixed
     */
    public function findBuddyByParticipantId($participantId)
    {
        $query = $this->dbal->createQueryBuilder()
            ->select('p.id')
            ->from('participant', 'p')
            ->where('p.assigned_participant_id = :id')
            ->setParameter('id', $participantId);

        return $query->execute()->fetchAll();
    }
}
