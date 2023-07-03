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
namespace CrowdSec\Engine\Model\ResourceModel;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;
use CrowdSec\Engine\Helper\Data as Helper;

class Event extends AbstractDb
{

    /**
     * @var Helper
     */
    private $_helper;

    /**
     * Constructor.
     *
     * @param Context $context
     * @param Helper $helper
     * @param string $connectionName
     */
    public function __construct(
        Context $context,
        Helper $helper,
        $connectionName = null
    ) {
        $this->_helper = $helper;
        parent::__construct($context, $connectionName);
    }

     /**
      * Delete all entries for some ids
      *
      * @param array $ids
      * @return int
      * @throws LocalizedException
      */
    public function massDeleteForIds(array $ids):int
    {
        $connection = $this->getConnection();
        $mainTable = $this->getMainTable();
        $condition = $connection->quoteInto($this->getIdFieldName() . ' IN (?)', $ids);

        return $connection->delete($mainTable, $condition);
    }

    /**
     * Update all entries for some ids
     *
     * @param array $bind
     * @param array $ids
     * @return int
     * @throws LocalizedException
     */
    public function massUpdateByIds(array $bind, array $ids): int
    {
        $connection = $this->getConnection();
        $mainTable = $this->getMainTable();
        $condition = $connection->quoteInto($this->getIdFieldName() . ' IN (?)', $ids);

        return $connection->update($mainTable, $bind, $condition);
    }

    /**
     * @inheritDoc
     *
     * @param AbstractModel $object
     * @return Event
     */
    protected function _beforeSave(AbstractModel $object)
    {
        $object->setUpdatedAt($this->_helper->getCurrentGMTDate());

        return parent::_beforeSave($object);
    }

    /**
     * @inheritDoc
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('crowdsec_event', 'event_id');
    }
}
