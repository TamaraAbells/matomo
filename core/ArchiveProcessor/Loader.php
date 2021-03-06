<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\ArchiveProcessor;

use Piwik\Archive\ArchiveInvalidator;
use Piwik\Cache;
use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\Context;
use Piwik\DataAccess\ArchiveSelector;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\DataAccess\Model;
use Piwik\DataAccess\RawLogDao;
use Piwik\Date;
use Piwik\Db;
use Piwik\Piwik;
use Piwik\Site;
use Psr\Log\LoggerInterface;

/**
 * This class uses PluginsArchiver class to trigger data aggregation and create archives.
 */
class Loader
{
    const MIN_VISIT_TIME_TTL = 3600;

    /**
     * @var Parameters
     */
    protected $params;

    /**
     * @var ArchiveInvalidator
     */
    private $invalidator;

    /**
     * @var \Matomo\Cache\Cache
     */
    private $cache;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var RawLogDao
     */
    private $rawLogDao;

    /**
     * @var Model
     */
    private $dataAccessModel;

    public function __construct(Parameters $params, $invalidateBeforeArchiving = false)
    {
        $this->params = $params;
        $this->invalidateBeforeArchiving = $invalidateBeforeArchiving;
        $this->invalidator = StaticContainer::get(ArchiveInvalidator::class);
        $this->cache = Cache::getTransientCache();
        $this->logger = StaticContainer::get(LoggerInterface::class);
        $this->rawLogDao = new RawLogDao();
        $this->dataAccessModel = new Model();
    }

    /**
     * @return bool
     */
    protected function isThereSomeVisits($visits)
    {
        return $visits > 0;
    }

    /**
     * @return bool
     */
    protected function mustProcessVisitCount($visits)
    {
        return $visits === false;
    }

    public function prepareArchive($pluginName)
    {
        return Context::changeIdSite($this->params->getSite()->getId(), function () use ($pluginName) {
            return $this->prepareArchiveImpl($pluginName);
        });
    }

    private function prepareArchiveImpl($pluginName)
    {
        $this->params->setRequestedPlugin($pluginName);

        list($idArchive, $visits, $visitsConverted, $isAnyArchiveExists) = $this->loadExistingArchiveIdFromDb();
        if (!empty($idArchive)) { // we have a usable idarchive (it's not invalidated and it's new enough)
            return $idArchive;
        }

        // NOTE: this optimization helps when archiving large periods. eg, if archiving a year w/ a segment where
        // there are not visits in the entire year, we don't have to go through and do anything. but, w/o this
        // code, we will end up launching archiving for each month, week and day, even though we don't have to.
        //
        // we don't create an archive in this case, because the archive may be in progress in some way, so a 0
        // visits archive can be inaccurate in the long run.
        if ($this->canSkipThisArchive()) {
            return false;
        }

        // if there is an archive, but we can't use it for some reason, invalidate existing archives before
        // we start archiving. if the archive is made invalid, we will correctly re-archive below.
        if ($this->invalidateBeforeArchiving
            && $isAnyArchiveExists
        ) {
            $this->invalidatedReportsIfNeeded();
        }

        /** @var ArchivingStatus $archivingStatus */
        $archivingStatus = StaticContainer::get(ArchivingStatus::class);
        $locked = $archivingStatus->archiveStarted($this->params);

        try {
            list($visits, $visitsConverted) = $this->prepareCoreMetricsArchive($visits, $visitsConverted);
            list($idArchive, $visits) = $this->prepareAllPluginsArchive($visits, $visitsConverted);
        } finally {
            if ($locked) {
                $archivingStatus->archiveFinished();
            }
        }

        if ($this->isThereSomeVisits($visits) || PluginsArchiver::doesAnyPluginArchiveWithoutVisits()) {
            return $idArchive;
        }

        return false;
    }

    /**
     * Prepares the core metrics if needed.
     *
     * @param $visits
     * @return array
     */
    protected function prepareCoreMetricsArchive($visits, $visitsConverted)
    {
        $createSeparateArchiveForCoreMetrics = $this->mustProcessVisitCount($visits)
                                && !$this->doesRequestedPluginIncludeVisitsSummary();

        if ($createSeparateArchiveForCoreMetrics) {
            $requestedPlugin = $this->params->getRequestedPlugin();

            $this->params->setRequestedPlugin('VisitsSummary');

            $pluginsArchiver = new PluginsArchiver($this->params);
            $metrics = $pluginsArchiver->callAggregateCoreMetrics();
            $pluginsArchiver->finalizeArchive();

            $this->params->setRequestedPlugin($requestedPlugin);

            $visits = $metrics['nb_visits'];
            $visitsConverted = $metrics['nb_visits_converted'];
        }

        return array($visits, $visitsConverted);
    }

    protected function prepareAllPluginsArchive($visits, $visitsConverted)
    {
        $pluginsArchiver = new PluginsArchiver($this->params);

        if ($this->mustProcessVisitCount($visits)
            || $this->doesRequestedPluginIncludeVisitsSummary()
        ) {
            $metrics = $pluginsArchiver->callAggregateCoreMetrics();
            $visits = $metrics['nb_visits'];
            $visitsConverted = $metrics['nb_visits_converted'];
        }

        $forceArchivingWithoutVisits = !$this->isThereSomeVisits($visits) && $this->shouldArchiveForSiteEvenWhenNoVisits();
        $pluginsArchiver->callAggregateAllPlugins($visits, $visitsConverted, $forceArchivingWithoutVisits);

        $idArchive = $pluginsArchiver->finalizeArchive();

        return array($idArchive, $visits);
    }

    protected function doesRequestedPluginIncludeVisitsSummary()
    {
        $processAllReportsIncludingVisitsSummary =
                Rules::shouldProcessReportsAllPlugins(array($this->params->getSite()->getId()), $this->params->getSegment(), $this->params->getPeriod()->getLabel());
        $doesRequestedPluginIncludeVisitsSummary = $processAllReportsIncludingVisitsSummary
                                                        || $this->params->getRequestedPlugin() == 'VisitsSummary';
        return $doesRequestedPluginIncludeVisitsSummary;
    }

    protected function isArchivingForcedToTrigger()
    {
        $period = $this->params->getPeriod()->getLabel();
        $debugSetting = 'always_archive_data_period'; // default

        if ($period == 'day') {
            $debugSetting = 'always_archive_data_day';
        } elseif ($period == 'range') {
            $debugSetting = 'always_archive_data_range';
        }

        return (bool) Config::getInstance()->Debug[$debugSetting];
    }

    /**
     * Returns the idArchive if the archive is available in the database for the requested plugin.
     * Returns false if the archive needs to be processed.
     *
     * (public for tests)
     *
     * @return array
     */
    public function loadExistingArchiveIdFromDb()
    {
        if ($this->isArchivingForcedToTrigger()) {
            $this->logger->debug("Archiving forced to trigger for {$this->params}.");

            // return no usable archive found, and no existing archive. this will skip invalidation, which should
            // be fine since we just force archiving.
            return [false, false, false, false];
        }

        $minDatetimeArchiveProcessedUTC = $this->getMinTimeArchiveProcessed();
        $result = ArchiveSelector::getArchiveIdAndVisits($this->params, $minDatetimeArchiveProcessedUTC);
        return $result;
    }

    /**
     * Returns the minimum archive processed datetime to look at. Only public for tests.
     *
     * @return int|bool  Datetime timestamp, or false if must look at any archive available
     */
    protected function getMinTimeArchiveProcessed()
    {
        $endDateTimestamp = self::determineIfArchivePermanent($this->params->getDateEnd());
        if ($endDateTimestamp) {
            // past archive
            return $endDateTimestamp;
        }
        $dateStart = $this->params->getDateStart();
        $period    = $this->params->getPeriod();
        $segment   = $this->params->getSegment();
        $site      = $this->params->getSite();
        // in-progress archive
        return Rules::getMinTimeProcessedForInProgressArchive($dateStart, $period, $segment, $site);
    }

    protected static function determineIfArchivePermanent(Date $dateEnd)
    {
        $now = time();
        $endTimestampUTC = strtotime($dateEnd->getDateEndUTC());

        if ($endTimestampUTC <= $now) {
            // - if the period we are looking for is finished, we look for a ts_archived that
            //   is greater than the last day of the archive
            return $endTimestampUTC;
        }

        return false;
    }

    private function shouldArchiveForSiteEvenWhenNoVisits()
    {
        $idSitesToArchive = $this->getIdSitesToArchiveWhenNoVisits();
        return in_array($this->params->getSite()->getId(), $idSitesToArchive);
    }

    private function getIdSitesToArchiveWhenNoVisits()
    {
        $cache = Cache::getTransientCache();
        $cacheKey = 'Archiving.getIdSitesToArchiveWhenNoVisits';

        if (!$cache->contains($cacheKey)) {
            $idSites = array();

            // leaving undocumented unless decided otherwise
            Piwik::postEvent('Archiving.getIdSitesToArchiveWhenNoVisits', array(&$idSites));

            $cache->save($cacheKey, $idSites);
        }

        return $cache->fetch($cacheKey);
    }

    // public for tests
    public function getReportsToInvalidate()
    {
        $sitesPerDays = $this->invalidator->getRememberedArchivedReportsThatShouldBeInvalidated();

        foreach ($sitesPerDays as $dateStr => $siteIds) {
            if (empty($siteIds)
                || !in_array($this->params->getSite()->getId(), $siteIds)
            ) {
                unset($sitesPerDays[$dateStr]);
            }

            $date = Date::factory($dateStr);
            if ($date->isEarlier($this->params->getPeriod()->getDateStart())
                || $date->isLater($this->params->getPeriod()->getDateEnd())
            ) { // date in list is not the current date, so ignore it
                unset($sitesPerDays[$dateStr]);
            }
        }

        return $sitesPerDays;
    }

    private function invalidatedReportsIfNeeded()
    {
        $sitesPerDays = $this->getReportsToInvalidate();
        if (empty($sitesPerDays)) {
            return;
        }

        foreach ($sitesPerDays as $date => $siteIds) {
            try {
                $this->invalidator->markArchivesAsInvalidated([$this->params->getSite()->getId()], array(Date::factory($date)), false, $this->params->getSegment());
            } catch (\Exception $e) {
                Site::clearCache();
                throw $e;
            }
        }

        Site::clearCache();
    }

    public function canSkipThisArchive()
    {
        $params = $this->params;
        $idSite = $params->getSite()->getId();

        $isWebsiteUsingTracker = $this->isWebsiteUsingTheTracker($idSite);
        $hasSiteVisitsBetweenTimeframe = $this->hasSiteVisitsBetweenTimeframe($idSite, $params->getPeriod()->getDateStart()->getDatetime(), $params->getPeriod()->getDateEnd()->getDatetime());
        $hasChildArchivesInPeriod = $this->dataAccessModel->hasChildArchivesInPeriod($idSite, $params->getPeriod());

        return $isWebsiteUsingTracker
            && !$hasSiteVisitsBetweenTimeframe
            && !$hasChildArchivesInPeriod;
    }

    private function isWebsiteUsingTheTracker($idSite)
    {
        $idSitesNotUsingTracker = self::getSitesNotUsingTracker();

        $isUsingTracker = !in_array($idSite, $idSitesNotUsingTracker);

        return $isUsingTracker;
    }

    public static function getSitesNotUsingTracker()
    {
        $cache = Cache::getTransientCache();

        $cacheKey = 'Archiving.isWebsiteUsingTheTracker';
        $idSitesNotUsingTracker = $cache->fetch($cacheKey);
        if ($idSitesNotUsingTracker === false || !isset($idSitesNotUsingTracker)) {
            // we want to trigger event only once
            $idSitesNotUsingTracker = array();

            /**
             * This event is triggered when detecting whether there are sites that do not use the tracker.
             *
             * By default we only archive a site when there was actually any visit since the last archiving.
             * However, some plugins do import data from another source instead of using the tracker and therefore
             * will never have any visits for this site. To make sure we still archive data for such a site when
             * archiving for this site is requested, you can listen to this event and add the idSite to the list of
             * sites that do not use the tracker.
             *
             * @param bool $idSitesNotUsingTracker The list of idSites that rather import data instead of using the tracker
             */
            Piwik::postEvent('CronArchive.getIdSitesNotUsingTracker', array(&$idSitesNotUsingTracker));

            $cache->save($cacheKey, $idSitesNotUsingTracker);
        }
        return $idSitesNotUsingTracker;
    }

    private function hasSiteVisitsBetweenTimeframe($idSite, $date1, $date2)
    {
        $minVisitTimesPerSite = $this->getMinVisitTimesPerSite($idSite);
        if (empty($minVisitTimesPerSite)) {
            return false;
        }

        $date2 = Date::factory($date2)->addDay(1)->getStartOfDay();
        if ($date2->isEarlier($minVisitTimesPerSite)) {
            return false;
        }

        return $this->rawLogDao->hasSiteVisitsBetweenTimeframe(Date::factory($date1)->getDatetime(), $date2->getDatetime(), $idSite);
    }

    private function getMinVisitTimesPerSite($idSite)
    {
        $cache = Cache::getLazyCache();
        $cacheKey = 'Archiving.minVisitTime.' . $idSite;

        $value = $cache->fetch($cacheKey);
        if ($value === false) {
            $value = $this->rawLogDao->getMinimumVisitTimeForSite($idSite);
            $cache->save($cacheKey, $value, $ttl = self::MIN_VISIT_TIME_TTL);
        }

        if (!empty($value)) {
            $value = Date::factory($value);
        }

        return $value;
    }

    public static function invalidateMinVisitTimeCache($idSite)
    {
        $cache = Cache::getLazyCache();
        $cacheKey = 'Archiving.minVisitTime.' . $idSite;
        $cache->delete($cacheKey);
    }
}
