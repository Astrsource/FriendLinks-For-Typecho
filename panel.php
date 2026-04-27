<?php
/**
 * 友情链接管理面板
 * 
 * 提供后台管理界面：链接/分类的增删改查、信息抓取、缓存管理、排序、分类筛选、分页
 * 
 * @package FriendLinks
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Utils\Helper;

// 权限验证
$user = \Typecho\Widget::widget('Widget_User');
if (!$user->pass('administrator', true)) die(_t('权限不足'));

// ==================== 处理 POST 请求 ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'add_category':
            $name = $_POST['name'] ?? '';
            $sort = isset($_POST['sort']) ? intval($_POST['sort']) : 0;
            FriendLinks_Plugin::addCategory($name, $sort);
            $message = _t('分类添加成功');
            break;
        case 'edit_category':
            FriendLinks_Plugin::updateCategory(
                intval($_POST['id'] ?? 0),
                $_POST['name'] ?? '',
                isset($_POST['sort']) ? intval($_POST['sort']) : null
            );
            $message = _t('分类更新成功');
            break;
        case 'delete_category':
            FriendLinks_Plugin::deleteCategory(intval($_POST['id'] ?? 0));
            $message = _t('分类删除成功（链接已移至未分类）');
            break;
        case 'add':
            $categoryId = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? intval($_POST['category_id']) : null;
            FriendLinks_Plugin::addLink([
                'title'       => $_POST['title'] ?? '',
                'url'         => $_POST['url'] ?? '',
                'description' => $_POST['description'] ?? '',
                'icon'        => $_POST['icon'] ?? '',
                'status'      => intval($_POST['status'] ?? 1),
                'sort'        => intval($_POST['sort'] ?? 0),
                'category_id' => $categoryId
            ]);
            FriendLinks_Plugin::refreshCache();
            $message = _t('添加成功');
            break;
        case 'edit':
            $id = intval($_POST['id'] ?? 0);
            $categoryId = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? intval($_POST['category_id']) : null;
            FriendLinks_Plugin::updateLink($id, [
                'title'       => $_POST['title'] ?? '',
                'url'         => $_POST['url'] ?? '',
                'description' => $_POST['description'] ?? '',
                'icon'        => $_POST['icon'] ?? '',
                'status'      => intval($_POST['status'] ?? 1),
                'sort'        => intval($_POST['sort'] ?? 0),
                'category_id' => $categoryId
            ]);
            FriendLinks_Plugin::refreshCache();
            $message = _t('更新成功');
            break;
        case 'delete':
            FriendLinks_Plugin::deleteLink(intval($_POST['id'] ?? 0));
            FriendLinks_Plugin::refreshCache();
            $message = _t('删除成功');
            break;
        case 'update_info':
            FriendLinks_Plugin::updateLinkInfo(intval($_POST['id'] ?? 0));
            $message = _t('信息更新成功');
            break;
        case 'update_all':
            $updated = FriendLinks_Plugin::updateAllLinksInfo();
            $message = sprintf(_t('成功更新 %d 个链接的信息'), $updated);
            break;
        case 'clear_cache':
            FriendLinks_Plugin::refreshCache();
            $message = _t('缓存已刷新');
            break;
        case 'compact_sorts':
            FriendLinks_Plugin::compactSorts();
            FriendLinks_Plugin::refreshCache();
            $message = _t('序号已重新排列');
            break;
        case 'delete_dead':
            $deleted = FriendLinks_Plugin::deleteDeadLinks();
            $message = sprintf(_t('成功删除 %d 个异常链接'), $deleted);
            break;
    }
}

// ==================== 加载页面数据 ====================
$allowedSort = ['sort', 'created_desc', 'created_asc', 'title_asc', 'title_desc', 'random'];
$cookieSort = $_COOKIE['friendlinks_sort'] ?? '';
$currentSort = $_GET['sortby'] ?? (in_array($cookieSort, $allowedSort) ? $cookieSort : 'sort');
$currentCategory = $_GET['category_id'] ?? 'all';

// 分页参数
$perPageOptions = [10, 20, 50];
$perPage = isset($_GET['per_page']) && in_array(intval($_GET['per_page']), $perPageOptions) ? intval($_GET['per_page']) : 10;
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

$totalCount = FriendLinks_Plugin::getLinksCount(true, $currentCategory);
$totalPages = ceil($totalCount / $perPage);
$offset = ($currentPage - 1) * $perPage;
$links = FriendLinks_Plugin::getLinksPaginated(true, $currentSort, $currentCategory, $perPage, $offset);

$cacheInfo = FriendLinks_Plugin::getCacheInfo();
$categories = FriendLinks_Plugin::getCategories();
$categoryCounts = FriendLinks_Plugin::getCategoryLinkCounts();

$options = Helper::options();
$siteUrl = $options->siteUrl;
$pluginOptions = $options->plugin('FriendLinks');
$secretKey = $pluginOptions->secretKey ?? '';
$cronUrl = rtrim($siteUrl, '/') . '/friendlinks/cron' . ($secretKey ? '?key=' . $secretKey : '');

// 计算默认排序值（全表最大值+1，确保跨页正确）
$nextSort = FriendLinks_Plugin::getMaxSort() + 1;
$maxCatSort = 0;
foreach ($categories as $cat) if ($cat['sort'] > $maxCatSort) $maxCatSort = $cat['sort'];
$nextCatSort = $maxCatSort + 1;

$categoryMap = [];
foreach ($categories as $cat) $categoryMap[$cat['id']] = $cat['name'];

// 构建分页 HTML
$paginationHtml = '<div class="friendlinks-pagination" style="margin-top:20px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">';
// 每页条数选择器
$paginationHtml .= '<select id="perPageSelect" style="padding:5px;">';
foreach ($perPageOptions as $opt) {
    $selected = ($opt == $perPage) ? ' selected' : '';
    $paginationHtml .= '<option value="' . $opt . '"' . $selected . '>' . $opt . ' 条/页</option>';
}
$paginationHtml .= '</select>';

// 页码导航
$urlParams = $_GET;
unset($urlParams['page']);
$paginationHtml .= '<div style="display:flex; gap:5px;">';
if ($currentPage > 1) {
    $prevParams = array_merge($urlParams, ['page' => $currentPage - 1]);
    $paginationHtml .= '<a class="btn btn-sm" href="?' . http_build_query($prevParams) . '" data-page="' . ($currentPage - 1) . '">上一页</a>';
}
for ($i = 1; $i <= $totalPages; $i++) {
    if ($i == $currentPage) {
        $paginationHtml .= '<span class="btn btn-sm" style="background:#0073aa;color:#fff;">' . $i . '</span>';
    } else {
        $pageParams = array_merge($urlParams, ['page' => $i]);
        $paginationHtml .= '<a class="btn btn-sm" href="?' . http_build_query($pageParams) . '" data-page="' . $i . '">' . $i . '</a>';
    }
}
if ($currentPage < $totalPages) {
    $nextParams = array_merge($urlParams, ['page' => $currentPage + 1]);
    $paginationHtml .= '<a class="btn btn-sm" href="?' . http_build_query($nextParams) . '" data-page="' . ($currentPage + 1) . '">下一页</a>';
}
$paginationHtml .= '</div>';
$paginationHtml .= '<span style="margin-left:10px;">共 ' . $totalCount . ' 条</span>';
$paginationHtml .= '</div>';

include 'header.php';
include 'menu.php';
?>
<style>
    .friendlinks-panel { padding: 20px; }
    .friendlinks-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    .friendlinks-table th, .friendlinks-table td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
    .friendlinks-table th { background: #f5f5f5; font-weight: bold; }
    .friendlinks-table .actions a, .friendlinks-table .actions button { margin-right: 8px; }
    .friendlinks-table .icon-preview { width: 24px; height: 24px; }
    .status-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; }
    .status-show { background: #d4edda; color: #155724; }
    .status-hide { background: #f8d7da; color: #721c24; }
    .category-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; background: #e8f0fe; color: #1967d2; }
    .cache-info { background: #f9f9f9; padding: 15px; border-radius: 6px; margin: 20px 0; }
    .cache-info p { margin: 5px 0; }
    .category-section { background: #f9f9f9; padding: 15px; border-radius: 6px; margin: 20px 0; }
    .category-grid { display: flex; gap: 12px; flex-wrap: wrap; }
    .category-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px 16px; min-width: 170px; flex: 1; max-width: 220px; }
    .category-card .cat-name { font-weight: bold; font-size: 16px; margin-bottom: 4px; }
    .category-card .cat-meta { font-size: 12px; color: #666; }
    .category-card .cat-actions { margin-top: 8px; }
    .category-card .cat-actions button { font-size: 12px; padding: 2px 8px; margin-right: 4px; }
    .toolbar { margin: 20px 0; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .btn { display: inline-block; padding: 8px 16px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #333; background: #fff; cursor: pointer; }
    .btn-primary { background: #0073aa; border-color: #0073aa; color: #fff; }
    .btn-danger { background: #dc3545; border-color: #dc3545; color: #fff; }
    .btn-warning { background: #ffc107; border-color: #ffc107; color: #333; }
    .btn-sm { padding: 4px 8px; font-size: 12px; }
    .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
    .modal-content { background: #fff; margin: 50px auto; padding: 20px; width: 500px; max-height: 80vh; overflow-y: auto; border-radius: 8px; }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .modal-close { font-size: 24px; cursor: pointer; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
    .cron-info { background: #f0f7ff; border-left: 4px solid #0073aa; padding: 15px; margin: 20px 0; }
    .cron-info pre { background: #fff; padding: 10px; border-radius: 4px; overflow-x: auto; }
    .message.notice { transition: opacity 0.5s; background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
</style>

<div class="friendlinks-panel">
    <h2><?php _e('友情链接管理'); ?></h2>
    <?php if (isset($message)): ?>
        <div class="message notice"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- 分类管理区块 -->
    <div class="category-section">
        <h3><?php _e('分类管理'); ?></h3>
        <div class="category-grid">
            <div class="category-card" style="border-style: dashed;">
                <div class="cat-name">📁 <?php _e('未分类'); ?></div>
                <div class="cat-meta"><?php echo sprintf(_t('%d 条链接'), $categoryCounts['uncategorized'] ?? 0); ?></div>
            </div>
            <?php foreach ($categories as $cat): ?>
                <div class="category-card">
                    <div class="cat-name">📁 <?php echo htmlspecialchars($cat['name']); ?></div>
                    <div class="cat-meta">ID: <?php echo $cat['id']; ?> | <?php echo sprintf(_t('%d 条链接'), $categoryCounts[$cat['id']] ?? 0); ?></div>
                    <div class="cat-actions">
                        <button class="btn btn-sm" onclick='editCategory(<?php echo json_encode($cat, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'>✏️ 编辑</button>
                        <form method="post" class="ajax-form" style="display:inline;" onsubmit="return confirm('<?php _e('确定要删除该分类吗？分类下的链接将移至未分类。'); ?>')">
                            <input type="hidden" name="action" value="delete_category">
                            <input type="hidden" name="id" value="<?php echo $cat['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger">🗑️ 删除</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="category-card" style="border-style: dashed; display: flex; align-items: center; justify-content: center;">
                <button class="btn btn-primary btn-sm" onclick="openCategoryModal()">➕ <?php _e('添加分类'); ?></button>
            </div>
        </div>
    </div>

    <!-- 缓存状态 -->
    <div class="cache-info">
        <h3><?php _e('缓存状态'); ?></h3>
        <p><?php _e('缓存文件:'); ?> <?php echo $cacheInfo['exists'] ? _t('存在') : _t('不存在'); ?></p>
        <?php if ($cacheInfo['exists']): ?>
            <p><?php _e('文件大小:'); ?> <?php echo round($cacheInfo['size'] / 1024, 2); ?> KB</p>
            <p><?php _e('最后更新:'); ?> <?php echo date('Y-m-d H:i:s', $cacheInfo['modified']); ?></p>
            <p><?php _e('剩余有效时间:'); ?> <?php echo floor($cacheInfo['remaining'] / 3600) . '小时 ' . floor(($cacheInfo['remaining'] % 3600) / 60) . '分钟'; ?></p>
            <p><?php _e('状态:'); ?> <?php echo $cacheInfo['expired'] ? '<span style="color:#dc3545;">已过期</span>' : '<span style="color:#28a745;">有效</span>'; ?></p>
        <?php endif; ?>
    </div>

    <!-- 工具栏 -->
    <div class="toolbar">
        <button class="btn btn-primary" onclick="openModal('add')">➕ <?php _e('添加链接'); ?></button>
        <form method="post" class="ajax-form"><input type="hidden" name="action" value="update_all"><button type="submit" class="btn btn-warning">🔄 <?php _e('更新所有信息'); ?></button></form>
        <form method="post" class="ajax-form"><input type="hidden" name="action" value="clear_cache"><button type="submit" class="btn">🗑️ <?php _e('刷新缓存'); ?></button></form>
        <form method="post" class="ajax-form"><input type="hidden" name="action" value="compact_sorts"><button type="submit" class="btn">🔢 <?php _e('重整序号'); ?></button></form>
        <form method="post" class="ajax-form" onsubmit="return confirm('<?php _e('确定要删除所有存活状态异常的链接吗？此操作不可恢复。'); ?>')">
            <input type="hidden" name="action" value="delete_dead"><button type="submit" class="btn btn-danger">🗑️ <?php _e('删除异常链接'); ?></button>
        </form>
        <div style="margin-left: auto; display: flex; align-items: center; gap: 8px; margin-right: 16px;">
            <label for="categoryFilterSelect">分类筛选：</label>
            <select id="categoryFilterSelect" style="padding: 0px 10px; border-radius: 4px; border: 1px solid #ddd;">
                <option value="all" <?php echo $currentCategory == 'all' ? 'selected' : ''; ?>>全部</option>
                <option value="uncategorized" <?php echo $currentCategory == 'uncategorized' ? 'selected' : ''; ?>>未分类</option>
                <option value="dead" <?php echo $currentCategory == 'dead' ? 'selected' : ''; ?>>异常</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $currentCategory == $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <label for="sortSelect" style="font-weight: normal;">排序：</label>
            <select id="sortSelect" style="padding: 0px 10px; border-radius: 4px; border: 1px solid #ddd;">
                <option value="sort" <?php echo $currentSort == 'sort' ? 'selected' : ''; ?>>手动排序</option>
                <option value="created_desc" <?php echo $currentSort == 'created_desc' ? 'selected' : ''; ?>>添加时间 ↓</option>
                <option value="created_asc" <?php echo $currentSort == 'created_asc' ? 'selected' : ''; ?>>添加时间 ↑</option>
                <option value="title_asc" <?php echo $currentSort == 'title_asc' ? 'selected' : ''; ?>>标题 A-Z</option>
                <option value="title_desc" <?php echo $currentSort == 'title_desc' ? 'selected' : ''; ?>>标题 Z-A</option>
                <option value="random" <?php echo $currentSort == 'random' ? 'selected' : ''; ?>>随机</option>
            </select>
            <button type="button" id="refreshSortBtn" class="btn btn-sm" title="刷新当前排序">🔄</button>
        </div>
    </div>

    <!-- 链接列表 -->
    <div class="table-container">
    <table class="friendlinks-table">
        <thead>
            <tr>
                <th>ID</th>
                <th width="40px"><?php _e('存活'); ?></th>
                <th width="80px"><?php _e('分类'); ?></th>
                <th width="160px"><?php _e('标题'); ?></th>
                <th><?php _e('描述'); ?></th>
                <th>URL</th>
                <th width="40px"><?php _e('图标'); ?></th>
                <th width="40px"><?php _e('状态'); ?></th>
                <th width="40px"><?php _e('排序'); ?></th>
                <th><?php _e('最后更新'); ?></th>
                <th width="200px"><?php _e('操作'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($links as $link): ?>
                <tr>
                    <td><?php echo $link['id']; ?></td>
                    <td>
                        <?php if (isset($link['alive']) && $link['alive'] == 1): ?>
                            <span class="status-badge status-show">正常</span>
                        <?php elseif (isset($link['alive']) && $link['alive'] == 0): ?>
                            <span class="status-badge status-hide">异常</span>
                        <?php else: ?>
                            <span class="status-badge" style="background:#eee;color:#666;">未知</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($link['category_id']) && isset($categoryMap[$link['category_id']])): ?>
                            <span class="category-badge"><?php echo htmlspecialchars($categoryMap[$link['category_id']]); ?></span>
                        <?php else: ?>
                            <span style="color:#999;"><?php _e('未分类'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($link['title']); ?></td>
                    <td><?php echo htmlspecialchars(mb_substr($link['description'] ?? '', 0, 30)) . (mb_strlen($link['description'] ?? '') > 30 ? '...' : ''); ?></td>
                    <td><a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank"><?php echo htmlspecialchars(substr($link['url'], 0, 30)); ?></a></td>
                    <td><?php if ($link['icon']): ?><img src="<?php echo htmlspecialchars($link['icon']); ?>" style="width:24px;height:24px;"><?php else: ?><span style="color:#999;">无</span><?php endif; ?></td>
                    <td><span class="status-badge <?php echo $link['status'] ? 'status-show' : 'status-hide'; ?>"><?php echo $link['status'] ? _t('显示') : _t('隐藏'); ?></span></td>
                    <td><?php echo $link['sort']; ?></td>
                    <td><?php echo $link['last_update'] ? date('Y-m-d H:i', $link['last_update']) : _t('未更新'); ?></td>
                    <td class="actions">
                        <button class="btn btn-sm" onclick='editLink(<?php echo json_encode($link, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>)'><?php _e('编辑'); ?></button>
                        <form method="post" class="ajax-form" style="display:inline;"><input type="hidden" name="action" value="update_info"><input type="hidden" name="id" value="<?php echo $link['id']; ?>"><button type="submit" class="btn btn-sm btn-warning"><?php _e('更新信息'); ?></button></form>
                        <form method="post" class="ajax-form" style="display:inline;" onsubmit="return confirm('<?php _e('确定要删除吗？'); ?>')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $link['id']; ?>"><button type="submit" class="btn btn-sm btn-danger"><?php _e('删除'); ?></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($links)): ?><tr><td colspan="11" style="text-align:center;"><?php _e('暂无友情链接'); ?></td></tr><?php endif; ?>
        </tbody>
    </table>
    <!-- 隐藏元素：存储全局最大排序值，供 AJAX 刷新后同步 -->
    <span id="nextSortValue" style="display:none;"><?php echo $nextSort; ?></span>
    <span id="nextCatSortValue" style="display:none;"><?php echo $nextCatSort; ?></span>
    <?php echo $paginationHtml; ?>
    </div>

    <!-- 使用说明 -->
    <div class="cron-info">
        <h3>📌 使用说明</h3>
        <p>
            <span style="color: red;">container_class</span>：自定义容器类名<br>
            <span style="color: red;">category_id</span>：按分类ID过滤链接<br>
            <span style="color: red;">include_uncategorized</span>：是否包含未分类链接（"0"或"false"排除）<br>
            <span style="color: red;">include_dead</span>：是否包含存活异常的链接（全局设置："0"、包含："1"、仅输出："2"）
        </p>
        <h4>1. 短代码示例</h4>
        <pre>[friendlinks]</pre>
        <pre>[friendlinks container_class="my-links" card_class="my-card" category_id="1"]</pre>
        <pre>[friendlinks include_uncategorized="0"]</pre>
        <pre>[friendlinks include_dead="1"]</pre>
        <p><span>注意：</span><code>category_id</code><span> 和 </span><code>include_uncategorized</code><span> 可组合使用；当指定 </span><code>category_id</code><span> 时，</span><code>include_uncategorized</code><span> 无效。</span></p>
        <h4>2. 模板调用</h4>
        <pre>&lt;?php FriendLinks_Plugin::output(); ?&gt;</pre>
        带完整参数（顺序：容器类, 卡片类, 分类ID, 是否包含未分类, 是否包含异常）：
        <pre>&lt;?php FriendLinks_Plugin::output('friendlinks-container', '', 1, false, true); ?&gt;</pre>
        <h4>3. 定时任务</h4>
        <p>Cron URL：</p>
        <pre><?php echo htmlspecialchars($cronUrl); ?></pre>
        <pre>0 2 * * * curl -s "<?php echo htmlspecialchars($cronUrl); ?>" > /dev/null 2>&1</pre>
    </div>
</div>

<!-- 分类编辑模态框 -->
<div id="categoryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="categoryModalTitle"><?php _e('添加分类'); ?></h3>
            <span class="modal-close" onclick="closeCategoryModal()">&times;</span>
        </div>
        <form method="post" id="categoryForm">
            <input type="hidden" name="action" id="catFormAction" value="add_category">
            <input type="hidden" name="id" id="catId">
            <div class="form-group">
                <label><?php _e('分类名称'); ?></label>
                <input type="text" name="name" id="catName" required placeholder="<?php _e('例如：友链、合作伙伴'); ?>">
            </div>
            <div class="form-group">
                <label><?php _e('排序'); ?> <small>(留空自动递增)</small></label>
                <input type="number" name="sort" id="catSort" value="" min="1" placeholder="自动">
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" class="btn" onclick="closeCategoryModal()">取消</button>
                <button type="submit" class="btn btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>

<!-- 链接编辑模态框 -->
<div id="linkModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle"><?php _e('添加链接'); ?></h3>
            <span class="modal-close" onclick="closeModal()">&times;</span>
        </div>
        <form method="post" id="linkForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="linkId">
            <div class="form-group"><label><?php _e('网站标题'); ?></label><input type="text" name="title" id="linkTitle" placeholder="例如：Typecho 官方"></div>
            <div class="form-group"><label><?php _e('网站地址'); ?> *</label><input type="url" name="url" id="linkUrl" required placeholder="https://typecho.org"></div>
            <div class="form-group"><label><?php _e('网站描述'); ?></label><textarea name="description" id="linkDescription" rows="3" placeholder="选填，自动抓取优先"></textarea></div>
            <div class="form-group"><label><?php _e('图标 URL'); ?></label><input type="text" name="icon" id="linkIcon" placeholder="选填，自动抓取优先"></div>
            <div class="form-group"><label><?php _e('分类'); ?></label><select name="category_id" id="linkCategory" style="height:auto;">
                    <option value=""><?php _e('未分类'); ?></option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group"><label><?php _e('状态'); ?></label><select style="height:auto;" name="status" id="linkStatus">
                    <option value="1">显示</option>
                    <option value="0">隐藏</option>
                </select></div>
            <div class="form-group"><label><?php _e('排序'); ?></label><input type="number" name="sort" id="linkSort" value="0" min="0"><small><?php _e('数字越小越靠前'); ?></small></div>
            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" class="btn" onclick="closeModal()">取消</button>
                <button type="submit" class="btn btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>

<script>
    var nextSortValue = <?php echo $nextSort; ?>;
    var nextCatSortValue = <?php echo $nextCatSort; ?>;

    function openCategoryModal() {
        document.getElementById('categoryModalTitle').textContent = '<?php _e('添加分类'); ?>';
        document.getElementById('catFormAction').value = 'add_category';
        document.getElementById('catId').value = '';
        document.getElementById('catName').value = '';
        document.getElementById('catSort').value = nextCatSortValue;
        document.getElementById('categoryModal').style.display = 'block';
    }

    function editCategory(cat) {
        document.getElementById('categoryModalTitle').textContent = '<?php _e('编辑分类'); ?>';
        document.getElementById('catFormAction').value = 'edit_category';
        document.getElementById('catId').value = cat.id;
        document.getElementById('catName').value = cat.name || '';
        document.getElementById('catSort').value = cat.sort || 0;
        document.getElementById('categoryModal').style.display = 'block';
    }

    function closeCategoryModal() {
        document.getElementById('categoryModal').style.display = 'none';
    }

    function openModal(type) {
        document.getElementById('modalTitle').textContent = '<?php _e('添加链接'); ?>';
        document.getElementById('formAction').value = 'add';
        document.getElementById('linkId').value = '';
        document.getElementById('linkTitle').value = '';
        document.getElementById('linkUrl').value = '';
        document.getElementById('linkDescription').value = '';
        document.getElementById('linkIcon').value = '';
        document.getElementById('linkCategory').value = '';
        document.getElementById('linkStatus').value = '1';
        document.getElementById('linkSort').value = nextSortValue;
        document.getElementById('linkModal').style.display = 'block';
    }

    function editLink(link) {
        document.getElementById('modalTitle').textContent = '<?php _e('编辑链接'); ?>';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('linkId').value = link.id;
        document.getElementById('linkTitle').value = link.title || '';
        document.getElementById('linkUrl').value = link.url || '';
        document.getElementById('linkDescription').value = link.description || '';
        document.getElementById('linkIcon').value = link.icon || '';
        document.getElementById('linkCategory').value = link.category_id || '';
        document.getElementById('linkStatus').value = link.status;
        document.getElementById('linkSort').value = link.sort;
        document.getElementById('linkModal').style.display = 'block';
    }

    function closeModal() {
        document.getElementById('linkModal').style.display = 'none';
    }

    window.onclick = function(e) {
        if (e.target == document.getElementById('linkModal')) closeModal();
        if (e.target == document.getElementById('categoryModal')) closeCategoryModal();
    };

    function bindEditButtons() {
        document.querySelectorAll('.actions button[onclick^="editLink"]').forEach(btn => {
            var fn = btn.getAttribute('onclick');
            if (fn) btn.onclick = function() { eval(fn); };
        });
    }

    // AJAX 加载页面并替换表格、分页、缓存，并同步全局排序值
    function loadTableByUrl(url) {
        var select = document.getElementById('sortSelect');
        var refreshBtn = document.getElementById('refreshSortBtn');
        var originalText = refreshBtn ? refreshBtn.textContent : '';
        if (select) select.disabled = true;
        if (refreshBtn) { refreshBtn.disabled = true; refreshBtn.textContent = '⏳'; }

        fetch(url).then(r => r.text()).then(html => {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var newTable = doc.querySelector('.friendlinks-table');
            if (newTable) document.querySelector('.friendlinks-table').outerHTML = newTable.outerHTML;

            var newPagination = doc.querySelector('.friendlinks-pagination');
            var oldPagination = document.querySelector('.friendlinks-pagination');
            if (newPagination && oldPagination) {
                oldPagination.outerHTML = newPagination.outerHTML;
            } else if (newPagination) {
                document.querySelector('.table-container').appendChild(newPagination.cloneNode(true));
            }

            var newCache = doc.querySelector('.cache-info');
            if (newCache) document.querySelector('.cache-info').outerHTML = newCache.outerHTML;

            var newCategory = doc.querySelector('.category-section');
            if (newCategory) document.querySelector('.category-section').outerHTML = newCategory.outerHTML;

            // 从隐藏元素读取全局最大排序值
            var nextSortEl = doc.getElementById('nextSortValue');
            if (nextSortEl) nextSortValue = parseInt(nextSortEl.textContent, 10) || 0;
            var nextCatEl = doc.getElementById('nextCatSortValue');
            if (nextCatEl) nextCatSortValue = parseInt(nextCatEl.textContent, 10) || 0;

            bindEditButtons();
            bindPaginationEvents();
            window.history.replaceState(null, '', url);

            var validSorts = ['sort', 'created_desc', 'created_asc', 'title_asc', 'title_desc', 'random'];
            var sortBy = new URL(url).searchParams.get('sortby');
            if (sortBy && validSorts.indexOf(sortBy) !== -1) {
                document.cookie = 'friendlinks_sort=' + sortBy + '; path=/; max-age=' + (60*60*24*365) + '; SameSite=Lax';
            }
        }).catch(e => console.error('加载失败:', e)).finally(() => {
            if (select) select.disabled = false;
            if (refreshBtn) { refreshBtn.disabled = false; refreshBtn.textContent = originalText; }
        });
    }

    function loadTableBySort(sortBy) {
        var categoryId = document.getElementById('categoryFilterSelect').value;
        var perPage = document.getElementById('perPageSelect') ? document.getElementById('perPageSelect').value : <?php echo $perPage; ?>;
        var url = new URL(window.location.href);
        url.searchParams.set('sortby', sortBy);
        if (categoryId === 'all') {
            url.searchParams.delete('category_id');
        } else {
            url.searchParams.set('category_id', categoryId);
        }
        url.searchParams.set('page', 1);
        url.searchParams.set('per_page', perPage);
        loadTableByUrl(url.toString());
    }

    function bindPaginationEvents() {
        var perPageSelect = document.getElementById('perPageSelect');
        if (perPageSelect) {
            perPageSelect.addEventListener('change', function() {
                var categoryId = document.getElementById('categoryFilterSelect').value;
                var sortBy = document.getElementById('sortSelect').value;
                var url = new URL(window.location.href);
                url.searchParams.set('per_page', this.value);
                url.searchParams.set('page', 1);
                if (categoryId === 'all') {
                    url.searchParams.delete('category_id');
                } else {
                    url.searchParams.set('category_id', categoryId);
                }
                url.searchParams.set('sortby', sortBy);
                loadTableByUrl(url.toString());
            });
        }

        document.querySelectorAll('.friendlinks-pagination a[data-page]').forEach(a => {
            a.addEventListener('click', function(e) {
                e.preventDefault();
                loadTableByUrl(this.href);
            });
        });
    }

    function submitAjax(form, callback) {
        var data = new FormData(form);
        var btn = form.querySelector('button[type="submit"]');
        var originalText = btn ? btn.textContent : '';
        if (btn) { btn.disabled = true; btn.textContent = '处理中...'; }

        fetch(window.location.href, { method: 'POST', body: data })
            .then(r => r.text())
            .then(html => { location.reload(); })
            .catch(e => alert('操作失败：' + e))
            .finally(() => {
                if (btn) { btn.disabled = false; btn.textContent = originalText; }
            });
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('linkForm').addEventListener('submit', function(e) {
            e.preventDefault();
            submitAjax(this, closeModal);
        });
        document.getElementById('categoryForm').addEventListener('submit', function(e) {
            e.preventDefault();
            submitAjax(this, closeCategoryModal);
        });
        document.querySelectorAll('.ajax-form').forEach(f => {
            f.addEventListener('submit', function(e) {
                e.preventDefault();
                var action = this.querySelector('input[name="action"]').value;
                if (action === 'update_all' && !confirm('<?php _e('确定要更新所有链接的信息吗？'); ?>')) return;
                if (action === 'delete_category' && !confirm('<?php _e('确定要删除该分类吗？'); ?>')) return;
                submitAjax(this);
            });
        });
        bindEditButtons();

        var sortSelect = document.getElementById('sortSelect');
        sortSelect.addEventListener('change', function() { loadTableBySort(this.value); });

        var categoryFilter = document.getElementById('categoryFilterSelect');
        categoryFilter.addEventListener('change', function() { loadTableBySort(sortSelect.value); });

        var refreshBtn = document.getElementById('refreshSortBtn');
        if (refreshBtn) refreshBtn.addEventListener('click', function() { loadTableBySort(sortSelect.value); });

        bindPaginationEvents();
    });
</script>

<?php
include 'copyright.php';
include 'common-js.php';
include 'footer.php';
?>
