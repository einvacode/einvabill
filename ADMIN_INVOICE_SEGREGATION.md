# Admin Manual Invoice Segregation - Implementation Complete

## Summary
Tagihan yang dibuat dari `admin_create_invoice` sekarang **terpisah sepenuhnya** dari sistem billing normal. Mereka tidak akan:
- ❌ Ikut dalam pengiriman masal (broadcast)
- ❌ Tampil di data pelanggan (customer list)
- ❌ Tampil di data kemitraan (partner data)
- ❌ Tampil di customer portal

## How It Works

### Identification
Invoices created from `admin_create_invoice` are marked with:
```
created_via = 'admin_manual'
```

### Segregation Points

**1. Invoice Creation** (`views/admin/create_invoice.php`)
- Changed from `created_via = 'quick'` → `created_via = 'admin_manual'`
- Ensures all invoices from this page are properly flagged

**2. Broadcast Filter** (`views/components/wa_broadcast.php`)
- Query excludes: `created_via NOT IN ('admin_manual', 'quick', 'external')`
- Admin manual invoices won't appear in batch send reminders

**3. Invoice List** (`views/admin/invoices.php`)
- Added filter: `$admin_manual_where` variable
- Applied to both count and main invoice queries
- Both grouped and ungrouped views respect filter

**4. Customer Portal** (`views/customer_portal.php`)
- Added filter to invoice query
- Customers won't see admin manual invoices for their account

## Files Modified

| File | Changes | Line(s) |
|------|---------|---------|
| `views/admin/create_invoice.php` | Changed created_via to 'admin_manual' | 81 |
| `views/components/wa_broadcast.php` | Added filter to exclude admin_manual | 13-28 |
| `views/admin/invoices.php` | Added $admin_manual_where filter, applied to 3 queries | 517, 698-703, 707-735 |
| `views/customer_portal.php` | Added filter to invoice query | 32-36 |

## Filter Logic

All queries use this pattern:
```sql
AND (i.created_via IS NULL OR i.created_via NOT IN ('admin_manual', 'quick', 'external'))
```

This:
- ✅ Includes invoices with `created_via = NULL` (older invoices before this feature)
- ❌ Excludes invoices with `created_via IN ('admin_manual', 'quick', 'external')`
- Ensures backward compatibility with existing invoices

## Behavior Changes

### Before
- Admin manual invoices appeared in broadcast list ❌
- Appeared in customer list ❌
- Customers could see them in portal ❌
- Could be sent in bulk WA ❌

### After
- Admin manual invoices **hidden from broadcast** ✅
- **Not shown in customer lists** ✅
- **Not visible in customer portal** ✅
- **Can only be viewed/managed in admin_assets page** ✅

## User Impact

### Admin/Partner
- Regular workflow unchanged
- Can still create invoices via `admin_create_invoice`
- Invoices are still functional (can be paid, printed, etc.)
- Just not included in bulk operations

### Customers
- Won't see admin manual invoices in portal
- Won't receive WA reminders about them
- Only see regular billing invoices

### Partners
- Don't see admin manual invoices in their list
- Can't include them in their broadcasts
- Completely separated from partner billing

## Testing Checklist

✅ Create invoice via admin_create_invoice page
✅ Verify it has `created_via = 'admin_manual'` in database
✅ Invoice should NOT appear in Daftar Tagihan (invoice list)
✅ Invoice should NOT appear in broadcast antrean
✅ Customer checking portal should NOT see it
✅ Regular customer invoices still work normally
✅ Broadcast includes regular invoices, excludes admin manual

## SQL Query to Verify

```sql
-- Check admin_manual invoices
SELECT id, customer_id, amount, created_via, created_at 
FROM invoices 
WHERE created_via = 'admin_manual'
LIMIT 10;

-- Check that regular invoices still work
SELECT COUNT(*) FROM invoices 
WHERE status = 'Belum Lunas' 
AND (created_via IS NULL OR created_via NOT IN ('admin_manual', 'quick', 'external'));
```

## Rollback Instructions (if needed)

If this feature needs to be reversed:

1. Change line 81 in `views/admin/create_invoice.php` from `'admin_manual'` back to `'quick'`
2. Remove `$admin_manual_where` filter variable from `views/admin/invoices.php` line 517
3. Remove `$admin_manual_where` from all 3 queries in `views/admin/invoices.php`
4. Remove filter from `views/components/wa_broadcast.php` line 23
5. Remove filter from `views/customer_portal.php` line 34

## Technical Notes

### Why `created_via` Works
- Column already exists in invoices table
- Originally used for 'quick', 'external' invoices
- Now expanded to include 'admin_manual'
- No database migration needed

### Performance
- No impact - using existing indexed column
- Filter is simple AND clause
- No additional queries needed

### Multi-Tenancy
- All queries respect tenant_id
- Each tenant's admin_manual invoices stay separated
- No cross-tenant data leakage

### Backward Compatibility
- NULL checks ensure old invoices (before this feature) still show up
- Existing invoice functionality unchanged
- Only visibility in lists is affected

## Status: ✅ READY FOR PRODUCTION

All changes deployed. No database migration required. Segregation is immediate upon deployment.

---

Implementation by Copilot - EinvaBill Project
Date: 2026-08-11
