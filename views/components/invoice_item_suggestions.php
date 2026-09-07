<?php
/**
 * Item suggestions for external invoice forms, sourced from the saved-item
 * catalog (managed on the "Item tersimpan" tab of the external invoice page).
 *
 * Prints the <datalist id="itemSuggestions"> the description inputs attach
 * to and exposes description => unit price as window.ITEM_SUGGESTIONS.
 */
$item_suggestions = invoice_catalog_list($db, intval($_SESSION['tenant_id'] ?? 1));
?>
<datalist id="itemSuggestions">
    <?php foreach ($item_suggestions as $s): ?>
    <option value="<?= htmlspecialchars($s['description']) ?>"><?= $s['unit_price'] > 0 ? 'Rp ' . number_format($s['unit_price'], 0, ',', '.') : '' ?></option>
    <?php endforeach; ?>
</datalist>
<script>
window.ITEM_SUGGESTIONS = <?= json_encode(array_column(array_map(fn($s) => ['d' => $s['description'], 'p' => floatval($s['unit_price'])], $item_suggestions), 'p', 'd')) ?>;
// When a description matches a saved item, pre-fill an empty unit price with its price.
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
