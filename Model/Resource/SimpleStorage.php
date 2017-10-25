<?php

namespace BigBridge\ProductImport\Model\Resource;

use BigBridge\ProductImport\Model\Db\Magento2DbConnection;
use BigBridge\ProductImport\Model\Data\SimpleProduct;
use BigBridge\ProductImport\Model\ImportConfig;
use Exception;
use PDOException;

/**
 * @author Patrick van Bergen
 */
class SimpleStorage
{
    /** @var  Magento2DbConnection */
    protected $db;

    /** @var  MetaData */
    protected $metaData;

    /** @var Validator  */
    protected $validator;

    /** @var  ReferenceResolver */
    protected $referenceResolver;

    public function __construct(Magento2DbConnection $db, MetaData $metaData, Validator $validator, ReferenceResolver $referenceResolver)
    {
        $this->db = $db;
        $this->metaData = $metaData;
        $this->validator = $validator;
        $this->referenceResolver = $referenceResolver;
    }

    /**
     * @param SimpleProduct[] $simpleProducts
     * @param ImportConfig $config
     */
    public function storeSimpleProducts(array $simpleProducts, ImportConfig $config)
    {
        $this->db->execute("START TRANSACTION");

        try {

            $this->doTransaction($simpleProducts, $config);

            $this->db->execute("COMMIT");

        } catch (PDOException $e) {

            try { $this->db->execute("ROLLBACK"); } catch(Exception $f) {}

            foreach ($simpleProducts as $product) {
                $product->errors[] = $e->getMessage();
                $product->ok = false;
            }

        } catch (Exception $e) {

            try { $this->db->execute("ROLLBACK"); } catch(Exception $f) {}

            foreach ($simpleProducts as $product) {
                $message = $e->getMessage();
                $product->errors[] = $message;
                $product->ok = false;
            }

        }

        // call user defined functions to let them process the results
        foreach ($config->resultCallbacks as $callback) {
            foreach ($simpleProducts as $product) {
                call_user_func($callback, $product);
            }
        }
    }

    /**
     * @param SimpleProduct[] $simpleProducts
     * @param ImportConfig $config
     */
    protected function doTransaction(array $simpleProducts, ImportConfig $config)
    {
        // collect skus
        $skus = array_column($simpleProducts, 'sku');

        // collect inserts and updates
        $sku2id = $this->getExistingSkus($skus);

        $productsByAttribute = [];

        $insertProducts = [];
        $updateProducts = [];
        foreach ($simpleProducts as $product) {

            // replace Reference(s) with ids, changes $product->ok and $product->errors
            $this->referenceResolver->resolveIds($product, $config);

            // checks all attributes, changes $product->ok and $product->errors
            $this->validator->validate($product);

            if (!$product->ok) {
                continue;
            }

            if (array_key_exists($product->sku, $sku2id)) {
                $product->id = $sku2id[$product->sku];
                $updateProducts[] = $product;
            } else {
                $insertProducts[] = $product;
            }

            foreach ($product as $key => $value) {
                if ($value !== null) {
                    $productsByAttribute[$key][] = $product;
                }
            }
        }

        // in a "dry run" no actual imports to the database are done
        if ($config->dryRun) {
            return;
        }

        if (count($insertProducts) > 0) {
            $this->insertMainTable($insertProducts);
            $this->insertRewrites($insertProducts);
        }
        if (count($updateProducts) > 0) {
            $this->updateMainTable($updateProducts);
        }

        foreach ($this->metaData->productEavAttributeInfo as $eavAttribute => $info) {
            if (array_key_exists($eavAttribute, $productsByAttribute)) {
                $this->insertEavAttribute($productsByAttribute[$eavAttribute], $eavAttribute);
            }
        }

        if (array_key_exists('category_ids', $productsByAttribute)) {
            $this->insertCategoryIds($productsByAttribute['category_ids']);
        }
    }

    /**
     * Returns an sku => id map for all existing skus.
     *
     * @param array $skus
     * @return array
     */
    protected function getExistingSkus(array $skus)
    {
        if (count($skus) == 0) {
            return [];
        }

        $serialized = $this->db->quoteSet($skus);
        return $this->db->fetchMap("SELECT `sku`, `entity_id` FROM {$this->metaData->productEntityTable} WHERE `sku` in ({$serialized})");
    }

    /**
     * @param SimpleProduct[] $products
     */
    protected function insertMainTable(array $products)
    {
#todo has_options, required_options

        $values = [];
        $skus = [];
        foreach ($products as $product) {

            // index with sku to prevent creation of multiple products with the same sku
            // (this happens when products with different store views are inserted at once)
            if (array_key_exists($product->sku, $skus)) {
                continue;
            }
            $skus[$product->sku] = $product->sku;

            $sku = $this->db->quote($product->sku);
            $values []= "({$product->attribute_set_id}, 'simple', {$sku}, 0, 0)";
        }

        $sql = "INSERT INTO `{$this->metaData->productEntityTable}` (`attribute_set_id`, `type_id`, `sku`, `has_options`, `required_options`) VALUES " .
            implode(',', $values);

        $this->db->execute($sql);

        // store the new ids with the products
        $serialized = $this->db->quoteSet($skus);
        $sql = "SELECT `sku`, `entity_id` FROM `{$this->metaData->productEntityTable}` WHERE `sku` IN ({$serialized})";
        $sku2id = $this->db->fetchMap($sql);

        foreach ($products as $product) {
            $product->id = $sku2id[$product->sku];
        }
    }

    /**
     * @param SimpleProduct[] $products
     */
    protected function updateMainTable(array $products)
    {
#todo has_options, required_options

        $values = [];
        $skus = [];
        foreach ($products as $product) {
            $skus[] = $product->sku;
            $sku = $this->db->quote($product->sku);
            $values[] = "({$product->id},{$product->attribute_set_id}, 'simple', {$sku}, 0, 0)";
        }

        $sql = "INSERT INTO `{$this->metaData->productEntityTable}`" .
            " (`entity_id`, `attribute_set_id`, `type_id`, `sku`, `has_options`, `required_options`) " .
            " VALUES " . implode(', ', $values) .
            " ON DUPLICATE KEY UPDATE `attribute_set_id`=VALUES(`attribute_set_id`), `has_options`=VALUES(`has_options`), `required_options`=VALUES(`required_options`)";

        $this->db->execute($sql);
    }

    /**
     * @param SimpleProduct[] $products
     */
    protected function insertRewrites(array $products)
    {
        $values = [];

        foreach ($products as $product) {

            if (!$product->url_key) {
                continue;
            }

            $shortUrl = $product->url_key . $this->metaData->productUrlSuffix;

            // store ids
            if ($product->store_view_id == 0) {
                $storeIds = $this->metaData->storeViewMap;
                // remove store id 0
                $storeIds = array_diff($storeIds, ['0']);
            } else {
                $storeIds = [$product->store_view_id];
            }

            foreach ($storeIds as $storeId) {

                // url keys without categories
                $requestPath = $this->db->quote($shortUrl);
                $targetPath = $this->db->quote('catalog/product/view/id/' . $product->id);
                $values[] = "('product', {$product->id},{$requestPath}, {$targetPath}, 0, {$storeId}, 1, null)";

                // url keys with categories
                foreach ($product->category_ids as $directCategoryId) {

                    // here we check if the category id supplied actually exists
                    if (!array_key_exists($directCategoryId, $this->metaData->allCategoryInfo)) {
                        continue;
                    }

                    $path = "";
                    foreach ($this->metaData->allCategoryInfo[$directCategoryId]->path as $i => $parentCategoryId) {

                        // the root category is not used for the url path
                        if ($i === 0) {
                            continue;
                        }

                        $categoryInfo = $this->metaData->allCategoryInfo[$parentCategoryId];

                        // take the url_key from the store view, or default to the global url_key
                        $urlKey = array_key_exists($storeId, $categoryInfo->urlKeys) ? $categoryInfo->urlKeys[$storeId] : $categoryInfo->urlKeys[0];

                        $path .= $urlKey . "/";

                        $requestPath = $this->db->quote($path . $shortUrl);
                        $targetPath = $this->db->quote('catalog/product/view/id/' . $product->id . '/category/' . $parentCategoryId);
                        $metadata = $this->db->quote(serialize(['category_id' => (string)$parentCategoryId]));
                        $values[] = "('product', {$product->id},{$requestPath}, {$targetPath}, 0, {$storeId}, 1, {$metadata})";
                    }
                }
            }
        }

        if (!empty($values)) {

            // IGNORE works on the key request_path, store_id
            // when this combination already exists, it is ignored
            // this may happen if a main product is followed by one of its store views
            $sql = "
            INSERT IGNORE INTO `{$this->metaData->urlRewriteTable}`
            (`entity_type`, `entity_id`, `request_path`, `target_path`, `redirect_type`, `store_id`, `is_autogenerated`, `metadata`)
            VALUES " . implode(', ', $values) . "
        ";

            $this->db->execute($sql);
        }
    }

    /**
     * @param SimpleProduct[] $products
     * @param string $eavAttribute
     */
    protected function insertEavAttribute(array $products, string $eavAttribute)
    {
        $attributeInfo = $this->metaData->productEavAttributeInfo[$eavAttribute];
        $tableName = $attributeInfo->tableName;
        $attributeId = $attributeInfo->attributeId;

        $values = [];
        foreach ($products as $product) {

            $entityId = $product->id;
            $value = $this->db->quote($product->$eavAttribute);
            $values[] = "({$entityId},{$attributeId},{$product->store_view_id},{$value})";
        }

        $sql = "INSERT INTO `{$tableName}` (`entity_id`, `attribute_id`, `store_id`, `value`)" .
            " VALUES " . implode(', ', $values) .
            " ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

        $this->db->execute($sql);
    }

    protected function insertCategoryIds(array $products)
    {
        $values = [];

        foreach ($products as $product) {
            foreach ($product->category_ids as $categoryId) {
                $values []= "({$categoryId}, {$product->id})";
            }
        }

        if (!empty($values)) {

            // IGNORE serves two purposes:
            // 1. do not fail if the product-category link already existed
            // 2. do not fail if the category does not exist

            $sql = "
                INSERT IGNORE INTO `{$this->metaData->categoryProductTable}` (`category_id`, `product_id`) 
                VALUES " . implode(', ', $values);

            $this->db->execute($sql);
        }
    }
}