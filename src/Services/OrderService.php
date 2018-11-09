<?php //strict

namespace IO\Services;

use IO\Builder\Order\AddressType;
use IO\Builder\Order\OrderBuilder;
use IO\Builder\Order\OrderItemType;
use IO\Builder\Order\OrderType;
use IO\Builder\Order\OrderOptionSubType;
use IO\Constants\OrderPaymentStatus;
use IO\Constants\SessionStorageKeys;
use IO\Models\LocalizedOrder;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Frontend\PaymentMethod\Contracts\FrontendPaymentMethodRepositoryContract;
use Plenty\Modules\Helper\AutomaticEmail\Contracts\AutomaticEmailContract;
use Plenty\Modules\Helper\AutomaticEmail\Models\AutomaticEmail;
use Plenty\Modules\Helper\AutomaticEmail\Models\AutomaticEmailOrder;
use Plenty\Modules\Helper\AutomaticEmail\Models\AutomaticEmailTemplate;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Property\Contracts\OrderPropertyRepositoryContract;
use Plenty\Modules\Order\Property\Models\OrderPropertyType;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Repositories\Models\PaginatedResult;
use Plenty\Modules\Order\Models\Order;
use Plenty\Plugin\Application;
use Plenty\Plugin\ConfigRepository;

/**
 * Class OrderService
 * @package IO\Services
 */
class OrderService
{
	/**
	 * @var OrderRepositoryContract
	 */
	private $orderRepository;
	/**
	 * @var BasketService
	 */
	private $basketService;
    /**
     * @var SessionStorageService
     */
    private $sessionStorage;
    
    /**
     * @var FrontendPaymentMethodRepositoryContract
     */
    private $frontendPaymentMethodRepository;
    /**
     * @var AddressRepositoryContract
     */
    private $addressRepository;
    /**
     * @var UrlService
     */
    private $urlService;

    /**
     * @var AutomaticEmailContract
     */
    private $automaticEmailRepository;

    /**
     * The OrderItem types that will be wrapped. All other OrderItems will be stripped from the order.
     */
    const WRAPPED_ORDERITEM_TYPES =
    [
        OrderItemType::VARIATION,
        OrderItemType::ITEM_BUNDLE,
        OrderItemType::BUNDLE_COMPONENT,
        OrderItemType::UNASSIGNED_VARIATION
    ];

    /**
     * OrderService constructor.
     * @param OrderRepositoryContract $orderRepository
     * @param BasketService $basketService
     * @param \IO\Services\SessionStorageService $sessionStorage
     * @param FrontendPaymentMethodRepositoryContract $frontendPaymentMethodRepository
     * @param AddressRepositoryContract $addressRepository
     * @param \IO\Services\UrlService $urlService
     * @param AutomaticEmailContract $automaticEmailRepositoryContract
     */
	public function __construct(
		OrderRepositoryContract $orderRepository,
		BasketService $basketService,
        SessionStorageService $sessionStorage,
        FrontendPaymentMethodRepositoryContract $frontendPaymentMethodRepository,
        AddressRepositoryContract $addressRepository,
        UrlService $urlService,
        AutomaticEmailContract $automaticEmailRepositoryContract
	)
	{
		$this->orderRepository = $orderRepository;
		$this->basketService   = $basketService;
        $this->sessionStorage  = $sessionStorage;
        $this->frontendPaymentMethodRepository = $frontendPaymentMethodRepository;
        $this->addressRepository = $addressRepository;
        $this->urlService = $urlService;
        $this->automaticEmailRepository = $automaticEmailRepositoryContract;
	}

    /**
     * Place an order
     * @return LocalizedOrder
     */
	public function placeOrder():LocalizedOrder
	{
	    /** @var CheckoutService $checkoutService */
        $checkoutService = pluginApp(CheckoutService::class);
        
        /** @var CustomerService $customerService */
        $customerService = pluginApp(CustomerService::class);
        
        $basket = $this->basketService->getBasket();
        
        $couponCode = null;
        if(strlen($basket->couponCode))
        {
            $couponCode = $basket->couponCode;
        }

        $isShippingPrivacyHintAccepted = $this->sessionStorage->getSessionValue(SessionStorageKeys::SHIPPING_PRIVACY_HINT_ACCEPTED);

        if(is_null($isShippingPrivacyHintAccepted) || !strlen($isShippingPrivacyHintAccepted))
        {
            $isShippingPrivacyHintAccepted = 'false';
        }

        $order = pluginApp(OrderBuilder::class)->prepare(OrderType::ORDER)
            ->fromBasket()
            ->withContactId($customerService->getContactId())
            ->withAddressId($checkoutService->getBillingAddressId(), AddressType::BILLING)
            ->withAddressId($checkoutService->getDeliveryAddressId(), AddressType::DELIVERY)
            ->withOrderProperty(OrderPropertyType::PAYMENT_METHOD, OrderOptionSubType::MAIN_VALUE, $checkoutService->getMethodOfPaymentId())
            ->withOrderProperty(OrderPropertyType::SHIPPING_PROFILE, OrderOptionSubType::MAIN_VALUE, $checkoutService->getShippingProfileId())
            ->withOrderProperty(OrderPropertyType::DOCUMENT_LANGUAGE, OrderOptionSubType::MAIN_VALUE, $this->sessionStorage->getLang())
            ->withOrderProperty(OrderPropertyType::SHIPPING_PRIVACY_HINT_ACCEPTED, OrderOptionSubType::MAIN_VALUE, $isShippingPrivacyHintAccepted)
            ->withComment(true, $this->sessionStorage->getSessionValue(SessionStorageKeys::ORDER_CONTACT_WISH))
            ->done();

        $order = $this->orderRepository->createOrder($order, $couponCode);

        if ($order instanceof Order && $order->id > 0) {
            /**
             * @var AutomaticEmailOrder $emailData
             */
            $emailData = pluginApp(Application::class)->make(AutomaticEmailOrder::class, ['orderId' => $order->id]);
            /**
             * @var AutomaticEmail $email
             */
            $email = pluginApp(Application::class)->make(AutomaticEmail::class, ['template' => AutomaticEmailTemplate::SHOP_ORDER , 'emailData' => $emailData ]);
            $this->automaticEmailRepository->sendAutomatic($email);
        }

        $this->sessionStorage->setSessionValue(SessionStorageKeys::ORDER_CONTACT_WISH, null);

        if($customerService->getContactId() <= 0)
        {
            $this->sessionStorage->setSessionValue(SessionStorageKeys::LATEST_ORDER_ID, $order->id);
        }

        return LocalizedOrder::wrap( $order, $this->sessionStorage->getLang() );
	}

    /**
     * Execute the payment for a given order.
     * @param int $orderId      The order id to execute payment for
     * @param int $paymentId    The MoP-ID to execute
     * @return array            An array containing a type ("succes"|"error") and a value.
     */
	public function executePayment( int $orderId, int $paymentId ):array
    {
        $paymentRepository = pluginApp( PaymentMethodRepositoryContract::class );
        return $paymentRepository->executePayment( $paymentId, $orderId );
    }
    
    /**
     * Find an order by ID
     * @param int $orderId
     * @param bool $removeReturnItems
     * @param bool $wrap
     * @return LocalizedOrder|mixed|Order
     */
	public function findOrderById(int $orderId, $removeReturnItems = false, $wrap = true)
	{
        if($removeReturnItems)
        {
            $order = $this->removeReturnItemsFromOrder($this->orderRepository->findOrderById($orderId));
        }
        else
        {
            $order = $this->orderRepository->findOrderById($orderId);
        }
        
        if($wrap)
        {
            return LocalizedOrder::wrap($order, $this->sessionStorage->getLang());
        }
        
        return $order;
	}
	
	public function findOrderByAccessKey($orderId, $orderAccessKey)
    {
        /**
         * @var TemplateConfigService $templateConfigService
         */
        $templateConfigService = pluginApp(TemplateConfigService::class);
        $redirectToLogin = $templateConfigService->get('my_account.confirmation_link_login_redirect');
    
        $order = $this->orderRepository->findOrderByAccessKey($orderId, $orderAccessKey);
        
        if($redirectToLogin == 'true')
        {
            /**
             * @var CustomerService $customerService
             */
            $customerService = pluginApp(CustomerService::class);
    
            $orderContactId = 0;
            foreach ($order->relations as $relation)
            {
                if ($relation['referenceType'] == 'contact' && (int)$relation['referenceId'] > 0)
                {
                    $orderContactId = $relation['referenceId'];
                }
            }
    
            if ((int)$orderContactId > 0)
            {
                if ((int)$customerService->getContactId() <= 0)
                {

                    return $this->urlService->redirectTo('login?backlink=confirmation/' . $orderId . '/' . $orderAccessKey);
                }
                elseif ((int)$orderContactId !== (int)$customerService->getContactId())
                {
                    return null;
                }
            }
        }
    
        return LocalizedOrder::wrap($order, $this->sessionStorage->getLang());
    }
    
    /**
     * Get a list of orders for a contact
     * @param int $contactId
     * @param int $page
     * @param int $items
     * @param array $filters
     * @param bool $wrapped
     * @return PaginatedResult
     */
    public function getOrdersForContact(int $contactId, int $page = 1, int $items = 50, array $filters = [], $wrapped = true)
    {
        if(!isset($filters['orderType']))
        {
            $filters['orderType'] = OrderType::ORDER;
        }
        
        $this->orderRepository->setFilters($filters);

        $orders = $this->orderRepository->allOrdersByContact(
            $contactId,
            $page,
            $items
        );

        if($wrapped)
        {
            $orders = LocalizedOrder::wrapPaginated( $orders, $this->sessionStorage->getLang() );
    
            $o = $orders->getResult();
            foreach($orders->getResult() as $key => $order)
            {
                $order = $order->order;
                if($order->typeId == OrderType::ORDER)
                {
                    $o[$key]->isReturnable = $this->isOrderReturnable($order);
                }
            }
            $orders->setResult($o);
        }
        
        return $orders;
    }
    
    /**
     * Get the last order created by the current contact
     * @param int $contactId
     * @return LocalizedOrder|null
     */
    public function getLatestOrderForContact( int $contactId )
    {
        if($contactId > 0)
        {
            $order = $this->orderRepository->getLatestOrderByContactId( $contactId );
        }
        else
        {
            $order = $this->orderRepository->findOrderById($this->sessionStorage->getSessionValue(SessionStorageKeys::LATEST_ORDER_ID));
        }
        
        if(!is_null($order))
        {
            return LocalizedOrder::wrap( $order, $this->sessionStorage->getLang() );
        }
        
        return null;
    }
    
    /**
     * Return order status text by status id
     * @param $statusId
     * @return string
     */
	public function getOrderStatusText($statusId)
    {
	    //OrderStatusTexts::$orderStatusTexts[(string)$statusId];
        return '';
    }
    
    public function getOrderPropertyByOrderId($orderId, $typeId)
    {
        /**
         * @var OrderPropertyRepositoryContract $orderPropertyRepo
         */
        $orderPropertyRepo = pluginApp(OrderPropertyRepositoryContract::class);
        return $orderPropertyRepo->findByOrderId($orderId, $typeId);
    }
    
    public function isReturnActive()
    {
        /**
         * @var TemplateConfigService $templateConfigService
         */
        $templateConfigService = pluginApp(TemplateConfigService::class);
        $returnsActive = $templateConfigService->get('my_account.order_return_active', 'true');
        
        if($returnsActive == 'true')
        {
            return true;
        }
        
        return false;
    }
    
    public function isOrderReturnable(Order $order)
    {
        $returnActive = $this->isReturnActive();
        
        if($returnActive)
        {
            /**
             * @var ConfigRepository $config
             */
            $config = pluginApp(ConfigRepository::class);
            $enabledRoutes = explode(', ',  $config->get('IO.routing.enabled_routes') );
            if ( !in_array('order-return', $enabledRoutes) && !in_array('all', $enabledRoutes) )
            {
                return false;
            }
            
            $orderWithoutReturnItems = $this->removeReturnItemsFromOrder($order);
            if(!count($orderWithoutReturnItems->orderItems))
            {
                return false;
            }

            $newOrderItems = [];

            foreach($orderWithoutReturnItems->orderItems as $orderItem)
            {
                if($orderItem['bundleType'] !== 'bundle_item' && count($orderItem['references']) === 0)
                {
                    $newOrderItems[] = $orderItem;
                }
            }

            $newItemsExist = false;

            if(count($newOrderItems) > 0)
            {
                foreach($newOrderItems as $newOrderItem)
                {
                    if($newOrderItem['quantity'] > 0)
                    {
                        $newItemsExist = true;
                    }
                }
            }
            
            $shippingDateSet = false;
            $createdDateUnix = 0;
    
            foreach($order->dates as $date)
            {
                if($date->typeId == 5 && strlen($date->date))
                {
                    $shippingDateSet = true;
                }
                elseif($date->typeId == 2 && strlen($date->date))
                {
                    $createdDateUnix = $date->date->timestamp;
                }
            }
    
            /**
             * @var TemplateConfigService $templateConfigService
             */
            $templateConfigService = pluginApp(TemplateConfigService::class);
            $returnTime = (int)$templateConfigService->get('my_account.order_return_days', 14);
    
            if( $shippingDateSet && ($createdDateUnix > 0 && $returnTime > 0) && (time() < ($createdDateUnix + ($returnTime * 24 * 60 * 60))) && $newItemsExist )
            {
                return true;
            }
        }
        
        return false;
    }
    
    public function createOrderReturn($orderId, $items = [], $returnNote = '')
    {
        $order = $this->orderRepository->findOrderById($orderId);
        $order = $this->removeReturnItemsFromOrder($order);
        $order = $order->toArray();
        
        if($this->isReturnActive())
        {
            foreach($order['orderItems'] as $key => $orderItem)
            {
                if(array_key_exists($orderItem['itemVariationId'], $items) && (int)$items[$orderItem['itemVariationId']] > 0)
                {
                    $returnQuantity = (int)$items[$orderItem['itemVariationId']];
                    
                    if($returnQuantity > $order['orderItems'][$key]['quantity'])
                    {
                        $returnQuantity = $order['orderItems'][$key]['quantity'];
                    }
                    
                    $order['orderItems'][$key]['quantity'] = $returnQuantity;

                    $order['orderItems'][$key]['references'][] = [
                        'referenceOrderItemId' =>   $order['orderItems'][$key]['id'],
                        'referenceType' => 'parent'
                    ];
                    
                    unset($order['orderItems'][$key]['id']);
                    unset($order['orderItems'][$key]['orderId']);
                }
                else
                {
                    unset($order['orderItems'][$key]);
                }
            }
    
            /**
             * @var TemplateConfigService $templateConfigService
             */
            $templateConfigService = pluginApp(TemplateConfigService::class);
            $returnStatus = $templateConfigService->get('my_account.order_return_initial_status', '');
            if(!strlen($returnStatus) || (float)$returnStatus <= 0)
            {
                $returnStatus = 9.0;
            }
    
            $order['statusId'] = (float)$returnStatus;
            $order['typeId'] = OrderType::RETURNS;
    
            $order['orderReferences'][] = [
                'referenceOrderId' => $order['id'],
                'referenceType' => 'parent'
            ];
    
            unset($order['id']);
            unset($order['dates']);
            unset($order['lockStatus']);

            if(!is_null($returnNote) && strlen($returnNote))
            {
                $order["comments"][] = [
                    "isVisibleForContact" => true,
                    "text"                => $returnNote
                ];
            }

            $createdReturn = $this->orderRepository->createOrder($order);

            return $createdReturn;
        }
        
        return $order;
    }
    
    private function removeReturnItemsFromOrder($order)
    {
        $orderId = $order->id;

        $returnFilters = [
            'orderType' => OrderType::RETURNS,
            'referenceOrderId' => $orderId
        ];
        
        $allReturns = $this->getOrdersForContact(pluginApp(CustomerService::class)->getContactId(), 1, 100, $returnFilters, false)->getResult();
        
        $returnItems = [];
        $newOrderItems = [];
        
        if(count($allReturns))
        {
            foreach($allReturns as $returnKey => $return)
            {
                foreach($return['orderReferences'] as $reference)
                {
                    if($reference['referenceType'] == 'parent' && (int)$reference['referenceOrderId'] == $orderId)
                    {
                        foreach($return['orderItems'] as $returnItem)
                        {
                            if(array_key_exists($returnItem['itemVariationId'], $returnItems))
                            {
                                $returnItems[$returnItem['itemVariationId']] += $returnItem['quantity'];
                            }
                            else
                            {
                                $returnItems[$returnItem['itemVariationId']] = $returnItem['quantity'];
                            }
                        }
                    }
                }
            }
            
            if(count($returnItems))
            {
                foreach($order->orderItems as $key => $orderItem)
                {
                    if(array_key_exists($orderItem['itemVariationId'], $returnItems))
                    {
                        $newQuantity = $orderItem['quantity'] - $returnItems[$orderItem['itemVariationId']];
                    }
                    else
                    {
                        $newQuantity = $orderItem['quantity'];
                    }
    
                    if($newQuantity > 0 && in_array((int)$orderItem->typeId, self::WRAPPED_ORDERITEM_TYPES))
                    {
                        $orderItem['quantity'] = $newQuantity;
                        $newOrderItems[] = $orderItem;
                    }
                    else
                    {
                        $orderItem->quantity = 0;
                    }
                }
                
                //$order->returnItems = $newOrderItems;
                $order->orderItems = $newOrderItems;
            }
            else
            {
                foreach($order->orderItems as $key => $orderItem)
                {
                    if(in_array((int)$orderItem->typeId, self::WRAPPED_ORDERITEM_TYPES))
                    {
                        $newOrderItems[] = $orderItem;
                    }
                }
                
                //$order->returnItems = $newOrderItems;
                $order->orderItems = $newOrderItems;
            }
        }
        
        return $order;
    }
    
    public function getReturnOrder($localizedOrder)
    {
        $order = $localizedOrder->order->toArray();
        $orderId = $order['id'];
        
        $returnFilters = [
            'orderType' => OrderType::RETURNS,
            'referenceOrderId' => $orderId
        ];
    
        $allReturns = $this->getOrdersForContact(pluginApp(CustomerService::class)->getContactId(), 1, 1000, $returnFilters, false)->getResult();
    
        $returnItems = [];
        $newOrderItems = [];
    
        if(count($allReturns))
        {
            foreach ($allReturns as $returnKey => $return)
            {
                foreach ($return['orderReferences'] as $reference)
                {
                    if ($reference['referenceType'] == 'parent' && (int)$reference['referenceOrderId'] == $orderId)
                    {
                        foreach ($return['orderItems'] as $returnItem)
                        {
                            if (array_key_exists($returnItem['itemVariationId'], $returnItems))
                            {
                                $returnItems[$returnItem['itemVariationId']] += $returnItem['quantity'];
                            }
                            else
                            {
                                $returnItems[$returnItem['itemVariationId']] = $returnItem['quantity'];
                            }
                        }
                    }
                }
            }
        }
        
        if(count($returnItems))
        {
            foreach($order['orderItems'] as $key => $orderItem)
            {
                if(array_key_exists($orderItem['itemVariationId'], $returnItems))
                {
                    $newQuantity = $orderItem['quantity'] - $returnItems[$orderItem['itemVariationId']];
                }
                else
                {
                    $newQuantity = $orderItem['quantity'];
                }
            
                if($newQuantity > 0 && in_array((int)$orderItem['typeId'], self::WRAPPED_ORDERITEM_TYPES))
                {
                    $orderItem['quantity'] = $newQuantity;
                    $newOrderItems[] = $orderItem;
                }
                else
                {
                    $orderItem['quantity'] = 0;
                }
            }
        }
        else
        {
            foreach($order['orderItems'] as $key => $orderItem)
            {
                if(in_array((int)$orderItem['typeId'], self::WRAPPED_ORDERITEM_TYPES))
                {
                    $newOrderItems[] = $orderItem;
                }
            }
        }
        
        $order['orderItems'] = $newOrderItems;
        $localizedOrder->orderData = $order;
        
        return $localizedOrder;
    }
    
    /**
     * List all payment methods available for switch in MyAccount
     *
     * @param int $currentPaymentMethodId
     * @param null $orderId
     */
    public function getPaymentMethodListForSwitch($currentPaymentMethodId = 0, $orderId = null)
    {
        return $this->frontendPaymentMethodRepository->getCurrentPaymentMethodsListForSwitch($currentPaymentMethodId, $orderId, $this->sessionStorage->getLang());
    }
    
    /**
     * @param $paymentMethodId
     * @param int $orderId
     * @return bool
     */
	public function allowPaymentMethodSwitchFrom($paymentMethodId, $orderId = null)
	{
		/** @var TemplateConfigService $config */
		$config = pluginApp(TemplateConfigService::class);
		if ($config->get('my_account.change_payment') == "false")
		{
			return false;
		}
		if($orderId != null)
		{
            /** @var AuthHelper $authHelper */
            $authHelper = pluginApp(AuthHelper::class);
            $orderRepo = $this->orderRepository;
            
            $order = $authHelper->processUnguarded( function() use ($orderId, $orderRepo)
            {
                return $orderRepo->findOrderById($orderId);
            });
			
			if ($order->paymentStatus !== OrderPaymentStatus::UNPAID)
			{
				// order was paid
				return false;
			}
			
			$statusId = $order->statusId;
			$orderCreatedDate = $order->createdAt;
			
			if(!($statusId <= 3.4 || ($statusId == 5 && $orderCreatedDate->toDateString() == date('Y-m-d'))))
			{
				return false;
			}
		}
		return $this->frontendPaymentMethodRepository->getPaymentMethodSwitchFromById($paymentMethodId, $orderId);
	}
    
    
    /**
     * @param $orderId
     * @param $paymentMethodId
     * @return LocalizedOrder|null
     */
    public function switchPaymentMethodForOrder($orderId, $paymentMethodId)
    {
        if((int)$orderId > 0)
        {
            $currentPaymentMethodId = 0;
    
            /** @var AuthHelper $authHelper */
            $authHelper = pluginApp(AuthHelper::class);
            $orderRepo = $this->orderRepository;
    
            $order = $authHelper->processUnguarded( function() use ($orderId, $orderRepo)
            {
                return $orderRepo->findOrderById($orderId);
            });
        
            $newOrderProperties = [];
            $orderProperties = $order->properties;
        
            if(count($orderProperties))
            {
                foreach($orderProperties as $key => $orderProperty)
                {
                    $newOrderProperties[$key] = [
                        'typeId' => $orderProperty->typeId,
                        'value' => (string)$orderProperty->value
                    ];
                    if($orderProperty->typeId == OrderPropertyType::PAYMENT_METHOD)
                    {
                        $currentPaymentMethodId = (int)$orderProperty->value;
                        $newOrderProperties[$key]['value'] = (string)$paymentMethodId;
                    }
                }
            }
        
            if($paymentMethodId !== $currentPaymentMethodId)
            {
                if($this->frontendPaymentMethodRepository->getPaymentMethodSwitchableFromById($currentPaymentMethodId, $orderId) && $this->frontendPaymentMethodRepository->getPaymentMethodSwitchableToById($paymentMethodId))
                {
                    $order = $authHelper->processUnguarded( function() use ($orderId, $newOrderProperties, $orderRepo)
                    {
                        return $orderRepo->updateOrder(['properties' => $newOrderProperties], $orderId);
                    });
                    
                    if(!is_null($order))
                    {
                        return LocalizedOrder::wrap( $order, $this->sessionStorage->getLang() );
                    }
                }
            }
        }
    
        return null;
    }
}
