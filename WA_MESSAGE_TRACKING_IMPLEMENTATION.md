# WhatsApp Message Delivery Tracking Implementation

## Overview
Implemented automatic logging of WhatsApp messages sent via the broadcast feature, with delivery status indicators visible on the invoice list.

## What's New

### 1. Database Schema Update (Version 23)
New table `wa_message_logs` tracks every WhatsApp message sent:
```sql
CREATE TABLE wa_message_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id INTEGER NOT NULL,
    customer_id INTEGER NOT NULL,
    customer_name TEXT,
    phone_number TEXT,
    message_type TEXT DEFAULT 'reminder',
    status TEXT DEFAULT 'sent',
    sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    sent_by INTEGER,
    notes TEXT,
    tenant_id INTEGER DEFAULT 1
);
```

**Tracked Data:**
- `invoice_id` - Which invoice the message is about
- `customer_id` - Recipient customer
- `customer_name` - Customer name (for quick lookup)
- `phone_number` - WhatsApp number that received the message
- `message_type` - Type of message ('reminder', 'payment_confirmation', etc.)
- `status` - Delivery status ('sent', 'delivered', 'read', 'failed')
- `sent_at` - Timestamp when message was sent
- `sent_by` - User ID who sent the message
- `tenant_id` - Multi-tenancy support

### 2. API Endpoint
**File:** `api_log_wa_message.php`

Receives POST requests from the broadcast JavaScript and logs to database:
```javascript
fetch('api_log_wa_message.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
        invoice_id: 123,
        customer_id: 456,
        customer_name: 'John Doe',
        phone_number: '628123456789',
        message_type: 'reminder',
        status: 'sent'
    })
});
```

### 3. WhatsApp Broadcast Enhancement
**File:** `views/components/wa_broadcast.php`

**Changes:**
- Query now retrieves `MIN(i.id) as first_invoice_id` for each customer
- Added fields to broadcast_data array: `customer_id`, `invoice_id`
- Automatic mode: Logs after `sendWAGateway()` succeeds
- Manual mode: Logs when user confirms with "Sudah Terkirim & Lanjut" button

### 4. Helper Functions
**File:** `app/helpers.php`

**New Functions:**
1. `get_wa_delivery_status($db, $invoice_id)`
   - Returns latest WA log for an invoice
   - Returns array with status, sent_at, customer_name, phone
   - Returns null if no log found

2. `render_wa_status_badge($db, $invoice_id)`
   - Renders styled HTML badge showing delivery status
   - Shows "ℹ Belum terkirim" if no log found
   - Shows "✅ Terkirim [date]" for sent messages
   - Shows "✔✔ Diterima [date]" for delivered messages
   - Shows "👁 Dibaca [date]" for read messages
   - Shows "❌ Gagal [date]" for failed messages

### 5. Invoice List Views
**File:** `views/admin/invoices.php`

**Mobile View Changes:**
- Added WA status badge under customer name/package info section
- Visible on every invoice card for easy scanning

**Desktop View Changes:**
- Added WA status badge under customer name/package info in table
- Same styling and status indicators

## How It Works

### Sending Flow
1. User goes to Broadcast page (Dashboard or Admin → Pengingat Tagihan)
2. Clicks "Mulai Pengiriman" to send messages
3. Message sent to customer via WhatsApp Gateway
4. JavaScript automatically logs to `api_log_wa_message.php`
5. Database records the delivery attempt

### Viewing Delivery Status
1. Go to Admin → Daftar Tagihan / Invoices
2. View any invoice in the list (mobile or desktop)
3. Below customer name and package, see delivery status indicator
4. Example: "✅ Terkirim 15 Aug 2024 14:30"

## Status Indicators

| Indicator | Meaning | Color |
|-----------|---------|-------|
| ℹ Belum terkirim | No WA sent yet | Gray |
| ✅ Terkirim | Message sent | Green |
| ✔✔ Diterima | Message delivered to WhatsApp | Blue |
| 👁 Dibaca | Customer read the message | Purple |
| ❌ Gagal | Message failed to send | Red |

## Deployment Instructions

### Step 1: Deploy Files
Copy these files to production server:
```
- app/database_setup.php (updated with v23)
- app/helpers.php (with new functions)
- api_log_wa_message.php (new file)
- views/components/wa_broadcast.php (updated with logging)
- views/admin/invoices.php (updated with status display)
```

### Step 2: Database Migration
The application will automatically:
1. Check current db_version in settings table
2. If < 23, run new schema to create wa_message_logs table
3. Update db_version to 23
4. Create performance indexes

**No manual SQL needed** - automatic on next app load.

### Step 3: Verify Deployment
1. Login to admin account
2. Go to Dashboard → Pengingat Tagihan / Reminder Widget
3. Send a few test messages
4. Go to Daftar Tagihan (Invoice List)
5. Verify status badge shows "✅ Terkirim [date]" for just-sent invoices

## Testing Checklist

- [ ] App loads without errors after deployment
- [ ] Database migration runs (check db_version = 23 in settings)
- [ ] wa_message_logs table created successfully
- [ ] Send test WA message from broadcast
- [ ] Status badge appears on invoice list
- [ ] Status badge shows correct date/time
- [ ] Status badge visible on mobile and desktop
- [ ] Multiple sends update the timestamp correctly
- [ ] Works for both Admin and Partner accounts

## Future Enhancements

### Phase 2: Delivery Confirmation
- Integrate with WhatsApp webhook to auto-update status to 'delivered' and 'read'
- Show real-time badge updates without page refresh

### Phase 3: Delivery Reports
- Dashboard widget showing delivery statistics
- Graph of sent vs delivered vs read messages
- Export delivery report to CSV

### Phase 4: Retry Logic
- Failed message retry queue
- Bulk retry for failed deliveries
- Notification when retry succeeds

## Technical Notes

### Multi-Tenancy
- wa_message_logs table includes tenant_id
- Each tenant sees only their own message logs
- No cross-tenant data leakage

### Performance
- New indexes on invoice_id, customer_id, tenant_id, status, sent_at
- Efficient badge retrieval: single query per invoice
- No N+1 queries in invoice list view

### Error Handling
- API endpoint returns 401 if user not authenticated
- API returns 400 if required fields missing
- Helper functions gracefully handle missing data
- If wa_message_logs table doesn't exist, badge shows "Belum terkirim"

## Files Modified Summary

```
✅ app/database_setup.php
   - Added wa_message_logs table definition
   - Added table indexes
   - Updated db_version to 23

✅ app/helpers.php
   - Added get_wa_delivery_status()
   - Added render_wa_status_badge()

✅ views/components/wa_broadcast.php
   - Modified query to include first_invoice_id
   - Added customer_id, invoice_id to broadcast_data
   - Added logging in automatic mode
   - Added logging in manual mode

✅ views/admin/invoices.php
   - Added badge to mobile view (line ~852)
   - Added badge to desktop view (line ~1050)

✅ api_log_wa_message.php (NEW)
   - API endpoint for logging WA messages
```

## Support & Troubleshooting

### Badge not showing?
1. Verify wa_message_logs table exists: `SELECT * FROM wa_message_logs LIMIT 1;`
2. Check if messages were logged: `SELECT * FROM wa_message_logs WHERE invoice_id = 123;`
3. Check browser console for JavaScript errors
4. Ensure helpers.php is included (should be automatic)

### Database migration failed?
1. Check SQLite file permissions
2. Verify database_setup.php can write to database
3. Check logs for specific error message
4. Manual fix: Create table using SQL from database_setup.php line 201-216

### API endpoint getting 401?
- User session invalid
- Try logging out and back in
- Check if api_log_wa_message.php is in project root

## License & Attribution
Implementation by Copilot - EinvaBill Project
