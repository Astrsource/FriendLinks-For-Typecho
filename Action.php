<?php
/**
 * 友情链接插件 - Action 处理类
 *
 * 处理后台 Ajax 请求（如手动更新所有链接信息）以及定时任务入口。
 * 定义路由 /friendlinks/cron，用于服务器 Cron 定期访问。
 *
 * @package FriendLinks
 * @author  Astrsource
 * @version 2.0.0
 * @link    https://astrsource.com
 */
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

use Typecho\Widget;
use Utils\Helper;

/**
 * Action 处理类
 *
 * 继承 Widget，可使用 response 等组件。
 */
class FriendLinks_Action extends Widget
{
    /**
     * 手动更新所有链接信息（Ajax 接口）
     *
     * 仅允许管理员执行。调用 Plugin 中的 updateAllLinksInfo() 并发刷新缓存，
     * 最后返回 JSON 格式结果。
     */
    public function action()
    {
        // 权限验证
        $user = $this->widget('Widget_User');
        if (!$user->pass('administrator', true)) {
            $this->response->throwJson(['success' => false, 'message' => _t('权限不足')]);
        }

        // 批量抓取并更新所有链接的标题、描述、图标及存活状态
        $updated = FriendLinks_Plugin::updateAllLinksInfo();
        // 更新前台使用的 JSON 缓存
        FriendLinks_Plugin::refreshCache();

        $this->response->throwJson([
            'success' => true,
            'message' => sprintf(_t('成功更新 %d 个链接的信息'), $updated),
            'updated' => $updated
        ]);
    }

    /**
     * 定时任务入口
     *
     * 通过访问 /friendlinks/cron?key=xxx 触发，可配置服务器 Cron 定期执行。
     * 如果后台设置了密钥 (secretKey)，则必须提供正确的 key 参数才能执行。
     */
    public function cron()
    {
        // 获取密钥参数
        $key = $this->request->get('key');
        $options = Helper::options();
        $pluginOptions = $options->plugin('FriendLinks');
        $secretKey = $pluginOptions->secretKey ?? '';

        // 如果设置了密钥，验证不通过则返回403
        if (!empty($secretKey) && $key !== $secretKey) {
            $this->response->setStatus(403);
            echo 'Invalid key';
            return;
        }

        // 执行更新操作
        $updated = FriendLinks_Plugin::updateAllLinksInfo();
        FriendLinks_Plugin::refreshCache();

        echo sprintf("OK: Updated %d links at %s", $updated, date('Y-m-d H:i:s'));
    }
}