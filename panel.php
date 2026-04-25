<?php
/**
 * 友情链接管理面板
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Utils\Helper;

$user = \Typecho\Widget::widget('Widget_User');
if (!$user->pass('administrator', true)) die(_t('权限不足'));

// 处理 POST 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        FriendLinks_Plugin::addLink([
            'title' => $_POST['title'] ?? '',
            'url' => $_POST['url'] ?? '',
            'description' => $_POST['description'] ?? '',
            'icon' => $_POST['icon'] ?? '',
            'status' => intval($_POST['status'] ?? 1),
            'sort' => intval($_POST['sort'] ?? 0)
        ]);
        FriendLinks_Plugin::refreshCache();
        $message = _t('添加成功');
    } elseif ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        FriendLinks_Plugin::updateLink($id, [
            'title' => $_POST['title'] ?? '',
            'url' => $_POST['url'] ?? '',
            'description' => $_POST['description'] ?? '',
            'icon' => $_POST['icon'] ?? '',
            'status' => intval($_POST['status'] ?? 1),
            'sort' => intval($_POST['sort'] ?? 0)
        ]);
        FriendLinks_Plugin::refreshCache();
        $message = _t('更新成功');
    } elseif ($action === 'delete') {
        FriendLinks_Plugin::deleteLink(intval($_POST['id'] ?? 0));
        FriendLinks_Plugin::refreshCache();
        $message = _t('删除成功');
    } elseif ($action === 'update_info') {
        // updateLinkInfo 内部已调用 refreshCache
        FriendLinks_Plugin::updateLinkInfo(intval($_POST['id'] ?? 0));
        $message = _t('信息更新成功');
    } elseif ($action === 'update_all') {
        // updateAllLinksInfo 内部已调用 refreshCache
        $updated = FriendLinks_Plugin::updateAllLinksInfo();
        $message = sprintf(_t('成功更新 %d 个链接的信息'), $updated);
    } elseif ($action === 'clear_cache') {
        FriendLinks_Plugin::refreshCache();
        $message = _t('缓存已刷新');
    } elseif ($action === 'compact_sorts') {
        FriendLinks_Plugin::compactSorts();
        FriendLinks_Plugin::refreshCache();
        $message = _t('序号已重新排列');
    } elseif ($action === 'delete_dead') {
        $deleted = FriendLinks_Plugin::deleteDeadLinks();
        // deleteDeadLinks 内部已调用 refreshCache
        $message = sprintf(_t('成功删除 %d 个异常链接'), $deleted);
    }
}

// 排序与数据加载
$allowedSort = ['sort', 'created_desc', 'created_asc', 'title_asc', 'title_desc', 'random'];
$cookieSort = $_COOKIE['friendlinks_sort'] ?? '';
$currentSort = $_GET['sortby'] ?? (in_array($cookieSort, $allowedSort) ? $cookieSort : 'sort');
$links = FriendLinks_Plugin::getAllLinks(true, $currentSort);
$cacheInfo = FriendLinks_Plugin::getCacheInfo();

$options = Helper::options();
$siteUrl = $options->siteUrl;
$pluginOptions = $options->plugin('FriendLinks');
$secretKey = $pluginOptions->secretKey ?? '';
$cronUrl = rtrim($siteUrl, '/') . '/friendlinks/cron' . ($secretKey ? '?key=' . $secretKey : '');

// 计算下一个可用的排序值
$maxSort = 0;
foreach ($links as $link) if ($link['sort'] > $maxSort) $maxSort = $link['sort'];
$nextSort = $maxSort + 1;

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
    .cache-info { background: #f9f9f9; padding: 15px; border-radius: 6px; margin: 20px 0; }
    .cache-info p { margin: 5px 0; }
    .toolbar { margin: 20px 0; display: flex; gap: 10px; flex-wrap: wrap; }
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

    <div class="toolbar">
        <button class="btn btn-primary" onclick="openModal('add')">➕ <?php _e('添加链接'); ?></button>
        <form method="post" class="ajax-form">
            <input type="hidden" name="action" value="update_all">
            <button type="submit" class="btn btn-warning">🔄 <?php _e('更新所有信息'); ?></button>
        </form>
        <form method="post" class="ajax-form">
            <input type="hidden" name="action" value="clear_cache">
            <button type="submit" class="btn">🗑️ <?php _e('刷新缓存'); ?></button>
        </form>
        <form method="post" class="ajax-form">
            <input type="hidden" name="action" value="compact_sorts">
            <button type="submit" class="btn">🔢 <?php _e('重整序号'); ?></button>
        </form>
        <form method="post" class="ajax-form" onsubmit="return confirm('<?php _e('确定要删除所有存活状态异常的链接吗？此操作不可恢复。'); ?>')">
            <input type="hidden" name="action" value="delete_dead">
            <button type="submit" class="btn btn-danger">🗑️ <?php _e('删除异常链接'); ?></button>
        </form>
        <div style="margin-left: auto; display: flex; align-items: center; gap: 8px;">
            <label for="sortSelect" style="font-weight: normal;">排序：</label>
            <select id="sortSelect" style="padding: 0px 10px; border-radius: 4px; border: 1px solid #ddd;">
                <option value="sort" <?php echo $currentSort == 'sort' ? 'selected' : ''; ?>>手动排序</option>
                <option value="created_desc" <?php echo $currentSort == 'created_desc' ? 'selected' : ''; ?>>添加时间 ↓</option>
                <option value="created_asc" <?php echo $currentSort == 'created_asc' ? 'selected' : ''; ?>>添加时间 ↑</option>
                <option value="title_asc" <?php echo $currentSort == 'title_asc' ? 'selected' : ''; ?>>标题 A-Z</option>
                <option value="title_desc" <?php echo $currentSort == 'title_desc' ? 'selected' : ''; ?>>标题 Z-A</option>
                <option value="random" <?php echo $currentSort == 'random' ? 'selected' : ''; ?>>随机</option>
            </select>
            <button type="button" id="refreshSortBtn" class="btn btn-sm" title="仅随机选项可刷新当前排序">🔄</button>
        </div>
    </div>

    <div class="table-container">
    <table class="friendlinks-table">
        <thead>
            <tr>
                <th>ID</th>
                <th width="40px"><?php _e('存活'); ?></th>
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
                    <td><?php echo htmlspecialchars($link['title']); ?></td>
                    <td><?php echo htmlspecialchars(mb_substr($link['description'] ?? '', 0, 30)) . (mb_strlen($link['description'] ?? '') > 30 ? '...' : ''); ?></td>
                    <td><a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank"><?php echo htmlspecialchars(substr($link['url'], 0, 30)); ?></a></td>
                    <td><?php if ($link['icon']): ?><img src="<?php echo htmlspecialchars($link['icon']); ?>" style="width:24px;height:24px;"><?php else: ?><span style="color:#999;">无</span><?php endif; ?></td>
                    <td><span class="status-badge <?php echo $link['status'] ? 'status-show' : 'status-hide'; ?>"><?php echo $link['status'] ? _t('显示') : _t('隐藏'); ?></span></td>
                    <td><?php echo $link['sort']; ?></td>
                    <td><?php echo $link['last_update'] ? date('Y-m-d H:i', $link['last_update']) : _t('未更新'); ?></td>
                    <td class="actions">
                        <button class="btn btn-sm" onclick='editLink(<?php echo json_encode($link, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><?php _e('编辑'); ?></button>
                        <form method="post" class="ajax-form" style="display:inline;">
                            <input type="hidden" name="action" value="update_info">
                            <input type="hidden" name="id" value="<?php echo $link['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-warning"><?php _e('更新信息'); ?></button>
                        </form>
                        <form method="post" class="ajax-form" style="display:inline;" onsubmit="return confirm('<?php _e('确定要删除吗？'); ?>')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $link['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger"><?php _e('删除'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($links)): ?><tr><td colspan="9" style="text-align:center;"><?php _e('暂无友情链接'); ?></td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>

    <div class="cron-info">
        <h3>📌 使用说明</h3>
        <p>可选参数说明：</p>
        <p><span style="color: red;">container_class</span>：自定义容器类名 </p>
        <p>与全局卡片模板的关系：全局模板中的 <span style="color: red;">.friendlink-card</span> 是基类，<span style="color: red;">card_class</span> 会追加到 class 属性中，即最终输出为 <span style="color: red;">class="friendlink-card my-card"</span></p>
        <h4>1. 在文章/页面中插入友情链接</h4>
        <p>短代码示例：</p>
        <pre>[friendlinks]</pre>
        <p>带参数：</p>
        <pre>[friendlinks container_class="my-links" card_class="my-card"]</pre>
        <h4>2. 在模板中调用</h4>
        <p>在主题模板文件中使用以下代码：</p>
        <pre>&lt;?php FriendLinks_Plugin::output(); ?&gt;</pre>
        <p>或带参数：</p>
        <pre>&lt;?php FriendLinks_Plugin::output('my-container', 'my-card'); ?&gt;</pre>
        <h4>3. 定时任务</h4>
        <p>Cron URL：</p>
        <pre><?php echo htmlspecialchars($cronUrl); ?></pre>
        <p>Cron 示例（每天凌晨2点）：</p>
        <pre>0 2 * * * curl -s "<?php echo htmlspecialchars($cronUrl); ?>" > /dev/null 2>&1</pre>
    </div>
</div>

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

    function openModal(type) {
        document.getElementById('modalTitle').textContent = '添加链接';
        document.getElementById('formAction').value = 'add';
        document.getElementById('linkId').value = '';
        document.getElementById('linkTitle').value = '';
        document.getElementById('linkUrl').value = '';
        document.getElementById('linkDescription').value = '';
        document.getElementById('linkIcon').value = '';
        document.getElementById('linkStatus').value = '1';
        document.getElementById('linkSort').value = nextSortValue;
        document.getElementById('linkModal').style.display = 'block';
    }

    function editLink(link) {
        document.getElementById('modalTitle').textContent = '编辑链接';
        document.getElementById('formAction').value = 'edit';
        document.getElementById('linkId').value = link.id;
        document.getElementById('linkTitle').value = link.title || '';
        document.getElementById('linkUrl').value = link.url || '';
        document.getElementById('linkDescription').value = link.description || '';
        document.getElementById('linkIcon').value = link.icon || '';
        document.getElementById('linkStatus').value = link.status;
        document.getElementById('linkSort').value = link.sort;
        document.getElementById('linkModal').style.display = 'block';
    }

    function closeModal() {
        document.getElementById('linkModal').style.display = 'none';
    }
    window.onclick = function(e) { if (e.target == document.getElementById('linkModal')) closeModal(); };

    function bindEditButtons() {
        document.querySelectorAll('.actions button[onclick^="editLink"]').forEach(btn => {
            var fn = btn.getAttribute('onclick');
            if (fn) btn.onclick = function() { eval(fn); };
        });
    }

    function loadTableBySort(sortBy) {
        var select = document.getElementById('sortSelect');
        var refreshBtn = document.getElementById('refreshSortBtn');
        var originalText = refreshBtn ? refreshBtn.textContent : '';
        select.disabled = true;
        if (refreshBtn) { refreshBtn.disabled = true; refreshBtn.textContent = '⏳'; }
        var url = new URL(window.location.href);
        url.searchParams.set('sortby', sortBy);
        fetch(url.toString()).then(r => r.text()).then(html => {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var newTable = doc.querySelector('.friendlinks-table');
            if (newTable) document.querySelector('.friendlinks-table').outerHTML = newTable.outerHTML;
            var newCache = doc.querySelector('.cache-info');
            if (newCache) document.querySelector('.cache-info').outerHTML = newCache.outerHTML;
            var maxSort = 0;
            document.querySelectorAll('.friendlinks-table tbody tr').forEach(row => {
                var sortCell = row.cells[6];
                if (sortCell) { var sortVal = parseInt(sortCell.textContent.trim(), 10); if (!isNaN(sortVal) && sortVal > maxSort) maxSort = sortVal; }
            });
            nextSortValue = maxSort + 1;
            bindEditButtons();
            window.history.replaceState(null, '', url.toString());
            var validSorts = ['sort', 'created_desc', 'created_asc', 'title_asc', 'title_desc', 'random'];
            if (validSorts.indexOf(sortBy) !== -1) {
                document.cookie = 'friendlinks_sort=' + sortBy + '; path=/; max-age=' + (60*60*24*365) + '; SameSite=Lax';
            }
        }).catch(e => console.error('加载失败:', e)).finally(() => {
            select.disabled = false;
            if (refreshBtn) { refreshBtn.disabled = false; refreshBtn.textContent = originalText; }
        });
    }

    function submitAjax(form, callback) {
        var data = new FormData(form);
        var btn = form.querySelector('button[type="submit"]');
        var originalText = btn ? btn.textContent : '';
        if (btn) { btn.disabled = true; btn.textContent = '处理中...'; }
        fetch(window.location.href, { method: 'POST', body: data }).then(r => r.text()).then(html => {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var newMsg = doc.querySelector('.message.notice');
            var msgBox = document.querySelector('.message.notice');
            if (newMsg) {
                if (msgBox) msgBox.outerHTML = newMsg.outerHTML;
                else document.querySelector('.friendlinks-panel').insertAdjacentHTML('afterbegin', newMsg.outerHTML);
                var msg = document.querySelector('.message.notice');
                if (msg) setTimeout(() => { msg.style.opacity = '0'; setTimeout(() => msg.remove(), 500); }, 3000);
            }
            var newTable = doc.querySelector('.friendlinks-table');
            if (newTable) document.querySelector('.friendlinks-table').outerHTML = newTable.outerHTML;
            var newCache = doc.querySelector('.cache-info');
            if (newCache) document.querySelector('.cache-info').outerHTML = newCache.outerHTML;
            var maxSort = 0;
            document.querySelectorAll('.friendlinks-table tbody tr').forEach(row => {
                var sortCell = row.cells[6];
                if (sortCell) { var sortVal = parseInt(sortCell.textContent.trim(), 10); if (!isNaN(sortVal) && sortVal > maxSort) maxSort = sortVal; }
            });
            nextSortValue = maxSort + 1;
            bindEditButtons();
            if (callback) callback();
        }).catch(e => alert('操作失败：' + e)).finally(() => {
            if (btn) { btn.disabled = false; btn.textContent = originalText; }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('linkForm').addEventListener('submit', function(e) { e.preventDefault(); submitAjax(this, closeModal); });
        document.querySelectorAll('.ajax-form').forEach(f => {
            f.addEventListener('submit', function(e) {
                e.preventDefault();
                if (this.querySelector('input[name="action"]').value === 'update_all' && !confirm('确定要更新所有链接的信息吗？')) return;
                submitAjax(this);
            });
        });
        bindEditButtons();
        var sortSelect = document.getElementById('sortSelect');
        sortSelect.addEventListener('change', function() { loadTableBySort(this.value); });
        var refreshBtn = document.getElementById('refreshSortBtn');
        if (refreshBtn) refreshBtn.addEventListener('click', function() { loadTableBySort(sortSelect.value); });
    });
</script>

<?php include 'copyright.php';
include 'common-js.php';
include 'footer.php'; ?>