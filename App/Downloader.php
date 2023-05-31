<?php
/**
 * Main cycle of the app
 */

namespace App;

use App\Exceptions\LoginException;
use App\Http\Resolver;
use App\Laracasts\Controller as LaracastsController;
use App\System\Controller as SystemController;
use App\Utils\Utils;
use Cocur\Slugify\Slugify;
use GuzzleHttp\Client as HttpClient;
use League\Flysystem\Filesystem;
use Ubench;

/**
 * Class Downloader
 *
 * @package App
 */
class Downloader
{
    /**
     * Http resolver object
     *
     * @var Resolver
     */
    private $client;

    /**
     * System object
     *
     * @var SystemController
     */
    private $system;

    /**
     * Ubench lib
     *
     * @var Ubench
     */
    private $bench;

    // [string => number[]]
    private $filters = [];

    /**
     * @var LaracastsController
     */
    private $laracasts;

    /** @var bool Don't scrap pages and only get from existing cache */
    private $cacheOnly = false;

    /**
     * Receives dependencies
     *
     * @param  HttpClient  $httpClient
     * @param  Filesystem  $system
     * @param  Ubench  $bench
     * @param  bool  $retryDownload
     */
    public function __construct(HttpClient $httpClient, Filesystem $system, Ubench $bench, $retryDownload = false)
    {
        $this->client = new Resolver($httpClient, $bench, $retryDownload);
        $this->system = new SystemController($system);
        $this->bench = $bench;
        $this->laracasts = new LaracastsController($this->client);
    }

    /**
     * All the logic
     *
     * @param $options
     */
    public function start($options)
    {
        $counter = [
            'series' => 1,
            'failed_episode' => 0,
        ];

        $this->authenticate($options['email'], $options['password']);

        Utils::box('Starting Collecting the data');

        $this->setFilters();

        $this->bench->start();

        $localSeries = $this->system->getSeries();

        if (empty($this->filters)) {
            $cachedData = $this->system->getCache();

            $onlineSeries = $this->laracasts->getSeries($cachedData, $this->cacheOnly);

            $this->system->setCache($onlineSeries);
        } else {
            $onlineSeries = $this->laracasts->getFilteredSeries($this->filters);
        }

        $this->bench->end();

        Utils::box('Downloading');

        $newEpisodes = Utils::compareLocalAndOnlineSeries($onlineSeries, $localSeries);
        
        $newEpisodesCount = Utils::countEpisodes($newEpisodes);

        Utils::write(
            sprintf(
                "%d new episodes. %s elapsed with %s of memory usage.",
                $newEpisodesCount,
                $this->bench->getTime(),
                $this->bench->getMemoryUsage()
            )
        );

        if ($newEpisodesCount > 0) {
            $this->downloadEpisodes($newEpisodes, $counter, $newEpisodesCount);
        }

        Utils::writeln(
            sprintf(
                "Finished! Downloaded %d new episodes. Failed: %d",
                $newEpisodesCount - $counter['failed_episode'],
                $counter['failed_episode']
            )
        );
    }

    /**
     * Tries to login.
     *
     * @param  string  $email
     * @param  string  $password
     *
     * @return bool
     * @throws LoginException
     */
    public function authenticate($email, $password)
    {
        Utils::box('Authenticating');

        if (empty($email) or empty($password))
            throw new LoginException("No EMAIL and PASSWORD is set in .env file");

        $user = $this->client->login($email, $password);

        if (! is_null($user['error']))
            throw new LoginException($user['error']);

        if ($user['signedIn'])
            Utils::write("Logged in as ".$user['data']['email']);

        if (! $user['data']['subscribed'])
            throw new LoginException("You don't have active subscription!");

        return $user['signedIn'];
    }

    /**
     * Download Episodes
     *
     * @param $newEpisodes
     * @param $counter
     * @param $newEpisodesCount
     */
    public function downloadEpisodes($newEpisodes, &$counter, $newEpisodesCount)
    {
        $this->system->createFolderIfNotExists(SERIES_FOLDER);

        Utils::box('Downloading Series');

        foreach ($newEpisodes as $serie) {
            $this->system->createSerieFolderIfNotExists($serie['slug']);

            foreach ($serie['episodes'] as $episode) {

                if ($this->client->downloadEpisode($serie['slug'], $episode) === false) {
                    $counter['failed_episode'] = $counter['failed_episode'] + 1;
                }

                Utils::write(
                    sprintf(
                        "Current: %d of %d total. Left: %d              ",
                        $counter['series']++,
                        $newEpisodesCount,
                        $newEpisodesCount - $counter['series'] + 1
                    )
                );
            }
        }
    }

    protected function setFilters()
    {
        $shortOptions = "s:";
        $shortOptions .= 'e:';

        $longOptions = [
            "series-name:",
            "series-episodes:",
            "cache-only"
        ];

        $options = getopt($shortOptions, $longOptions);

        if (array_key_exists('cache-only', $options)) {
            $this->cacheOnly = true;
            unset($options['cache-only']);
        }

        Utils::box(sprintf("Checking for options %s", json_encode($options)));

        if (count($options) == 0) {
            Utils::write('No options provided');

            return false;
        }

        $this->setSeriesFilter($options);

        $this->setEpisodesFilter($options);

        $newEpisodes = count($this->filters['episodes']) - count($this->filters['series']);

        $this->filters['episodes'] = array_merge(
            $this->filters['episodes'],
            array_fill(0, abs($newEpisodes), [])
        );

        $this->filters = array_combine(
            $this->filters['series'],
            $this->filters['episodes']
        );

        return true;
    }

    private function setSeriesFilter($options)
    {
        if (isset($options['s']) || isset($options['series-name'])) {
            $series = isset($options['s']) ? $options['s'] : $options['series-name'];

            if (! is_array($series))
                $series = [$series];

            $slugify = new Slugify();
            $slugify->addRule("'", '');

            $this->filters['series'] = array_map(function($serie) use ($slugify) {
                return $slugify->slugify($serie);
            }, $series);

            Utils::write(sprintf("Series names provided: %s", json_encode($this->filters['series'])));
        }
    }

    private function setEpisodesFilter($options)
    {
        $this->filters['episodes'] = [];

        if (isset($options['e']) || isset($options['series-episodes'])) {
            $episodes = isset($options['e']) ? $options['e'] : $options['series-episodes'];

            Utils::write(sprintf("Episode numbers provided: %s", json_encode($episodes)));

            if (! is_array($episodes)) {
                $episodes = [$episodes];
            }

            foreach ($episodes as $episode) {
                $positions = explode(',', $episode);

                sort($positions, SORT_NUMERIC);

                $this->filters['episodes'][] = $positions;
            }
        }
    }
}
