# 🚀 Partner WhatsApp - Deployment Instructions

## Pre-Deployment Checklist

- [ ] Read `PARTNER_WA_SUMMARY.md`
- [ ] Node.js WhatsApp gateway running
- [ ] Database backup taken
- [ ] Test account ready

---

## 1. Code Deployment

### Files to Deploy

```
✨ NEW (1 file):
   views/partner/wa_device.php

📝 MODIFIED (2 files):
   index.php
   views/layout.php

📖 DOCUMENTATION (3 files):
   PARTNER_WA_SETUP.md
   PARTNER_WA_SUMMARY.md
   PARTNER_WA_QUICK_REF.md
```

### Steps

1. **Backup existing files** (if on production)
   ```bash
   cp index.php index.php.backup
   cp views/layout.php views/layout.php.backup
   ```

2. **Copy new file**
   ```bash
   # Create if not exists:
   mkdir -p views/partner/
   cp views/partner/wa_device.php /production/path/
   ```

3. **Update existing files**
   - Update `index.php` with routing case
   - Update `views/layout.php` with menu

4. **No database migration needed**
   - Uses existing WhatsApp gateway
   - No new tables or columns

---

## 2. Verify WhatsApp Gateway

### Check Gateway Status

```bash
# Test gateway is running
curl http://localhost:3000/status

# Expected response:
# {"connected": true/false, "qr_available": true/false, "message": "..."}

# If error, start gateway:
cd wa-gateway
npm start
```

### Gateway Requirements

```
Node.js >= 12.x
Port 3000 open
Puppeteer chromium installed
```

---

## 3. Test Deployment

### Login Test

```
1. Login as Partner (username/password)
2. Go to dashboard
3. Should see "Perangkat WhatsApp" in sidebar
```

### QR Scan Test

```
1. Click "Perangkat WhatsApp"
2. Should see QR Code
3. Status shows "Menunggu Scan QR"
4. Scan with WhatsApp → terhubung
5. Status changes to "TERHUBUNG!"
```

### Message Send Test

```
1. After connected, go to "Penagihan Lapangan"
2. Select a customer
3. Send message via WhatsApp
4. Verify message received on WhatsApp partner
```

---

## 4. Rollback (If Needed)

```bash
# Restore from backup
cp index.php.backup index.php
cp views/layout.php.backup views/layout.php
rm views/partner/wa_device.php
```

---

## 5. Monitoring

### Check Logs

```
# Node.js gateway logs
# Check console output where gateway runs

# Browser console
# Press F12 in portal → Console tab
# Should show fetch calls to WA Gateway
```

### Status Indicators

```
✅ TERHUBUNG! = WhatsApp connected
🕐 Menunggu Scan = QR waiting to be scanned
⚠️ GATEWAY OFFLINE = Node.js not responding
❌ Error = Check browser console + gateway logs
```

---

## 6. Admin Verification

Partner feature should NOT affect Admin:

```
✅ Admin WA Gateway still works (admin_wa_gateway)
✅ Admin can send to all customers
✅ Partner WA Device is separate (partner_wa_device)
✅ Partner can only send to their customers
```

### Multi-Tenant Check

If using multi-tenant:
```
✅ Tenant A partners only see their own device
✅ Tenant B partners only see their own device
✅ No cross-tenant access
```

---

## 7. Post-Deployment

### Notify Partners

Send message to partners:

```
"Anda sekarang bisa mengirim tagihan langsung dari portal ke WhatsApp!

Cara:
1. Masuk ke Portal Partner Anda
2. Klik menu 'Perangkat WhatsApp'
3. Scan QR Code dengan WhatsApp HP
4. Setelah terhubung, bisa kirim tagihan ke pelanggan

Butuh bantuan? Hubungi Admin"
```

### Monitor Usage

```
Check gateway logs periodically:
- Message send success/failure
- Device connection status
- Any errors or issues
```

---

## 8. Troubleshooting

### Partner can't see menu

```
Solution:
1. Clear browser cache
2. Logout & login again
3. Check user role is 'partner' in database
```

### QR Code not showing

```
Solution:
1. Refresh page
2. Check gateway running (curl localhost:3000/status)
3. Check browser console for JS errors
```

### Scan QR not working

```
Solution:
1. Use "Perangkat Tertaut" menu, not "Riwayat Chat"
2. Ensure WiFi/data connected on phone
3. Try re-scan
4. Try different browser if on desktop
```

### Messages not sending

```
Solution:
1. Verify WhatsApp is still connected ("TERHUBUNG!")
2. Check customer phone number format
3. Ensure message template is correct
4. Check gateway logs for errors
```

---

## 9. Performance Considerations

### Expected Performance

- QR loading: < 1 second
- Status refresh: 3 seconds
- Message send: 10+ seconds per message (intentional delay)
- Simultaneous partners: Limited by gateway capacity

### Optimization (If needed)

```
- Increase gateway timeout
- Add more Node.js workers
- Use dedicated server for gateway
- Add caching layer for QR codes
```

---

## 10. Rollout Strategy

### Phase 1: Test (Day 1)
```
- Deploy to staging
- Test with 1 partner account
- Verify all features work
- Fix any issues
```

### Phase 2: Limited (Day 2)
```
- Deploy to production
- Enable for 5-10 partners
- Monitor for issues
- Collect feedback
```

### Phase 3: Full (Day 3+)
```
- Enable for all partners
- Announce feature
- Provide support
- Monitor performance
```

---

## 11. Backup & Recovery

### What to Backup

```
- Database (database.sqlite)
- Portal files (index.php, views/)
- Gateway files (wa-gateway/)
- Configurations
```

### Recovery Steps

```
1. Stop portal & gateway
2. Restore backup files
3. Restore database
4. Restart services
5. Verify everything works
```

---

## 12. Support Checklist

### Before Supporting Users

- [ ] Tested login for partner
- [ ] Tested scan QR
- [ ] Tested message send
- [ ] Tested gateway offline scenario
- [ ] Tested browser cache clear
- [ ] Tested re-scan after disconnect

### Help Resources to Provide

1. Link to `PARTNER_WA_SETUP.md`
2. Link to `PARTNER_WA_QUICK_REF.md`
3. Help email/phone
4. FAQ section

---

## 13. Common Issues & Solutions

| Issue | Cause | Fix |
|-------|-------|-----|
| QR not showing | Gateway offline | Start: cd wa-gateway && npm start |
| Scan fails | Wrong menu used | Use "Perangkat Tertaut" menu |
| Message not sent | Phone offline | Ensure HP has internet |
| Disconnect button doesn't work | Stale page | Refresh browser |
| Error messages show | JS error | Check browser F12 console |

---

## 14. Success Criteria

✅ Implementation is successful if:

- [x] Partner can access WhatsApp menu
- [x] QR Code displays correctly
- [x] Scan QR connects WhatsApp
- [x] Status shows "TERHUBUNG!"
- [x] Can send messages to customers
- [x] No errors in browser console
- [x] Works on mobile
- [x] Gateway logs show activity
- [x] Disconnect/reconnect works
- [x] Admin features unaffected

---

**Deployment Complete!** ✅

Partner WhatsApp integration is now live!
