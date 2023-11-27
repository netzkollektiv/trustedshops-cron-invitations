<?php
namespace Netzkollektiv\TrustedshopsInvitations\Cron;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;
use Magento\Sales\Model\OrderFactory;

class Processor {

    /**
     * @var OrderRepositoryInterface
     */
    private $orderCollectionFactory;

    /** @var LoggerInterface */
    private $logger;

    private $orderFactory;

    public function __construct(
        OrderCollectionFactory $collectionFactory,
        LoggerInterface $logger,
        OrderFactory $orderFactory
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->logger = $logger;
        $this->orderFactory = $orderFactory;
    }

    public function process() {
        /** @var OrderInterface $order */
        foreach ($this->getOrders() as $order) {
            try {
                $order = $this->orderFactory->create()->loadByIncrementId($order->getIncrementId());

                $this->sendOrder($order);
                
                $order->addCommentToStatusHistory('Trustedshops invitation sent by cronjob');
                $order->save();
            } catch (\Throwable $e) {
                $this->logger->error($e->getMessage());
            }
        }
    }

    protected function sendOrder ($order) {
        $this->logger->info('Dispatching Event for '.$order->getId());
        return;
		$this->_eventManager->dispatch('send_trustedshops_invitation', [
            'data_object' => $order,
            'order' => $order,
        ]);        
    }

    protected function getOrders () {

        // SELECT  h.created_at, o.created_at
	    // FROM sales_order_status_history h
	    // INNER JOIN sales_order o ON o.entity_id = h.parent_id 
	    // WHERE 

        //     /* The dispatch of the invitations should be initiated 21 days after setting the status "Complete" (dispatch from initiation "immediately") */
		//     h.status = 'complete' AND 
		//     h.created_at <= CURRENT_DATE() - INTERVAL 14 DAY AND

               /* only the orders of the last 60 days are considered */		
       //     o.created_at >= CURRENT_DATE() - INTERVAL 60 DAY

               /* for orders that have the status "On Hold" at this time, no evaluation invitation should be sent out */
		//     o.status != 'holded';

        $orderCollection = $this->collectionFactory->create();
        $orderCollection->getSelect()
            ->join(['history'=>'sales_order_status_history'], 'main_table.entity_id = history.parent_id')
            ->where('history.status = ?', 'complete')
            ->where('history.created_at <= ? ', new \Zend_Db_Expr('CURRENT_DATE() - INTERVAL 14 DAY'))
            ->where('history.comment != ? ', 'trustedshops-invitation-sent')
            ->where('main_table.status != ?', 'holded')
            ->where('main_table.created_at >= ?', new \Zend_Db_Expr('CURRENT_DATE() - INTERVAL 60 DAY'))
            ->where('main_table.entity_id NOT IN ?', new \Zend_Db_Expr('
                (SELECT parent_id FROM `sales_order_status_history` history_sub  WHERE history_sub.parent_id = history.parent_id AND history_sub.comment LIKE "%trustedshops%")
            '));

        $this->logger->info('Executing query: ['. (string) $orderCollection->getSelect().']');
        return $orderCollection;
    }
}