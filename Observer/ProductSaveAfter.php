<?php

namespace Vendor\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Type;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableType;


class ProductSaveAfter implements ObserverInterface
{
    protected $curl;
    protected $logger;

    protected $storeManager;

    protected $configurableType;
    protected $productRepository;

    public function __construct(
        // Curl $curl,
        LoggerInterface $logger,
        StoreManagerInterface $storeManager,
        ConfigurableType $configurableType,
        ProductRepositoryInterface $productRepository
    ) {
        // $this->curl = $curl;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
        $this->configurableType = $configurableType;
        $this->productRepository = $productRepository;
    }

    public function execute(Observer $observer)
    {
        try {

            // 生成 Hook ID 和时间戳
            $hookId = 'hook_' . bin2hex(random_bytes(16));  // 生成一个唯一的 Hook ID
            $hookTimestamp = time();  // 使用 time() 函数生成当前 Unix 时间戳

            // 获取产品对象
            $product = $observer->getEvent()->getProduct();

             // 检查订单对象是否存在
            if (!$product) {
                $this->logger->error('Product object is not available.');
                return;
            }

            $isNew = 'products/update';

            if ($product->isObjectNew()) {
                $isNew = 'products/create';
            }

            if ($observer->getEvent()->getName() == 'catalog_product_delete_after') {
                $isNew = 'products/delete';
            }

            // Determine product type and log accordingly
            $productType = $product->getTypeId();

            // Assume $product is a loaded product instance

            if ($productType === 'configurable') {
                // For Configurable products
                $parentId = $product->getId();
            } else {
                // For other types of products
                $parentIds = $this->configurableType->getParentIdsByChild($product->getId());
                if (!empty($parentIds)) {
                    $parentId = $parentIds[0]; // 取第一个父ID
                    $this->logger->info('Parent ID for simple product: ' . $parentId);
                } else {
                    $parentId = $product->getId();
                    $this->logger->info('No parent ID found for simple product.');
                }
            }

            // 准备要发送的数据
            $data = json_encode([
                'id' => $product->getId(),
                'parent_id' => $parentId,
                'sku' => $product->getSku(),
		        'product_type' => $productType,
                'name' => $product->getName(),
                // 'price' => $product->getPrice(),
                'status' => $product->getStatus(),
                // 'data' => $product->getData() // 获取产品的所有数据
            ]);

            // 获取商店域名
            $shopDomain = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);

            // Webhook URL
            //$webhookUrl = 'https://open-seller-api-gw.dsers.com/magento/magento-webhook-consumer/webhook';
            $webhookUrl = 'https://open-seller-api-gw-test.dsers.com/magento/magento-webhook-consumer/webhook';

            // 使用 PHP cURL 异步发送请求
            $ch = curl_init($webhookUrl);

            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Trace-ID: $hookId",
                "Timestamp: $hookTimestamp",
                "Shop-Domain: $shopDomain",
                "Hook-Type: products",
                "Hook-Topic: $isNew"
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);  // 设置 1 秒超时，以便快速返回
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1); // 对于超时小于 1 秒的请求设置

            // 执行请求
            $response = curl_exec($ch);

            // 检查 cURL 错误
            if ($response === false) {
                $error = curl_error($ch);
                $this->logger->error('cURL error: ' . $error);
            } else {
                $this->logger->info('Order webhook sent: ' . $data);
            }

            curl_close($ch);

        } catch (\Exception $e) {
            $this->logger->error('Error sending product webhook: ' . $e->getMessage());
        }
    }
}

