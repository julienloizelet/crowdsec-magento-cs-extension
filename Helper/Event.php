<?php declare(strict_types=1);
/**
 * CrowdSec_Engine Extension
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT LICENSE
 * that is bundled with this package in the file LICENSE
 *
 * @category   CrowdSec
 * @package    CrowdSec_Engine
 * @copyright  Copyright (c)  2023+ CrowdSec
 * @author     CrowdSec team
 * @see        https://crowdsec.net CrowdSec Official Website
 * @license    MIT LICENSE
 *
 */

/**
 *
 * @category CrowdSec
 * @package  CrowdSec_Engine
 * @module   Engine
 * @author   CrowdSec team
 *
 */

namespace CrowdSec\Engine\Helper;

use CrowdSec\CapiClient\ClientException;
use CrowdSec\Engine\Api\Data\EventInterface;
use CrowdSec\Engine\Api\EventRepositoryInterface;
use CrowdSec\Engine\CapiEngine\Watcher;
use CrowdSec\Engine\Constants;
use CrowdSec\Engine\Helper\Data as Helper;
use CrowdSec\Engine\Model\EventFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderFactory;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\LocalizedException;

class Event extends AbstractHelper
{
    /**
     * @var EventFactory
     */
    private $eventFactory;
    /**
     * @var EventRepositoryInterface
     */
    private $eventRepository;
    /**
     * @var Helper
     */
    private $helper;
    /**
     * @var array
     */
    private $lastEvent = [];
    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;
    /**
     * @var SortOrderFactory
     */
    private $sortOrderFactory;

    /**
     * Data constructor.
     *
     * @param Context $context
     * @param Helper $helper
     * @param EventFactory $eventFactory
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderFactory $sortOrderFactory
     * @param EventRepositoryInterface $eventRepository
     */
    public function __construct(
        Context $context,
        Helper $helper,
        EventFactory $eventFactory,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SortOrderFactory $sortOrderFactory,
        EventRepositoryInterface $eventRepository
    ) {
        $this->helper = $helper;
        $this->eventFactory = $eventFactory;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->sortOrderFactory = $sortOrderFactory;
        $this->eventRepository = $eventRepository;

        parent::__construct($context);
    }

    /**
     * Retrieve last event for some ip and scenario.
     * If no result, return an empty event object
     *
     * @param string $ip
     * @param string $scenario
     * @return EventInterface
     * @throws \Magento\Framework\Exception\InputException
     * @throws LocalizedException
     */
    public function getLastEvent(string $ip, string $scenario): EventInterface
    {
        if (!isset($this->lastEvent[$ip][$scenario])) {
            $sort = $this->sortOrderFactory->create()
                ->setField(EventInterface::LAST_EVENT_DATE)
                ->setDirection(SortOrder::SORT_DESC);

            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter(EventInterface::SCENARIO, $scenario)
                ->addFilter(EventInterface::IP, $ip)
                ->setPageSize(1)
                ->setCurrentPage(1)
                ->setSortOrders([$sort])
                ->create();

            $events = $this->eventRepository->getList($searchCriteria);
            $firstItem = current($events->getItems());

            $this->lastEvent[$ip][$scenario] = $firstItem ?: $this->eventFactory->create();
        }

        return $this->lastEvent[$ip][$scenario];
    }

    public function getLogger()
    {
        return $this->helper->getLogger();
    }

    /**
     * Send signals to CAPI
     *
     * @param Watcher $watcher
     * @param int $max
     * @param int $maxError
     * @return void
     * @throws LocalizedException
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function sendSignals(Watcher $watcher, int $max, int $maxError): array
    {

        //@TODO : if last push too recent, return early

        $result = ['candidates' => 0, 'sent' => 0, 'errors' => 0];

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(EventInterface::STATUS_ID, EventInterface::STATUS_ALERT_TRIGGERED)
            ->addFilter(EventInterface::ERROR_COUNT, $maxError, 'lteq')
            ->create();

        $events = $this->eventRepository->getList($searchCriteria)->getItems();
        $result['candidates'] = count($events);

        $signals = [];
        $pushedEvents = [];
        $i = 0;
        /**
         * @var $event \CrowdSec\Engine\Model\Event
         */
        while ($event = array_shift($events)) {
            $i++;
            if ($i > $max) {
                break;
            }
            $pushedEvents[$event->getId()] = true;

            $lastEventTime = (int)strtotime($event->getLastEventDate());

            $signalDate = (new \DateTime())->setTimestamp($lastEventTime);
            try {

                $context = $event->getContext();
                $duration = $context['duration'] ?? Constants::DURATION;

                $signals[] = $watcher->buildSimpleSignalForIp(
                    $event->getIp(),
                    $event->getScenario(),
                    $signalDate,
                    '',
                    $duration
                );

            }
            catch (ClientException $e) {

                unset($pushedEvents[$event->getId()]);
                $errorCount = $event->getErrorCount() + 1;
                $event->setErrorCount($errorCount);
                $this->eventRepository->save($event);
                $result['errors'] += 1;

                //@TODO log
            }
        }

        if ($signals) {
            $pushedIds = array_keys($pushedEvents);
            try {
                $watcher->pushSignals($signals);

                $this->eventRepository->massUpdateByIds(['status_id' => EventInterface::STATUS_SIGNAL_SENT], $pushedIds);

                $result['sent'] += count($pushedIds);

                // @TODO log

            } catch (ClientException $e) {

                $this->eventRepository->massUpdateByIds(
                    ['error_count' => new \Zend_Db_Expr('error_count + 1')],
                    $pushedIds
                );
                $result['errors'] += count($pushedIds);

                //@TODO log
            }
        }

        return $result;
    }


}
