# Gyanam India Portal

A comprehensive education management system for managing DLC offices, ATC centers, student admissions, fee collection, course management, and material dispatch tracking.

## 🎯 Overview

Gyanam India Portal is a multi-role web application designed to streamline operations across three organizational levels:
- **Head Office (Admin)** - Central management and oversight
- **DLC Offices** - Regional coordination and ATC management
- **ATC Centers** - Student admissions, fee collection, and course delivery

## ✨ Key Features

### 📚 Student Management
- Complete admission process with photo upload
- Student records with course enrollment
- Search and filter capabilities
- Status tracking (Active/Inactive)

### 💰 Fee Management
- Fee collection with multiple payment modes
- Payment history with remarks
- Professional receipt generation
- Pending/paid fee tracking
- Payment reminders

### 📖 Course Management
- Course catalog with fees and duration
- Course assignment to students
- Course-wise student filtering
- Fee structure management

### 🎓 Hall Tickets
- Professional hall ticket generation
- Student photo integration
- Exam center details
- Print-optimized layout
- Bulk generation capability

### 📦 Dispatch Management
- End-to-end material tracking
- Three-tier workflow (Admin → DLC → ATC)
- Courier tracking integration
- Complete audit trail
- Status notifications

### 📄 Document Management
- Centralized document repository
- Role-based access (Admin upload, DLC/ATC download)
- Multiple format support
- File size validation
- Category organization

### 📊 Analytics & Reports
- ATC center statistics
- Fee collection reports
- Student enrollment trends
- Dispatch tracking reports
- Performance metrics

## 🏗️ System Architecture

### Technology Stack
- **Backend:** PHP 8.2+
- **Database:** MySQL 8.0+
- **Frontend:** HTML5, CSS3, JavaScript (ES6+)
- **Server:** Apache 2.4+
- **Icons:** Feather Icons (SVG)

### Database Structure
```
├── users                    # User authentication
├── dlc_offices             # DLC office information
├── atc_centers             # ATC center information
├── courses                 # Course catalog
├── admissions              # Student admissions
├── fee_payments            # Payment records
├── fee_payment_remarks     # Payment history
├── documents               # Document storage
├── dispatches              # Dispatch tracking
└── dispatch_history        # Audit trail
```

### Directory Structure
```
Gyanam/
├── admin/                  # Admin panel
│   ├── index.php          # Dashboard
│   ├── dlc_offices.php    # DLC management
│   ├── atc_centers.php    # ATC statistics
│   ├── dispatches.php     # Dispatch creation
│   ├── documents.php      # Document upload
│   └── sidebar.php        # Navigation
├── dlc/                   # DLC panel
│   ├── index.php          # Dashboard
│   ├── atc_centers.php    # ATC management
│   ├── dispatches.php     # Dispatch forwarding
│   ├── documents.php      # Document download
│   └── sidebar.php        # Navigation
├── atc/                   # ATC panel
│   ├── index.php          # Dashboard
│   ├── students.php       # Student list
│   ├── new_admission.php  # New admission
│   ├── fees.php           # Fee management
│   ├── courses.php        # Course management
│   ├── hall_tickets.php   # Hall ticket generation
│   ├── dispatches.php     # Delivery confirmation
│   ├── documents.php      # Document download
│   └── sidebar.php        # Navigation
├── assets/                # Static assets
│   ├── css/              # Stylesheets
│   ├── js/               # JavaScript files
│   └── logo.png          # Logo
├── config/               # Configuration
│   └── db.php           # Database connection
├── includes/            # Shared components
│   ├── auth.php        # Authentication
│   ├── functions.php   # Helper functions
│   └── notifications.php # Notification system
├── uploads/            # User uploads
│   ├── documents/     # Document files
│   └── students/      # Student photos
└── index.php          # Login page
```

## 🚀 Installation

### Prerequisites
- PHP 8.2 or higher
- MySQL 8.0 or higher
- Apache 2.4 or higher
- Web browser (Chrome, Firefox, Edge, Safari)

### Setup Steps

1. **Clone or Download**
   ```bash
   # Place files in your web server directory
   # Example: C:\xampp\htdocs\Gyanam
   ```

2. **Database Setup**
   ```sql
   -- Create database
   CREATE DATABASE gyanam_portal;
   
   -- Import tables
   -- Run create_dispatch_tables.sql
   -- Run create_documents_table.sql
   -- Import other table schemas
   ```

3. **Configure Database**
   ```php
   // Edit config/db.php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'gyanam_portal');
   define('DB_USER', 'your_username');
   define('DB_PASS', 'your_password');
   ```

4. **Set Permissions**
   ```bash
   # Make uploads directory writable
   chmod 755 uploads/
   chmod 755 uploads/documents/
   chmod 755 uploads/students/
   ```

5. **Access Application**
   ```
   http://localhost/Gyanam/
   ```

### Default Login Credentials
```
Admin:
Username: admin
Password: admin123

DLC Office:
Username: dlc_pune
Password: dlc123

ATC Center:
Username: atc_pune_01
Password: atc123
```

**⚠️ Important:** Change default passwords after first login!

## 👥 User Roles & Permissions

### Admin (Head Office)
✅ Full system access  
✅ Manage DLC offices  
✅ Manage ATC centers  
✅ Create dispatches  
✅ Upload documents  
✅ View all statistics  
✅ Cancel dispatches  

### DLC Office
✅ Manage ATC centers (under jurisdiction)  
✅ Forward dispatches to ATC  
✅ Download documents  
✅ View ATC statistics  
✅ Create ATC user accounts  

### ATC Center
✅ Student admissions  
✅ Fee collection  
✅ Course management  
✅ Hall ticket generation  
✅ Confirm dispatch delivery  
✅ Download documents  
✅ View own statistics  

## 📖 Documentation

### User Guides
- **[QUICK_REFERENCE.md](QUICK_REFERENCE.md)** - Quick guide for common tasks
- **[DISPATCH_SYSTEM_GUIDE.md](DISPATCH_SYSTEM_GUIDE.md)** - Complete dispatch workflow

### Technical Documentation
- **[IMPLEMENTATION_STATUS.md](IMPLEMENTATION_STATUS.md)** - Feature status and testing
- **[DEPLOYMENT_CHECKLIST.md](DEPLOYMENT_CHECKLIST.md)** - Complete deployment guide
- **[database_schema_complete.sql](database_schema_complete.sql)** - Complete database schema

### Legacy SQL Files
- **[create_dispatch_tables.sql](create_dispatch_tables.sql)** - Dispatch tables only
- **[create_documents_table.sql](create_documents_table.sql)** - Documents table only

## 🎨 UI/UX Guidelines

### Color Scheme
- **Primary:** Purple gradient (#6366f1 → #8b5cf6)
- **Payment:** Pink gradient (#ec4899 → #db2777)
- **Success:** Green (#10b981)
- **Warning:** Orange (#f59e0b)
- **Danger:** Red (#ef4444)

### Design Principles
- Clean, modern interface
- Responsive design (mobile-friendly)
- Icon-based navigation
- Status badges with color coding
- Modal-based forms
- Print-friendly layouts

## 🔒 Security Features

- ✅ Role-based access control
- ✅ Session management
- ✅ SQL injection prevention
- ✅ XSS protection
- ✅ File upload validation
- ✅ Secure password handling
- ✅ CSRF protection
- ✅ Input sanitization

## 🧪 Testing

### Manual Testing Checklist
- [ ] Admin login and dashboard
- [ ] DLC office creation and management
- [ ] ATC center creation and management
- [ ] Student admission process
- [ ] Fee payment and receipt generation
- [ ] Course management
- [ ] Hall ticket generation
- [ ] Document upload and download
- [ ] Dispatch creation and tracking
- [ ] Dispatch forwarding (DLC)
- [ ] Delivery confirmation (ATC)
- [ ] Search and filter functionality
- [ ] Print functionality
- [ ] Mobile responsiveness

### Browser Testing
- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Edge (latest)
- [ ] Safari (latest)
- [ ] Mobile browsers

## 🐛 Troubleshooting

### Common Issues

**Cannot Login**
- Check username/password
- Clear browser cache
- Verify database connection

**File Upload Failed**
- Check file size (max 10MB for documents, 2MB for photos)
- Verify file format
- Check upload directory permissions

**Page Not Loading**
- Hard refresh (Ctrl + Shift + R)
- Check Apache/PHP errors
- Verify database connection

**Receipt Not Printing**
- Check browser print settings
- Try "Save as PDF" option
- Verify CSS is loading

## 🔄 Backup & Maintenance

### Regular Tasks
- **Daily:** Database backup
- **Weekly:** File upload backup
- **Monthly:** Log file cleanup
- **Quarterly:** Security audit

### Backup Commands
```bash
# Database backup
mysqldump -u username -p gyanam_portal > backup_$(date +%Y%m%d).sql

# File backup
tar -czf uploads_backup_$(date +%Y%m%d).tar.gz uploads/
```

## 🚀 Future Enhancements

### Planned Features
- Email notifications
- SMS alerts
- Mobile app
- Barcode/QR code integration
- Courier API integration
- Advanced analytics
- Bulk operations
- Export to Excel/PDF
- Multi-language support
- Dark mode

## 📞 Support

For technical support or questions:
- Check documentation files
- Review troubleshooting section
- Contact system administrator

## 📄 License

Proprietary - Gyanam India  
All rights reserved.

## 👨‍💻 Development

**Version:** 1.0  
**Last Updated:** March 4, 2026  
**Status:** Production Ready

---

## Quick Start Guide

1. **Install XAMPP** (or similar LAMP/WAMP stack)
2. **Place files** in htdocs/Gyanam
3. **Create database** and import SQL files
4. **Configure** config/db.php
5. **Access** http://localhost/Gyanam/
6. **Login** with default credentials
7. **Change passwords** immediately
8. **Start using** the system!

For detailed instructions, see [QUICK_REFERENCE.md](QUICK_REFERENCE.md)

---

**Made with ❤️ for Gyanam India**
