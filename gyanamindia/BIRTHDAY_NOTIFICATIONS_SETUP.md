# Birthday Notifications Setup Guide

## Overview
The birthday notification system allows admin to add birthdays and automatically sends notifications to all users on the birthday date.

## Database Setup

1. Run the SQL file to create the birthdays table:
```bash
mysql -u u587292075_gyanam_db -p u587292075_gyanam_db_1 < create_birthdays_table.sql
```

Or execute the SQL directly in phpMyAdmin or your database client.

## Features

### Admin Panel
- **Location**: Admin → Birthdays (`admin/birthdays.php`)
- **Add Birthday**: Click "Add Birthday" button
- **Fields**:
  - Person Name (required)
  - Mobile Number (optional)
  - Birth Date (required)
  - Description/Message (optional)
  - Status (Active/Inactive)

### Automatic Notifications
Notifications are created automatically in two ways:

#### 1. On Dashboard Load (Recommended for small deployments)
- When any user logs in and visits their dashboard
- The system checks if today has any birthdays
- Creates notifications for all active users
- Only creates once per day

#### 2. Via Cron Job (Recommended for production)
For better performance and reliability, set up a daily cron job:

```bash
# Edit crontab
crontab -e

# Add this line to run daily at 6 AM
0 6 * * * php /path/to/Gyanam/cron/birthday_notifications.php
```

Replace `/path/to/Gyanam/` with your actual installation path.

### Notification Format
When a birthday occurs, all users receive a notification like:

```
🎉 Birthday Today!
🎂 Happy Birthday to John Doe! Turning 25 today. Contact: 9876543210. Wishing you a wonderful year ahead!
```

## Usage Instructions

### For Admin:
1. Go to Admin Panel → Birthdays
2. Click "Add Birthday"
3. Fill in the person's details
4. Set status to "Active"
5. Save

### For All Users:
- Birthday notifications appear automatically in the notification bell
- Click the bell icon to view birthday messages
- Notifications include:
  - Person's name
  - Age (calculated automatically)
  - Mobile number (if provided)
  - Custom message (if provided)

## Features

✅ Add/Edit/Delete birthdays
✅ Filter by status (Active/Inactive)
✅ Filter by month
✅ View today's birthdays count
✅ View this month's birthdays count
✅ Automatic age calculation
✅ Highlight today's birthdays in yellow
✅ Automatic notification creation
✅ Notifications sent to all users
✅ One notification per birthday per day

## Troubleshooting

### Notifications not appearing?
1. Check if birthday status is "Active"
2. Verify the birth date is set to today
3. Check if notifications table exists
4. Ensure users are "Active" in the users table

### Duplicate notifications?
- The system prevents duplicate notifications for the same day
- If you see duplicates, check the cron job isn't running multiple times

### Cron job not working?
1. Verify PHP path: `which php`
2. Check cron logs: `grep CRON /var/log/syslog`
3. Test manually: `php /path/to/Gyanam/cron/birthday_notifications.php`
4. Ensure file permissions: `chmod +x cron/birthday_notifications.php`

## Database Schema

```sql
CREATE TABLE birthdays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_name VARCHAR(100) NOT NULL,
    mobile VARCHAR(15),
    birth_date DATE NOT NULL,
    description TEXT,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

## Support
For issues or questions, contact the development team.
