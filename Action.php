<?php
/**
 * 友情链接插件 - Action 处理
 *
 * 后台 Ajax 接口与定时任务入口
 * 
 * @package FriendLinks
 * @author Astrsource
 */
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

use Typecho\Widget;
use Utils\Helper;

class FriendLinks_Action extends Widget
{
    /**
     * 手动更新所有链接信息（Ajax 接口）
     * 
     * 仅允许管理员调用，逐个抓取所有可见链接的网站信息并更新
     * 
     * @return void (通过 JSON 响应)
     */
    public function action()
    {
        // 权限验证：仅管理员
        $user = $this->widget('Widget_User');
        if (!$user->pass('administrator', true)) {
            $this->response->throwJson(['success' => false, 'message' => _t('权限不足')]);
        }

        // 执行全量更新
        $updated = FriendLinks_Plugin::updateAllLinksInfo();

        // 返回 JSON 结果
        $this->response->throwJson([
            'success' => true,
            'message' => sprintf(_t('成功更新 %d 个链接的信息'), $updated),
            'updated' => $updated
        ]);
    }

    /**
     * 定时任务入口 (GET /friendlinks/cron?key=...)
     * 
     * 通过 Cron 触发，使用密钥验证，更新所有可见链接的信息
     * 输出纯文本结果，适合服务器定时任务调用
     * 
     * @return void (直接输出文本)
     */
    public function cron()
    {
        // 验证密钥
        $key = $this->request->get('key');
        $pluginOptions = Helper::options()->plugin('FriendLinks');
        $secretKey = $pluginOptions->secretKey ?? '';

        if (!empty($secretKey) && $key !== $secretKey) {
            $this->response->setStatus(403);
            echo 'Invalid key';
            return;
        }

        // 执行全量更新
        $updated = FriendLinks_Plugin::updateAllLinksInfo();

        // 输出结果（纯文本，便于日志记录）
        echo sprintf("OK: Updated %d links at %s", $updated, date('Y-m-d H:i:s'));
    }
}