<?php
namespace Netzkollektiv\TrustedshopsInvitations\Cron;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Framework\Event\ManagerInterface;

class Processor {

    /**
     * @var OrderRepositoryInterface
     */
    private $orderCollectionFactory;

    /** @var LoggerInterface */
    private $logger;

    private $orderFactory;

    /**
     * Core event manager proxy
     *
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $_eventManager = null;

    public function __construct(
        OrderCollectionFactory $collectionFactory,
        LoggerInterface $logger,
        OrderFactory $orderFactory,
        ManagerInterface $eventManager
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->logger = $logger;
        $this->orderFactory = $orderFactory;
        $this->_eventManager = $eventManager;
    }

    public function process() {
        /** @var OrderInterface $order */
        foreach ($this->getOrders() as $order) {
            try {
                $order = $this->orderFactory->create()->loadByIncrementId($order->getIncrementId());

		$origOrderStatus = $order->getStatus();
                $order->setStatus('processing');

		$this->sendOrder($order);

		$order->setStatus($origOrderStatus);

                $order->addCommentToStatusHistory('Trustedshops invitation sent by cronjob');
                $order->save();
            } catch (\Throwable $e) {
                $this->logger->error($e->getMessage());
            }
        }
    }

    protected function sendOrder ($order) {
        $this->logger->info('Dispatching Event for '.$order->getId());
	$this->_eventManager->dispatch('send_trustedshops_invitation', [
            'data_object' => $order,
            'order' => $order,
        ]);
    }

    protected function getOrders () {
	/*
	SELECT

	  main_table.increment_id,
	  main_table.created_at,
	  (CURRENT_DATE() - INTERVAL 60 DAY) as `from`,
	  (CURRENT_DATE() - INTERVAL 14 DAY) as `to`,

	FROM `sales_order` AS `main_table`
	JOIN `sales_order_status_history` AS `history` ON main_table.entity_id = history.parent_id
	LEFT OUTER JOIN `sales_order_status_history` AS `history2` ON (
		main_table.entity_id = history2.parent_id
	)
	WHERE
	// for orders that have the status "On Hold" at this time, no evaluation invitation should be sent out
	main_table.state = 'complete' AND main_table.status != 'holded'
	// The dispatch of the invitations should be initiated 21 days after setting the status "Complete" (dispatch from initiation "immediately")
	AND (history.created_at <= CURRENT_DATE() - INTERVAL 14 DAY )
	AND (history.created_at >= CURRENT_DATE() - INTERVAL 60 DAY)
	AND history2.entity_id IS NULL
	AND (main_table.entity_id NOT IN
	   (SELECT parent_id FROM `sales_order_status_history` history_sub  WHERE history_sub.parent_id = history.parent_id AND history_sub.comment LIKE "%trustedshops%"))
	*/

        $orderCollection = $this->collectionFactory->create();
        $orderCollection->getSelect()
            ->join(['history'=>'sales_order_status_history'], 'main_table.entity_id = history.parent_id')
            ->joinLeft(['history2'=>'sales_order_status_history'], 'main_table.entity_id = history2.parent_id  AND
              (history.created_at < history2.created_at
              OR (history.created_at = history2.created_at AND history.entity_id < history2.entity_id))
            ')
            ->where('history2.entity_id IS NULL')
            ->where('main_table.status = ?', 'complete')
            ->where('main_table.status != ?', 'holded')
            ->where('history.created_at <= ? ', new \Zend_Db_Expr('CURRENT_DATE() - INTERVAL 14 DAY'))
            ->where('history.created_at >= ?', new \Zend_Db_Expr('CURRENT_DATE() - INTERVAL 60 DAY'))
            ->where('history.comment != ? ', 'trustedshops-invitation-sent')
            ->where('main_table.entity_id NOT IN ?', new \Zend_Db_Expr('
                (SELECT parent_id FROM `sales_order_status_history` history_sub  WHERE history_sub.parent_id = history.parent_id AND history_sub.comment LIKE "%trustedshops%")
            '));

        $this->logger->info('Executing query: ['. (string) $orderCollection->getSelect().']');
        return $orderCollection;
    }
}
