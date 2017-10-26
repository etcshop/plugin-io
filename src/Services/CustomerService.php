<?php //strict

namespace IO\Services;

use IO\Builder\Order\OrderType;
use IO\Models\LocalizedOrder;
use IO\Validators\Customer\ContactValidator;
use IO\Validators\Customer\AddressValidator;
use Plenty\Modules\Account\Address\Models\AddressOption;
use Plenty\Modules\Account\Contact\Contracts\ContactRepositoryContract;
use Plenty\Modules\Account\Contact\Contracts\ContactAddressRepositoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Account\Contact\Models\Contact;
use IO\Builder\Order\AddressType;
use Plenty\Modules\Account\Address\Models\Address;
use Plenty\Modules\Authorization\Services\AuthHelper;
use IO\Helper\UserSession;
use Plenty\Modules\Frontend\Events\FrontendCustomerAddressChanged;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use IO\Services\SessionStorageService;
use IO\Constants\SessionStorageKeys;
use IO\Services\OrderService;
use IO\Services\NotificationService;
use IO\Services\CustomerPasswordResetService;
use Plenty\Plugin\Events\Dispatcher;

/**
 * Class CustomerService
 * @package IO\Services
 */
class CustomerService
{
	/**
	 * @var ContactRepositoryContract
	 */
	private $contactRepository;
	/**
	 * @var ContactAddressRepositoryContract
	 */
	private $contactAddressRepository;
    /**
     * @var AddressRepositoryContract
     */
    private $addressRepository;
    /**
     * @var SessionStorageService
     */
    private $sessionStorage;
	/**
	 * @var UserSession
	 */
	private $userSession = null;

    /**
     * CustomerService constructor.
     * @param ContactRepositoryContract $contactRepository
     * @param ContactAddressRepositoryContract $contactAddressRepository
     * @param AddressRepositoryContract $addressRepository
     * @param \IO\Services\SessionStorageService $sessionStorage
     */
	public function __construct(
		ContactRepositoryContract $contactRepository,
		ContactAddressRepositoryContract $contactAddressRepository,
        AddressRepositoryContract $addressRepository,
        SessionStorageService $sessionStorage)
	{
		$this->contactRepository        = $contactRepository;
		$this->contactAddressRepository = $contactAddressRepository;
        $this->addressRepository        = $addressRepository;
        $this->sessionStorage           = $sessionStorage;
	}

    /**
     * Get the ID of the current contact from the session
     * @return int
     */
	public function getContactId():int
	{
		if($this->userSession === null)
		{
			$this->userSession = pluginApp(UserSession::class);
		}
		return $this->userSession->getCurrentContactId();
	}

    /**
     * Create a contact with addresses if specified
     * @param array $contactData
     * @param null $billingAddressData
     * @param null $deliveryAddressData
     * @return Contact
     */
	public function registerCustomer(array $contactData, $billingAddressData = null, $deliveryAddressData = null)
	{
        /**
         * @var BasketService $basketService
         */
        $basketService = pluginApp(BasketService::class);
        
        $guestBillingAddress = null;
        //$guestBillingAddressId = $this->sessionStorage->getSessionValue(SessionStorageKeys::BILLING_ADDRESS_ID);
        $guestBillingAddressId = $basketService->getBillingAddressId();
        if((int)$guestBillingAddressId > 0)
        {
            $guestBillingAddress = $this->addressRepository->findAddressById($guestBillingAddressId);
        }
        
        $guestDeliveryAddress = null;
        //$guestDeliveryAddressId = $this->sessionStorage->getSessionValue(SessionStorageKeys::DELIVERY_ADDRESS_ID);
        $guestDeliveryAddressId = $basketService->getDeliveryAddressId();
        if((int)$guestDeliveryAddressId > 0)
        {
            $guestDeliveryAddress = $this->addressRepository->findAddressById($guestDeliveryAddressId);
        }
        
        $contact = $this->createContact($contactData);
        
        if(!is_null($contact) && $contact->id > 0)
        {
            //Login
            pluginApp(AuthenticationService::class)->loginWithContactId($contact->id, (string)$contactData['password']);
            
            if($guestBillingAddress !== null)
            {
                $newBillingAddress = $this->createAddress(
                    $guestBillingAddress->toArray(),
                    AddressType::BILLING
                );
                //$this->sessionStorage->setSessionValue(SessionStorageKeys::BILLING_ADDRESS_ID, $newBillingAddress->id);
                $basketService->setBillingAddressId($newBillingAddress->id);
            }
            
            if($guestDeliveryAddress !== null)
            {
                $newDeliveryAddress = $this->createAddress(
                    $guestDeliveryAddress->toArray(),
                    AddressType::DELIVERY
                );
                //$this->sessionStorage->setSessionValue(SessionStorageKeys::DELIVERY_ADDRESS_ID, $newDeliveryAddress->id);
                $basketService->setDeliveryAddressId($newDeliveryAddress->id);
            }
    
            if($billingAddressData !== null)
            {
                $newBillingAddress = $this->createAddress($billingAddressData, AddressType::BILLING);
                //$this->sessionStorage->setSessionValue(SessionStorageKeys::BILLING_ADDRESS_ID, $newBillingAddress->id);
                $basketService->setBillingAddressId($newBillingAddress->id);
            }
    
            if($deliveryAddressData !== null)
            {
                $newDeliveryAddress = $this->createAddress($deliveryAddressData, AddressType::DELIVERY);
                //$this->sessionStorage->setSessionValue(SessionStorageKeys::DELIVERY_ADDRESS_ID, $newDeliveryAddress->id);
                $basketService->setDeliveryAddressId($newDeliveryAddress->id);
            }
        }
        
		return $contact;
	}

    /**
     * Create a new contact
     * @param array $contactData
     * @return Contact
     */
	public function createContact(array $contactData)
	{
	    $contact = null;
	    $contactData['checkForExistingEmail'] = true;
	    
	    try
        {
            $contact = $this->contactRepository->createContact($contactData);
        }
        catch(\Exception $e)
        {
            $contact = 'Die angegebene E-Mail-Adresse existiert bereits';
        }
		
		return $contact;
	}

    /**
     * Find the current contact by ID
     * @return null|Contact
     */
	public function getContact()
	{
		if($this->getContactId() > 0)
		{
			return $this->contactRepository->findContactById($this->getContactId());
		}
		return null;
	}

    /**
     * Update a contact
     * @param array $contactData
     * @return null|Contact
     */
	public function updateContact(array $contactData)
	{
		if($this->getContactId() > 0)
		{
			return $this->contactRepository->updateContact($contactData, $this->getContactId());
		}

		return null;
	}
	
	public function updatePassword($newPassword, $contactId = 0, $hash='')
    {
        /**
         * @var CustomerPasswordResetService $customerPasswordResetService
         */
        $customerPasswordResetService = pluginApp(CustomerPasswordResetService::class);
        
        if((int)$this->getContactId() <= 0 && strlen($hash) && $customerPasswordResetService->checkHash($contactId, $hash))
        {
            /** @var AuthHelper $authHelper */
            $authHelper = pluginApp(AuthHelper::class);
            $contactRepo = $this->contactRepository;
            
            $result = $authHelper->processUnguarded( function() use ($newPassword, $contactId, $contactRepo)
            {
                return $contactRepo->updateContact([
                                                        'changeOnlyPassword' => true,
                                                        'password'           => $newPassword
                                                   ],
                                                   (int)$contactId);
            });
            
            if($result instanceof Contact && (int)$result->id > 0)
            {
                $customerPasswordResetService->deleteHash($contactId);
            }
        }
        else
        {
            $result = $this->updateContact([
                                                'changeOnlyPassword' => true,
                                                'password'           => $newPassword
                                           ]);
        }
        
        return $result;
    }

    /**
     * List the addresses of a contact
     * @param null $type
     * @return array|\Illuminate\Database\Eloquent\Collection
     */
	public function getAddresses($type = null)
	{
        if($this->getContactId() > 0)
        {
            return $this->contactAddressRepository->getAddresses($this->getContactId(), $type);
        }
        else
        {
            /**
             * @var BasketService $basketService
             */
            $basketService = pluginApp(BasketService::class);
            
            $address = null;
            
            if($type == AddressType::BILLING && $basketService->getBillingAddressId() > 0)
            {
                $address = $this->addressRepository->findAddressById($basketService->getBillingAddressId());
            }
            elseif($type == AddressType::DELIVERY && $basketService->getDeliveryAddressId() > 0)
            {
                $address = $this->addressRepository->findAddressById($basketService->getDeliveryAddressId());
            }
    
            if($address instanceof Address)
            {
                return [
                    $address
                ];
            }
    
            return [];
        }
	}

    /**
     * Get an address by ID
     * @param int $addressId
     * @param int $type
     * @return Address
     */
	public function getAddress(int $addressId, int $type):Address
	{
        if($this->getContactId() > 0)
        {
            return $this->contactAddressRepository->getAddress($addressId, $this->getContactId(), $type);
        }
        else
        {
            /**
             * @var BasketService $basketService
             */
            $basketService = pluginApp(BasketService::class);
            
            if($type == AddressType::BILLING)
            {
                return $this->addressRepository->findAddressById($basketService->getBillingAddressId());
            }
            elseif($type == AddressType::DELIVERY)
            {
                return $this->addressRepository->findAddressById($basketService->getDeliveryAddressId());
            }
        }
	}

    /**
     * Create an address with the specified address type
     * @param array $addressData
     * @param int $type
     * @return Address
     */
	public function createAddress(array $addressData, int $type):Address
	{
        AddressValidator::validateOrFail($type, $addressData);
        
        if(AddressValidator::isEnAddress($addressData['countryId']))
        {
            $addressData['useAddressLightValidator'] = true;
        }
        
        if (isset($addressData['stateId']) && empty($addressData['stateId']))
        {
            $addressData['stateId'] = null;
        }
        
        $newAddress = null;
        
        if($this->getContactId() > 0)
        {
            $addressData['options'] = $this->buildAddressEmailOptions([], false, $addressData);
            $newAddress = $this->contactAddressRepository->createAddress($addressData, $this->getContactId(), $type);
        }
		else
        {
            $addressData['options'] = $this->buildAddressEmailOptions([], true, $addressData);
            $newAddress =  $this->addressRepository->createAddress($addressData);
        }
        
        /**
         * @var BasketService $basketService
         */
        $basketService = pluginApp(BasketService::class);
        
        if($newAddress instanceof Address)
        {
            if($type == AddressType::BILLING)
            {
                $basketService->setBillingAddressId($newAddress->id);
            }
            elseif($type == AddressType::DELIVERY)
            {
                $basketService->setDeliveryAddressId($newAddress->id);
            }
        }
        
        return $newAddress;
	}
	
	private function buildAddressEmailOptions(array $options = [], $isGuest = false, $addressData = [])
    {
        if($isGuest)
        {
            /**
             * @var SessionStorageService $sessionStorage
             */
            $sessionStorage = pluginApp(SessionStorageService::class);
            $email = $sessionStorage->getSessionValue(SessionStorageKeys::GUEST_EMAIL);
        }
        else
        {
            $email = $this->getContact()->email;
        }
        
        if(strlen($email))
        {
            $options[] = [
                'typeId' => AddressOption::TYPE_EMAIL,
                'value' => $email
            ];
        }
        
        if(count($addressData))
        {
            if(isset($addressData['vatNumber']))
            {
                $options[] = [
                    'typeId' => AddressOption::TYPE_VAT_NUMBER,
                    'value'  => $addressData['vatNumber']
                ];
            }
            
            if(isset($addressData['birthday']))
            {
                $options[] = [
                    'typeId' => AddressOption::TYPE_BIRTHDAY,
                    'value'  => $addressData['birthday']
                ];
            }

            if(isset($addressData['title']))
            {
                $options[] = [
                    'typeId' => AddressOption::TYPE_TITLE,
                    'value'  => $addressData['title']
                ];
            }

            if(isset($addressData['telephone']))
            {
                $options[] = [
                    'typeId' => AddressOption::TYPE_TELEPHONE,
                    'value'  => $addressData['telephone']
                ];
            }
            
            if(isset($addressData['address2']) && (strtoupper($addressData['address1']) == 'PACKSTATION' || strtoupper($addressData['address1']) == 'POSTFILIALE') && isset($addressData['address3']))
            {
                $options[] =
                [
                    'typeId' => 6,
                    'value' => $addressData['address3']
                ];
            }
            
        }
        
        return $options;
    }

    /**
     * Update an address
     * @param int $addressId
     * @param array $addressData
     * @param int $type
     * @return Address
     */
    public function updateAddress(int $addressId, array $addressData, int $type):Address
    {
        AddressValidator::validateOrFail($type, $addressData);

        if (isset($addressData['stateId']) && empty($addressData['stateId']))
        {
            $addressData['stateId'] = null;
        }

        if (isset($addressData['checkedAt']) && empty($addressData['checkedAt']))
        {
            unset($addressData['checkedAt']);
        }

        if((int)$this->getContactId() > 0)
        {
            $addressData['options'] = $this->buildAddressEmailOptions([], false, $addressData);
            $newAddress = $this->contactAddressRepository->updateAddress($addressData, $addressId, $this->getContactId(), $type);
        }
        else
        {
            //case for guests
            $addressData['options'] = $this->buildAddressEmailOptions([], true, $addressData);
            $newAddress = $this->addressRepository->updateAddress($addressData, $addressId);
        }
        
        

        //fire public event
        /** @var Dispatcher $pluginEventDispatcher */
        $pluginEventDispatcher = pluginApp(Dispatcher::class);
        $pluginEventDispatcher->fire(FrontendCustomerAddressChanged::class);

        return $newAddress;
    }

    /**
     * Delete an address
     * @param int $addressId
     * @param int $type
     */
	public function deleteAddress(int $addressId, int $type = 0)
	{
        /**
         * @var BasketService $basketService
         */
        $basketService = pluginApp(BasketService::class);
	    
        if($this->getContactId() > 0)
        {
            $this->contactAddressRepository->deleteAddress($addressId, $this->getContactId(), $type);
            
            if($type == AddressType::BILLING)
            {
                $basketService->setBillingAddressId(0);
            }
            elseif($type == AddressType::DELIVERY)
            {
                $basketService->setDeliveryAddressId(-99);
            }
        }
        else
        {
            $this->addressRepository->deleteAddress($addressId);
            if($addressId == $basketService->getBillingAddressId())
            {
                $basketService->setBillingAddressId(0);
            }
            elseif($addressId == $basketService->getDeliveryAddressId())
            {
                $basketService->setDeliveryAddressId(-99);
            }
        }
	}

    /**
     * Get a list of orders for the current contact
     * @param int $page
     * @param int $items
     * @param array $filters
     * @return array|\Plenty\Repositories\Models\PaginatedResult
     */
	public function getOrders(int $page = 1, int $items = 10, array $filters = [])
	{
		$orders = [];
        
        try
        {
            $orders = pluginApp(OrderService::class)->getOrdersForContact(
                $this->getContactId(),
                $page,
                $items,
                $filters
            );
        }
        catch(\Exception $e)
        {}

        return $orders;
	}
	
	public function hasReturns()
    {
        $returns = $this->getReturns(1, 1, [], false);
        if(count($returns->getResult()))
        {
            return true;
        }
        
        return false;
    }
	
	public function getReturns(int $page = 1, int $items = 10, array $filters = [], $wrapped = true)
    {
        $filters['orderType'] = OrderType::RETURNS;
        
        return pluginApp(OrderService::class)->getOrdersForContact(
            $this->getContactId(),
            $page,
            $items,
            $filters,
            $wrapped
        );
    }

    /**
     * Get the last order created by the current contact
     * @return LocalizedOrder
     */
	public function getLatestOrder()
	{
        return pluginApp(OrderService::class)->getLatestOrderForContact(
            $this->getContactId()
        );
	}
}
