<?php

/**
 * Product Sync Cron Model for Magento Commerce
 *
 * @category   Camiloo
 * @package    Camiloo_Channelunity
 * @copyright  Copyright (c) 2012 Camiloo Limited (http://www.camiloo.co.uk)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Camiloo_Channelunity_Model_Sync extends Mage_Core_Model_Abstract
{

    /**
     * Sends stock and price information for each SKU in the Magento catalog to CU.
     */
    public function lite()
    {
        $prd = Mage::getModel('channelunity/products');

        do {
            ob_start();
            // Get a block of products with SKU, qty, price
            $prd->fullstockpricemessageAction();

            $buf = ob_get_clean();

            // Get the URL of the store
            $sourceUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);

            $xml = "<Products>
                    <SourceURL>{$sourceUrl}</SourceURL>
                    <StoreViewId>0</StoreViewId>
                    <Data><![CDATA[ $buf ]]></Data>
                    </Products>";

            Mage::log($xml);

            // Send to ChannelUnity
            $response = $prd->postToChannelUnity($xml, 'ProductDataLite');

            Mage::log($response);
        }
        while ($prd->lastSyncProd > 0);
        // Loop while more products to synchronise
    }

    /**
     * Sends full product data to CU
     */
    public function data()
    {
        $collection = Mage::getModel('channelunity/collection');
        $collection->setPageSize(1000);
        $pages = $collection->getLastPageNumber();
        $currentPage = 1;

        // loop through collection and create batches of 1000 records
        do {
            $startTime = microtime(true);

            // load current batch of 1000 records
            $collection->setCurPage($currentPage);
            $collection->load();

            // Get the URL of the store
            $sourceUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);

            $xml = "<Products>
                    <SourceURL>{$sourceUrl}</SourceURL>
                    <StoreViewId>0</StoreViewId>";

            foreach ($collection as $item) {

                // Get XML data to represent the product
                $data = Mage::getModel('channelunity/products')
                    ->generateCuXmlForSingleProduct($item->getEntityId(), 0);

                // Create overall XML message
                $xml .= $data;
            }

            $xml .= "</Products>";
            Mage::log($xml);

            $endTime = microtime(true) - $startTime;

            Mage::log("We took $endTime sec to generate");

            // Send to ChannelUnity
            $response = Mage::getModel('channelunity/products')
                ->postToChannelUnity($xml, 'ProductData');

            Mage::log($response);

            $currentPage++;

            // clear current page from memory so next iteration picks up next page
            $collection->clear();

        } while ($currentPage <= $pages);
    }
}