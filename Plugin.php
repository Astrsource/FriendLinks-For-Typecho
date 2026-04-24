<?php
/**
 * 友情链接插件 - 自动抓取网站信息版<br>
 * 启用后在[管理]-[友情链接]中管理和操作友情链接
 * @package FriendLinks
 * @author Astrsource
 * @version 2.0.0
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
    const CACHE_DIR = __TYPECHO_ROOT_DIR__ . '/usr/cache/';

    public static function activate()
    {
        if (!extension_loaded('curl')) {
            throw new \Typecho\Plugin\Exception(_t('需要 PHP cURL 扩展'));
        }
        self::createTable();
        if (!is_dir(self::CACHE_DIR)) mkdir(self::CACHE_DIR, 0755, true);
        Helper::addPanel(3, 'FriendLinks/panel.php', _t('友情链接'), _t('管理友情链接'), 'administrator');
        Helper::addAction('FriendLinks-edit', 'FriendLinks_Edit');
        Helper::addAction('friendlinks-update', 'FriendLinks_Action');
        Helper::addRoute('friendlinks_cron', '/friendlinks/cron', 'FriendLinks_Action', 'cron');
        \Typecho\Plugin::factory('Widget\Abstract\Contents')->contentEx = ['FriendLinks_Plugin', 'parseShortcode'];
        \Typecho\Plugin::factory('Widget\Abstract\Contents')->excerptEx = ['FriendLinks_Plugin', 'parseShortcode'];
        return _t('自动抓取网站信息，支持缓存、定时更新、自定义模板和CSS');
    }

    public static function deactivate()
    {
        $options = Helper::options();
        $pluginOptions = $options->plugin('FriendLinks');
        $dropTable = isset($pluginOptions->dropTableOnDeactivate) && $pluginOptions->dropTableOnDeactivate == 1;

        if ($dropTable) {
            $db = Db::get();
            $prefix = $db->getPrefix();
            $table = $prefix . self::TABLE_NAME;
            try {
                $db->query("DROP TABLE IF EXISTS `{$table}`");
            } catch (Exception $e) {
                // 静默处理
            }
        }

        Helper::removeAction('FriendLinks-edit');
        Helper::removePanel(3, 'FriendLinks/panel.php');
    }

    public static function config(Form $form)
    {
        $cacheTime = new \Typecho\Widget\Helper\Form\Element\Text('cacheTime', null, '604800', _t('缓存时间（秒）'), _t('缓存时间单位为秒，默认时间是 7 天'));
        $form->addInput($cacheTime);

        $timeout = new \Typecho\Widget\Helper\Form\Element\Text('timeout', null, '10', _t('请求超时（秒）'), _t('默认请求超时时间是 10 秒'));
        $form->addInput($timeout);

        $defaultIcon = new \Typecho\Widget\Helper\Form\Element\Text(
            'defaultIcon',
            null,
            '/favicon.png',
            _t('默认图标 URL'),
            _t('当无法获取到网站图标时显示的默认图标地址，留空则不显示')
        );
        $form->addInput($defaultIcon);

        $template = new \Typecho\Widget\Helper\Form\Element\Textarea('template', null,
            '<div class="friendlink-card">
<div class="result-header">
<div class="favicon">
<img src="{icon}" alt="favicon">
</div>
<div>
<div class="title">{title}</div>
<div class="url-display"><a href="{url}" target="_blank" style="color: #2563eb;">{url}</a></div>
</div>
</div>
<div class="description">
<div class="label" style="font-style:normal;">描述</div>
{description}
</div>
<div class="alive">
<div class="label">最后更新/网站状态</div>
<span>{last_update}/{alive}</span>
</div>
</div>',
            _t('卡片模板'),
            _t('占位符：{url}、{title}、{description}、{icon}、{last_update}、{alive}<br><span style="color: red">.friendlink-card类名请保留，用于card_class参数锚定！</span>')
        );
        $form->addInput($template);

        $customCss = new \Typecho\Widget\Helper\Form\Element\Textarea('customCss', null,
            '.friendlink-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
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
        .description {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px dashed #cbd5e1;
            color: #334155;
            line-height: 1.5;
            word-break: break-word;
        }
        .label {
            font-size: 13px;
            font-weight: 500;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .alive {
            font-family: monospace;
            font-size: 14px;
            color: #475569;
            background: #eef2ff;
            padding: 6px 10px;
            border-radius: 8px;
            margin-top: 12px;
            word-break: break-all;
        }',
            _t('自定义 CSS'),
            _t('自定义友情链接卡片的 CSS 样式')
        );
        $form->addInput($customCss);

        $sortOrder = new \Typecho\Widget\Helper\Form\Element\Select(
            'sortOrder',
            [
                'manual'       => _t('手动排序（按后台设置的序号）'),
                'created_desc' => _t('添加时间（新 → 旧）'),
                'created_asc'  => _t('添加时间（旧 → 新）'),
                'title_asc'    => _t('标题（A → Z）'),
                'title_desc'   => _t('标题（Z → A）'),
                'random'       => _t('随机')
            ],
            'manual',
            _t('前台排序方式'),
            _t('选择友情链接在前台页面中的显示顺序<br>注意：若开启“允许访客选择排序”选项，此设置将被覆盖')
        );
        $form->addInput($sortOrder);

        $allowVisitorSort = new \Typecho\Widget\Helper\Form\Element\Radio(
            'allowVisitorSort',
            [
                '0' => _t('关闭'),
                '1' => _t('开启')
            ],
            '0',
            _t('允许访客选择排序'),
            _t('开启后，前台将显示排序下拉框，访客可切换排序方式，偏好会记录在浏览器中<br注意：访客在不同设备上访问时，排序偏好会不同')
        );
        $form->addInput($allowVisitorSort);

        $skipDeadLinks = new \Typecho\Widget\Helper\Form\Element\Radio(
            'skipDeadLinks',
            [
                '0' => _t('不跳过'),
                '1' => _t('跳过')
            ],
            '0',
            _t('跳过异常网站'),
            _t('前台渲染时，是否跳过存活状态为异常的网站（即不显示已失效的链接）')
        );
        $form->addInput($skipDeadLinks);

        $secretKey = new \Typecho\Widget\Helper\Form\Element\Text('secretKey', null, '', _t('Cron 密钥'), _t('设置服务器 Cron 定时访问时的密钥，用于验证请求来源'));
        $form->addInput($secretKey);

        $dropTable = new \Typecho\Widget\Helper\Form\Element\Radio(
            'dropTableOnDeactivate',
            [
                '0' => _t('不删除'),
                '1' => _t('<span style="color: red;font-weight: bold;">删除</span>')
            ],
            '0',
            _t('禁用插件时删除数据表'),
            _t('<span style="color: red;font-weight: bold;">注意：若选择删除，禁用插件后所有友情链接数据将被永久删除，请谨慎操作</span>')
        );
        $form->addInput($dropTable);
    }

    public static function personalConfig(Form $form) {}

    // ==================== 数据库表 ====================

    private static function createTable()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $tableName = $prefix . self::TABLE_NAME;

        $sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `url` varchar(255) NOT NULL,
            `title` varchar(255) NOT NULL,
            `description` text,
            `icon` varchar(255) DEFAULT NULL,
            `status` tinyint(1) DEFAULT '1',
            `sort` int(11) DEFAULT '0',
            `last_update` int(11) DEFAULT '0',
            `created` int(11) DEFAULT '0',
            `alive` tinyint(1) DEFAULT NULL,
            `alive_checked` int(11) DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `idx_status_sort` (`status`, `sort`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $db->query($sql);
    }

    // ==================== 短代码 ====================

    public static function parseShortcode($content, $widget, $lastResult)
    {
        $content = empty($lastResult) ? $content : $lastResult;
        if (strpos($content, '[friendlinks') !== false) {
            $pattern = '/\[friendlinks(?:\s+container_class="([^"]*)")?(?:\s+card_class="([^"]*)")?\]/';
            $content = preg_replace_callback($pattern, function ($m) {
                return self::renderLinks($m[1] ?? 'friendlinks-container', $m[2] ?? '');
            }, $content);
        }
        return $content;
    }

    public static function renderLinks($containerClass = 'friendlinks-container', $cardClass = '')
    {
        $options = Helper::options();
        $pluginOptions = $options->plugin('FriendLinks');
        $cacheTime = intval($pluginOptions->cacheTime ?? 604800);
        $template = $pluginOptions->template ?: '<div class="friendlink-card"><a href="{url}"><img src="{icon}"><h4>{title}</h4><p>{description}</p></a></div>';
        $customCss = $pluginOptions->customCss ?? '';
        $defaultSortOrder = $pluginOptions->sortOrder ?? 'manual';
        $defaultIcon = $pluginOptions->defaultIcon ?? '';
        $allowVisitorSort = isset($pluginOptions->allowVisitorSort) && $pluginOptions->allowVisitorSort == 1;
        $skipDead = ($pluginOptions->skipDeadLinks ?? '0') == '1';

        // 允许访客排序时，优先读取 Cookie，其次 URL 参数，最后后台设置
        $currentSort = $defaultSortOrder;
        $allowedSorts = ['manual', 'created_desc', 'created_asc', 'title_asc', 'title_desc', 'random'];

        if ($allowVisitorSort) {
            $cookieSort = $_COOKIE['friendlinks_visitor_sort'] ?? '';
            if ($cookieSort && in_array($cookieSort, $allowedSorts)) {
                $currentSort = $cookieSort;
            } elseif (isset($_GET['sort']) && in_array($_GET['sort'], $allowedSorts)) {
                $currentSort = $_GET['sort'];
            }
        } else {
            if (isset($_GET['sort']) && in_array($_GET['sort'], $allowedSorts)) {
                // 仍可使用 URL 参数强制改变？为了安全，禁止
            }
        }

        // 重要：开启访客排序时，完全禁用渲染缓存，因为排序随时可能变化
        $useRenderCache = ($currentSort !== 'random') && !$allowVisitorSort;

        if ($useRenderCache) {
            // 缓存键需考虑 skipDead 选项，不同设置输出不同
            $renderKey = 'friendlinks_rendered_' . md5($containerClass . $cardClass . $template . $customCss . $currentSort . $defaultIcon . ($skipDead ? '1' : '0'));
            $renderedCacheFile = self::CACHE_DIR . $renderKey . '.html';
            if (file_exists($renderedCacheFile) && (time() - filemtime($renderedCacheFile)) < $cacheTime) {
                return file_get_contents($renderedCacheFile);
            }
        }

        $links = self::getLinksFromCacheOnly();
        if (empty($links)) return '<p class="friendlinks-empty">' . _t('暂无友情链接') . '</p>';

        // 如果开启跳过异常网站，则过滤掉 alive 为 0（异常）的链接
        if ($skipDead) {
            $links = array_values(array_filter($links, function($link) {
                return ($link['alive'] ?? null) !== 0;
            }));
        }

        // 排序逻辑
        switch ($currentSort) {
            case 'manual':
                break;
            case 'created_desc':
                usort($links, function($a, $b) { return $b['created'] <=> $a['created']; });
                break;
            case 'created_asc':
                usort($links, function($a, $b) { return $a['created'] <=> $b['created']; });
                break;
            case 'title_asc':
                usort($links, function($a, $b) { return strcasecmp($a['title'], $b['title']); });
                break;
            case 'title_desc':
                usort($links, function($a, $b) { return strcasecmp($b['title'], $a['title']); });
                break;
            case 'random':
                shuffle($links);
                break;
        }

        // 生成排序选择器 HTML（仅开启访客排序时显示）
        $sortSelectorHtml = '';
        if ($allowVisitorSort) {
            $sortOptions = [
                'manual'       => '默认排序',
                'created_desc' => '最新添加',
                'created_asc'  => '最早添加',
                'title_asc'    => '标题 A-Z',
                'title_desc'   => '标题 Z-A',
                'random'       => '随机'
            ];
            $selectHtml = '<select id="friendlinks-sort-select" style="margin-bottom: 10px; padding: 5px 10px; border-radius: 4px; border: 1px solid #ccc;">';
            foreach ($sortOptions as $val => $label) {
                $selected = ($val === $currentSort) ? ' selected' : '';
                $selectHtml .= '<option value="' . $val . '"' . $selected . '>' . $label . '</option>';
            }
            $selectHtml .= '</select>';
            $sortSelectorHtml = '<div class="friendlinks-sort-toolbar">' . $selectHtml . '</div>';

            $sortSelectorHtml .= <<<HTML
<script>
(function() {
    var select = document.getElementById('friendlinks-sort-select');
    if (select) {
        select.addEventListener('change', function() {
            var value = this.value;
            var d = new Date();
            d.setFullYear(d.getFullYear() + 1);
            document.cookie = 'friendlinks_visitor_sort=' + value +
                '; path=/; expires=' + d.toUTCString() +
                '; SameSite=Lax';
            window.location.reload();
        });
    }
})();
</script>
HTML;
        }

        // 构建卡片列表
        $output = '<style>' . $customCss . '</style>';
        $output .= $sortSelectorHtml;
        $output .= '<div class="' . htmlspecialchars($containerClass) . '">';
        foreach ($links as $link) {
            $icon = $link['icon'] ?: $defaultIcon;
            $lastUpdate = $link['last_update'] ? date('Y-m-d H:i', $link['last_update']) : '';
            $aliveText = '';
            if (isset($link['alive'])) {
                $aliveText = $link['alive'] ? '正常' : '异常';
            } else {
                $aliveText = '未知';
            }

            $card = str_replace(
                ['{url}', '{title}', '{description}', '{icon}', '{last_update}', '{alive}'],
                [
                    htmlspecialchars($link['url']),
                    htmlspecialchars($link['title']),
                    htmlspecialchars($link['description'] ?: ''),
                    htmlspecialchars($icon),
                    htmlspecialchars($lastUpdate),
                    htmlspecialchars($aliveText)
                ],
                $template
            );
            if ($cardClass) $card = str_replace('friendlink-card', 'friendlink-card ' . $cardClass, $card);
            $output .= $card;
        }
        $output .= '</div>';

        if ($useRenderCache) {
            file_put_contents($renderedCacheFile, $output, LOCK_EX);
        }

        return $output;
    }

    public static function output($containerClass = 'friendlinks-container', $cardClass = '')
    {
        echo self::renderLinks($containerClass, $cardClass);
    }

    private static function getLinksFromCacheOnly()
    {
        $cacheFile = self::CACHE_DIR . 'friendlinks.cache.json';
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            return is_array($data) ? $data : [];
        }
        return [];
    }

    public static function refreshCache()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $tableName = $prefix . self::TABLE_NAME;
        $links = $db->fetchAll($db->select()->from($tableName)->where('status = ?', 1)->order('sort')->order('id'));
        file_put_contents(self::CACHE_DIR . 'friendlinks.cache.json', json_encode($links, JSON_UNESCAPED_UNICODE), LOCK_EX);
        self::clearRenderedCache();
        return $links;
    }

    private static function clearRenderedCache()
    {
        foreach (glob(self::CACHE_DIR . 'friendlinks_rendered_*.html') as $file) @unlink($file);
    }

    // ==================== 信息抓取 ====================

    private static function parseSiteInfo($html, $finalUrl)
    {
        $info = ['title' => '', 'description' => '', 'icon' => ''];
        if (preg_match('/<title[^>]*>(.*?)<\/title>/i', $html, $m)) $info['title'] = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        $patterns = ['/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\']/i', '/<meta[^>]*property=["\']og:description["\'][^>]*content=["\']([^"\']*)["\']/i'];
        foreach ($patterns as $p) if (preg_match($p, $html, $m)) { $info['description'] = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')); break; }
        $iconPatterns = ['/<link[^>]*rel=["\'](?:shortcut )?icon["\'][^>]*href=["\']([^"\']*)["\']/i', '/<link[^>]*rel=["\']apple-touch-icon["\'][^>]*href=["\']([^"\']*)["\']/i'];
        foreach ($iconPatterns as $p) if (preg_match($p, $html, $m)) {
            $icon = $m[1];
            if (!preg_match('/^https?:\/\//', $icon)) {
                $purl = parse_url($finalUrl);
                $base = $purl['scheme'] . '://' . $purl['host'] . (isset($purl['port']) ? ':' . $purl['port'] : '');
                $icon = $base . ($icon[0] === '/' ? '' : '/') . $icon;
            }
            $info['icon'] = $icon;
            break;
        }
        return $info;
    }

    public static function fetchSiteInfo($url)
    {
        $options = Helper::options();
        $timeout = intval($options->plugin('FriendLinks')->timeout ?? 10);
        $url = rtrim($url, '/');
        if (!preg_match('/^https?:\/\//', $url)) $url = 'https://' . $url;
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => $timeout, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false, CURLOPT_USERAGENT => 'Mozilla/5.0', CURLOPT_ENCODING => 'gzip, deflate']);
        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return ($code === 200 && $html) ? self::parseSiteInfo($html, $final) : ['title' => '', 'description' => '', 'icon' => ''];
    }

    public static function fetchMultiSiteInfo(array $urls)
    {
        $mh = curl_multi_init();
        $handles = $results = [];
        $timeout = intval(Helper::options()->plugin('FriendLinks')->timeout ?? 10);
        foreach ($urls as $k => $url) {
            $url = rtrim($url, '/');
            if (!preg_match('/^https?:\/\//', $url)) $url = 'https://' . $url;
            $ch = curl_init();
            curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => $timeout, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false, CURLOPT_USERAGENT => 'Mozilla/5.0']);
            curl_multi_add_handle($mh, $ch);
            $handles[$k] = $ch;
        }
        do { curl_multi_exec($mh, $active); if ($active) curl_multi_select($mh); } while ($active);
        foreach ($handles as $k => $ch) {
            $html = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $results[$k] = ($code === 200 && $html) ? self::parseSiteInfo($html, $final) : ['title' => '', 'description' => '', 'icon' => ''];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $results;
    }

    // ==================== 存活检查 ====================

    private static function checkAlive($url)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0'
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 400);
    }

    // ==================== CRUD ====================

    public static function getAllLinks($includeHidden = true, $orderBy = 'sort')
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $select = $db->select()->from($prefix . self::TABLE_NAME);
        if (!$includeHidden) {
            $select->where('status = ?', 1);
        }

        switch ($orderBy) {
            case 'created_asc':
                $select->order('created', Db::SORT_ASC);
                break;
            case 'created_desc':
                $select->order('created', Db::SORT_DESC);
                break;
            case 'title_asc':
                $select->order('title', Db::SORT_ASC);
                break;
            case 'title_desc':
                $select->order('title', Db::SORT_DESC);
                break;
            case 'random':
                $select->order('RAND()');
                break;
            case 'sort':
            default:
                $select->order('sort')->order('id');
                break;
        }

        return $db->fetchAll($select);
    }

    public static function getLink($id)
    {
        return Db::get()->fetchRow(Db::get()->select()->from(Db::get()->getPrefix() . self::TABLE_NAME)->where('id = ?', $id));
    }

    public static function addLink($data)
    {
        $db = Db::get();
        $table = $db->getPrefix() . self::TABLE_NAME;
        $info = self::fetchSiteInfo($data['url']);
        $title = $info['title'] ?: ($data['title'] ?: parse_url($data['url'], PHP_URL_HOST));
        $desc = $info['description'] ?: ($data['description'] ?? '');
        $icon = $info['icon'] ?: ($data['icon'] ?? '');
        $alive = self::checkAlive($data['url']) ? 1 : 0;

        return $db->query($db->insert($table)->rows([
            'url' => $data['url'],
            'title' => $title,
            'description' => $desc,
            'icon' => $icon,
            'status' => $data['status'] ?? 1,
            'sort' => $data['sort'] ?? 0,
            'last_update' => time(),
            'created' => time(),
            'alive' => $alive,
            'alive_checked' => time()
        ]));
    }

    public static function updateLink($id, $data)
    {
        $db = Db::get();
        $url = $data['url'];
        $alive = self::checkAlive($url) ? 1 : 0;

        return $db->query($db->update($db->getPrefix() . self::TABLE_NAME)->rows([
            'url' => $url,
            'title' => $data['title'],
            'description' => $data['description'] ?? '',
            'icon' => $data['icon'] ?? '',
            'status' => $data['status'],
            'sort' => $data['sort'],
            'alive' => $alive,
            'alive_checked' => time()
        ])->where('id = ?', $id));
    }

    public static function deleteLink($id)
    {
        return Db::get()->query(Db::get()->delete(Db::get()->getPrefix() . self::TABLE_NAME)->where('id = ?', $id));
    }

    public static function updateLinkInfo($linkId)
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::TABLE_NAME;
        $link = $db->fetchRow($db->select()->from($table)->where('id = ?', $linkId));
        if (!$link) return false;
        $info = self::fetchSiteInfo($link['url']);
        
        $updateData = [];
        if (!empty($info['title'])) {
            $updateData['title'] = $info['title'];
        }
        if (!empty($info['description'])) {
            $updateData['description'] = $info['description'];
        }
        if (!empty($info['icon'])) {
            $updateData['icon'] = $info['icon'];
        }
        $updateData['alive'] = self::checkAlive($link['url']) ? 1 : 0;
        $updateData['alive_checked'] = time();
        $updateData['last_update'] = time();

        $db->query($db->update($table)->rows($updateData)->where('id = ?', $linkId));
        self::refreshCache();
        return true;
    }

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
            if (!empty($info['title'])) {
                $updateData['title'] = $info['title'];
            }
            if (!empty($info['description'])) {
                $updateData['description'] = $info['description'];
            }
            if (!empty($info['icon'])) {
                $updateData['icon'] = $info['icon'];
            }
            $updateData['alive'] = self::checkAlive($link['url']) ? 1 : 0;
            $updateData['alive_checked'] = time();
            $updateData['last_update'] = time();

            $db->query($db->update($table)->rows($updateData)->where('id = ?', $link['id']));
            $updated++;
            usleep(100000);
        }
        self::refreshCache();
        return $updated;
    }

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

    public static function deleteDeadLinks()
    {
        $db = Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::TABLE_NAME;

        $count = $db->fetchObject($db->select(['COUNT(*)' => 'num'])->from($table)->where('alive = ?', 0))->num;
        if ($count > 0) {
            $db->query($db->delete($table)->where('alive = ?', 0));
        }
        self::refreshCache();
        return (int)$count;
    }

    public static function getCacheInfo()
    {
        $cacheFile = self::CACHE_DIR . 'friendlinks.cache.json';
        $cacheTime = intval(Helper::options()->plugin('FriendLinks')->cacheTime ?? 604800);
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