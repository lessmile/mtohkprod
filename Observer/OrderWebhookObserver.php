<?php
namespace Vendor\Webhook\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
// use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use Magento\Store\Model\StoreManagerInterface;

class OrderWebhookObserver implements ObserverInterface
{
    protected $curl;
    protected $logger;

    protected $storeManager;

    public function __construct(
        // Curl $curl, 
        LoggerInterface $logger,
        StoreManagerInterface $storeManager
    ){
        // $this->curl = $curl;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
    }

    public function execute(Observer $observer)
    {
        // die('Observer reached!');

        // 生成 Hook ID 和时间戳
        $hookId = 'hook_' . bin2hex(random_bytes(16));
        $hookTimestamp = time();  // 使用 time() 函数生成当前 Unix 时间戳

        // 获取订单对象
        $order = $observer->getEvent()->getOrder();
        
        // 检查订单对象是否存在
        if (!$order) {
            $this->logger->error('Order object is not available.');
            return;
        }

        // $isNew = 'orders/update';

        // if ($order->isObjectNew()) {
        //     $isNew = 'orders/create';
        // }

        try {
            // 设置Webhook URL
            //$webhookUrl = 'https://open-seller-api-gw.dsers.com/magento/magento-webhook-consumer/webhook';
            $webhookUrl = 'https://open-seller-api-gw-test.dsers.com/magento/magento-webhook-consumer/webhook';

            // 设置请求体数据
            $data = json_encode([
                'id' => $order->getId(), 
                'status' => $order->getStatus(),
                // 'data' => $order->getData(),
            ]);

            // 获取商店域名
            $shopDomain = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);


            // 使用 PHP cURL 异步发送请求
            $ch = curl_init($webhookUrl);

            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Trace-ID: $hookId",
                "Timestamp: $hookTimestamp",
                "Shop-Domain: $shopDomain",
                "Hook-Type: orders",
                "Hook-Topic: orders/update"
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
            $this->logger->error('Error sending webhook: ' . $e->getMessage());
        }
    }
}
