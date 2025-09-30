<?php
// Networks section partial: HTML + JS
?>
<div id="networks-section" style="display:none;">
    <h3><?php echo __('Сети публикации'); ?></h3>
    <?php if ($networksMsg): ?>
        <div class="alert alert-info fade-in"><?php echo htmlspecialchars($networksMsg); ?></div>
    <?php endif; ?>
    <?php /* The following block is copied from admin.php, unchanged to preserve behavior. */ ?>
    <?php /* BEGIN networks form and table */ ?>
    <?php /* Variables used: $nodeBinaryStored, $puppeteerExecStored, $puppeteerArgsStored, $networkDefaultPriority, $networkDefaultLevelsList, $networks, $normalizeFilterToken */ ?>
    <?php include __DIR__ . '/networks_section_content.php'; ?>
</div>
