<?php

namespace ClassyLlama\AvaTax\Framework\Interaction;

use AvaTax\LineFactory;
use ClassyLlama\AvaTax\Framework\Interaction\MetaData\MetaDataObjectFactory;
use ClassyLlama\AvaTax\Model\Config;
use Magento\Catalog\Model\ResourceModel\Product as ResourceProduct;

class Line
{
    /**
     * @var Config
     */
    protected $config = null;

    /**
     * @var \ClassyLlama\AvaTax\Helper\TaxClass
     */
    protected $taxClassHelper;

    /**
     * @var LineFactory
     */
    protected $lineFactory = null;

    /**
     * @var ResourceProduct
     */
    protected $resourceProduct = null;

    /**
     * @var array
     */
    protected $productSkus = [];

    /**
     * Description for shipping line
     */
    const SHIPPING_LINE_DESCRIPTION = 'Shipping costs';

    /**
     * An arbitrary ID used to track tax for shipping
     */
    const SHIPPING_LINE_NO = 'shipping';

    /**
     * Description for Gift Wrap order line
     */
    const GIFT_WRAP_ORDER_LINE_DESCRIPTION = 'Gift Wrap Order Amount';

    /**
     * Description for Gift Wrap item line
     */
    const GIFT_WRAP_ITEM_LINE_DESCRIPTION = 'Gift Wrap Items Amount';

    /**
     * Description for Gift Wrap card line
     */
    const GIFT_WRAP_CARD_LINE_DESCRIPTION = 'Gift Wrap Printed Card Amount';

    /**
     * Description for Adjustment refund line
     */
    const ADJUSTMENT_POSITIVE_LINE_DESCRIPTION = 'Adjustment refund';

    /**
     * Description for Adjustment fee line
     */
    const ADJUSTMENT_NEGATIVE_LINE_DESCRIPTION = 'Adjustment fee';

    /**
     * A list of valid fields for the data array and meta data about their types to use in validation
     * based on the API documentation.
     * Validation based on API documentation found here:
     * http://developer.avalara.com/wp-content/apireference/master/?php#line30
     *
     * @var array
     */
    public static $validFields = [
        'StoreId' => ['type' => 'integer', 'use_in_cache_key' => false],
        'No' => ['type' => 'string', 'length' => 50, 'required' => true],
        'OriginAddress' => ['type' => 'object', 'class' => '\AvaTax\Address'],
        'DestinationAddress' => ['type' => 'object', 'class' => '\AvaTax\Address'],
        'ItemCode' => ['type' => 'string', 'length' => 50],
        'TaxCode' => ['type' => 'string', 'length' => 25],
        'ExemptionNo' => ['type' => 'string', 'length' => 25],
        'Description' => ['type' => 'string', 'length' => 255],
        'Qty' => ['type' => 'double'],
        'Amount' => ['type' => 'double'], // Required but $0 value is acceptable so removing required attribute.
        'Discounted' => ['type' => 'boolean'],
        'TaxIncluded' => ['type' => 'boolean'],
        'Ref1' => ['type' => 'string', 'length' => 250],
        'Ref2' => ['type' => 'string', 'length' => 250],
        'TaxOverride' => ['type' => 'object', 'class' => '\AvaTax\TaxOverride'],
    ];

    /**
     * Index that will be incremented for \AvaTax\Line numbers
     *
     * @var int
     */
    protected $lineNumberIndex = 0;

    /**
     * Class constructor
     *
     * @param Config $config
     * @param \ClassyLlama\AvaTax\Helper\TaxClass $taxClassHelper
     * @param LineFactory $lineFactory
     * @param ResourceProduct $resourceProduct
     */
    public function __construct(
        Config $config,
        \ClassyLlama\AvaTax\Helper\TaxClass $taxClassHelper,
        MetaDataObjectFactory $metaDataObjectFactory,
        LineFactory $lineFactory,
        ResourceProduct $resourceProduct
    ) {
        $this->config = $config;
        $this->taxClassHelper = $taxClassHelper;
        $this->metaDataObject = $metaDataObjectFactory->create(['metaDataProperties' => $this::$validFields]);
        $this->lineFactory = $lineFactory;
        $this->resourceProduct = $resourceProduct;
    }

    /**
     * Return an array with relevant data from an invoice item
     *
     * All TODOs in the doc block and the method body apply to all 4 conversion methods
     * TODO: Fields to figure out: tax_override
     *
     * @param \Magento\Sales\Api\Data\InvoiceItemInterface $item
     * @return array|bool
     */
    protected function convertInvoiceItemToData(\Magento\Sales\Api\Data\InvoiceItemInterface $item)
    {
        if (!$this->isProductCalculated($item->getOrderItem())) {
            return false;
        }

        // The AvaTax 15 API doesn't support the concept of line-based discounts, so subtract discount amount
        // from taxable amount
        $amount = $item->getBaseRowTotal() - $item->getBaseDiscountAmount();

        if ($item->getQty() == 0 || $amount == 0) {
            return false;
        }

        return [
            'StoreId' => $item->getStoreId(),
            'No' => $this->getLineNumber(),
            'ItemCode' => $item->getSku(),
            'TaxCode' => $this->taxClassHelper->getAvataxTaxCodeForProduct($item->getOrderItem()->getProduct()),
            'Description' => $item->getName(),
            'Qty' => $item->getQty(),
            'Amount' => $amount,
            'Discounted' => (bool)($item->getBaseDiscountAmount() > 0),
            'TaxIncluded' => false,
            'Ref1' => $this->config->getRef1($item->getStoreId()), // TODO: Switch to getting values from buy request and put data on buy request
            'Ref2' => $this->config->getRef2($item->getStoreId()),
//            'TaxOverride' => null,
        ];
    }

    /**
     * Return an array with relevant data from an credit memo item
     *
     * @param \Magento\Sales\Api\Data\CreditmemoItemInterface $item
     * @return array|bool
     */
    protected function convertCreditMemoItemToData(\Magento\Sales\Api\Data\CreditmemoItemInterface $item)
    {
        if (!$this->isProductCalculated($item->getOrderItem())) {
            return false;
        }

        // The AvaTax 15 API doesn't support the concept of line-based discounts, so subtract discount amount
        // from taxable amount
        $amount = $item->getBaseRowTotal() - $item->getBaseDiscountAmount();

        // Credit memo amounts need to be sent to AvaTax as negative numbers
        $amount *= -1;

        if ($item->getQty() == 0 || $amount == 0) {
            return false;
        }

        return [
            'StoreId' => $item->getStoreId(),
            'No' => $this->getLineNumber(),
            'ItemCode' => $item->getSku(),
            'TaxCode' => $this->taxClassHelper->getAvataxTaxCodeForProduct($item->getOrderItem()->getProduct()),
            'Description' => $item->getName(),
            'Qty' => $item->getQty(),
            'Amount' => $amount,
            'Discounted' => (bool)($item->getBaseDiscountAmount() > 0),
            'TaxIncluded' => false,
            'Ref1' => $this->config->getRef1($item->getStoreId()), // TODO: Switch to getting values from buy request and put data on buy request
            'Ref2' => $this->config->getRef2($item->getStoreId()),
//            'TaxOverride' => null,
        ];
    }

    /**
     * Convert \Magento\Tax\Model\Sales\Quote\ItemDetails to an array to be used for building an \AvaTax\Line object
     *
     * @param \Magento\Tax\Api\Data\QuoteDetailsItemInterface $item
     * @return array
     */
    protected function convertTaxQuoteDetailsItemToData(\Magento\Tax\Api\Data\QuoteDetailsItemInterface $item)
    {
        $extensionAttributes = $item->getExtensionAttributes();
        if ($extensionAttributes) {
            $quantity = $extensionAttributes->getTotalQuantity() !== null
                ? $extensionAttributes->getTotalQuantity()
                : $item->getQuantity();
        } else {
            $quantity = $item->getQuantity();
        }

        $itemCode = $extensionAttributes ? $extensionAttributes->getAvataxItemCode() : '';
        $description = $extensionAttributes ? $extensionAttributes->getAvataxDescription() : '';
        $taxCode = $extensionAttributes ? $extensionAttributes->getAvataxTaxCode() : null;

        // The AvaTax 15 API doesn't support the concept of line-based discounts, so subtract discount amount
        // from taxable amount
        $amount = ($item->getUnitPrice() * $quantity) - $item->getDiscountAmount();

        $ref1 = $extensionAttributes ? $extensionAttributes->getAvataxRef1() : null;
        $ref2 = $extensionAttributes ? $extensionAttributes->getAvataxRef2() : null;

        return [
//            'StoreId' => $item->getStoreId(),
            'No' => $item->getCode(),
            'ItemCode' => $itemCode,
            'TaxCode' => $taxCode,
            'Description' => $description,
            'Qty' => $item->getQuantity(),
            'Amount' => $amount,
            'Discounted' => (bool)($item->getDiscountAmount() > 0),
            'TaxIncluded' => false,
            'Ref1' => $ref1,
            'Ref2' => $ref2,
        ];
    }

    /**
     *
     * TODO: Figure out if we need to account for Streamlined Sales Tax requirements for Description
     *
     * @author Jonathan Hodges <jonathan@classyllama.com>
     * @param $data
     * @return \AvaTax\Line|null|bool
     */
    public function getLine($data)
    {
        switch (true) {
            case ($data instanceof \Magento\Tax\Api\Data\QuoteDetailsItemInterface):
                $data = $this->convertTaxQuoteDetailsItemToData($data);
                break;
            case ($data instanceof \Magento\Sales\Api\Data\InvoiceItemInterface):
                $data = $this->convertInvoiceItemToData($data);
                break;
            case ($data instanceof \Magento\Sales\Api\Data\CreditmemoItemInterface):
                $data = $this->convertCreditMemoItemToData($data);
                break;
            case (!is_array($data)):
                return false;
                break;
        }

        if (!$data) {
            return null;
        }

        $data = $this->metaDataObject->validateData($data);
        /** @var $line \AvaTax\Line */
        $line = $this->lineFactory->create();

        $this->populateLine($data, $line);
        return $line;
    }

    /**
     * Accepts an invoice or creditmemo and returns an \AvaTax\Line object
     *
     * @param \Magento\Sales\Api\Data\InvoiceInterface|\Magento\Sales\Api\Data\CreditmemoInterface $data
     * @return \AvaTax\Line|bool
     */
    public function getShippingLine($data, $credit)
    {
        $shippingAmount = $data->getBaseShippingAmount();

        // If shipping rate doesn't have cost associated with it, do nothing
        if ($shippingAmount <= 0) {
            return false;
        }

        if ($credit) {
            $shippingAmount *= -1;
        }

        $storeId = $data->getStoreId();
        $itemCode = $this->config->getSkuShipping($storeId);
        $data = [
            'No' => $this->getLineNumber(),
            'ItemCode' => $itemCode,
            'TaxCode' => $this->taxClassHelper->getAvataxTaxCodeForShipping(),
            'Description' => self::SHIPPING_LINE_DESCRIPTION,
            'Qty' => 1,
            'Amount' => $shippingAmount,
            'Discounted' => false,
        ];

        $data = $this->metaDataObject->validateData($data);
        /** @var $line \AvaTax\Line */
        $line = $this->lineFactory->create();

        $this->populateLine($data, $line);
        return $line;
    }

    /**
     * Accepts an invoice or creditmemo and returns an \AvaTax\Line object
     *
     * @param \Magento\Sales\Api\Data\InvoiceInterface|\Magento\Sales\Api\Data\CreditmemoInterface $data
     * @return \AvaTax\Line|bool
     */
    public function getGiftWrapOrderLine($data, $credit)
    {
        $giftWrapOrderAmount = $data->getGwBasePrice();

        if ($giftWrapOrderAmount <= 0) {
            return false;
        }

        if ($credit) {
            $giftWrapOrderAmount *= -1;
        }

        $storeId = $data->getStoreId();
        $itemCode = $this->config->getSkuGiftWrapOrder($storeId);
        $data = [
            'No' => $this->getLineNumber(),
            'ItemCode' => $itemCode,
            'TaxCode' => $this->taxClassHelper->getAvataxTaxCodeForGiftOptions($storeId),
            'Description' => self::GIFT_WRAP_ORDER_LINE_DESCRIPTION,
            'Qty' => 1,
            'Amount' => $giftWrapOrderAmount,
            'Discounted' => false,
        ];


        $data = $this->metaDataObject->validateData($data);
        /** @var $line \AvaTax\Line */
        $line = $this->lineFactory->create();

        $this->populateLine($data, $line);
        return $line;
    }

    /**
     * Accepts an invoice or creditmemo and returns an \AvaTax\Line object
     *
     * @param \Magento\Sales\Api\Data\InvoiceInterface|\Magento\Sales\Api\Data\CreditmemoInterface $data
     * @return \AvaTax\Line|bool
     */
    public function getGiftWrapItemsLine($data, $credit) {
        $giftWrapItemsPrice = $data->getGwItemsBasePrice();

        if ($giftWrapItemsPrice <= 0) {
            return false;
        }
//        $qty = $data->getTotalQty();

//        $giftWrapItemAmount = $giftWrapItemsPrice * $qty;
        $giftWrapItemAmount = $giftWrapItemsPrice;

        if ($credit) {
            $giftWrapItemAmount *= -1;
        }

        $storeId = $data->getStoreId();
        $itemCode = $this->config->getSkuShippingGiftWrapItem($storeId);
        $data = [
            'No' => $this->getLineNumber(),
            'ItemCode' => $itemCode,
            'TaxCode' => $this->taxClassHelper->getAvataxTaxCodeForGiftOptions($storeId),
            'Description' => self::GIFT_WRAP_ITEM_LINE_DESCRIPTION,
            'Qty' => 1,
            'Amount' => $giftWrapItemAmount,
            'Discounted' => false,
        ];

        $data = $this->metaDataObject->validateData($data);
        /** @var $line \AvaTax\Line */
        $line = $this->lineFactory->create();

        $this->populateLine($data, $line);
        return $line;
    }

    /**
     * Accepts an invoice or creditmemo and returns an \AvaTax\Line object
     *
     * @param \Magento\Sales\Api\Data\InvoiceInterface|\Magento\Sales\Api\Data\CreditmemoInterface $data
     * @return \AvaTax\Line|bool
     */
    public function getGiftWrapCardLine($data, $credit) {
        $giftWrapCardAmount = $data->getGwCardBasePrice();

        if ($giftWrapCardAmount <= 0) {
            return false;
        }

        if ($credit) {
            $giftWrapCardAmount *= -1;
        }

        $storeId = $data->getStoreId();
        $itemCode = $this->config->getSkuShippingGiftWrapCard($storeId);
        $data = [
            'No' => $this->getLineNumber(),
            'ItemCode' => $itemCode,
            'TaxCode' => $this->taxClassHelper->getAvataxTaxCodeForGiftOptions($storeId),
            'Description' => self::GIFT_WRAP_CARD_LINE_DESCRIPTION,
            'Qty' => 1,
            'Amount' => $giftWrapCardAmount,
            'Discounted' => false,
        ];

        $data = $this->metaDataObject->validateData($data);
        /** @var $line \AvaTax\Line */
        $line = $this->lineFactory->create();

        $this->populateLine($data, $line);
        return $line;
    }

    /**
     * Accepts an invoice or creditmemo and returns an \AvaTax\Line object
     *
     * @param \Magento\Sales\Api\Data\InvoiceInterface|\Magento\Sales\Api\Data\CreditmemoInterface $data
     * @return \AvaTax\Line|bool
     */
    public function getPositiveAdjustmentLine($data) {
        $amount = $data->getBaseAdjustmentPositive();

        if ($amount == 0) {
            return false;
        }

        // Credit memo amounts need to be sent to AvaTax as negative numbers
        $amount *= -1;

        $storeId = $data->getStoreId();
        $itemCode = $this->config->getSkuAdjustmentPositive($storeId);
        $data = [
            'No' => $this->getLineNumber(),
            'ItemCode' => $itemCode,
            // Intentionally excluding TaxCode key
            'Description' => self::ADJUSTMENT_POSITIVE_LINE_DESCRIPTION,
            'Qty' => 1,
            'Amount' => $amount,
            'Discounted' => false,
            // Since taxes will already be included in this amount, set this flag to true
            'TaxIncluded' => true
        ];

        $data = $this->metaDataObject->validateData($data);
        /** @var $line \AvaTax\Line */
        $line = $this->lineFactory->create();

        $this->populateLine($data, $line);
        return $line;
    }

    /**
     * Accepts an invoice or creditmemo and returns an \AvaTax\Line object
     *
     * @param \Magento\Sales\Api\Data\InvoiceInterface|\Magento\Sales\Api\Data\CreditmemoInterface $data
     * @return \AvaTax\Line|bool
     */
    public function getNegativeAdjustmentLine($data) {
        $amount = $data->getBaseAdjustmentNegative();

        if ($amount == 0) {
            return false;
        }

        $storeId = $data->getStoreId();
        $itemCode = $this->config->getSkuAdjustmentNegative($storeId);
        $data = [
            'No' => $this->getLineNumber(),
            'ItemCode' => $itemCode,
            // Intentionally excluding TaxCode key
            'Description' => self::ADJUSTMENT_NEGATIVE_LINE_DESCRIPTION,
            'Qty' => 1,
            'Amount' => $amount,
            'Discounted' => false,
            // Since taxes will already be included in this amount, set this flag to true
            'TaxIncluded' => true
        ];

        $data = $this->metaDataObject->validateData($data);
        /** @var $line \AvaTax\Line */
        $line = $this->lineFactory->create();

        $this->populateLine($data, $line);
        return $line;
    }
    /**
     * @param array $data
     * @param \AvaTax\Line $line
     */
    protected function populateLine(array $data, \AvaTax\Line $line)
    {
        // Set any data elements that exist on the getTaxRequest
        foreach ($data as $key => $datum) {
            $methodName = 'set' . $key;
            if (method_exists($line, $methodName)) {
                $line->$methodName($datum);
            }
        }
        return $line;
    }

    /**
     * Get line number for \AvaTax\Line "No" field
     *
     * @return int
     */
    protected function getLineNumber()
    {
        return ++$this->lineNumberIndex;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderItemInterface $item
     * @return bool
     */
    protected function isProductCalculated(\Magento\Sales\Api\Data\OrderItemInterface $item)
    {
        // @see \Magento\Tax\Model\Sales\Total\Quote\CommonTaxCollector::mapItems
        if ($item->getHasChildren() && $item->isChildrenCalculated()) {
            return false;
        } else {
            return true;
        }
    }
}
