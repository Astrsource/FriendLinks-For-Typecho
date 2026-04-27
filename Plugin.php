<?php
/**
 * 友情链接插件 - 自动抓取网站信息版
 * <br>
 * 启用后在 [管理] -> [友情链接] 管理友情链接
 *
 * @package FriendLinks
 * @author Astrsource
 * @version 2.2.3
 * @link https://astrsource.com
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Db;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Utils\Helper;

class FriendLinks_Plugin implements PluginInterface
{
    const TABLE_NAME = 'friendlinks';
    const CATEGORY_TABLE = 'friendlinks_categories';
    const CACHE_DIR = __TYPECHO_ROOT_DIR__ . '/usr/cache/';

    private static $pluginOptionsCache = null;
    private static $linksCache = null;

    public static function activate()
    {
        if (!extension_loaded('curl')) {
            throw new \Typecho\Plugin\Exception(_t('需要 PHP cURL 扩展'));
        }
        self::createTables();
        if (!is_dir(self::CACHE_DIR)) mkdir(self::CACHE_DIR, 0755, true);

        Helper::addPanel(3, 'FriendLinks/panel.php', _t('友情链接'), _t('管理友情链接'), 'administrator');
        Helper::addAction('friendlinks-update', 'FriendLinks_Action');
        Helper::addRoute('friendlinks_cron', '/friendlinks/cron', 'FriendLinks_Action', 'cron');
        \Typecho\Plugin::factory('Widget\Abstract\Contents')->contentEx = ['FriendLinks_Plugin', 'parseShortcode'];
        \Typecho\Plugin::factory('Widget\Abstract\Contents')->excerptEx = ['FriendLinks_Plugin', 'parseShortcode'];

        return _t('自动抓取网站信息，支持缓存、定时更新、分类管理、自定义模板和CSS');
    }

    public static function deactivate()
    {
        $pluginOptions = self::getPluginOptions();
        $dropTable = isset($pluginOptions->dropTableOnDeactivate) && $pluginOptions->dropTableOnDeactivate == 1;
        if ($dropTable) {
            $db = Db::get();
            $prefix = $db->getPrefix();
            try {
                $db->query("DROP TABLE IF EXISTS `{$prefix}" . self::TABLE_NAME . "`");
                $db->query("DROP TABLE IF EXISTS `{$prefix}" . self::CATEGORY_TABLE . "`");
            } catch (Exception $e) {}
        }
        Helper::removeAction('FriendLinks-edit');
        Helper::removePanel(3, 'FriendLinks/panel.php');
    }

    private static function getPluginOptions()
    {
        if (self::$pluginOptionsCache === null) {
            self::$pluginOptionsCache = Helper::options()->plugin('FriendLinks');
        }
        return self::$pluginOptionsCache;
    }

    private static function clearPluginOptionsCache()
    {
        self::$pluginOptionsCache = null;
    }

    public static function config(Form $form)
    {
        $cacheTime = new \Typecho\Widget\Helper\Form\Element\Text('cacheTime', null, '604800', _t('缓存时间（秒）'), _t('缓存时间单位为秒，默认时间是 7 天'));
        $form->addInput($cacheTime);

        $timeout = new \Typecho\Widget\Helper\Form\Element\Text('timeout', null, '10', _t('请求超时（秒）'), _t('请求超时时间单位为秒，默认时间是 10 秒'));
        $form->addInput($timeout);

        $defaultIcon = new \Typecho\Widget\Helper\Form\Element\Text('defaultIcon', null, '/favicon.png', _t('默认图标 URL'), _t('当无法获取到网站图标时显示的默认图标地址，留空则不显示'));
        $form->addInput($defaultIcon);

        $template = new \Typecho\Widget\Helper\Form\Element\Textarea('template', null, '<div class="friendlink-card">
        <!-- 头部 -->
        <div class="result-header">
            <div class="favicon">
                <img src="{icon}" alt="favicon">
            </div>
            <div>
                <div class="title">{title}</div>
                <div class="url-display">
                    <a href="{url}" target="_blank">{url}</a>
                </div>
            </div>
        </div>

        <!-- 描述 -->
        <div class="description">
            <div class="label">描述</div>
            {description}
        </div>

        <!-- 底部 Badge 组 -->
        <div class="badge-group">
            <span class="badge badge-category">{category}</span>
            <span class="badge badge-update">{last_update}</span>
            <span class="badge badge-status">{alive}</span>
        </div>
    </div>',
            _t('卡片模板'), _t('占位符：{url}、{title}、{description}、{icon}、{last_update}、{alive}、{category}<br>注意：自定义模板中的 <span style="color: red;">.friendlink-card</span> 是基类，使用 <span style="color: red;">card_class</span> 参数输出会追加到 class 属性中，即最终输出为 <span style="color: red;">class="friendlink-card my-card"</span>'));
        $form->addInput($template);

        $customCss = new \Typecho\Widget\Helper\Form\Element\Textarea('customCss', null, '.friendlink-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
            max-width: 520px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        .result-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }

        .favicon {
            width: 48px;
            height: 48px;
            background: #f1f5f9;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            flex-shrink: 0;
        }

        .favicon img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .title {
            font-size: 20px;
            font-weight: 600;
            color: #0f172a;
            word-break: break-word;
        }

        .url-display {
            font-size: 14px;
            color: #64748b;
            margin-top: 4px;
            word-break: break-all;
        }

        .url-display a {
            color: #2563eb;
            text-decoration: none;
            transition: color 0.2s ease;
        }
        .url-display a:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }

        .description {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px dashed #cbd5e1;
            color: #334155;
            line-height: 1.5;
            word-break: break-word;
        }

        .description .label {
            font-size: 13px;
            font-weight: 500;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        /* ========== 底部 Badge 区域 ========== */
        .badge-group {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px dashed #cbd5e1;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-family: "SF Mono", "Fira Code", "JetBrains Mono", "Consolas", monospace;
            font-size: 13px;
            font-weight: 500;
            padding: 6px 12px;
            border-radius: 20px;
            /* 胶囊圆角 */
            line-height: 1;
            white-space: nowrap;
            letter-spacing: 0.3px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            cursor: default;
        }

        .badge:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        /* 分类 Badge - 蓝紫调 */
        .badge-category {
            background: #ede9fe;
            /* 淡紫 */
            color: #6d28d9;
            border: 1px solid #ddd6fe;
        }

        .badge-category::before {
            content: "📁";
            font-size: 11px;
            line-height: 1;
        }

        /* 最后更新 Badge - 蓝绿调 */
        .badge-update {
            background: #e0f2fe;
            /* 淡天蓝 */
            color: #0369a1;
            border: 1px solid #bae6fd;
        }

        .badge-update::before {
            content: "📅";
            font-size: 11px;
            line-height: 1;
        }

        /* 网站状态 Badge - 动态色调 */
        .badge-status {
            background: #f0fdf4;
            /* 淡绿（默认正常） */
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        .badge-status::before {
            content: "✅";
            font-size: 11px;
            line-height: 1;
        }

        /* 状态异常变体 */
        .error .badge-status{
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .error .badge-status::before {
            content: "❌";
        }

        /* 状态警告变体 */
        .warning .badge-status{
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fed7aa;
        }
        .warning .badge-status::before {
            content: "⚠️";
        }

        /* Badge 内图标与文字间距微调 */
        .badge .badge-icon {
            font-size: 12px;
            line-height: 1;
            flex-shrink: 0;
        }

        /* 响应式：小屏时badge可换行 */
        @media (max-width: 400px) {
            .badge-group {
                gap: 8px;
            }
            .badge {
                font-size: 12px;
                padding: 5px 10px;
                border-radius: 16px;
            }
        }',
            _t('自定义 CSS'), _t('自定义友情链接卡片的 CSS 样式'));
        $form->addInput($customCss);

        $sortOrder = new \Typecho\Widget\Helper\Form\Element\Select('sortOrder', [
            'manual' => _t('手动排序'),
            'created_desc' => _t('添加时间（新→旧）'),
            'created_asc' => _t('添加时间（旧→新）'),
            'title_asc' => _t('标题 A→Z'),
            'title_desc' => _t('标题 Z→A'),
            'random' => _t('随机')
        ], 'manual', _t('前台排序方式'), _t('选择友情链接在前台页面中的显示顺序<br>注意：若开启"允许访客选择排序"选项，此设置将被覆盖'));
        $form->addInput($sortOrder);

        $allowVisitorSort = new \Typecho\Widget\Helper\Form\Element\Radio('allowVisitorSort', ['0' => _t('关闭'), '1' => _t('开启')], '0', _t('允许访客选择排序'), _t('开启后，前台将显示排序下拉框，访客可切换排序方式，偏好会记录在浏览器中'));
        $form->addInput($allowVisitorSort);

        $skipDeadLinks = new \Typecho\Widget\Helper\Form\Element\Radio('skipDeadLinks', ['0' => _t('不跳过'), '1' => _t('跳过')], '0', _t('跳过异常网站'), _t('前台渲染时，是否跳过存活状态为异常的网站（即不显示已失效的链接）<br>注意：短代码参数 include_dead="1" 可强制包含异常链接，include_dead="2" 仅显示异常链接（钩子输出参数同理）'));
        $form->addInput($skipDeadLinks);

        $secretKey = new \Typecho\Widget\Helper\Form\Element\Text('secretKey', null, '', _t('Cron 密钥'), _t('设置服务器 Cron 定时访问时的密钥，用于验证请求来源'));
        $form->addInput($secretKey);

        $dropTable = new \Typecho\Widget\Helper\Form\Element\Radio('dropTableOnDeactivate', [
            '0' => _t('不删除'),
            '1' => _t('<span style="color: red;font-weight: bold;">删除</span>')
        ], '0', _t('禁用插件时删除数据表'), _t('<span style="color: red;font-weight: bold;">注意：若选择删除，禁用插件后所有链接和分类数据将被永久删除</span>'));
        $form->addInput($dropTable);
    }

    public static function personalConfig(Form $form) {}

    private static function createTables()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();

        $categoryTable = $prefix . self::CATEGORY_TABLE;
        $db->query("CREATE TABLE IF NOT EXISTS `{$categoryTable}` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `sort` int(11) DEFAULT '0',
            `created` int(11) DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `idx_sort` (`sort`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $table = $prefix . self::TABLE_NAME;
        $db->query("CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `url` varchar(255) NOT NULL,
            `title` varchar(255) NOT NULL,
            `description` text,
            `icon` varchar(255) DEFAULT NULL,
            `status` tinyint(1) DEFAULT '1',
            `sort` int(11) DEFAULT '0',
            `category_id` int(11) DEFAULT NULL,
            `last_update` int(11) DEFAULT '0',
            `created` int(11) DEFAULT '0',
            `alive` tinyint(1) DEFAULT NULL,
            `alive_checked` int(11) DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `idx_status_sort` (`status`, `sort`),
            KEY `idx_category_id` (`category_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        try {
            $result = $db->fetchAll($db->query("SHOW COLUMNS FROM `{$table}` LIKE 'category_id'"));
            if (empty($result)) {
                $db->query("ALTER TABLE `{$table}` ADD COLUMN `category_id` int(11) DEFAULT NULL AFTER `sort`, ADD KEY `idx_category_id` (`category_id`)");
            }
        } catch (Exception $e) {}
    }

    public static function parseShortcode($content, $widget, $lastResult)
    {
        $content = empty($lastResult) ? $content : $lastResult;
        if (strpos($content, '[friendlinks') !== false) {
            $pattern = '/\[friendlinks(?:\s+container_class="([^"]*)")?(?:\s+card_class="([^"]*)")?(?:\s+category_id="([^"]*)")?(?:\s+include_uncategorized="([^"]*)")?(?:\s+include_dead="([^"]*)")?\]/';
            $content = preg_replace_callback($pattern, function ($m) {
                $containerClass = $m[1] ?? 'friendlinks-container';
                $cardClass = $m[2] ?? '';
                $categoryId = isset($m[3]) && $m[3] !== '' ? intval($m[3]) : null;

                $uncategorizedRaw = $m[4] ?? '';
                if ($uncategorizedRaw === '0' || $uncategorizedRaw === 'false') {
                    $uncategorizedMode = 0;
                } elseif ($uncategorizedRaw === '2') {
                    $uncategorizedMode = 2;
                } else {
                    $uncategorizedMode = 1;
                }

                $includeDeadMode = isset($m[5]) && $m[5] !== '' ? intval($m[5]) : 0;
                return self::renderLinks($containerClass, $cardClass, $categoryId, $uncategorizedMode, $includeDeadMode);
            }, $content);
        }
        return $content;
    }

    public static function renderLinks($containerClass = 'friendlinks-container', $cardClass = '', $categoryId = null, $uncategorizedMode = 1, $includeDeadMode = 0)
    {
        $pluginOptions = self::getPluginOptions();
        $cacheTime = intval($pluginOptions->cacheTime ?? 604800);
        $template = $pluginOptions->template ?: '<div class="friendlink-card">...</div>';
        $customCss = $pluginOptions->customCss ?? '';
        $defaultSortOrder = $pluginOptions->sortOrder ?? 'manual';
        $defaultIcon = $pluginOptions->defaultIcon ?? '';
        $allowVisitorSort = isset($pluginOptions->allowVisitorSort) && $pluginOptions->allowVisitorSort == 1;
        $globalSkipDead = ($pluginOptions->skipDeadLinks ?? '0') == '1';

        $currentSort = $defaultSortOrder;
        $allowedSorts = ['manual', 'created_desc', 'created_asc', 'title_asc', 'title_desc', 'random'];

        if ($allowVisitorSort) {
            $sortKey = 'fl_sort_' . md5($containerClass . '|' . ($categoryId ?? '0') . '|' . $uncategorizedMode . '|' . $includeDeadMode);
            $cookieSort = $_COOKIE[$sortKey] ?? '';
            if ($cookieSort && in_array($cookieSort, $allowedSorts)) {
                $currentSort = $cookieSort;
            } elseif (isset($_GET['sort']) && in_array($_GET['sort'], $allowedSorts)) {
                $currentSort = $_GET['sort'];
            }
        }

        $useRenderCache = ($categoryId === null) && ($uncategorizedMode === 1) && ($includeDeadMode === 0);

        if ($useRenderCache) {
            $renderKey = 'friendlinks_rendered_' . md5($containerClass . $cardClass . $template . $customCss . $currentSort . $defaultIcon . ($globalSkipDead ? '1' : '0'));
            $renderedCacheFile = self::CACHE_DIR . $renderKey . '.html';
            if (file_exists($renderedCacheFile) && (time() - filemtime($renderedCacheFile)) < $cacheTime) {
                return file_get_contents($renderedCacheFile);
            }
        }

        $links = self::getLinksFromCacheOnly();
        if (empty($links)) {
            return '<p class="friendlinks-empty">' . _t('暂无友情链接') . '</p>';
        }

        // 分类筛选
        if ($categoryId !== null) {
            $links = array_values(array_filter($links, function($link) use ($categoryId) {
                return ($link['category_id'] ?? null) == $categoryId;
            }));
            if (empty($links)) return '<p class="friendlinks-empty">' . _t('该分类下暂无友情链接') . '</p>';
        } else {
            if ($uncategorizedMode === 0) {
                $links = array_values(array_filter($links, function($link) {
                    return !empty($link['category_id']);
                }));
            } elseif ($uncategorizedMode === 2) {
                $links = array_values(array_filter($links, function($link) {
                    return empty($link['category_id']);
                }));
            }
        }

        // 存活状态筛选
        if ($includeDeadMode === 2) {
            $links = array_values(array_filter($links, function($link) {
                return isset($link['alive']) && $link['alive'] == 0;
            }));
        } elseif ($includeDeadMode === 1) {
            // 强制包含所有
        } else {
            if ($globalSkipDead) {
                $links = array_values(array_filter($links, function($link) {
                    return ($link['alive'] ?? null) !== 0;
                }));
            }
        }

        // 排序
        if ($useRenderCache && $currentSort === 'random') {
            // 客户端随机，稍后添加脚本
        } else {
            switch ($currentSort) {
                case 'created_desc': usort($links, function($a,$b){ return $b['created'] <=> $a['created']; }); break;
                case 'created_asc':  usort($links, function($a,$b){ return $a['created'] <=> $b['created']; }); break;
                case 'title_asc':    usort($links, function($a,$b){ return strcasecmp($a['title'], $b['title']); }); break;
                case 'title_desc':   usort($links, function($a,$b){ return strcasecmp($b['title'], $a['title']); }); break;
                case 'random':       shuffle($links); break;
            }
        }

        // 获取分类映射（用于 {category} 占位符）
        $allCategories = self::getCategories();
        $categoryNames = [];
        foreach ($allCategories as $cat) {
            $categoryNames[$cat['id']] = $cat['name'];
        }

        // 排序选择器
        $sortSelectorHtml = '';
        if ($allowVisitorSort) {
            $sortOptions = [
                'manual' => '默认排序', 'created_desc' => '最新添加', 'created_asc' => '最早添加',
                'title_asc' => '标题 A-Z', 'title_desc' => '标题 Z-A', 'random' => '随机'
            ];
            $selectHtml = '<select class="friendlinks-sort-select" style="margin-bottom: 10px; padding: 5px 10px; border-radius: 4px; border: 1px solid #ccc;">';
            foreach ($sortOptions as $val => $label) {
                $selected = ($val === $currentSort) ? ' selected' : '';
                $selectHtml .= '<option value="' . $val . '"' . $selected . '>' . $label . '</option>';
            }
            $selectHtml .= '</select>';
            $sortSelectorHtml = '<div class="friendlinks-sort-toolbar">' . $selectHtml . '</div>';
            $sortSelectorHtml .= <<<HTML
<script>
(function() {
    var toolbar = document.currentScript.previousElementSibling;
    if (toolbar && toolbar.classList.contains('friendlinks-sort-toolbar')) {
        var select = toolbar.querySelector('select');
        if (select) {
            select.addEventListener('change', function() {
                var d = new Date();
                d.setFullYear(d.getFullYear() + 1);
                document.cookie = '{$sortKey}=' + this.value + '; path=/; expires=' + d.toUTCString() + '; SameSite=Lax';
                window.location.reload();
            });
        }
    }
})();
</script>
HTML;
        }

        // 构建卡片
        $output = '<style>' . $customCss . '</style>' . $sortSelectorHtml . '<div class="' . htmlspecialchars($containerClass) . '">';
        foreach ($links as $link) {
            $icon = $link['icon'] ?: $defaultIcon;
            $lastUpdate = $link['last_update'] ? date('Y-m-d', $link['last_update']) : '';
            $aliveText = isset($link['alive']) ? ($link['alive'] ? '正常' : '异常') : '未知';
            $categoryName = '';
            if (!empty($link['category_id']) && isset($categoryNames[$link['category_id']])) {
                $categoryName = $categoryNames[$link['category_id']];
            }
            $card = str_replace(
                ['{url}', '{title}', '{description}', '{icon}', '{last_update}', '{alive}', '{category}'],
                [
                    htmlspecialchars($link['url']),
                    htmlspecialchars($link['title']),
                    htmlspecialchars($link['description'] ?? ''),
                    htmlspecialchars($icon),
                    htmlspecialchars($lastUpdate),
                    htmlspecialchars($aliveText),
                    htmlspecialchars($categoryName) ?: '未分类'
                ],
                $template
            );
            if ($cardClass) {
                $card = str_replace('friendlink-card', 'friendlink-card ' . htmlspecialchars($cardClass), $card);
            }
            $output .= $card;
        }
        $output .= '</div>';

        if ($useRenderCache && $currentSort === 'random') {
            $output .= <<<HTML
<script>
(function() {
    var container = document.currentScript.previousElementSibling;
    if (container && container.classList.contains('{$containerClass}')) {
        var cards = Array.from(container.children);
        for (var i = cards.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            container.appendChild(cards[j]);
        }
    }
})();
</script>
HTML;
        }

        if ($useRenderCache) {
            file_put_contents($renderedCacheFile, $output, LOCK_EX);
        }

        return $output;
    }

    public static function output($containerClass = 'friendlinks-container', $cardClass = '', $categoryId = null, $uncategorizedMode = 1, $includeDeadMode = 0)
    {
        echo self::renderLinks($containerClass, $cardClass, $categoryId, $uncategorizedMode, $includeDeadMode);
    }

    // ==================== 分类 CRUD ====================

    /**
     * 获取所有分类
     *
     * @return array
     */
    public static function getCategories()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        return $db->fetchAll($db->select()->from($prefix . self::CATEGORY_TABLE)->order('sort')->order('id'));
    }

    /**
     * 获取单个分类
     *
     * @param int $id
     * @return array|null
     */
    public static function getCategory($id)
    {
        return Db::get()->fetchRow(Db::get()->select()->from(Db::get()->getPrefix() . self::CATEGORY_TABLE)->where('id = ?', $id));
    }

    /**
     * 添加分类
     *
     * @param string $name 分类名
     * @param int $sort 排序值，0 或负数自动递增
     * @return bool
     */
    public static function addCategory($name, $sort = 0)
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::CATEGORY_TABLE;

        if ($sort <= 0) {
            $maxResult = $db->fetchRow($db->select('MAX(`sort`) as max_sort')->from($table));
            $sort = intval($maxResult['max_sort'] ?? 0) + 1;
        }

        return $db->query($db->insert($table)->rows([
            'name'    => $name,
            'sort'    => $sort,
            'created' => time()
        ]));
    }

    /**
     * 更新分类
     *
     * @param int $id
     * @param string $name
     * @param int|null $sort 若为 null 则不更新排序
     * @return bool
     */
    public static function updateCategory($id, $name, $sort = null)
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::CATEGORY_TABLE;

        $rows = ['name' => $name];
        if ($sort !== null) {
            $rows['sort'] = intval($sort);
        }

        return $db->query($db->update($table)->rows($rows)->where('id = ?', $id));
    }

    /**
     * 删除分类（该分类下的链接自动移到未分类）
     *
     * @param int $id
     * @return bool
     */
    public static function deleteCategory($id)
    {
        $db = Db::get();
        $prefix = $db->getPrefix();

        // 将链接的分类置空
        $db->query($db->update($prefix . self::TABLE_NAME)->rows(['category_id' => null])->where('category_id = ?', $id));
        // 删除分类
        return $db->query($db->delete($prefix . self::CATEGORY_TABLE)->where('id = ?', $id));
    }

    /**
     * 获取每个分类下的链接数量（用于管理面板）
     *
     * @return array ['uncategorized' => count, cat_id => count, ...]
     */
    public static function getCategoryLinkCounts()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();

        $counts = [];
        // 未分类链接数
        $result = $db->fetchRow($db->select('COUNT(*) as cnt')->from($prefix . self::TABLE_NAME)->where('category_id IS NULL'));
        $counts['uncategorized'] = intval($result['cnt'] ?? 0);

        // 各分类链接数
        $categories = self::getCategories();
        foreach ($categories as $cat) {
            $result = $db->fetchRow($db->select('COUNT(*) as cnt')->from($prefix . self::TABLE_NAME)->where('category_id = ?', $cat['id']));
            $counts[$cat['id']] = intval($result['cnt'] ?? 0);
        }

        return $counts;
    }

    // ==================== 缓存管理 ====================

    /**
     * 从 JSON 缓存文件读取链接数据（带内存缓存）
     *
     * @return array
     */
    private static function getLinksFromCacheOnly()
    {
        if (self::$linksCache !== null) {
            return self::$linksCache;
        }

        $cacheFile = self::CACHE_DIR . 'friendlinks.cache.json';
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            self::$linksCache = is_array($data) ? $data : [];
        } else {
            self::$linksCache = [];
        }

        return self::$linksCache;
    }

    /**
     * 刷新数据缓存（从数据库重建 JSON 缓存，并清除渲染缓存）
     */
    public static function refreshCache()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::TABLE_NAME;

        // 仅缓存状态为显示的链接（前台使用）
        $links = $db->fetchAll($db->select()->from($table)->where('status = ?', 1)->order('sort')->order('id'));

        // 更新 JSON 缓存
        file_put_contents(self::CACHE_DIR . 'friendlinks.cache.json', json_encode($links, JSON_UNESCAPED_UNICODE), LOCK_EX);
        // 清空渲染缓存
        self::clearRenderedCache();
        // 更新内存缓存
        self::$linksCache = $links;

        return $links;
    }

    /**
     * 删除所有渲染缓存文件
     */
    private static function clearRenderedCache()
    {
        foreach (glob(self::CACHE_DIR . 'friendlinks_rendered_*.html') as $file) {
            @unlink($file);
        }
    }

    // ==================== 网站信息抓取 ====================

    /**
     * 解析 HTML 提取标题、描述、图标
     *
     * @param string $html
     * @param string $finalUrl 最终重定向后的 URL（用于补全相对路径图标）
     * @return array
     */
    private static function parseSiteInfo($html, $finalUrl)
    {
        $info = ['title' => '', 'description' => '', 'icon' => ''];

        // 提取标题
        if (preg_match('/<title[^>]*>(.*?)<\/title>/i', $html, $m)) {
            $info['title'] = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }

        // 提取描述
        $patterns = [
            '/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\']/i',
            '/<meta[^>]*property=["\']og:description["\'][^>]*content=["\']([^"\']*)["\']/i'
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $html, $m)) {
                $info['description'] = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                break;
            }
        }

        // 提取图标
        $iconPatterns = [
            '/<link[^>]*rel=["\'](?:shortcut )?icon["\'][^>]*href=["\']([^"\']*)["\']/i',
            '/<link[^>]*rel=["\']apple-touch-icon["\'][^>]*href=["\']([^"\']*)["\']/i'
        ];
        foreach ($iconPatterns as $p) {
            if (preg_match($p, $html, $m)) {
                $icon = $m[1];
                // 处理相对路径
                if (!preg_match('/^https?:\/\//', $icon)) {
                    $purl = parse_url($finalUrl);
                    $base = $purl['scheme'] . '://' . $purl['host'] . (isset($purl['port']) ? ':' . $purl['port'] : '');
                    $icon = $base . ($icon[0] === '/' ? '' : '/') . $icon;
                }
                $info['icon'] = $icon;
                break;
            }
        }

        return $info;
    }

    /**
     * 抓取单个网站信息（标题、描述、图标、存活状态）
     *
     * @param string $url
     * @return array
     */
    public static function fetchSiteInfo($url)
    {
        $pluginOptions = self::getPluginOptions();
        $timeout = intval($pluginOptions->timeout ?? 10);
        $url = rtrim($url, '/');
        if (!preg_match('/^https?:\/\//', $url)) $url = 'https://' . $url;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
            CURLOPT_ENCODING       => 'gzip, deflate'
        ]);

        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $alive = ($code >= 200 && $code < 400);

        if ($code === 200 && $html) {
            $info = self::parseSiteInfo($html, $finalUrl);
            $info['alive'] = $alive;
            return $info;
        }

        return ['title' => '', 'description' => '', 'icon' => '', 'alive' => $alive];
    }

    /**
     * 批量抓取多个网站信息（使用 curl_multi 并发）
     *
     * @param array $urls
     * @return array 键名保持传入顺序的结果数组
     */
    public static function fetchMultiSiteInfo(array $urls)
    {
        $mh = curl_multi_init();
        $handles = $results = [];
        $timeout = intval(self::getPluginOptions()->timeout ?? 10);

        // 初始化所有 curl 句柄
        foreach ($urls as $k => $url) {
            $url = rtrim($url, '/');
            if (!preg_match('/^https?:\/\//', $url)) $url = 'https://' . $url;

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0',
                CURLOPT_ENCODING       => 'gzip, deflate'
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$k] = $ch;
        }

        // 执行并发请求
        do {
            curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh);
        } while ($active);

        // 收集结果
        foreach ($handles as $k => $ch) {
            $html = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $alive = ($code >= 200 && $code < 400);

            if ($code === 200 && $html) {
                $info = self::parseSiteInfo($html, $finalUrl);
                $info['alive'] = $alive;
            } else {
                $info = ['title' => '', 'description' => '', 'icon' => '', 'alive' => $alive];
            }
            $results[$k] = $info;

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
        return $results;
    }

    // ==================== 链接 CRUD ====================

    /**
     * 获取最大排序值
     *
     * @return int
     */
    public static function getMaxSort()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $result = $db->fetchRow($db->select('MAX(`sort`) as max_sort')->from($prefix . self::TABLE_NAME));
        return intval($result['max_sort'] ?? 0);
    }

    /**
     * 获取链接总数（支持筛选）
     *
     * @param bool $includeHidden 是否包含隐藏链接
     * @param string $categoryFilter 'all', 'uncategorized' 或分类ID字符串
     * @return int
     */
    public static function getLinksCount($includeHidden = true, $categoryFilter = 'all')
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $select = $db->select('COUNT(*) as cnt')->from($prefix . self::TABLE_NAME);

        if (!$includeHidden) $select->where('status = ?', 1);
        if ($categoryFilter === 'uncategorized') {
            $select->where('category_id IS NULL');
        } elseif ($categoryFilter === 'dead') {           // 新增
            $select->where('alive = ?', 0);              // 新增
        } elseif ($categoryFilter !== 'all') {
            $select->where('category_id = ?', intval($categoryFilter));
        }

        $result = $db->fetchRow($select);
        return intval($result['cnt'] ?? 0);
    }

    /**
     * 获取分页链接列表
     *
     * @param bool $includeHidden
     * @param string $orderBy
     * @param string $categoryFilter
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function getLinksPaginated($includeHidden = true, $orderBy = 'sort', $categoryFilter = 'all', $limit = 10, $offset = 0)
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $select = $db->select()->from($prefix . self::TABLE_NAME);

        if (!$includeHidden) $select->where('status = ?', 1);
        if ($categoryFilter === 'uncategorized') {
            $select->where('category_id IS NULL');
        } elseif ($categoryFilter === 'dead') {           // 新增
            $select->where('alive = ?', 0);              // 新增
        } elseif ($categoryFilter !== 'all') {
            $select->where('category_id = ?', intval($categoryFilter));
        }
                

        switch ($orderBy) {
            case 'created_asc': $select->order('created', Db::SORT_ASC); break;
            case 'created_desc': $select->order('created', Db::SORT_DESC); break;
            case 'title_asc': $select->order('title', Db::SORT_ASC); break;
            case 'title_desc': $select->order('title', Db::SORT_DESC); break;
            case 'random': $select->order('RAND()'); break;
            default: $select->order('sort')->order('id');
        }

        $select->limit($limit)->offset($offset);
        return $db->fetchAll($select);
    }

    /**
     * 获取所有链接（管理面板使用）
     *
     * @param bool $includeHidden
     * @param string $orderBy
     * @param string $categoryFilter 'all' | 'uncategorized' | 'dead' | 分类ID
     * @return array
     */
    public static function getAllLinks($includeHidden = true, $orderBy = 'sort', $categoryFilter = 'all')
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $select = $db->select()->from($prefix . self::TABLE_NAME);
    
        if (!$includeHidden) $select->where('status = ?', 1);
    
        // 分类与异常筛选
        if ($categoryFilter === 'uncategorized') {
            $select->where('category_id IS NULL');
        } elseif ($categoryFilter === 'dead') {
            $select->where('alive = ?', 0);
        } elseif ($categoryFilter !== 'all') {
            // 具体分类 ID
            $select->where('category_id = ?', intval($categoryFilter));
        }
    
        switch ($orderBy) {
            case 'created_asc': $select->order('created', Db::SORT_ASC); break;
            case 'created_desc': $select->order('created', Db::SORT_DESC); break;
            case 'title_asc': $select->order('title', Db::SORT_ASC); break;
            case 'title_desc': $select->order('title', Db::SORT_DESC); break;
            case 'random': $select->order('RAND()'); break;
            default: $select->order('sort')->order('id');
        }
    
        return $db->fetchAll($select);
    }

    /**
     * 获取单个链接
     *
     * @param int $id
     * @return array|null
     */
    public static function getLink($id)
    {
        return Db::get()->fetchRow(Db::get()->select()->from(Db::get()->getPrefix() . self::TABLE_NAME)->where('id = ?', $id));
    }

    /**
     * 添加链接（自动抓取网站信息）
     *
     * @param array $data
     * @return bool
     */
    public static function addLink($data)
    {
        $db = Db::get();
        $table = $db->getPrefix() . self::TABLE_NAME;

        // 抓取网站信息
        $info = self::fetchSiteInfo($data['url']);
        $title = $info['title'] ?: ($data['title'] ?: parse_url($data['url'], PHP_URL_HOST));
        $desc = $info['description'] ?: ($data['description'] ?? '');
        $icon = $info['icon'] ?: ($data['icon'] ?? '');
        $alive = $info['alive'] ? 1 : 0;

        $categoryId = isset($data['category_id']) && $data['category_id'] !== '' ? intval($data['category_id']) : null;
        if ($categoryId === 0) $categoryId = null;

        return $db->query($db->insert($table)->rows([
            'url'          => $data['url'],
            'title'        => $title,
            'description'  => $desc,
            'icon'         => $icon,
            'status'       => $data['status'] ?? 1,
            'sort'         => $data['sort'] ?? 0,
            'category_id'  => $categoryId,
            'last_update'  => time(),
            'created'      => time(),
            'alive'        => $alive,
            'alive_checked'=> time()
        ]));
    }

    /**
     * 更新链接（重新抓取网站信息）
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public static function updateLink($id, $data)
    {
        $db = Db::get();
        $info = self::fetchSiteInfo($data['url']);
        $alive = $info['alive'] ? 1 : 0;

        $categoryId = isset($data['category_id']) && $data['category_id'] !== '' ? intval($data['category_id']) : null;
        if ($categoryId === 0) $categoryId = null;

        return $db->query($db->update($db->getPrefix() . self::TABLE_NAME)->rows([
            'url'          => $data['url'],
            'title'        => $data['title'],
            'description'  => $data['description'] ?? '',
            'icon'         => $data['icon'] ?? '',
            'status'       => $data['status'],
            'sort'         => $data['sort'],
            'category_id'  => $categoryId,
            'alive'        => $alive,
            'alive_checked'=> time()
        ])->where('id = ?', $id));
    }

    /**
     * 删除链接
     *
     * @param int $id
     * @return bool
     */
    public static function deleteLink($id)
    {
        return Db::get()->query(Db::get()->delete(Db::get()->getPrefix() . self::TABLE_NAME)->where('id = ?', $id));
    }

    /**
     * 更新单个链接的信息（重新抓取并更新数据库）
     *
     * @param int $linkId
     * @return bool
     */
    public static function updateLinkInfo($linkId)
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::TABLE_NAME;

        $link = $db->fetchRow($db->select()->from($table)->where('id = ?', $linkId));
        if (!$link) return false;

        $info = self::fetchSiteInfo($link['url']);
        $updateData = [];
        if (!empty($info['title'])) $updateData['title'] = $info['title'];
        if (!empty($info['description'])) $updateData['description'] = $info['description'];
        if (!empty($info['icon'])) $updateData['icon'] = $info['icon'];
        $updateData['alive'] = $info['alive'] ? 1 : 0;
        $updateData['alive_checked'] = time();
        $updateData['last_update'] = time();

        $db->query($db->update($table)->rows($updateData)->where('id = ?', $linkId));
        self::refreshCache();
        return true;
    }

    /**
     * 更新所有显示链接的信息（批量并发抓取）
     *
     * @return int 更新的链接数量
     */
    public static function updateAllLinksInfo()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::TABLE_NAME;

        $links = $db->fetchAll($db->select()->from($table)->where('status = ?', 1));
        if (!$links) return 0;

        $urls = array_column($links, 'url');
        $infos = self::fetchMultiSiteInfo($urls);

        $updated = 0;
        foreach ($links as $i => $link) {
            $info = $infos[$i];
            $updateData = [];
            if (!empty($info['title'])) $updateData['title'] = $info['title'];
            if (!empty($info['description'])) $updateData['description'] = $info['description'];
            if (!empty($info['icon'])) $updateData['icon'] = $info['icon'];
            $updateData['alive'] = $info['alive'] ? 1 : 0;
            $updateData['alive_checked'] = time();
            $updateData['last_update'] = time();

            $db->query($db->update($table)->rows($updateData)->where('id = ?', $link['id']));
            $updated++;
        }

        self::refreshCache();
        return $updated;
    }

    /**
     * 重整排序值（从 1 开始连续编号）
     */
    public static function compactSorts()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::TABLE_NAME;

        $links = $db->fetchAll($db->select()->from($table)->order('sort')->order('id'));
        $i = 1;
        foreach ($links as $link) {
            if ($link['sort'] != $i) {
                $db->query($db->update($table)->rows(['sort' => $i])->where('id = ?', $link['id']));
            }
            $i++;
        }
    }

    /**
     * 删除所有存活状态异常的链接
     *
     * @return int 删除的数量
     */
    public static function deleteDeadLinks()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::TABLE_NAME;

        $stmt = $db->query($db->delete($table)->where('alive = ?', 0));
        $deleted = is_object($stmt) ? $stmt->rowCount() : 0;
        self::refreshCache();
        return $deleted;
    }

    /**
     * 获取缓存状态信息（用于后台面板展示）
     *
     * @return array
     */
    public static function getCacheInfo()
    {
        $cacheFile = self::CACHE_DIR . 'friendlinks.cache.json';
        $cacheTime = intval(self::getPluginOptions()->cacheTime ?? 604800);
        $info = ['exists' => file_exists($cacheFile), 'size' => 0, 'modified' => 0, 'ttl' => $cacheTime];

        if ($info['exists']) {
            $info['size'] = filesize($cacheFile);
            $info['modified'] = filemtime($cacheFile);
            $info['remaining'] = max(0, $info['modified'] + $cacheTime - time());
            $info['expired'] = $info['remaining'] <= 0;
        }

        return $info;
    }
}