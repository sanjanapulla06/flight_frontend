# Database Migrations

## How to Apply Migrations

### Step 1: Backup Your Database
```sql
mysqldump -u root -p airport_demo > backup_before_migration_$(date +%Y%m%d).sql
```

### Step 2: Apply Migrations in Order
```bash
# Windows (PowerShell in XAMPP)
cd C:\xampp\mysql\bin
.\mysql.exe -u root -p airport_demo < "C:\xampp\htdocs\flight_frontend\database_backup\migrations\001_add_missing_schema.sql"
.\mysql.exe -u root -p airport_demo < "C:\xampp\htdocs\flight_frontend\database_backup\migrations\002_sample_data.sql"
```

Or from MySQL Workbench:
1. Open MySQL Workbench
2. Connect to your database
3. File → Open SQL Script
4. Run `001_add_missing_schema.sql`
5. Run `002_sample_data.sql`

## What These Migrations Add

### 001_add_missing_schema.sql
- ✅ `airport_airline` table (CONTAINS relationship)
- ✅ `pmr` table (special assistance passengers)
- ✅ `serves` table (employee-passenger relationship)
- ✅ `monitors` table (ATC-flight relationship)
- ✅ `connecting` table (multi-leg flights)
- ✅ Missing columns in `passenger` (fname, mname, lname, age, sex, pid)
- ✅ Missing columns in `ticket` (surcharge, date_of_booking, date_of_travel)
- ✅ Missing columns in `flight` (no_of_stops, layover_time, flight_type)
- ✅ Indexes for performance

### 002_sample_data.sql
- ✅ Populates airport_airline from existing flights
- ✅ Sample PMR records
- ✅ Sets default flight types

## Backward Compatibility

✅ **Your existing code will NOT break** because:
- All additions use `ADD COLUMN IF NOT EXISTS` (safe)
- Original columns remain unchanged
- New tables don't conflict with existing queries
- Your `name` column in passenger still works
- Indexes improve performance without breaking functionality

## Testing After Migration

Run these queries to verify:

```sql
-- Check new tables exist
SHOW TABLES LIKE '%airline%';
SHOW TABLES LIKE 'pmr';
SHOW TABLES LIKE 'serves';
SHOW TABLES LIKE 'monitors';
SHOW TABLES LIKE 'connecting';

-- Check new columns
DESCRIBE passenger;
DESCRIBE ticket;
DESCRIBE flight;

-- Verify data integrity
SELECT COUNT(*) FROM airport_airline;
SELECT COUNT(*) FROM passenger WHERE fname IS NOT NULL;
```

## Rollback (if needed)

If something goes wrong, restore from backup:
```bash
mysql -u root -p airport_demo < backup_before_migration_YYYYMMDD.sql
```
