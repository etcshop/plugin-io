<?php

namespace IO\Services;

use Plenty\Legacy\Services\Accounting\VatInitService;
use Plenty\Legacy\Services\Item\Variation\DetectSalesPriceService;
use Plenty\Modules\Account\Contact\Models\Contact;
use Plenty\Modules\Accounting\Vat\Contracts\VatInitContract;
use Plenty\Modules\Frontend\Services\VatService;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\Application;
use IO\Services\CustomerService;
use IO\Services\BasketService;


/**
 * Class PriceDetectService
 * @package IO\Services
 */
class PriceDetectService
{
    private $classId = null;
    private $singleAccess = null;
    private $currency = null;
    private $plentyId = null;
    private $shippingCountryId = null;

    /**
     * @var DetectSalesPriceService
     */
    private $detectSalesPriceService;

    /**
     * @var CustomerService
     */
    private $customerService;

    /**
     * @var Application
     */
    private $app;

    /**
     * @var CheckoutService
     */
    private $checkoutService;

    /**
     * @var BasketService $basketService
     */
    private $basketService;

    /**
     * @var VatService $vatService
     */
    private $vatService;

    /**
     * @var VatInitService $vatService
     */
    private $vatInitService;

    private $referrerId;

    /**
     * PriceDetectService constructor.
     * @param DetectSalesPriceService $detectSalesPriceService
     * @param \IO\Services\CustomerService $customerService
     * @param Application $app
     * @param CheckoutService $checkoutService
     */
    public function __construct(DetectSalesPriceService $detectSalesPriceService,
                                CustomerService $customerService,
                                Application $app,
                                CheckoutService $checkoutService,
                                BasketService $basketService,
                                VatInitContract $vatInitService,
                                VatService $vatService)
    {
        $this->detectSalesPriceService = $detectSalesPriceService;
        $this->customerService = $customerService;
        $this->app = $app;
        $this->checkoutService = $checkoutService;
        $this->basketService = $basketService;
        $this->vatInitService = $vatInitService;
        $this->vatService = $vatService;

        $this->init();
    }

    private function init()
    {
        $contact = $this->customerService->getContact();

        if ($contact instanceof Contact) {
            $this->singleAccess = $contact->singleAccess;
        }

        $this->classId           = $this->customerService->getContactClassId();
        $this->currency          = $this->checkoutService->getCurrency();
        $this->shippingCountryId = $this->checkoutService->getShippingCountryId();
        $this->plentyId          = $this->app->getPlentyId();

        $referrerId = (int)$this->basketService->getBasket()->referrerId;
        $this->referrerId        = ((int)$referrerId > 0 ? $referrerId : 1);

        if(!$this->vatInitService->isInitialized())
        {
            $vat = $this->vatService->getVat();
        }
    }

    public function getPriceIdsForCustomer()
    {
        $this->detectSalesPriceService
            ->setAccountId(0)
            ->setAccountType($this->singleAccess)
            ->setCountryOfDelivery($this->shippingCountryId)
            ->setCurrency($this->currency)
            ->setCustomerClass($this->classId)
            ->setOrderReferrer($this->referrerId)
            ->setPlentyId($this->plentyId)
            ->setQuantity(-1)
            ->setType(DetectSalesPriceService::PRICE_TYPE_DEFAULT);

        return $this->detectSalesPriceService->detect();
    }
}
