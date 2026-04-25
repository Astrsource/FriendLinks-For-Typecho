<?php
/**
 * 友情链接插件 - Action 处理
 *
 * 后台 Ajax 接口与定时任务入口
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
     */
    public function action()
    {
        $user = $this->widget('Widget_User');
        if (!$user->pass('administrator', true)) {
            $this->response->throwJson(['success' => false, 'message' => _t('权限不足')]);
        }

        $updated = FriendLinks_Plugin::updateAllLinksInfo();

        $this->response->throwJson([
            'success' => true,
            'message' => sprintf(_t('成功更新 %d 个链接的信息'), $updated),
            'updated' => $updated
        ]);
    }

    /**
     * 定时任务入口 (GET /friendlinks/cron?key=...)
     */
    public function cron()
    {
        $key = $this->request->get('key');
        $options = Helper::options();
        $pluginOptions = $options->plugin('FriendLinks');
        $secretKey = $pluginOptions->secretKey ?? '';

        if (!empty($secretKey) && $key !== $secretKey) {
            $this->response->setStatus(403);
            echo 'Invalid key';
            return;
        }

        $updated = FriendLinks_Plugin::updateAllLinksInfo();

        echo sprintf("OK: Updated %d links at %s", $updated, date('Y-m-d H:i:s'));
    }
}