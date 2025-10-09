<?php
// Multi-level promotion (cascade publications with reporting)

require_once __DIR__ . '/promotion_helpers.php';
require_once __DIR__ . '/promotion/settings.php';
require_once __DIR__ . '/promotion/utils.php';
require_once __DIR__ . '/promotion/crowd.php';

if (!function_exists('pp_promotion_process_run')) {
    function pp_promotion_process_run(mysqli $conn, array $run): void {
        $runId = (int)$run['id'];
        $projectId = (int)$run['project_id'];
        $stage = (string)$run['stage'];
        $status = (string)$run['status'];
        if ($status === 'cancelled' || $status === 'failed' || $status === 'completed') { return; }
        $project = pp_promotion_fetch_project($conn, $projectId);
        if (!$project) {
            @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='PROJECT_MISSING', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
            return;
        }
        $requirements = pp_promotion_get_level_requirements();
        // Load link row
        $linkRow = null;
        $stmt = $conn->prepare('SELECT * FROM project_links WHERE id = ? LIMIT 1');
        if ($stmt) {
            $linkId = (int)$run['link_id'];
            $stmt->bind_param('i', $linkId);
            if ($stmt->execute()) { $linkRow = $stmt->get_result()->fetch_assoc(); }
            $stmt->close();
        }
        if (!$linkRow) {
            @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LINK_MISSING', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
            return;
        }
        $runLanguage = pp_promotion_resolve_language($linkRow, $project);
        if ($stage === 'pending_level1') {
            $req = $requirements[1];
            $count = (int)$req['count'];
            $usage = [];
            $nets = pp_promotion_pick_networks(1, $count, $project, $usage);
            if (empty($nets)) {
                pp_promotion_log('promotion.level1.networks_missing', [
                    'run_id' => $runId,
                    'project_id' => $projectId,
                    'target_url' => $run['target_url'],
                    'requested' => $count,
                    'region' => $project['region'] ?? null,
                    'topic' => $project['topic'] ?? null,
                ]);
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='NO_NETWORKS_L1', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            $selectedSlugs = array_map(static function(array $net) { return (string)($net['slug'] ?? ''); }, $nets);
            $usageSnapshot = [];
            foreach ($selectedSlugs as $slug) {
                if ($slug === '') { continue; }
                $usageSnapshot[$slug] = (int)($usage[$slug] ?? 0);
            }
            pp_promotion_log('promotion.level1.networks_selected', [
                'run_id' => $runId,
                'project_id' => $projectId,
                'target_url' => $run['target_url'],
                'requested' => $count,
                'selected' => $selectedSlugs,
                'usage' => $usageSnapshot,
                'region' => $project['region'] ?? null,
                'topic' => $project['topic'] ?? null,
            ]);
            $created = 0;
            foreach ($nets as $net) {
                $stmt = $conn->prepare('INSERT INTO promotion_nodes (run_id, level, target_url, network_slug, anchor_text, status, initiated_by) VALUES (?, 1, ?, ?, ?, \'pending\', ?)');
                if ($stmt) {
                    $anchor = (string)($linkRow['anchor'] ?? '');
                    if ($anchor === '') { $anchor = $project['name'] ?? __('Материал'); }
                    $initiated = (int)$run['initiated_by'];
                    $stmt->bind_param('isssi', $runId, $run['target_url'], $net['slug'], $anchor, $initiated);
                    if ($stmt->execute()) { $created++; }
                    $stmt->close();
                }
            }
            @$conn->query("UPDATE promotion_runs SET stage='level1_active', status='level1_active', started_at=COALESCE(started_at, CURRENT_TIMESTAMP), updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
            $res = @$conn->query('SELECT * FROM promotion_nodes WHERE run_id = ' . $runId . ' AND level = 1 AND status = \'pending\'');
            if ($res) {
                while ($node = $res->fetch_assoc()) {
                    $node['parent_url'] = $run['target_url'];
                    $node['initiated_by'] = $run['initiated_by'];
                    $node['level'] = 1;
                    $node['target_url'] = $run['target_url'];
                    pp_promotion_enqueue_publication($conn, $node, $project, $linkRow, [
                        'min_len' => $requirements[1]['min_len'],
                        'max_len' => $requirements[1]['max_len'],
                        'level' => 1,
                        'prepared_language' => $runLanguage,
                    ]);
                }
                $res->free();
            }
            pp_promotion_update_progress($conn, $runId);
            return;
        }
        if ($stage === 'level1_active') {
            $res = @$conn->query('SELECT status, COUNT(*) AS c FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=1 GROUP BY status');
            $pending = 0; $success = 0; $failed = 0;
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $statusNode = (string)$row['status'];
                    $cnt = (int)$row['c'];
                    if (in_array($statusNode, ['pending','queued','running'], true)) { $pending += $cnt; }
                    elseif (in_array($statusNode, ['success','completed'], true)) { $success += $cnt; }
                    elseif (in_array($statusNode, ['failed','cancelled'], true)) { $failed += $cnt; }
                }
                $res->free();
            }
            if ($pending > 0) { return; }
            $requiredLevel1 = max(1, (int)($requirements[1]['count'] ?? 1));
            if ($success < $requiredLevel1) {
                $needed = $requiredLevel1 - $success;
                $usage = [];
                if ($usageRes = @$conn->query('SELECT network_slug, COUNT(*) AS c FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=1 GROUP BY network_slug')) {
                    while ($u = $usageRes->fetch_assoc()) {
                        $slug = (string)($u['network_slug'] ?? '');
                        if ($slug === '') { continue; }
                        $usage[$slug] = (int)($u['c'] ?? 0);
                    }
                    $usageRes->free();
                }
                $netsRetry = pp_promotion_pick_networks(1, $needed, $project, $usage);
                if (empty($netsRetry)) {
                    pp_promotion_log('promotion.level1.retry_exhausted', [
                        'run_id' => $runId,
                        'project_id' => $projectId,
                        'target_url' => $run['target_url'],
                        'needed' => $needed,
                        'success' => $success,
                        'failed' => $failed,
                    ]);
                    @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL1_INSUFFICIENT_SUCCESS', finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                    return;
                }
                $retrySlugs = array_map(static function(array $net) { return (string)($net['slug'] ?? ''); }, $netsRetry);
                pp_promotion_log('promotion.level1.retry_scheduled', [
                    'run_id' => $runId,
                    'project_id' => $projectId,
                    'target_url' => $run['target_url'],
                    'needed' => $needed,
                    'selected' => $retrySlugs,
                ]);
                $newNodeIds = [];
                foreach ($netsRetry as $net) {
                    $stmt = $conn->prepare('INSERT INTO promotion_nodes (run_id, level, target_url, network_slug, anchor_text, status, initiated_by) VALUES (?, 1, ?, ?, ?, \'pending\', ?)');
                    if ($stmt) {
                        $anchor = (string)($linkRow['anchor'] ?? '');
                        if ($anchor === '') { $anchor = $project['name'] ?? __('Материал'); }
                        $initiated = (int)$run['initiated_by'];
                        $stmt->bind_param('isssi', $runId, $run['target_url'], $net['slug'], $anchor, $initiated);
                        if ($stmt->execute()) {
                            $newNodeIds[] = (int)$conn->insert_id;
                        }
                        $stmt->close();
                    }
                }
                if (!empty($newNodeIds)) {
                    $idsList = implode(',', array_map('intval', $newNodeIds));
                    if ($idsList !== '') {
                        $sql = 'SELECT * FROM promotion_nodes WHERE id IN (' . $idsList . ')';
                        if ($resNew = @$conn->query($sql)) {
                            while ($node = $resNew->fetch_assoc()) {
                                $node['parent_url'] = $run['target_url'];
                                $node['initiated_by'] = $run['initiated_by'];
                                $node['level'] = 1;
                                $node['target_url'] = $run['target_url'];
                                pp_promotion_enqueue_publication($conn, $node, $project, $linkRow, [
                                    'min_len' => $requirements[1]['min_len'],
                                    'max_len' => $requirements[1]['max_len'],
                                    'level' => 1,
                                    'prepared_language' => $runLanguage,
                                ]);
                            }
                            $resNew->free();
                        }
                    }
                    pp_promotion_update_progress($conn, $runId);
                    return;
                }
                // fallback if no nodes were created
                pp_promotion_log('promotion.level1.retry_insert_failed', [
                    'run_id' => $runId,
                    'project_id' => $projectId,
                    'needed' => $needed,
                ]);
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL1_INSERT_FAILED', finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            if ($success === 0) {
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL1_FAILED', finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            if (!pp_promotion_is_level_enabled(2)) {
                @$conn->query("UPDATE promotion_runs SET stage='pending_crowd', status='pending_crowd', updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            @$conn->query("UPDATE promotion_runs SET stage='pending_level2', status='pending_level2', updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
            return;
        }
        if ($stage === 'pending_level2') {
            $perParent = (int)$requirements[2]['per_parent'];
            $nodesL1 = [];
            $res = @$conn->query('SELECT id, result_url, anchor_text FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=1 AND status IN (\'success\',\'completed\')');
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $url = trim((string)$row['result_url']);
                    if ($url !== '') { $nodesL1[] = $row; }
                }
                $res->free();
            }
            if (empty($nodesL1)) {
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL1_NO_URL', finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            $usage = [];
            $level1Contexts = [];
            $cachedArticlesL1 = [];
            foreach ($nodesL1 as $parentNode) {
                $ctx = pp_promotion_get_article_context((string)$parentNode['result_url']);
                if ($ctx) {
                    $level1Contexts[(int)$parentNode['id']] = $ctx;
                }
                $cached = pp_promotion_load_cached_article((int)$parentNode['id']);
                if (is_array($cached) && !empty($cached['htmlContent'])) {
                    $cachedArticlesL1[(int)$parentNode['id']] = $cached;
                }
            }
            $created = 0;
            foreach ($nodesL1 as $parentNode) {
                $nets = pp_promotion_pick_networks(2, $perParent, $project, $usage);
                if (empty($nets)) { continue; }
                $selectedSlugsL2 = array_map(static function(array $net) { return (string)($net['slug'] ?? ''); }, $nets);
                $usageSnapshotL2 = [];
                foreach ($selectedSlugsL2 as $slug) {
                    if ($slug === '') { continue; }
                    $usageSnapshotL2[$slug] = (int)($usage[$slug] ?? 0);
                }
                pp_promotion_log('promotion.level2.networks_selected', [
                    'run_id' => $runId,
                    'project_id' => $projectId,
                    'parent_node_id' => (int)$parentNode['id'],
                    'target_url' => $parentNode['result_url'],
                    'requested' => $perParent,
                    'selected' => $selectedSlugsL2,
                    'usage' => $usageSnapshotL2,
                    'region' => $project['region'] ?? null,
                    'topic' => $project['topic'] ?? null,
                ]);
                $parentContext = $level1Contexts[(int)$parentNode['id']] ?? null;
                foreach ($nets as $net) {
                    $stmt = $conn->prepare('INSERT INTO promotion_nodes (run_id, level, parent_id, target_url, network_slug, anchor_text, status, initiated_by) VALUES (?, 2, ?, ?, ?, ?, \'pending\', ?)');
                    if ($stmt) {
                        $anchor = pp_promotion_generate_contextual_anchor($parentContext, (string)$linkRow['anchor']);
                        $initiated = (int)$run['initiated_by'];
                        $stmt->bind_param('iisssi', $runId, $parentNode['id'], $parentNode['result_url'], $net['slug'], $anchor, $initiated);
                        if ($stmt->execute()) { $created++; }
                        $stmt->close();
                    }
                }
            }
            @$conn->query("UPDATE promotion_runs SET stage='level2_active', status='level2_active', updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
            $res2 = @$conn->query('SELECT n.*, p.result_url AS parent_url, p.target_url AS parent_target_url, p.level AS parent_level FROM promotion_nodes n LEFT JOIN promotion_nodes p ON p.id = n.parent_id WHERE n.run_id=' . $runId . ' AND n.level=2 AND n.status=\'pending\'');
            if ($res2) {
                while ($node = $res2->fetch_assoc()) {
                    $node['initiated_by'] = $run['initiated_by'];
                    $nodeId = isset($node['id']) ? (int)$node['id'] : 0;
                    $parentNodeId = (int)($node['parent_id'] ?? 0);
                    $parentCtx = $level1Contexts[$parentNodeId] ?? null;
                    $trail = [];
                    if ($parentCtx) { $trail[] = $parentCtx; }
                    $preparedArticle = null;
                    $articleMeta = [];
                    $preparedLanguage = pp_promotion_normalize_language_code($runLanguage, 'ru');
                    $cachedParent = $cachedArticlesL1[$parentNodeId] ?? null;
                    $canReuseParent = false;
                    if (is_array($cachedParent) && !empty($cachedParent['htmlContent'])) {
                        $cachedLanguage = pp_promotion_normalize_language_code($cachedParent['language'] ?? null, '');
                        if ($cachedLanguage === '' || $cachedLanguage === $preparedLanguage) {
                            $canReuseParent = true;
                        }
                    }
                    if ($canReuseParent) {
                        $fallbackAnchor = (string)($node['anchor_text'] ?? '');
                        $childAnchor = pp_promotion_generate_child_anchor($cachedParent, $preparedLanguage, $fallbackAnchor);
                        if ($childAnchor !== '') {
                            if ($childAnchor !== $fallbackAnchor && $nodeId > 0) {
                                $updateAnchor = $conn->prepare('UPDATE promotion_nodes SET anchor_text=? WHERE id=? LIMIT 1');
                                if ($updateAnchor) {
                                    $updateAnchor->bind_param('si', $childAnchor, $nodeId);
                                    $updateAnchor->execute();
                                    $updateAnchor->close();
                                }
                            }
                            $node['anchor_text'] = $childAnchor;
                        }
                        $parentTargetUrl = (string)($node['parent_target_url'] ?? '');
                        if ($parentTargetUrl === '') { $parentTargetUrl = (string)$run['target_url']; }
                        $preparedArticle = pp_promotion_prepare_child_article(
                            $cachedParent,
                            (string)$node['target_url'],
                            $parentTargetUrl,
                            $preparedLanguage,
                            (string)$node['anchor_text']
                        );
                        if (is_array($preparedArticle) && !empty($preparedArticle['htmlContent'])) {
                            if (empty($preparedArticle['language'])) { $preparedArticle['language'] = $preparedLanguage; }
                            if (empty($preparedArticle['plainText'])) {
                                $plain = trim(strip_tags((string)$preparedArticle['htmlContent']));
                                if ($plain !== '') { $preparedArticle['plainText'] = $plain; }
                            }
                            $preparedArticle['sourceUrl'] = $parentTargetUrl;
                            $preparedArticle['sourceNodeId'] = $parentNodeId;
                            $articleMeta = [
                                'source_node_id' => $parentNodeId,
                                'source_target_url' => $parentTargetUrl,
                                'source_level' => (int)($node['parent_level'] ?? 1),
                                'parent_result_url' => (string)($node['parent_url'] ?? ''),
                                'reuse_mode' => 'cached_parent',
                            ];
                            pp_promotion_log('promotion.level2.article_reuse', [
                                'run_id' => $runId,
                                'node_id' => $nodeId,
                                'parent_node_id' => $parentNodeId,
                                'prepared_language' => $preparedLanguage,
                                'target_url' => (string)$node['target_url'],
                                'parent_target_url' => $parentTargetUrl,
                            ]);
                        } else {
                            $preparedArticle = null;
                            $articleMeta = [];
                        }
                    }
                    $requirementsPayload = [
                        'min_len' => $requirements[2]['min_len'],
                        'max_len' => $requirements[2]['max_len'],
                        'level' => 2,
                        'parent_url' => $node['parent_url'],
                        'parent_context' => $parentCtx,
                        'ancestor_trail' => $trail,
                    ];
                    $requirementsPayload['prepared_language'] = $preparedLanguage;
                    if ($preparedArticle) {
                        $requirementsPayload['prepared_article'] = $preparedArticle;
                        if (!empty($articleMeta)) {
                            $requirementsPayload['article_meta'] = $articleMeta;
                        }
                    }
                    pp_promotion_enqueue_publication($conn, $node, $project, $linkRow, $requirementsPayload);
                }
                $res2->free();
            }
            pp_promotion_update_progress($conn, $runId);
            return;
        }
        if ($stage === 'level2_active') {
            $res = @$conn->query('SELECT status, COUNT(*) AS c FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=2 GROUP BY status');
            $pending = 0; $success = 0; $failed = 0;
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $statusNode = (string)$row['status'];
                    $cnt = (int)$row['c'];
                    if (in_array($statusNode, ['pending','queued','running'], true)) { $pending += $cnt; }
                    elseif (in_array($statusNode, ['success','completed'], true)) { $success += $cnt; }
                    elseif (in_array($statusNode, ['failed','cancelled'], true)) { $failed += $cnt; }
                }
                $res->free();
            }
            if ($pending > 0) { return; }

            $perParentRequired = max(1, (int)($requirements[2]['per_parent'] ?? 1));
            $usageLevel2 = [];
            if ($usageRes = @$conn->query('SELECT network_slug, COUNT(*) AS c FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=2 GROUP BY network_slug')) {
                while ($u = $usageRes->fetch_assoc()) {
                    $slugUsage = (string)($u['network_slug'] ?? '');
                    if ($slugUsage === '') { continue; }
                    $usageLevel2[$slugUsage] = (int)($u['c'] ?? 0);
                }
                $usageRes->free();
            }

            $parentStats = [];
            $parentInfo = [];
            if ($detailRes = @$conn->query('SELECT n.id, n.parent_id, n.status, n.network_slug, n.target_url, p.result_url AS parent_result_url, p.target_url AS parent_target_url, p.level AS parent_level FROM promotion_nodes n LEFT JOIN promotion_nodes p ON p.id = n.parent_id WHERE n.run_id=' . $runId . ' AND n.level=2')) {
                while ($row = $detailRes->fetch_assoc()) {
                    $parentId = isset($row['parent_id']) ? (int)$row['parent_id'] : 0;
                    if ($parentId <= 0) { continue; }
                    if (!isset($parentStats[$parentId])) {
                        $parentStats[$parentId] = ['success' => 0];
                    }
                    $statusNode = (string)($row['status'] ?? '');
                    if (in_array($statusNode, ['success','completed'], true)) {
                        $parentStats[$parentId]['success']++;
                    }
                    $slugUsage = (string)($row['network_slug'] ?? '');
                    if ($slugUsage !== '' && !isset($usageLevel2[$slugUsage])) {
                        $usageLevel2[$slugUsage] = 1;
                    }
                    $parentInfo[$parentId] = [
                        'result_url' => (string)($row['parent_result_url'] ?? ''),
                        'target_url' => (string)($row['parent_target_url'] ?? ''),
                        'level' => isset($row['parent_level']) ? (int)$row['parent_level'] : 1,
                    ];
                }
                $detailRes->free();
            }

            $parentsNeeding = [];
            foreach ($parentStats as $parentId => $stats) {
                $completed = (int)($stats['success'] ?? 0);
                if ($completed < $perParentRequired) {
                    $parentsNeeding[$parentId] = $perParentRequired - $completed;
                }
            }

            if (!empty($parentsNeeding)) {
                $parentContexts = [];
                $parentCachedArticles = [];
                $createdAny = false;
                foreach ($parentsNeeding as $parentId => $deficit) {
                    $parentResultUrl = (string)($parentInfo[$parentId]['result_url'] ?? '');
                    if ($parentResultUrl === '') { continue; }
                    if (!array_key_exists($parentId, $parentContexts)) {
                        $parentContexts[$parentId] = pp_promotion_get_article_context($parentResultUrl);
                    }
                    if (!array_key_exists($parentId, $parentCachedArticles)) {
                        $parentCachedArticles[$parentId] = pp_promotion_load_cached_article($parentId);
                    }
                    $parentContext = $parentContexts[$parentId] ?? null;
                    $cachedParent = $parentCachedArticles[$parentId] ?? null;
                    $anchorBase = pp_promotion_generate_contextual_anchor($parentContext, (string)$linkRow['anchor']);
                    $parentTargetUrl = (string)($parentInfo[$parentId]['target_url'] ?? '');
                    if ($parentTargetUrl === '') { $parentTargetUrl = (string)$run['target_url']; }
                    $preparedLanguage = pp_promotion_normalize_language_code($runLanguage, 'ru');
                    $preparedArticle = null;
                    $articleMeta = [];
                    $anchorFinal = $anchorBase;
                    if (is_array($cachedParent) && !empty($cachedParent['htmlContent'])) {
                        $cachedLanguage = pp_promotion_normalize_language_code($cachedParent['language'] ?? null, '');
                        if ($cachedLanguage === '' || $cachedLanguage === $preparedLanguage) {
                            $childAnchor = pp_promotion_generate_child_anchor($cachedParent, $preparedLanguage, $anchorBase);
                            if ($childAnchor !== '') { $anchorFinal = $childAnchor; }
                            $preparedArticleCandidate = pp_promotion_prepare_child_article(
                                $cachedParent,
                                $parentResultUrl,
                                $parentTargetUrl,
                                $preparedLanguage,
                                $anchorFinal
                            );
                            if (is_array($preparedArticleCandidate) && !empty($preparedArticleCandidate['htmlContent'])) {
                                if (empty($preparedArticleCandidate['language'])) { $preparedArticleCandidate['language'] = $preparedLanguage; }
                                if (empty($preparedArticleCandidate['plainText'])) {
                                    $plainCandidate = trim(strip_tags((string)$preparedArticleCandidate['htmlContent']));
                                    if ($plainCandidate !== '') { $preparedArticleCandidate['plainText'] = $plainCandidate; }
                                }
                                $preparedArticleCandidate['sourceUrl'] = $parentTargetUrl;
                                $preparedArticleCandidate['sourceNodeId'] = $parentId;
                                $preparedArticle = $preparedArticleCandidate;
                                $articleMeta = [
                                    'source_node_id' => $parentId,
                                    'source_target_url' => $parentTargetUrl,
                                    'source_level' => (int)($parentInfo[$parentId]['level'] ?? 1),
                                    'parent_result_url' => $parentResultUrl,
                                    'reuse_mode' => 'cached_parent',
                                ];
                            }
                        }
                    }

                    $netsRetry = pp_promotion_pick_networks(2, $deficit, $project, $usageLevel2);
                    if (empty($netsRetry)) { continue; }
                    $retrySlugs = array_map(static function(array $net) { return (string)($net['slug'] ?? ''); }, $netsRetry);
                    pp_promotion_log('promotion.level2.retry_scheduled', [
                        'run_id' => $runId,
                        'project_id' => $projectId,
                        'parent_node_id' => $parentId,
                        'needed' => $deficit,
                        'selected' => $retrySlugs,
                    ]);
                    foreach ($netsRetry as $netRetry) {
                        $stmt = $conn->prepare('INSERT INTO promotion_nodes (run_id, level, parent_id, target_url, network_slug, anchor_text, status, initiated_by) VALUES (?, 2, ?, ?, ?, ?, \'pending\', ?)');
                        if (!$stmt) { continue; }
                        $initiated = (int)$run['initiated_by'];
                        $stmt->bind_param('iisssi', $runId, $parentId, $parentResultUrl, $netRetry['slug'], $anchorFinal, $initiated);
                        if ($stmt->execute()) {
                            $createdAny = true;
                            $newNodeId = (int)$conn->insert_id;
                            $stmt->close();
                            $nodeRow = [
                                'id' => $newNodeId,
                                'run_id' => $runId,
                                'parent_id' => $parentId,
                                'level' => 2,
                                'target_url' => $parentResultUrl,
                                'network_slug' => (string)$netRetry['slug'],
                                'anchor_text' => $anchorFinal,
                                'parent_url' => $parentResultUrl,
                                'parent_target_url' => $parentTargetUrl,
                                'parent_level' => (int)($parentInfo[$parentId]['level'] ?? 1),
                                'initiated_by' => $run['initiated_by'],
                            ];
                            $trail = [];
                            if ($parentContext) { $trail[] = $parentContext; }
                            $requirementsPayload = [
                                'min_len' => $requirements[2]['min_len'],
                                'max_len' => $requirements[2]['max_len'],
                                'level' => 2,
                                'parent_url' => $parentResultUrl,
                                'parent_context' => $parentContext,
                                'ancestor_trail' => $trail,
                            ];
                            $requirementsPayload['prepared_language'] = $preparedLanguage;
                            if ($preparedArticle) {
                                $requirementsPayload['prepared_article'] = $preparedArticle;
                                if (!empty($articleMeta)) {
                                    $requirementsPayload['article_meta'] = $articleMeta;
                                }
                            }
                            pp_promotion_enqueue_publication($conn, $nodeRow, $project, $linkRow, $requirementsPayload);
                        } else {
                            $stmt->close();
                        }
                    }
                }

                if ($createdAny) {
                    pp_promotion_update_progress($conn, $runId);
                    return;
                }

                if (!empty($parentsNeeding)) {
                    pp_promotion_log('promotion.level2.retry_exhausted', [
                        'run_id' => $runId,
                        'project_id' => $projectId,
                        'deficit' => $parentsNeeding,
                    ]);
                    @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL2_INSUFFICIENT_SUCCESS', finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                    return;
                }
            }
            if ($success === 0) {
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL2_FAILED', finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            if (pp_promotion_is_level_enabled(3)) {
                @$conn->query("UPDATE promotion_runs SET stage='pending_level3', status='pending_level3', updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
            } else {
                @$conn->query("UPDATE promotion_runs SET stage='pending_crowd', status='pending_crowd', updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
            }
            return;
        }
        if ($stage === 'pending_level3') {
            $perParent = (int)($requirements[3]['per_parent'] ?? 0);
            $level2Nodes = [];
            $res = @$conn->query('SELECT id, parent_id, result_url FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=2 AND status IN (\'success\',\'completed\')');
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $url = trim((string)$row['result_url']);
                    if ($url !== '') { $level2Nodes[] = $row; }
                }
                $res->free();
            }
            if (empty($level2Nodes)) {
                @$conn->query("UPDATE promotion_runs SET stage='failed', status='failed', error='LEVEL2_NO_URL', finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            $usage = [];
            $level2Contexts = [];
            $cachedArticlesL2 = [];
            $level1IdsNeeded = [];
            $level2ParentMap = [];
            foreach ($level2Nodes as $parentNode) {
                $ctx = pp_promotion_get_article_context((string)$parentNode['result_url']);
                if ($ctx) { $level2Contexts[(int)$parentNode['id']] = $ctx; }
                $pid = (int)($parentNode['parent_id'] ?? 0);
                if ($pid > 0) {
                    $level1IdsNeeded[$pid] = $pid;
                    $level2ParentMap[(int)$parentNode['id']] = $pid;
                }
                $cached = pp_promotion_load_cached_article((int)$parentNode['id']);
                if (is_array($cached) && !empty($cached['htmlContent'])) {
                    $cachedArticlesL2[(int)$parentNode['id']] = $cached;
                }
            }
            $level1Contexts = [];
            if (!empty($level1IdsNeeded)) {
                $idsList = implode(',', array_map('intval', array_values($level1IdsNeeded)));
                if ($idsList !== '') {
                    $resLvl1 = @$conn->query('SELECT id, result_url FROM promotion_nodes WHERE id IN (' . $idsList . ')');
                    if ($resLvl1) {
                        while ($row = $resLvl1->fetch_assoc()) {
                            $ctx = pp_promotion_get_article_context((string)$row['result_url']);
                            if ($ctx) {
                                $level1Contexts[(int)$row['id']] = $ctx;
                            }
                        }
                        $resLvl1->free();
                    }
                }
            }
            foreach ($level2Nodes as $parentNode) {
                $nets = pp_promotion_pick_networks(3, $perParent, $project, $usage);
                if (empty($nets)) { continue; }
                $selectedSlugs = array_map(static function(array $net) { return (string)($net['slug'] ?? ''); }, $nets);
                $usageSnapshot = [];
                foreach ($selectedSlugs as $slug) {
                    if ($slug === '') { continue; }
                    $usageSnapshot[$slug] = (int)($usage[$slug] ?? 0);
                }
                pp_promotion_log('promotion.level3.networks_selected', [
                    'run_id' => $runId,
                    'project_id' => $projectId,
                    'parent_node_id' => (int)$parentNode['id'],
                    'target_url' => $parentNode['result_url'],
                    'requested' => $perParent,
                    'selected' => $selectedSlugs,
                    'usage' => $usageSnapshot,
                ]);
                $parentCtx = $level2Contexts[(int)$parentNode['id']] ?? null;
                foreach ($nets as $net) {
                    $stmt = $conn->prepare('INSERT INTO promotion_nodes (run_id, level, parent_id, target_url, network_slug, anchor_text, status, initiated_by) VALUES (?, 3, ?, ?, ?, ?, \'pending\', ?)');
                    if ($stmt) {
                        $anchor = pp_promotion_generate_contextual_anchor($parentCtx, (string)$linkRow['anchor']);
                        $initiated = (int)$run['initiated_by'];
                        $stmt->bind_param('iisssi', $runId, $parentNode['id'], $parentNode['result_url'], $net['slug'], $anchor, $initiated);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
            @$conn->query("UPDATE promotion_runs SET stage='level3_active', status='level3_active', updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
            $res3 = @$conn->query('SELECT n.*, p.result_url AS parent_url, p.target_url AS parent_target_url, p.level AS parent_level FROM promotion_nodes n LEFT JOIN promotion_nodes p ON p.id = n.parent_id WHERE n.run_id=' . $runId . ' AND n.level=3 AND n.status=\'pending\'');
            if ($res3) {
                while ($node = $res3->fetch_assoc()) {
                    $node['initiated_by'] = $run['initiated_by'];
                    $nodeId = isset($node['id']) ? (int)$node['id'] : 0;
                    $parentId = (int)($node['parent_id'] ?? 0);
                    $parentCtx = $level2Contexts[$parentId] ?? null;
                    $trail = [];
                    $level1ParentId = $level2ParentMap[$parentId] ?? null;
                    if ($level1ParentId && isset($level1Contexts[$level1ParentId])) {
                        $trail[] = $level1Contexts[$level1ParentId];
                    }
                    if ($parentCtx) { $trail[] = $parentCtx; }
                    $preparedArticle = null;
                    $articleMeta = [];
                    $preparedLanguage = pp_promotion_normalize_language_code($runLanguage, 'ru');
                    $cachedParent = $cachedArticlesL2[$parentId] ?? null;
                    if (is_array($cachedParent) && !empty($cachedParent['htmlContent'])) {
                        $cachedLanguage = pp_promotion_normalize_language_code($cachedParent['language'] ?? null, '');
                        if ($cachedLanguage === '' || $cachedLanguage === $preparedLanguage) {
                            $fallbackAnchor = (string)($node['anchor_text'] ?? '');
                            $childAnchor = pp_promotion_generate_child_anchor($cachedParent, $preparedLanguage, $fallbackAnchor);
                            if ($childAnchor !== '') {
                                if ($childAnchor !== $fallbackAnchor && $nodeId > 0) {
                                    $updateAnchor = $conn->prepare('UPDATE promotion_nodes SET anchor_text=? WHERE id=? LIMIT 1');
                                    if ($updateAnchor) {
                                        $updateAnchor->bind_param('si', $childAnchor, $nodeId);
                                        $updateAnchor->execute();
                                        $updateAnchor->close();
                                    }
                                }
                                $node['anchor_text'] = $childAnchor;
                            }
                            $parentTargetUrl = (string)($node['parent_target_url'] ?? '');
                            if ($parentTargetUrl === '') { $parentTargetUrl = (string)$node['parent_url']; }
                            $preparedArticle = pp_promotion_prepare_child_article(
                                $cachedParent,
                                (string)$node['target_url'],
                                $parentTargetUrl,
                                $preparedLanguage,
                                (string)$node['anchor_text']
                            );
                            if (is_array($preparedArticle) && !empty($preparedArticle['htmlContent'])) {
                                if (empty($preparedArticle['language'])) { $preparedArticle['language'] = $preparedLanguage; }
                                if (empty($preparedArticle['plainText'])) {
                                    $plain = trim(strip_tags((string)$preparedArticle['htmlContent']));
                                    if ($plain !== '') { $preparedArticle['plainText'] = $plain; }
                                }
                                $preparedArticle['sourceUrl'] = $parentTargetUrl;
                                $preparedArticle['sourceNodeId'] = $parentId;
                                $articleMeta = [
                                    'source_node_id' => $parentId,
                                    'source_target_url' => $parentTargetUrl,
                                    'source_level' => (int)($node['parent_level'] ?? 2),
                                    'parent_result_url' => (string)($node['parent_url'] ?? ''),
                                    'ancestor_source_node_id' => $level1ParentId,
                                    'reuse_mode' => 'cached_parent',
                                ];
                                pp_promotion_log('promotion.level3.article_reuse', [
                                    'run_id' => $runId,
                                    'node_id' => $nodeId,
                                    'parent_node_id' => $parentId,
                                    'prepared_language' => $preparedLanguage,
                                    'target_url' => (string)$node['target_url'],
                                    'parent_target_url' => $parentTargetUrl,
                                    'level1_parent_id' => $level1ParentId,
                                ]);
                            } else {
                                $preparedArticle = null;
                                $articleMeta = [];
                            }
                        }
                    }
                    $requirementsPayload = [
                        'min_len' => $requirements[3]['min_len'],
                        'max_len' => $requirements[3]['max_len'],
                        'level' => 3,
                        'parent_url' => $node['parent_url'],
                        'parent_context' => $parentCtx,
                        'ancestor_trail' => $trail,
                    ];
                    $requirementsPayload['prepared_language'] = $preparedLanguage;
                    if ($preparedArticle) {
                        $requirementsPayload['prepared_article'] = $preparedArticle;
                        if (!empty($articleMeta)) {
                            $requirementsPayload['article_meta'] = $articleMeta;
                        }
                    }
                    pp_promotion_enqueue_publication($conn, $node, $project, $linkRow, $requirementsPayload);
                }
                $res3->free();
            }
            pp_promotion_update_progress($conn, $runId);
            return;
        }
        if ($stage === 'level3_active') {
            $res = @$conn->query('SELECT status, COUNT(*) AS c FROM promotion_nodes WHERE run_id=' . $runId . ' AND level=3 GROUP BY status');
            $pending = 0; $success = 0; $failed = 0;
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $statusNode = (string)$row['status'];
                    $cnt = (int)$row['c'];
                    if (in_array($statusNode, ['pending','queued','running'], true)) { $pending += $cnt; }
                    elseif (in_array($statusNode, ['success','completed'], true)) { $success += $cnt; }
                    elseif (in_array($statusNode, ['failed','cancelled'], true)) { $failed += $cnt; }
                }
                $res->free();
            }
            if ($pending > 0) { return; }
            if ($success === 0) {
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='LEVEL3_FAILED', finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            @$conn->query("UPDATE promotion_runs SET stage='pending_crowd', status='pending_crowd' WHERE id=" . $runId . " LIMIT 1");
            return;
        }
        if ($stage === 'pending_crowd') {
            if (!pp_promotion_is_crowd_enabled()) {
                @$conn->query("UPDATE promotion_runs SET stage='report_ready', status='report_ready', updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            $crowdPerArticle = pp_promotion_crowd_required_per_article($run);
            if ($crowdPerArticle <= 0) {
                @$conn->query("UPDATE promotion_runs SET stage='report_ready', status='report_ready', updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            $crowdSource = pp_promotion_crowd_collect_nodes($conn, $runId);
            $finalNodes = $crowdSource['nodes'] ?? [];
            if (empty($finalNodes)) {
                @$conn->query("UPDATE promotion_runs SET stage='report_ready', status='report_ready', updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            $nodesNeeds = [];
            foreach ($finalNodes as $node) {
                $targetUrl = trim((string)($node['result_url'] ?? ''));
                $nodeId = (int)($node['id'] ?? 0);
                if ($targetUrl === '' || $nodeId <= 0) { continue; }
                $nodesNeeds[$nodeId] = [
                    'target_url' => $targetUrl,
                    'needed' => $crowdPerArticle,
                ];
            }
            if (empty($nodesNeeds)) {
                @$conn->query("UPDATE promotion_runs SET stage='report_ready', status='report_ready', updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }

            $queueResult = pp_promotion_crowd_queue_tasks($conn, $run, $project, $linkRow, $nodesNeeds, [
                'crowd_per_article' => $crowdPerArticle,
            ]);

            pp_promotion_log('promotion.crowd.seed_summary', [
                'run_id' => $runId,
                'project_id' => $projectId,
                'nodes' => count($nodesNeeds),
                'per_article' => $crowdPerArticle,
                'created' => $queueResult['created'] ?? 0,
                'manual_fallback' => $queueResult['fallback'] ?? 0,
                'shortage' => $queueResult['shortage'] ?? false,
            ]);

            if (($queueResult['created'] ?? 0) <= 0) {
                $errorCode = ($queueResult['shortage'] ?? false) ? 'CROWD_SOURCES_UNAVAILABLE' : 'CROWD_TASKS_NOT_CREATED';
                pp_promotion_log('promotion.crowd.seed_failed', [
                    'run_id' => $runId,
                    'project_id' => $projectId,
                    'error' => $errorCode,
                ]);
                $errorEscaped = $conn->real_escape_string($errorCode);
                @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='" . $errorEscaped . "', finished_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }

            @$conn->query("UPDATE promotion_runs SET stage='crowd_ready', status='crowd_ready', updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
            pp_promotion_launch_crowd_worker();
            return;
        }
        if ($stage === 'crowd_ready') {
            if (!pp_promotion_is_crowd_enabled()) {
                @$conn->query("UPDATE promotion_runs SET stage='report_ready', status='report_ready', updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            $crowdPerArticle = pp_promotion_crowd_required_per_article($run);
            if ($crowdPerArticle <= 0) {
                @$conn->query("UPDATE promotion_runs SET stage='report_ready', status='report_ready', updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }
            $crowdSource = pp_promotion_crowd_collect_nodes($conn, $runId);
            $crowdNodes = $crowdSource['nodes'] ?? [];
            if (empty($crowdNodes)) {
                @$conn->query("UPDATE promotion_runs SET stage='report_ready', status='report_ready', updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }

            $nodeTargets = [];
            $nodeStats = [];
            foreach ($crowdNodes as $node) {
                $nodeId = (int)($node['id'] ?? 0);
                $targetUrl = trim((string)($node['result_url'] ?? ''));
                if ($nodeId <= 0 || $targetUrl === '') { continue; }
                $nodeTargets[$nodeId] = $targetUrl;
                $nodeStats[$nodeId] = [
                    'completed' => 0,
                    'pending' => 0,
                    'failed' => 0,
                    'manual' => 0,
                    'attempts' => 0,
                ];
            }
            if (empty($nodeTargets)) {
                @$conn->query("UPDATE promotion_runs SET stage='report_ready', status='report_ready', updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }

            $successStatuses = ['completed','success','done','posted','published','ok'];
            $pendingStatuses = ['planned','queued','running','pending','created'];
            $failureStatuses = ['failed','error','blocked','cancelled','rejected','timeout'];

            if ($res = @$conn->query('SELECT node_id, status, COUNT(*) AS c FROM promotion_crowd_tasks WHERE run_id=' . $runId . ' GROUP BY node_id, status')) {
                while ($row = $res->fetch_assoc()) {
                    $nodeId = (int)($row['node_id'] ?? 0);
                    if ($nodeId <= 0 || !isset($nodeStats[$nodeId])) { continue; }
                    $statusKey = strtolower((string)($row['status'] ?? ''));
                    $count = (int)($row['c'] ?? 0);
                    if ($count <= 0) { continue; }
                    $nodeStats[$nodeId]['attempts'] += $count;
                    if (in_array($statusKey, $successStatuses, true)) {
                        $nodeStats[$nodeId]['completed'] += $count;
                    } elseif (in_array($statusKey, $pendingStatuses, true)) {
                        $nodeStats[$nodeId]['pending'] += $count;
                    } elseif ($statusKey === 'manual') {
                        $nodeStats[$nodeId]['manual'] += $count;
                    } else {
                        $nodeStats[$nodeId]['failed'] += $count;
                    }
                }
                $res->free();
            }

            $existingLinkMap = [];
            if ($resLinks = @$conn->query('SELECT node_id, crowd_link_id FROM promotion_crowd_tasks WHERE run_id=' . $runId . ' AND crowd_link_id IS NOT NULL')) {
                while ($row = $resLinks->fetch_assoc()) {
                    $nodeId = (int)($row['node_id'] ?? 0);
                    $crowdLinkId = (int)($row['crowd_link_id'] ?? 0);
                    if ($nodeId <= 0 || $crowdLinkId <= 0) { continue; }
                    if (!isset($existingLinkMap[$nodeId])) { $existingLinkMap[$nodeId] = []; }
                    $existingLinkMap[$nodeId][] = $crowdLinkId;
                }
                $resLinks->free();
            }

            $needsTopUp = [];
            $totalPending = 0;
            $totalCompleted = 0;
            $totalFailed = 0;
            $totalManual = 0;

            foreach ($nodeStats as $nodeId => $stats) {
                $totalPending += (int)$stats['pending'];
                $totalCompleted += (int)$stats['completed'];
                $totalFailed += (int)$stats['failed'];
                $totalManual += (int)$stats['manual'];
                $attempts = (int)$stats['attempts'];
                $successPlusActive = (int)$stats['completed'] + (int)$stats['pending'];
                if ($successPlusActive < $crowdPerArticle) {
                    $deficit = $crowdPerArticle - $successPlusActive;
                    $attempts = max($attempts, (int)$stats['completed'] + (int)$stats['pending'] + (int)$stats['failed'] + (int)$stats['manual']);
                    $nodeStats[$nodeId]['attempts'] = $attempts;
                    $attemptLimit = max($crowdPerArticle * 6, $crowdPerArticle + 12);
                    if ($attempts >= $attemptLimit) {
                        $nodeStats[$nodeId]['exhausted'] = true;
                        continue;
                    }
                    $needsTopUp[$nodeId] = [
                        'target_url' => $nodeTargets[$nodeId],
                        'needed' => $deficit,
                    ];
                }
            }

            if (!empty($needsTopUp)) {
                $topUpResult = pp_promotion_crowd_queue_tasks($conn, $run, $project, $linkRow, $needsTopUp, [
                    'crowd_per_article' => $crowdPerArticle,
                    'existing_link_map' => $existingLinkMap,
                ]);
                $needsSummary = [];
                foreach ($needsTopUp as $nodeId => $info) {
                    $needsSummary[$nodeId] = (int)($info['needed'] ?? 0);
                }
                pp_promotion_log('promotion.crowd.topup_summary', [
                    'run_id' => $runId,
                    'project_id' => $projectId,
                    'needs' => $needsSummary,
                    'created' => $topUpResult['created'] ?? 0,
                    'manual_fallback' => $topUpResult['fallback'] ?? 0,
                    'shortage' => $topUpResult['shortage'] ?? false,
                ]);
                if (($topUpResult['created'] ?? 0) > 0) {
                    @$conn->query("UPDATE promotion_runs SET stage='crowd_ready', status='crowd_ready', updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                    pp_promotion_launch_crowd_worker();
                    return;
                }
            }

            $activeTasks = $totalPending;
            if ($activeTasks > 0) {
                pp_promotion_launch_crowd_worker();
                return;
            }

            $completedSuccess = $totalCompleted;
            $requiredSuccess = count($nodeTargets) * $crowdPerArticle;

            if ($completedSuccess >= $requiredSuccess) {
                @$conn->query("UPDATE promotion_runs SET stage='report_ready', status='report_ready', updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
                return;
            }

            $hasExhausted = false;
            foreach ($nodeStats as $stats) {
                if (!empty($stats['exhausted'])) { $hasExhausted = true; break; }
            }

            @$conn->query("UPDATE promotion_runs SET status='failed', stage='failed', error='CROWD_FAILED_INSUFFICIENT', finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1");
            pp_promotion_log('promotion.crowd.failed', [
                'run_id' => $runId,
                'project_id' => $projectId,
                'required_success' => $requiredSuccess,
                'completed_success' => $completedSuccess,
                'manual' => $totalManual,
                'failed' => $totalFailed,
                'exhausted' => $hasExhausted,
            ]);
            return;
        }
        if ($stage === 'report_ready') {
            $report = pp_promotion_build_report($conn, $runId);
            $reportJson = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($reportJson === false) { $reportJson = '{}'; }
            $updateSql = "UPDATE promotion_runs SET status='completed', stage='completed', report_json='" . $conn->real_escape_string($reportJson) . "', finished_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=" . $runId . " LIMIT 1";
            $updated = @$conn->query($updateSql);
            if ($updated && $conn->affected_rows > 0 && function_exists('pp_promotion_send_completion_notification')) {
                $runForNotify = $run;
                $runForNotify['status'] = 'completed';
                $runForNotify['stage'] = 'completed';
                $runForNotify['report_json'] = $reportJson;
                try {
                    pp_promotion_send_completion_notification($conn, $runForNotify, $project, $linkRow, $report);
                } catch (Throwable $notifyError) {
                    pp_promotion_log('promotion.notify_exception', [
                        'run_id' => $runId,
                        'error' => $notifyError->getMessage(),
                    ]);
                }
            }
            return;
        }
    }
}

if (!function_exists('pp_promotion_worker')) {
    function pp_promotion_worker(?int $specificRunId = null, int $maxIterations = 20): void {
        if (function_exists('session_write_close')) { @session_write_close(); }
        @ignore_user_abort(true);
        pp_promotion_log('promotion.worker.start', [
            'specific_run_id' => $specificRunId,
            'max_iterations' => $maxIterations,
        ]);
        try { $conn = @connect_db(); } catch (Throwable $e) {
            $message = $e->getMessage();
            pp_promotion_log('promotion.worker.db_error', ['error' => $message]);
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "Promotion worker: unable to connect to database ({$message})." . PHP_EOL);
            }
            return;
        }
        if (!$conn) {
            pp_promotion_log('promotion.worker.db_unavailable', []);
            return;
        }
        for ($i = 0; $i < $maxIterations; $i++) {
            $run = null;
            if ($specificRunId) {
                $stmt = $conn->prepare('SELECT * FROM promotion_runs WHERE id = ? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('i', $specificRunId);
                    if ($stmt->execute()) { $run = $stmt->get_result()->fetch_assoc(); }
                    $stmt->close();
                }
                $specificRunId = null;
            } else {
                $sql = "SELECT * FROM promotion_runs WHERE status IN ('queued','running','pending_level1','level1_active','pending_level2','level2_active','pending_level3','level3_active','pending_crowd','crowd_ready','report_ready') ORDER BY id ASC LIMIT 1";
                if ($res = @$conn->query($sql)) {
                    $run = $res->fetch_assoc();
                    $res->free();
                }
            }
            if (!$run) { break; }
            pp_promotion_process_run($conn, $run);
            pp_promotion_update_progress($conn, (int)$run['id']);
            usleep(200000);
        }
        $conn->close();
    }
}

if (!function_exists('pp_promotion_handle_publication_update')) {
    function pp_promotion_handle_publication_update(int $publicationId, string $status, ?string $postUrl, ?string $error, ?array $jobResult = null): void {
        try { $conn = @connect_db(); } catch (Throwable $e) { return; }
        if (!$conn) { return; }
    $stmt = $conn->prepare('SELECT run_id, id, level, target_url, parent_id, network_slug FROM promotion_nodes WHERE publication_id = ? LIMIT 1');
        if (!$stmt) { $conn->close(); return; }
        $stmt->bind_param('i', $publicationId);
        if (!$stmt->execute()) { $stmt->close(); $conn->close(); return; }
        $node = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$node) { $conn->close(); return; }
    $nodeId = (int)$node['id'];
    $runId = (int)$node['run_id'];
    $nodeLevel = isset($node['level']) ? (int)$node['level'] : null;
    $nodeTargetUrl = isset($node['target_url']) ? (string)$node['target_url'] : '';
    $parentNodeId = isset($node['parent_id']) ? (int)$node['parent_id'] : 0;
        $now = date('Y-m-d H:i:s');
        $statusUpdate = in_array($status, ['success','partial'], true) ? 'success' : ($status === 'failed' ? 'failed' : $status);
        $stmt2 = $conn->prepare('UPDATE promotion_nodes SET status=?, result_url=?, error=?, finished_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1');
        if ($stmt2) {
            $url = $postUrl ?: '';
            $err = $error ?: null;
            $stmt2->bind_param('sssi', $statusUpdate, $url, $err, $nodeId);
            $stmt2->execute();
            $stmt2->close();
        }
        pp_promotion_update_progress($conn, $runId);
        pp_promotion_log('promotion.publication_update', [
            'run_id' => $runId,
            'node_id' => $nodeId,
            'publication_id' => $publicationId,
            'new_status' => $statusUpdate,
            'original_status' => $status,
            'post_url' => $postUrl,
            'error' => $error,
        ]);
        if (in_array($statusUpdate, ['success','completed','partial'], true) && is_array($jobResult)) {
            $article = $jobResult['article'] ?? null;
            if (is_array($article) && !empty($article['htmlContent'])) {
                $meta = [
                    'level' => $nodeLevel,
                    'target_url' => $nodeTargetUrl,
                    'result_url' => $postUrl,
                    'status' => $statusUpdate,
                    'network' => (string)($node['network_slug'] ?? ''),
                    'stored_from' => 'publication_update',
                ];
                if ($parentNodeId > 0) { $meta['parent_node_id'] = $parentNodeId; }
                if (!empty($jobResult['articleMeta']) && is_array($jobResult['articleMeta'])) {
                    foreach ($jobResult['articleMeta'] as $metaKey => $metaValue) {
                        if (is_string($metaKey) && $metaKey !== '') {
                            $meta[$metaKey] = $metaValue;
                        }
                    }
                }
                $storedPath = pp_promotion_store_cached_article($nodeId, $article, $meta);
                if ($storedPath) {
                    pp_promotion_log('promotion.article_cached', [
                        'run_id' => $runId,
                        'node_id' => $nodeId,
                        'level' => $nodeLevel,
                        'path' => $storedPath,
                    ]);
                } else {
                    pp_promotion_log('promotion.article_cache_failed', [
                        'run_id' => $runId,
                        'node_id' => $nodeId,
                        'level' => $nodeLevel,
                    ]);
                }
            }
        }
        $conn->close();
    }
}

if (!function_exists('pp_promotion_get_status')) {
    function pp_promotion_get_status(int $projectId, string $url, ?int $linkId = null): array {
        $projectId = (int)$projectId;
        $linkId = $linkId !== null ? (int)$linkId : null;
        $url = trim($url);

        try { $conn = @connect_db(); } catch (Throwable $e) { return ['ok' => false, 'error' => 'DB']; }
        if (!$conn) { return ['ok' => false, 'error' => 'DB']; }

        $run = null;
        if ($linkId !== null && $linkId > 0) {
            $stmt = $conn->prepare('SELECT * FROM promotion_runs WHERE project_id = ? AND link_id = ? ORDER BY id DESC LIMIT 1');
            if (!$stmt) { $conn->close(); return ['ok' => false, 'error' => 'DB']; }
            $stmt->bind_param('ii', $projectId, $linkId);
            if ($stmt->execute()) {
                $run = $stmt->get_result()->fetch_assoc();
            }
            $stmt->close();
        }

        if (!$run) {
            $shouldFallbackByUrl = ($linkId === null || $linkId <= 0);
            if (!$shouldFallbackByUrl) {
                $stmt = $conn->prepare('SELECT * FROM promotion_runs WHERE project_id = ? AND target_url = ? AND (link_id IS NULL OR link_id = 0) ORDER BY id DESC LIMIT 1');
            } else {
                $stmt = $conn->prepare('SELECT * FROM promotion_runs WHERE project_id = ? AND target_url = ? ORDER BY id DESC LIMIT 1');
            }
            if (!$stmt) { $conn->close(); return ['ok' => false, 'error' => 'DB']; }
            $stmt->bind_param('is', $projectId, $url);
            if ($stmt->execute()) {
                $run = $stmt->get_result()->fetch_assoc();
            }
            $stmt->close();
            if (!$run && !$shouldFallbackByUrl) {
                $conn->close();
                return [
                    'ok' => true,
                    'status' => 'idle',
                    'link_id' => (int)$linkId,
                ];
            }
        }

        if (!$run) {
            $conn->close();
            return [
                'ok' => true,
                'status' => 'idle',
                'link_id' => $linkId ? (int)$linkId : 0,
            ];
        }

        $runId = (int)$run['id'];
        $linkIdResolved = isset($run['link_id']) ? (int)$run['link_id'] : ($linkId ?? 0);
        if (!empty($run['target_url']) && $url === '') {
            $url = (string)$run['target_url'];
        }

        $settingsSnapshot = [];
        if (!empty($run['settings_snapshot'])) {
            $decoded = json_decode((string)$run['settings_snapshot'], true);
            if (is_array($decoded)) { $settingsSnapshot = $decoded; }
        }
        $requirements = pp_promotion_get_level_requirements();
        $level1Required = isset($settingsSnapshot['level1_count']) ? (int)$settingsSnapshot['level1_count'] : (int)($requirements[1]['count'] ?? 5);
        if ($level1Required <= 0) { $level1Required = (int)($requirements[1]['count'] ?? 5); }
        $level2PerParent = isset($settingsSnapshot['level2_per_level1']) ? (int)$settingsSnapshot['level2_per_level1'] : (int)($requirements[2]['per_parent'] ?? 0);
        if ($level2PerParent < 0) { $level2PerParent = 0; }
        $level3PerParent = isset($settingsSnapshot['level3_per_level2']) ? (int)$settingsSnapshot['level3_per_level2'] : (int)($requirements[3]['per_parent'] ?? 0);
        if ($level3PerParent < 0) { $level3PerParent = 0; }
        $level3EnabledSnapshot = isset($settingsSnapshot['level3_enabled']) ? (bool)$settingsSnapshot['level3_enabled'] : pp_promotion_is_level_enabled(3);

        $levels = [
            1 => ['total' => 0, 'success' => 0, 'failed' => 0, 'attempted' => 0, 'required' => $level1Required],
            2 => ['total' => 0, 'success' => 0, 'failed' => 0, 'attempted' => 0, 'required' => 0],
            3 => ['total' => 0, 'success' => 0, 'failed' => 0, 'attempted' => 0, 'required' => 0],
        ];
        if ($res = @$conn->query('SELECT level, status, COUNT(*) AS c FROM promotion_nodes WHERE run_id=' . $runId . ' GROUP BY level, status')) {
            while ($row = $res->fetch_assoc()) {
                $lvl = (int)$row['level'];
                if (!isset($levels[$lvl])) {
                    $levels[$lvl] = ['total' => 0, 'success' => 0, 'failed' => 0, 'attempted' => 0, 'required' => 0];
                }
                $count = (int)$row['c'];
                $levels[$lvl]['attempted'] += $count;
                $statusNode = (string)$row['status'];
                if (in_array($statusNode, ['success','completed'], true)) { $levels[$lvl]['success'] += $count; }
                elseif (in_array($statusNode, ['failed','cancelled'], true)) { $levels[$lvl]['failed'] += $count; }
            }
            $res->free();
        }
        $level1Success = (int)($levels[1]['success'] ?? 0);
        $levels[1]['total'] = $level1Success;
        if (!isset($levels[1]['required']) || $levels[1]['required'] <= 0) {
            $levels[1]['required'] = $level1Required;
        }
        $expectedLevel2 = 0;
        if ($level2PerParent > 0 && $level1Success > 0) {
            $expectedLevel2 = $level2PerParent * $level1Success;
        }
        if (!isset($levels[2])) {
            $levels[2] = ['total' => 0, 'success' => 0, 'failed' => 0, 'attempted' => 0, 'required' => $expectedLevel2];
        }
        $levels[2]['total'] = (int)($levels[2]['success'] ?? 0);
        $levels[2]['required'] = $expectedLevel2;
        $level2Success = (int)($levels[2]['success'] ?? 0);
        $expectedLevel3 = 0;
        if ($level3EnabledSnapshot && $level3PerParent > 0 && $level2Success > 0) {
            $expectedLevel3 = $level3PerParent * $level2Success;
        }
        if (!isset($levels[3])) {
            $levels[3] = ['total' => 0, 'success' => 0, 'failed' => 0, 'attempted' => 0, 'required' => $expectedLevel3];
        }
        $levels[3]['total'] = (int)($levels[3]['success'] ?? 0);
        $levels[3]['required'] = $expectedLevel3;
        foreach ($levels as $lvl => &$info) {
            if (!isset($info['attempted'])) { $info['attempted'] = $info['success'] + $info['failed']; }
            if (!isset($info['required']) || $info['required'] < 0) { $info['required'] = 0; }
            $info['failed'] = (int)$info['failed'];
            $info['success'] = (int)$info['success'];
            $info['total'] = (int)$info['total'];
            $info['attempted'] = (int)$info['attempted'];
        }
        unset($info);
        $crowdStats = [
            'total' => 0,
            'planned' => 0,
            'queued' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'manual' => 0,
            'remaining' => 0,
            'percent' => 0.0,
            'completed_links' => 0,
            'manual_fallback' => 0,
            'attempted' => 0,
            'target' => 0,
            'items' => [],
        ];
        if ($res = @$conn->query('SELECT status, COUNT(*) AS c FROM promotion_crowd_tasks WHERE run_id=' . $runId . ' GROUP BY status')) {
            while ($row = $res->fetch_assoc()) {
                $status = strtolower((string)($row['status'] ?? ''));
                $count = (int)($row['c'] ?? 0);
                $crowdStats['total'] += $count;
                if (isset($crowdStats[$status])) {
                    $crowdStats[$status] += $count;
                    if ($status === 'manual') {
                        $crowdStats['manual_fallback'] += $count;
                        $crowdStats['failed'] += $count;
                    }
                } elseif ($status === 'posted' || $status === 'success' || $status === 'done') {
                    $crowdStats['completed'] += $count;
                } elseif ($status === 'error') {
                    $crowdStats['failed'] += $count;
                }
            }
            $res->free();
        }
        if ($crowdStats['completed'] === 0 && $crowdStats['total'] > 0) {
            $crowdStats['completed'] = $crowdStats['queued'] === 0 && $crowdStats['running'] === 0 ? $crowdStats['total'] - ($crowdStats['failed'] ?? 0) - $crowdStats['planned'] : $crowdStats['completed'];
            if ($crowdStats['completed'] < 0) { $crowdStats['completed'] = 0; }
        }
        $crowdStats['attempted'] = $crowdStats['total'];
        $crowdPerArticle = pp_promotion_crowd_required_per_article($run);
        $crowdNodes = pp_promotion_crowd_collect_nodes($conn, $runId);
        $crowdTarget = (int)($crowdNodes['total'] ?? 0) * $crowdPerArticle;
        $crowdStats['target'] = $crowdTarget;
        if ($crowdTarget > 0) {
            $crowdStats['total'] = $crowdTarget;
            $crowdStats['remaining'] = max(0, $crowdTarget - $crowdStats['completed']);
            $crowdStats['percent'] = (float)round(($crowdStats['completed'] / $crowdTarget) * 100, 1);
        } else {
            $crowdStats['remaining'] = max(0, $crowdStats['attempted'] - $crowdStats['completed'] - $crowdStats['failed']);
            if ($crowdStats['attempted'] > 0) {
                $crowdStats['percent'] = (float)round(($crowdStats['completed'] / $crowdStats['attempted']) * 100, 1);
            }
        }
        $crowdLinkCache = [];
        $taskSql = 'SELECT id, status, crowd_link_id, result_url, payload_json, target_url, updated_at FROM promotion_crowd_tasks WHERE run_id=' . $runId . ' ORDER BY updated_at DESC, id DESC LIMIT 80';
        if ($res = @$conn->query($taskSql)) {
            while ($row = $res->fetch_assoc()) {
                $statusRaw = (string)($row['status'] ?? '');
                $status = strtolower($statusRaw);
                $payloadLink = null;
                $messageBody = null;
                $messagePreview = null;
                $messageSubject = null;
                $messageAuthor = null;
                $messageEmail = null;
                $manualFallback = false;
                $fallbackReason = null;
                if (!empty($row['payload_json'])) {
                    $payload = json_decode((string)$row['payload_json'], true);
                    if (is_array($payload) && !empty($payload['crowd_link_url'])) {
                        $payloadLink = (string)$payload['crowd_link_url'];
                    }
                    if (is_array($payload)) {
                        if (!empty($payload['body'])) {
                            $messageBody = trim((string)$payload['body']);
                            if ($messageBody !== '') {
                                $messagePreview = $messageBody;
                                if (function_exists('mb_strlen')) {
                                    if (mb_strlen($messagePreview, 'UTF-8') > 220) {
                                        $messagePreview = rtrim(mb_substr($messagePreview, 0, 200, 'UTF-8')) . '…';
                                    }
                                } elseif (strlen($messagePreview) > 220) {
                                    $messagePreview = rtrim(substr($messagePreview, 0, 200)) . '…';
                                }
                            } else {
                                $messageBody = null;
                            }
                        }
                        if (!empty($payload['subject'])) {
                            $messageSubject = trim((string)$payload['subject']);
                        }
                        if (!empty($payload['author_name'])) {
                            $messageAuthor = trim((string)$payload['author_name']);
                        }
                        if (!empty($payload['author_email'])) {
                            $messageEmail = trim((string)$payload['author_email']);
                        }
                        if (array_key_exists('manual_fallback', $payload)) {
                            $manualFallback = !empty($payload['manual_fallback']);
                        }
                        if (array_key_exists('fallback_reason', $payload)) {
                            $reason = trim((string)$payload['fallback_reason']);
                            $fallbackReason = ($reason !== '') ? $reason : null;
                        }
                    }
                }
                $crowdLinkId = isset($row['crowd_link_id']) ? (int)$row['crowd_link_id'] : 0;
                if (!$payloadLink && $crowdLinkId > 0) {
                    if (array_key_exists($crowdLinkId, $crowdLinkCache)) {
                        $payloadLink = $crowdLinkCache[$crowdLinkId];
                    } else {
                        if ($resLink = @$conn->query('SELECT url FROM crowd_links WHERE id=' . $crowdLinkId . ' LIMIT 1')) {
                            if ($rowLink = $resLink->fetch_assoc()) {
                                $payloadLink = (string)($rowLink['url'] ?? '');
                            }
                            $resLink->free();
                        }
                        $crowdLinkCache[$crowdLinkId] = $payloadLink;
                    }
                }
                $resultUrl = trim((string)($row['result_url'] ?? ''));
                if ($resultUrl !== '') { $crowdStats['completed_links']++; }
                $articleUrl = (string)($row['target_url'] ?? '');
                if ($manualFallback) {
                    $crowdStats['manual_fallback']++;
                }
                $crowdStats['items'][] = [
                    'id' => (int)$row['id'],
                    'status' => $statusRaw,
                    'status_normalized' => $status,
                    'result_url' => $resultUrl !== '' ? $resultUrl : null,
                    'link_url' => $payloadLink ? (string)$payloadLink : null,
                    'crowd_url' => $payloadLink ? (string)$payloadLink : null,
                    'target_url' => $articleUrl,
                    'article_url' => $articleUrl,
                    'message' => $messageBody,
                    'message_preview' => $messagePreview,
                    'subject' => $messageSubject,
                    'author_name' => $messageAuthor,
                    'author_email' => $messageEmail,
                    'manual_fallback' => $manualFallback,
                    'fallback_reason' => $fallbackReason,
                    'updated_at' => isset($row['updated_at']) ? (string)$row['updated_at'] : null,
                ];
            }
            $res->free();
        }
        $successfulCrowdStatuses = ['completed','success','done','posted','published','ok'];
        $visibleCrowdItems = [];
        foreach ($crowdStats['items'] as $item) {
            $statusNormalized = strtolower((string)($item['status_normalized'] ?? $item['status'] ?? ''));
            if (!in_array($statusNormalized, $successfulCrowdStatuses, true)) {
                continue;
            }
            unset($item['fallback_reason'], $item['manual_fallback']);
            $visibleCrowdItems[] = $item;
        }
        $crowdStats['items'] = array_values($visibleCrowdItems);
        $crowdStats['manual'] = 0;
        $crowdStats['manual_fallback'] = 0;
        $crowdStats['failed'] = 0;
        $crowdStats['attempted'] = (int)$crowdStats['completed'] + (int)$crowdStats['queued'] + (int)$crowdStats['running'] + (int)$crowdStats['planned'];
        if ($crowdStats['target'] > 0) {
            $crowdStats['total'] = (int)$crowdStats['target'];
            $crowdStats['remaining'] = max(0, (int)$crowdStats['target'] - (int)$crowdStats['completed']);
            $crowdStats['percent'] = $crowdStats['target'] > 0
                ? (float)round(($crowdStats['completed'] / $crowdStats['target']) * 100, 1)
                : 0.0;
        }
        $conn->close();
        return [
            'ok' => true,
            'status' => (string)$run['status'],
            'stage' => (string)$run['stage'],
            'progress' => ['done' => (int)$run['progress_done'], 'total' => (int)$run['progress_total'], 'target' => $level1Required],
            'levels' => $levels,
            'crowd' => $crowdStats,
            'run_id' => $runId,
            'link_id' => $linkIdResolved,
            'target_url' => $url,
            'report_ready' => !empty($run['report_json']) || $run['status'] === 'completed',
            'charge' => [
                'amount' => (float)$run['charged_amount'],
                'discount_percent' => (float)$run['discount_percent'],
            ],
            'charged_amount' => (float)$run['charged_amount'],
            'discount_percent' => (float)$run['discount_percent'],
        ];
    }
}

if (!function_exists('pp_promotion_start_run')) {
    function pp_promotion_start_run(int $projectId, string $url, int $initiatedBy = 0, ?int $linkIdOverride = null): array {
        $projectId = (int)$projectId;
        $url = trim($url);
        if ($projectId <= 0 || $url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'error' => 'BAD_INPUT'];
        }
        if (!pp_promotion_is_level_enabled(1)) {
            return ['ok' => false, 'error' => 'LEVEL1_DISABLED'];
        }
        try { $conn = connect_db(); } catch (Throwable $e) { return ['ok' => false, 'error' => 'DB']; }
        if (!$conn) { return ['ok' => false, 'error' => 'DB']; }

        $transactionStarted = false;
        $shouldCommit = false;
        $result = ['ok' => false, 'error' => 'DB'];
        $balanceEvent = null;
        $runId = 0;
    $linkId = $linkIdOverride !== null ? max(0, (int)$linkIdOverride) : 0;
        $ownerId = 0;
        $balanceAfter = 0.0;
        $balanceBefore = 0.0;
        $basePrice = 0.0;
        $chargedAmount = 0.0;
        $discountPercent = 0.0;
        $initiator = $initiatedBy > 0 ? $initiatedBy : 0;

        try {
            $transactionStarted = method_exists($conn, 'begin_transaction')
                ? @$conn->begin_transaction()
                : @$conn->autocommit(false);
            if ($transactionStarted === false) {
                @$conn->query('START TRANSACTION');
                $transactionStarted = true;
            }
            if (!$transactionStarted) {
                return ['ok' => false, 'error' => 'DB'];
            }

            do {
                if ($linkId > 0) {
                    $linkStmt = $conn->prepare('SELECT l.id AS link_id, l.url, p.user_id FROM project_links l JOIN projects p ON p.id = l.project_id WHERE p.id = ? AND l.id = ? LIMIT 1 FOR UPDATE');
                    if (!$linkStmt) { break; }
                    $linkStmt->bind_param('ii', $projectId, $linkId);
                } else {
                    $linkStmt = $conn->prepare('SELECT l.id AS link_id, l.url, p.user_id FROM project_links l JOIN projects p ON p.id = l.project_id WHERE p.id = ? AND l.url = ? ORDER BY l.id DESC LIMIT 1 FOR UPDATE');
                    if (!$linkStmt) { break; }
                    $linkStmt->bind_param('is', $projectId, $url);
                }
                if (!$linkStmt->execute()) { $linkStmt->close(); break; }
                $linkRes = $linkStmt->get_result();
                $linkRow = $linkRes ? $linkRes->fetch_assoc() : null;
                if ($linkRes) { $linkRes->free(); }
                $linkStmt->close();
                if (!$linkRow) {
                    $result = ['ok' => false, 'error' => 'URL_NOT_FOUND'];
                    break;
                }
                $linkId = (int)($linkRow['link_id'] ?? 0);
                if (!empty($linkRow['url'])) {
                    $url = (string)$linkRow['url'];
                }
                $ownerId = (int)($linkRow['user_id'] ?? 0);
                if ($linkId <= 0 || $ownerId <= 0) {
                    $result = ['ok' => false, 'error' => 'URL_NOT_FOUND'];
                    break;
                }

                $existingRun = null;
                $existingStmt = $conn->prepare('SELECT id, status FROM promotion_runs WHERE project_id = ? AND link_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
                if ($existingStmt) {
                    $existingStmt->bind_param('ii', $projectId, $linkId);
                    if ($existingStmt->execute()) {
                        $existingRun = $existingStmt->get_result()->fetch_assoc();
                    }
                    $existingStmt->close();
                }
                if ($existingRun) {
                    $statusCurrent = (string)($existingRun['status'] ?? '');
                    $activeStatuses = ['queued','running','pending_level1','level1_active','pending_level2','level2_active','pending_level3','level3_active','pending_crowd','crowd_ready','report_ready'];
                    if (in_array($statusCurrent, $activeStatuses, true)) {
                        $result = [
                            'ok' => true,
                            'already' => true,
                            'run_id' => (int)$existingRun['id'],
                            'status' => $statusCurrent,
                        ];
                        break;
                    }
                }

                $userStmt = $conn->prepare('SELECT id, balance, promotion_discount FROM users WHERE id = ? FOR UPDATE');
                if (!$userStmt) { break; }
                $userStmt->bind_param('i', $ownerId);
                if (!$userStmt->execute()) { $userStmt->close(); break; }
                $userRes = $userStmt->get_result();
                $userRow = $userRes ? $userRes->fetch_assoc() : null;
                if ($userRes) { $userRes->free(); }
                $userStmt->close();
                if (!$userRow) {
                    $result = ['ok' => false, 'error' => 'USER_NOT_FOUND'];
                    break;
                }

                $balanceBefore = (float)($userRow['balance'] ?? 0.0);
                $discountPercent = max(0.0, min(100.0, (float)($userRow['promotion_discount'] ?? 0.0)));
                $settings = pp_promotion_settings();
                $basePrice = max(0.0, (float)($settings['price_per_link'] ?? 0.0));
                $chargedAmount = max(0.0, round($basePrice * (1 - $discountPercent / 100), 2));
                $shortfall = max(0.0, round($chargedAmount - $balanceBefore, 2));
                if ($chargedAmount > $balanceBefore + 0.00001) {
                    $result = [
                        'ok' => false,
                        'error' => 'INSUFFICIENT_FUNDS',
                        'required' => $chargedAmount,
                        'balance' => $balanceBefore,
                        'shortfall' => $shortfall,
                        'discount_percent' => $discountPercent,
                    ];
                    break;
                }

                $snapshot = [
                    'level1_count' => (int)($settings['level1_count'] ?? 0),
                    'level2_per_level1' => (int)($settings['level2_per_level1'] ?? 0),
                    'level3_per_level2' => (int)($settings['level3_per_level2'] ?? 0),
                    'level1_enabled' => !empty($settings['level1_enabled']),
                    'level2_enabled' => !empty($settings['level2_enabled']),
                    'level3_enabled' => !empty($settings['level3_enabled']),
                    'crowd_enabled' => !empty($settings['crowd_enabled']),
                    'crowd_per_article' => (int)($settings['crowd_per_article'] ?? 0),
                    'price_per_link' => $basePrice,
                    'discount_percent' => $discountPercent,
                    'charged_amount' => $chargedAmount,
                ];
                foreach (['level1_min_len','level1_max_len','level2_min_len','level2_max_len','level3_min_len','level3_max_len'] as $lenKey) {
                    if (array_key_exists($lenKey, $settings)) {
                        $snapshot[$lenKey] = (int)$settings[$lenKey];
                    }
                }
                $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
                if ($snapshotJson === false) { $snapshotJson = '{}'; }

                $status = 'queued';
                $stage = 'pending_level1';
                $progressTotal = max(0, (int)($settings['level1_count'] ?? 0));
                $progressDone = 0;
                $initiator = $initiatedBy > 0 ? $initiatedBy : $ownerId;

                $insertStmt = $conn->prepare('INSERT INTO promotion_runs (project_id, link_id, target_url, status, stage, initiated_by, settings_snapshot, charged_amount, discount_percent, progress_total, progress_done) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                if (!$insertStmt) { break; }
                $chargedParam = $chargedAmount;
                $discountParam = $discountPercent;
                $insertStmt->bind_param(
                    'iisssisddii',
                    $projectId,
                    $linkId,
                    $url,
                    $status,
                    $stage,
                    $initiator,
                    $snapshotJson,
                    $chargedParam,
                    $discountParam,
                    $progressTotal,
                    $progressDone
                );
                if (!$insertStmt->execute()) {
                    $insertStmt->close();
                    break;
                }
                $runId = (int)$conn->insert_id;
                $insertStmt->close();

                $balanceAfter = $balanceBefore;
                if ($chargedAmount > 0) {
                    $balanceAfter = round($balanceBefore - $chargedAmount, 2);
                    $updateBalance = $conn->prepare('UPDATE users SET balance = ? WHERE id = ?');
                    if (!$updateBalance) { break; }
                    $updateBalance->bind_param('di', $balanceAfter, $ownerId);
                    if (!$updateBalance->execute()) {
                        $updateBalance->close();
                        break;
                    }
                    $updateBalance->close();
                    $balanceEvent = pp_balance_record_event($conn, [
                        'user_id' => $ownerId,
                        'delta' => -$chargedAmount,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceAfter,
                        'source' => 'promotion',
                        'meta' => [
                            'project_id' => $projectId,
                            'link_id' => $linkId,
                            'url' => $url,
                            'run_id' => $runId,
                            'price_per_link' => $basePrice,
                            'discount_percent' => $discountPercent,
                            'charged_amount' => $chargedAmount,
                        ],
                    ]);
                    // Award referral commission for spend if enabled
                    if (function_exists('pp_referral_award_for_spend')) {
                        pp_referral_award_for_spend($conn, $ownerId, $chargedAmount, [
                            'source' => 'promotion',
                            'project_id' => $projectId,
                            'run_id' => $runId,
                        ]);
                    }
                }

                $shouldCommit = true;
                $result = [
                    'ok' => true,
                    'run_id' => $runId,
                    'status' => $status,
                    'link_id' => $linkId,
                    'charged' => $chargedAmount,
                    'discount' => $discountPercent,
                    'balance_after' => $balanceAfter,
                    'balance_after_formatted' => format_currency($balanceAfter),
                ];
            } while (false);
        } catch (Throwable $e) {
            pp_promotion_log('promotion.run_start_exception', [
                'project_id' => $projectId,
                'target_url' => $url,
                'error' => $e->getMessage(),
            ]);
            $result = $result['ok'] ? $result : ['ok' => false, 'error' => 'DB'];
        } finally {
            if ($transactionStarted) {
                if ($shouldCommit) {
                    @$conn->commit();
                } else {
                    @$conn->rollback();
                }
            }
            $conn->close();
        }

        if (!empty($result['ok']) && empty($result['already'])) {
            if ($balanceEvent && !empty($balanceEvent['history_id'])) {
                pp_balance_send_event_notification($balanceEvent);
            }
            if ($runId > 0) {
                pp_promotion_launch_worker($runId);
                pp_promotion_log('promotion.run_started', [
                    'project_id' => $projectId,
                    'run_id' => $runId,
                    'link_id' => $linkId,
                    'target_url' => $url,
                    'initiated_by' => $initiator,
                    'charged' => $chargedAmount,
                    'discount_percent' => $discountPercent,
                ]);
            }
        }

        return $result;
    }
}

if (!function_exists('pp_promotion_cancel_run')) {
    function pp_promotion_cancel_run(int $projectId, string $url, int $initiatedBy = 0, ?int $linkId = null): array {
        $projectId = (int)$projectId;
        $url = trim($url);
        if ($projectId <= 0 || $url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'error' => 'BAD_INPUT'];
        }
        $linkId = $linkId !== null ? (int)$linkId : null;
        try { $conn = connect_db(); } catch (Throwable $e) { return ['ok' => false, 'error' => 'DB']; }
        if (!$conn) { return ['ok' => false, 'error' => 'DB']; }

        $transactionStarted = false;
        $shouldCommit = false;
        $result = ['ok' => false, 'error' => 'DB'];
        $runId = 0;

        try {
            $transactionStarted = method_exists($conn, 'begin_transaction')
                ? @$conn->begin_transaction()
                : @$conn->autocommit(false);
            if ($transactionStarted === false) {
                @$conn->query('START TRANSACTION');
                $transactionStarted = true;
            }
            if (!$transactionStarted) {
                return ['ok' => false, 'error' => 'DB'];
            }

            do {
                if ($linkId !== null && $linkId > 0) {
                    $runStmt = $conn->prepare('SELECT pr.id, pr.status, pr.stage, pr.link_id, pr.target_url FROM promotion_runs pr WHERE pr.project_id = ? AND pr.link_id = ? ORDER BY pr.id DESC LIMIT 1 FOR UPDATE');
                    if (!$runStmt) { break; }
                    $runStmt->bind_param('ii', $projectId, $linkId);
                } else {
                    $runStmt = $conn->prepare('SELECT pr.id, pr.status, pr.stage, pr.link_id, pr.target_url FROM promotion_runs pr JOIN project_links l ON l.id = pr.link_id WHERE pr.project_id = ? AND l.url = ? ORDER BY pr.id DESC LIMIT 1 FOR UPDATE');
                    if (!$runStmt) { break; }
                    $runStmt->bind_param('is', $projectId, $url);
                }
                if (!$runStmt->execute()) { $runStmt->close(); break; }
                $runRes = $runStmt->get_result();
                $runRow = $runRes ? $runRes->fetch_assoc() : null;
                if ($runRes) { $runRes->free(); }
                $runStmt->close();
                if (!$runRow) {
                    $result = ['ok' => false, 'error' => 'RUN_NOT_FOUND'];
                    break;
                }
                $runId = (int)($runRow['id'] ?? 0);
                if (!empty($runRow['link_id'])) {
                    $linkId = (int)$runRow['link_id'];
                }
                if (!empty($runRow['target_url'])) {
                    $url = (string)$runRow['target_url'];
                }
                $statusCurrent = (string)($runRow['status'] ?? '');
                $activeStatuses = ['queued','running','pending_level1','level1_active','pending_level2','level2_active','pending_level3','level3_active','pending_crowd','crowd_ready','report_ready'];
                if (!in_array($statusCurrent, $activeStatuses, true)) {
                    $result = ['ok' => true, 'status' => $statusCurrent, 'run_id' => $runId, 'already' => true];
                    break;
                }

                $pubIds = [];
                if ($res = @$conn->query('SELECT publication_id FROM promotion_nodes WHERE run_id=' . $runId . ' AND publication_id IS NOT NULL')) {
                    while ($row = $res->fetch_assoc()) {
                        $pubId = (int)($row['publication_id'] ?? 0);
                        if ($pubId > 0) { $pubIds[$pubId] = true; }
                    }
                    $res->free();
                }
                if (!empty($pubIds)) {
                    $idList = implode(',', array_map('intval', array_keys($pubIds)));
                    @$conn->query('UPDATE publications SET cancel_requested=1 WHERE id IN (' . $idList . ')');
                    @$conn->query("UPDATE publications SET status='cancelled', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP), pid=NULL WHERE id IN (" . $idList . ") AND status IN ('queued','running')");
                    @$conn->query('UPDATE publication_queue SET status=\'cancelled\' WHERE publication_id IN (' . $idList . ')');
                }

                @$conn->query("UPDATE promotion_nodes SET status='cancelled', error=CASE WHEN error IS NULL OR error='' THEN 'CANCELLED_BY_USER' ELSE error END, finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP), updated_at=CURRENT_TIMESTAMP WHERE run_id=" . $runId . " AND status IN ('pending','queued','running')");
                @$conn->query('UPDATE promotion_nodes SET updated_at=CURRENT_TIMESTAMP WHERE run_id=' . $runId);
                @$conn->query("UPDATE promotion_crowd_tasks SET status='cancelled', updated_at=CURRENT_TIMESTAMP WHERE run_id=" . $runId . " AND status IN ('planned','queued','pending','running','created')");

                $updateRun = $conn->prepare("UPDATE promotion_runs SET status='cancelled', stage='cancelled', error='CANCELLED_BY_USER', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP), updated_at=CURRENT_TIMESTAMP WHERE id = ? LIMIT 1");
                if ($updateRun) {
                    $updateRun->bind_param('i', $runId);
                    $updateRun->execute();
                    $updateRun->close();
                }

                pp_promotion_update_progress($conn, $runId);
                $shouldCommit = true;
                $result = ['ok' => true, 'status' => 'cancelled', 'run_id' => $runId, 'link_id' => $linkId ?? 0];
            } while (false);
        } catch (Throwable $e) {
            pp_promotion_log('promotion.run_cancel_exception', [
                'project_id' => $projectId,
                'target_url' => $url,
                'error' => $e->getMessage(),
            ]);
            $result = $result['ok'] ? $result : ['ok' => false, 'error' => 'DB'];
        } finally {
            if ($transactionStarted) {
                if ($shouldCommit) {
                    @$conn->commit();
                } else {
                    @$conn->rollback();
                }
            }
            $conn->close();
        }

        if (!empty($result['ok']) && empty($result['already'])) {
            pp_promotion_log('promotion.run_cancelled', [
                'project_id' => $projectId,
                'run_id' => $runId,
                'target_url' => $url,
                'link_id' => $linkId ?? null,
                'initiated_by' => $initiatedBy > 0 ? $initiatedBy : null,
            ]);
        }

        return $result;
    }
}

if (!function_exists('pp_promotion_build_report')) {
    function pp_promotion_build_report(mysqli $conn, int $runId): array {
        $report = ['level1' => [], 'level2' => [], 'level3' => [], 'crowd' => []];
        if ($res = @$conn->query('SELECT id, parent_id, level, network_slug, result_url, status, anchor_text, target_url FROM promotion_nodes WHERE run_id=' . $runId . ' ORDER BY level ASC, id ASC')) {
            while ($row = $res->fetch_assoc()) {
                $status = (string)($row['status'] ?? '');
                if (!in_array($status, ['success', 'completed'], true)) {
                    continue;
                }
                $entry = [
                    'id' => (int)$row['id'],
                    'parent_id' => isset($row['parent_id']) ? (int)$row['parent_id'] : null,
                    'network' => (string)$row['network_slug'],
                    'url' => (string)$row['result_url'],
                    'status' => $status,
                    'anchor' => (string)$row['anchor_text'],
                    'target_url' => (string)$row['target_url'],
                ];
                if ((int)$row['level'] === 1) { $report['level1'][] = $entry; }
                elseif ((int)$row['level'] === 2) { $report['level2'][] = $entry; }
                elseif ((int)$row['level'] === 3) { $report['level3'][] = $entry; }
            }
            $res->free();
        }
        $crowdLinkCache = [];
        if ($res = @$conn->query('SELECT id, status, crowd_link_id, target_url, result_url, payload_json, updated_at, created_at FROM promotion_crowd_tasks WHERE run_id=' . $runId . ' ORDER BY id ASC')) {
            while ($row = $res->fetch_assoc()) {
                $linkUrl = null;
                $messageBody = null;
                $messageSubject = null;
                $messageAuthor = null;
                $messageEmail = null;
                $manualFallback = false;
                $fallbackReason = null;
                if (!empty($row['payload_json'])) {
                    $payload = json_decode((string)$row['payload_json'], true);
                    if (is_array($payload)) {
                        if (!empty($payload['crowd_link_url'])) {
                            $linkUrl = (string)$payload['crowd_link_url'];
                        }
                        if (!empty($payload['body'])) {
                            $body = trim((string)$payload['body']);
                            if ($body !== '') { $messageBody = $body; }
                        }
                        if (!empty($payload['subject'])) {
                            $messageSubject = trim((string)$payload['subject']);
                        }
                        if (!empty($payload['author_name'])) {
                            $messageAuthor = trim((string)$payload['author_name']);
                        }
                        if (!empty($payload['author_email'])) {
                            $messageEmail = trim((string)$payload['author_email']);
                        }
                        if (array_key_exists('manual_fallback', $payload)) {
                            $manualFallback = !empty($payload['manual_fallback']);
                        }
                        if (array_key_exists('fallback_reason', $payload)) {
                            $reason = trim((string)$payload['fallback_reason']);
                            $fallbackReason = ($reason !== '') ? $reason : null;
                        }
                    }
                }
                $statusRaw = (string)($row['status'] ?? '');
                $statusNormalized = strtolower($statusRaw);
                $allowedStatuses = ['completed', 'success', 'done', 'posted', 'published', 'ok', 'manual'];
                if (!$manualFallback && !in_array($statusNormalized, $allowedStatuses, true)) {
                    continue;
                }
                if ($manualFallback) {
                    continue;
                }
                if ($linkUrl === null && isset($row['crowd_link_id'])) {
                    $cid = (int)$row['crowd_link_id'];
                    if ($cid > 0) {
                        if (isset($crowdLinkCache[$cid])) {
                            $linkUrl = $crowdLinkCache[$cid];
                        } else {
                            if ($resLink = @$conn->query('SELECT url FROM crowd_links WHERE id=' . $cid . ' LIMIT 1')) {
                                if ($rowLink = $resLink->fetch_assoc()) {
                                    $linkUrl = (string)($rowLink['url'] ?? '');
                                }
                                $resLink->free();
                            }
                            $crowdLinkCache[$cid] = $linkUrl;
                        }
                    }
                }
                $report['crowd'][] = [
                    'task_id' => isset($row['id']) ? (int)$row['id'] : null,
                    'crowd_link_id' => (int)$row['crowd_link_id'],
                    'link_url' => $linkUrl ? (string)$linkUrl : null,
                    'crowd_url' => $linkUrl ? (string)$linkUrl : null,
                    'target_url' => (string)$row['target_url'],
                    'article_url' => (string)$row['target_url'],
                    'message' => $messageBody,
                    'subject' => $messageSubject,
                    'author_name' => $messageAuthor,
                    'author_email' => $messageEmail,
                    'manual_fallback' => $manualFallback,
                    'fallback_reason' => null,
                    'status' => $statusRaw,
                    'result_url' => isset($row['result_url']) && $row['result_url'] !== null ? (string)$row['result_url'] : null,
                    'updated_at' => isset($row['updated_at']) ? (string)$row['updated_at'] : null,
                    'created_at' => isset($row['created_at']) ? (string)$row['created_at'] : null,
                ];
            }
            $res->free();
        }
        return $report;
    }
}

if (!function_exists('pp_promotion_get_report')) {
    function pp_promotion_get_report(int $runId): array {
        try { $conn = @connect_db(); } catch (Throwable $e) { return ['ok' => false, 'error' => 'DB']; }
        if (!$conn) { return ['ok' => false, 'error' => 'DB']; }
        $stmt = $conn->prepare('SELECT project_id, target_url, status, report_json FROM promotion_runs WHERE id = ? LIMIT 1');
        if (!$stmt) { $conn->close(); return ['ok' => false, 'error' => 'DB']; }
        $stmt->bind_param('i', $runId);
        if (!$stmt->execute()) { $stmt->close(); $conn->close(); return ['ok' => false, 'error' => 'DB']; }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) { $conn->close(); return ['ok' => false, 'error' => 'NOT_FOUND']; }
        $report = [];
        if (!empty($row['report_json'])) {
            $decoded = json_decode((string)$row['report_json'], true);
            if (is_array($decoded)) { $report = $decoded; }
        }
        if (empty($report)) {
            $report = pp_promotion_build_report($conn, $runId);
        }
        $conn->close();
        return [
            'ok' => true,
            'status' => (string)$row['status'],
            'project_id' => (int)$row['project_id'],
            'target_url' => (string)$row['target_url'],
            'report' => $report,
            'levels_enabled' => [
                'level1' => pp_promotion_is_level_enabled(1),
                'level2' => pp_promotion_is_level_enabled(2),
                'level3' => pp_promotion_is_level_enabled(3),
                'crowd' => pp_promotion_is_crowd_enabled(),
            ],
        ];
    }
}

?>
