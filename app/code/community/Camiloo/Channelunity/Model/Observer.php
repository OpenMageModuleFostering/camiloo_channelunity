<?php

/**
 * ChannelUnity connector for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2012 Camiloo Limited (http://www.camiloo.co.uk)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * ChannelUnity observers.
 * Posts events to the CU cloud when various Magento events occur.
 */
class Camiloo_Channelunity_Model_Observer extends Camiloo_Channelunity_Model_Abstract
{

    /**
     * Allows the observing of more generic events in Magento.
     * Useful in multiple product save for example.
     */
    public function hookToControllerActionPostDispatch($observer)
    {
        try {

            // Get event name
            $eventName = $observer->getEvent()->getControllerAction()
                    ->getFullActionName();

            // Perform tasks
            switch ($eventName) {
                case 'adminhtml_catalog_category_save':

                    // Save category
                    /**
                     * comment it because it's already called in save_after event observer (method categorySave())
                     */
                    //$this->categorySave($observer);

                    break;
                case 'adminhtml_catalog_category_delete':

                    // Delete category
                    $this->categoryDelete($observer);

                    break;
                case 'adminhtml_catalog_product_action_attribute_save':

                    // Set variables
                    $helper = Mage::helper(
                                    'adminhtml/catalog_product_edit_action_attribute'
                    );
                    $storeViewId = $helper->getSelectedStoreId();
                    $productIds = $helper->getProductIds();
                    $data = '';

                    // Add products
                    foreach ($productIds as $id) {
                        $data .= Mage::getModel('channelunity/products')
                                ->generateCuXmlForSingleProduct(
                                $id, $storeViewId
                        );
                    }

                    // Send to CU
                    $this->_updateProductData($storeViewId, $data);

                    break;
                case 'adminhtml_catalog_product_delete':

                    // Set variables
                    $storeViewId = Mage::helper(
                                    'adminhtml/catalog_product_edit_action_attribute'
                            )
                            ->getSelectedStoreId();
                    $data = '<DeletedProductId>' . $observer->getEvent()
                                    ->getControllerAction()->getRequest()
                                    ->getParam('id') . '</DeletedProductId>';

                    // Send to CU
                    $this->_updateProductData($storeViewId, $data);

                    break;
                case 'adminhtml_catalog_product_massStatus':

                    // Check for product ids
                    $productIds = $observer->getEvent()->getControllerAction()
                                    ->getRequest()->getParam('product');
                    if (!is_array($productIds) || !count($productIds)) {
                        break;
                    }

                    // Set variables
                    $storeViewId = Mage::helper(
                                    'adminhtml/catalog_product_edit_action_attribute'
                            )
                            ->getSelectedStoreId();
                    $data = '';

                    // Add product data
                    foreach ($productIds as $id) {

                        // Load product
                        $product = Mage::getModel('catalog/product')
                                ->load($id);
                        $skipProduct = Mage::getModel('channelunity/products')
                                ->skipProduct($product);

                        // Add XML
                        if ($skipProduct) {
                            $data .= '<DeletedProductId>' . $id
                                    . '</DeletedProductId>';
                        } else {
                            $data .= Mage::getModel('channelunity/products')
                                    ->generateCuXmlForSingleProduct(
                                            $id, $storeViewId
                                            );
                        }
                    }

                    // Send to CU
                    $this->_updateProductData($storeViewId, $data);

                    break;
                default:
                    break;
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Called on saving a product in Magento.
     */
    public function productWasSaved(Varien_Event_Observer $observer)
    {
        try {

            // Load product
            $product = $observer->getEvent()->getProduct();
            $skipProduct = Mage::getModel('channelunity/products')
                    ->skipProduct($product);

            // Set variables
            $stores = Mage::app()->getStores();
            $keys = array_keys($stores);

            // Make sure the product exists in the first place
            if ($skipProduct) {
                $data = '<DeletedProductId>' . $product->getId()
                        . '</DeletedProductId>';
            } else {
                $data = Mage::getModel('channelunity/products')
                        ->generateCuXmlForSingleProduct($product->getId(), 0);
                $this->_updateProductData(0, $data);
            }

            // Loop through stores
            foreach ($keys as $i) {

                // Set variables
                $storeViewId = Mage::app()->getStore($i)->getId();
                if ($skipProduct) {
                    $data = '<DeletedProductId>' . $product->getId()
                            . '</DeletedProductId>';
                } else {
                    $data = Mage::getModel('channelunity/products')
                            ->generateCuXmlForSingleProduct(
                            $product->getId(), $storeViewId
                    );
                }

                // Send to CU
                $this->_updateProductData($storeViewId, $data);
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Called on deleting a product in Magento.
     */
    public function productWasDeleted(Varien_Event_Observer $observer)
    {
        try {

            // Load product
            $product = $observer->getEvent()->getProduct();

            // Set variables
            $storeViewId = $product->getStoreId();
            $data = '<DeletedProductId>' . $product->getId()
                    . '</DeletedProductId>';

            // Send to CU
            $this->_updateProductData($storeViewId, $data);
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Called on placing an order. Stock levels are updated on CU.
     */
    public function orderWasPlaced(Varien_Event_Observer $observer)
    {
        try {

            // Update order products
            $this->_updateOrderProducts($observer->getEvent()->getOrder());
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Called on an invoice being paid. Stock levels are updated on CU.
     */
    public function onInvoicePaid(Varien_Event_Observer $observer)
    {
        try {

            // Update order products
            $this->_updateOrderProducts($observer->getInvoice()->getOrder());
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Order is cancelled and has been saved. post order status change msg to CU
     */
    public function checkForCancellation(Varien_Event_Observer $observer)
    {
        try {

            // Load order
            $order = $observer->getOrder();

            // Create XML
            $xml = Mage::getModel('channelunity/orders')
                    ->generateCuXmlForOrderStatus($order);

            // Send XML to CU
            $this->postToChannelUnity($xml, 'OrderStatusUpdate');
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Send shipment to CU when tracking information is added.
     */
    public function saveTrackingToAmazon(Varien_Event_Observer $observer)
    {
        try {

            // Set variables
            $track = $observer->getEvent()->getTrack();
            $order = $track->getShipment()->getOrder();
            $carrier = $track->getCarrierCode();

            // Check carrier
            if ($carrier == 'custom') {
                $carrier = $track->getTitle();
            }

            // Create XML
            $xml = Mage::getModel('channelunity/orders')
                    ->generateCuXmlForOrderShip(
                    $order, $carrier, $track->getTitle(), $track->getNumber()
            );
            // Send XML to CU
            if (!empty($xml)) {
                $result = $this->postToChannelUnity($xml, 'OrderStatusUpdate');
//                Mage::log('saveTrackingToAmazon: ' . $result);
            } else {
//                Mage::log('Nothing to ship');
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    public function shipAmazon(Varien_Event_Observer $observer)
    {
        try {

            // Set variables
            $shipment = $observer->getEvent()->getShipment();
            $order = $shipment->getOrder();

            // Create XML
            $xml = Mage::getModel('channelunity/orders')
                    ->generateCuXmlForOrderShip($order, '', '', '');
            // Send XML to CU
            if (!empty($xml)) {
                $result = $this->postToChannelUnity($xml, 'OrderStatusUpdate');
                Mage::log('shipAmazon: ' . $result);
            } else {
                //Mage::log('Nothing to ship');
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Category is saved. CU needs to know about it.
     */
    public function categorySave(Varien_Event_Observer $observer)
    {
        try {

            // Send categories
            Mage::getModel('channelunity/categories')->postCategoriesToCU(
                    Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB)
            );
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Triggers on a category delete event. Removes category data in CU.
     * @author Gary Lockett
     *
     * @param Varien_Event_Observer $observer
     */
    public function categoryDelete(Varien_Event_Observer $observer)
    {
        try {

            // Set variables
            $sourceUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
            $categoryId = $observer->getEvent()->getControllerAction()
                            ->getRequest()->getParam('id');

            // Create XML
            $xml = <<<XML
<CategoryDelete>
    <SourceURL>{$sourceUrl}</SourceURL>
    <DeletedCategoryId>{$categoryId}</DeletedCategoryId>
</CategoryDelete>
XML;
            // Send XML to CU
            $this->postToChannelUnity($xml, 'categoryDelete');
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Configuration data is updated. CU needs to know about it.
     */
    public function configSaveAfter(Varien_Event_Observer $observer)
    {
        try {

            // Load configuration
            $config = $observer->getEvent()->getData('config_data')->getData();

            if (empty($config['fieldset_data']['merchantname']) == false) {
                // Set variables
                $merchant = $config['fieldset_data']['merchantname'];

                // Send to CU
                Mage::getModel('channelunity/products')
                        ->postMyURLToChannelUnity($merchant);
            }
        } catch (Exception $e) {

            Mage::logException($e);
        }
    }

    /**
     * Triggers on a store delete event. Removes store and category data in CU.
     * @author Matthew Gribben
     *
     * @param Varien_Event_Observer $observer
     */
    public function storeDelete(Varien_Event_Observer $observer)
    {
        try {

            // Load store
            $store = $observer->getEvent()->getStore();

            // Set variables
            $sourceUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);

            // Create XML
            $xml = <<<XML
<StoreDelete>
    <SourceURL>{$sourceUrl}</SourceURL>
    <StoreId>{$store->getGroupId()}</StoreId>
    <DeletedStoreViewId>{$store->getId()}</DeletedStoreViewId>
    <WebsiteId>{$store->getWebsiteId()}</WebsiteId>
</StoreDelete>
XML;
            // Send XML to CU
            $this->postToChannelUnity($xml, 'storeDelete');
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

	public function urapidflowAfterFetch($observer) {
		$vars = $observer->getEvent()->getVars();
        $skus = $vars['skus'];
        
        foreach ($skus as $sku => $pid) {
        
			// Get the SKU from the newData
			try {
				// Load product
				$product = Mage::getModel('catalog/product')->load($pid);
				
				$skipProduct = Mage::getModel('channelunity/products')
						->skipProduct($product);

				// Set variables
				$stores = Mage::app()->getStores();
				$keys = array_keys($stores);

				// Make sure the product exists in the first place
				if ($skipProduct) {
					$data = '<DeletedProductId>' . $product->getId()
							. '</DeletedProductId>';
				} else {
					$data = Mage::getModel('channelunity/products')
							->generateCuXmlForSingleProduct($product->getId(), 0);
					$this->_updateProductData(0, $data);
				}

				// Loop through stores
				foreach ($keys as $i) {

					// Set variables
					$storeViewId = Mage::app()->getStore($i)->getId();
					if ($skipProduct) {
						$data = '<DeletedProductId>' . $product->getId()
								. '</DeletedProductId>';
					} else {
						$data = Mage::getModel('channelunity/products')
								->generateCuXmlForSingleProduct(
								$product->getId(), $storeViewId
						);
					}

					// Send to CU
					$this->_updateProductData($storeViewId, $data);
				}
			} catch (Exception $e) {
				Mage::logException($e);
			}
        }
	}
	
	public function creditMemoSaveAfter($observer) {
		$creditMemo = $observer->getEvent()->getCreditmemo();
		$order      = $creditMemo->getOrder();
		
        $collection = Mage::getModel('sales/order_payment_transaction')->getCollection()
                ->setOrderFilter($order);

        $orderXml = "";
        $isCu = false;

		// Gather CU order details (subscription ID and marketplace order number)
        foreach ($collection as $txn) {
            $infoArray = $txn->getAdditionalInformation();
            if (isset($infoArray['SubscriptionId'])) {
                $orderXml .= "<SubscriptionID>{$infoArray['SubscriptionId']}</SubscriptionID>\n";
                $isCu = true;
            }
            if (isset($infoArray['RemoteOrderID'])) {
                $orderXml .= "<OrderID>{$infoArray['RemoteOrderID']}</OrderID>\n";
                $isCu = true;
            }
            break;
        }

		// If it's a CU order then prepare the PartialAction...
        if ($isCu) {
            $date = date('c');
            $totalAmountToRefund = 0;
			
			// Create XML
			$orderXml .= <<<XML
       <PartialActionDate>$date</PartialActionDate>
XML;
		
			$items = array();
			foreach ($creditMemo->getAllItems() as $item) {
			
				$qty = $item->getQty();
				$sku = $item->getSku();
				$amount = ($item->getPrice() + $item->getTaxAmount()) * $qty;
				
				$totalAmountToRefund += $amount;
				
				if ($qty > 0) {
				
					// Round to 2dp
					$amount = number_format($amount, 2);

					$orderXml .= <<<XML
		<PartialActionItem>
           <Action>Refund</Action>
           <Amount>$amount</Amount>
           <SKU>$sku</SKU>
           <QtyAffected>$qty</QtyAffected>
       </PartialActionItem>
XML;

				}
			}
		
			try {
			
				// If the total amount to refund is less than the grand total of the order
				// then use the partial action
			
				if ($totalAmountToRefund < $order->getGrandTotal()) {
					// "Yes, use the partial action\n\n";
				
					// Send XML to CU
					$ret = $this->postToChannelUnity($orderXml, 'DoPartialAction');
					Mage::log($ret);
				}
				else {
					// "No, don't use the partial action\n\n";
				}
			
			} catch (Exception $e) {
				Mage::logException($e);
			}
		}	
	}
	
    /**
     * PRIVATE METHODS
     */
    private function _updateOrderProducts($order)
    {
        try {
            // Set variables
            $items = $order->getAllItems();
            $storeViewId = $order->getStore()->getId();
            $data = '';
			
			$productsToUpdate = array();
			
			$updateStockOnCron = Mage::getStoreConfig('channelunityint/cron/updatestockoncron');
			
			if($updateStockOnCron == 1) {
				$productsToUpdate = $this->getProductsToUpdate();
			}

            // Loop through items
            foreach ($items as $item)
			{
                // Load product
                $product = Mage::getModel('catalog/product')
                        ->loadByAttribute('sku', $item->getSku());
                if (!$product) {
                    continue;
                }

				if($updateStockOnCron == 1)
				{
					if(isset($productsToUpdate[$storeViewId])) {
						if(!in_array($product->getId(), $productsToUpdate)) {
							$productsToUpdate[$storeViewId][] = $product->getId();
						}
					} else {
						$productsToUpdate[$storeViewId][] = $product->getId();
					}
				}
				else
				{
					 // Add XML
					$data .= Mage::getModel('channelunity/products')
							->generateCuXmlForSingleProduct(
							$product->getId(), $storeViewId, 0
					);
				}

            }

			if($updateStockOnCron == 1)
			{
				 $this->setProductsToUpdate($productsToUpdate);
			}
			else
			{
				// Send to CU
				$this->_updateProductData($storeViewId, $data);
			}
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    private function _updateProductData($storeViewId, $data)
    {
		return $this->updateProductData($storeViewId, $data);
    }

}
