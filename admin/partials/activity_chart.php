<?php
// Admin 30-day activity chart (users activity, spend, registrations, partner registrations, payments)
try { $conn = connect_db(); } catch (Throwable $e) { $conn = null; }
$days = 30;
$labels = [];
$today = new DateTime('today');
for ($i = $days-1; $i >= 0; $i--) { $d = clone $today; $d->modify("-$i day"); $labels[] = $d->format('Y-m-d'); }
$regs = array_fill(0, $days, 0);
$partnerRegs = array_fill(0, $days, 0);
$activeUsers = array_fill(0, $days, 0);
$payments = array_fill(0, $days, 0.0);
$spend = array_fill(0, $days, 0.0);
if ($conn) {
    $dateFrom = $labels[0] . ' 00:00:00';
    // registrations
    if ($res = $conn->query("SELECT DATE(created_at) d, COUNT(*) c FROM users WHERE created_at >= '" . $conn->real_escape_string($dateFrom) . "' GROUP BY d")) {
        while ($row = $res->fetch_assoc()) { $idx = array_search($row['d'], $labels, true); if ($idx !== false) { $regs[$idx] = (int)$row['c']; } }
        $res->free();
    }
    // partner registrations
    if ($res = $conn->query("SELECT DATE(created_at) d, COUNT(*) c FROM users WHERE created_at >= '" . $conn->real_escape_string($dateFrom) . "' AND referred_by IS NOT NULL AND referred_by > 0 GROUP BY d")) {
        while ($row = $res->fetch_assoc()) { $idx = array_search($row['d'], $labels, true); if ($idx !== false) { $partnerRegs[$idx] = (int)$row['c']; } }
        $res->free();
    }
    // active users by any balance event
    if ($res = $conn->query("SELECT DATE(created_at) d, COUNT(DISTINCT user_id) c FROM balance_history WHERE created_at >= '" . $conn->real_escape_string($dateFrom) . "' GROUP BY d")) {
        while ($row = $res->fetch_assoc()) { $idx = array_search($row['d'], $labels, true); if ($idx !== false) { $activeUsers[$idx] = (int)$row['c']; } }
        $res->free();
    }
    // payments sum (incoming)
    if ($res = $conn->query("SELECT DATE(created_at) d, SUM(delta) s FROM balance_history WHERE created_at >= '" . $conn->real_escape_string($dateFrom) . "' AND source='payment' AND delta > 0 GROUP BY d")) {
        while ($row = $res->fetch_assoc()) { $idx = array_search($row['d'], $labels, true); if ($idx !== false) { $payments[$idx] = (float)$row['s']; } }
        $res->free();
    }
    // spending sum (promotion charges)
    if ($res = $conn->query("SELECT DATE(created_at) d, SUM(-delta) s FROM balance_history WHERE created_at >= '" . $conn->real_escape_string($dateFrom) . "' AND source='promotion' AND delta < 0 GROUP BY d")) {
        while ($row = $res->fetch_assoc()) { $idx = array_search($row['d'], $labels, true); if ($idx !== false) { $spend[$idx] = (float)$row['s']; } }
        $res->free();
    }
}
$maxCount = max(1,
  (int)max($regs ?: [0]),
  (int)max($partnerRegs ?: [0]),
  (int)max($activeUsers ?: [0])
);
$maxAmount = max(1.0,
  (float)max($payments ?: [0]),
  (float)max($spend ?: [0])
);
$w = 980; $h = 220; $padL = 46; $padR = 46; $padT = 20; $padB = 24; $plotW = $w - $padL - $padR; $plotH = $h - $padT - $padB;
$sx = $days > 1 ? $plotW / ($days - 1) : $plotW;
$mapCount = function($i, $v) use ($padL,$padT,$plotH,$maxCount,$sx){ $x = $padL + $i*$sx; $y = $padT + ($maxCount <= 0 ? $plotH : ($plotH - (($v / max(1,$maxCount)) * $plotH))); return [$x,$y]; };
$mapAmount = function($i, $v) use ($padL,$padT,$plotH,$maxAmount,$sx){ $x = $padL + $i*$sx; $y = $padT + ($maxAmount <= 0 ? $plotH : ($plotH - (($v / max(0.00001,$maxAmount)) * $plotH))); return [$x,$y]; };
$buildPath = function($arr, $mapper) use ($days){ $d=''; for($i=0;$i<$days;$i++){ [$x,$y]=$mapper($i,$arr[$i]); $d.=($i===0?'M':'L').$x.' '.$y.' '; } return trim($d); };
$pathActive = $buildPath($activeUsers, $mapCount);
$pathRegs = $buildPath($regs, $mapCount);
$pathPartner = $buildPath($partnerRegs, $mapCount);
$pathSpend = $buildPath($spend, $mapAmount);
$pathPayments = $buildPath($payments, $mapAmount);
?>
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h2 class="h6 mb-0"><?php echo __('Активность за 30 дней'); ?></h2>
    <div class="small text-muted"><?php echo __('Слева: количество, справа: суммы'); ?></div>
  </div>
  <div class="card-body">
    <svg width="100%" viewBox="0 0 <?php echo $w; ?> <?php echo $h; ?>">
      <rect x="0" y="0" width="<?php echo $w; ?>" height="<?php echo $h; ?>" fill="none" />
      <g stroke="#6c757d" stroke-width="1" opacity="0.25">
        <line x1="<?php echo $padL; ?>" y1="<?php echo $padT; ?>" x2="<?php echo $w-$padR; ?>" y2="<?php echo $padT; ?>" />
        <line x1="<?php echo $padL; ?>" y1="<?php echo $h-$padB; ?>" x2="<?php echo $w-$padR; ?>" y2="<?php echo $h-$padB; ?>" />
      </g>
      <!-- Count series (left axis) -->
      <path d="<?php echo $pathActive; ?>" fill="none" stroke="#0d6efd" stroke-width="2" />
      <path d="<?php echo $pathRegs; ?>" fill="none" stroke="#6f42c1" stroke-width="2" />
      <path d="<?php echo $pathPartner; ?>" fill="none" stroke="#20c997" stroke-width="2" />
      <!-- Amount series (right axis) -->
      <path d="<?php echo $pathSpend; ?>" fill="none" stroke="#dc3545" stroke-width="2" />
      <path d="<?php echo $pathPayments; ?>" fill="none" stroke="#fd7e14" stroke-width="2" />
      <g font-size="10" fill="#6c757d">
        <?php foreach ($labels as $i => $lab): $x = $padL + $i*$sx; ?>
          <?php if ($i % 5 === 0 || $i === count($labels)-1): ?>
            <text x="<?php echo $x; ?>" y="<?php echo $h-6; ?>" text-anchor="middle"><?php echo htmlspecialchars(substr($lab,5)); ?></text>
          <?php endif; ?>
        <?php endforeach; ?>
        <text x="6" y="<?php echo $padT+12; ?>" text-anchor="start"><?php echo (int)$maxCount; ?></text>
        <text x="6" y="<?php echo $h-$padB; ?>" text-anchor="start">0</text>
        <text x="<?php echo $w-4; ?>" y="<?php echo $padT+12; ?>" text-anchor="end"><?php echo number_format($maxAmount, 0); ?></text>
        <text x="<?php echo $w-4; ?>" y="<?php echo $h-$padB; ?>" text-anchor="end">0</text>
      </g>
      <g font-size="11">
        <text x="<?php echo $padL; ?>" y="14" fill="#0d6efd"><?php echo __('Активные пользователи'); ?></text>
        <text x="<?php echo $padL+155; ?>" y="14" fill="#6f42c1"><?php echo __('Регистрации'); ?></text>
        <text x="<?php echo $padL+255; ?>" y="14" fill="#20c997"><?php echo __('Партнёрские рег.'); ?></text>
        <text x="<?php echo $padL+395; ?>" y="14" fill="#dc3545"><?php echo __('Траты'); ?></text>
        <text x="<?php echo $padL+465; ?>" y="14" fill="#fd7e14"><?php echo __('Пополнения'); ?></text>
      </g>
    </svg>
  </div>
</div>
