<?php
/**
 * Item suggestions for external invoice forms.
 *
 * Sets $item_suggestions = [ ['description' => ..., 'unit_price' => ...], ... ]
 * from every line item previously saved on this tenant's invoices (most
 * recent first, one entry per distinct description) and prints the
 * <datalist id="itemSuggestions"> the description inputs attach to.
 * The unit price map is exposed as window.ITEM_SUGGESTIONS for the page JS.
 */
$item_suggestions = [];
try {
    $sug_tenant = intval($_SESSION['tenant_id'] ?? 1);
    $sug_stmt = $db->prepare("SELECT ii.description, ii.unit_price, ii.qty, ii.amount, MAX(ii.id) AS last_id
        FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id
        WHERE i.tenant_id = ? AND ii.description IS NOT NULL AND TRIM(ii.description) <> ''
        GROUP BY ii.description ORDER BY last_id DESC LIMIT 300");
    $sug_stmt->execute([$sug_tenant]);
    foreach ($sug_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $unit = floatval($row['unit_price'] ?? 0);
        $qty = intval($row['qty'] ?? 1);
        if ($unit <= 0) $unit = $qty > 0 ? round(floatval($row['amount'] ?? 0) / $qty) : floatval($row['amount'] ?? 0);
        $item_suggestions[] = ['description' => trim($row['description']), 'unit_price' => $unit];
    }
} catch (Exception $e) { $item_suggestions = []; }
?>
<datalist id="itemSuggestions">
    <?php foreach ($item_suggestions as $s): ?>
    <option value="<?= htmlspecialchars($s['description']) ?>"><?= $s['unit_price'] > 0 ? 'Rp ' . number_format($s['unit_price'], 0, ',', '.') : '' ?></option>
    <?php endforeach; ?>
</datalist>
<script>
window.ITEM_SUGGESTIONS = <?= json_encode(array_column($item_suggestions, 'unit_price', 'description')) ?>;
// When a description matches a saved item, pre-fill an empty unit price with its last price.
window.applyItemSuggestion = function (descInput, recalc) {
    const map = window.ITEM_SUGGESTIONS || {};
    const key = (descInput.value || '').trim();
    if (!(key in map)) return;
    const tr = descInput.closest('tr'); if (!tr) return;
    const unit = tr.querySelector('input[name="item_unit[]"]');
    if (unit && !(parseFloat(unit.value) > 0)) {
        unit.value = map[key];
        if (typeof recalc === 'function') recalc(unit);
    }
};
</script>
