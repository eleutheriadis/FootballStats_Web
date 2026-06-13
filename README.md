# ΕΠΟ Stats — Web Εφαρμογή Διαχείρισης

## Εγκατάσταση (XAMPP/MAMP)

### 1. Βάση δεδομένων
```bash
mysql -u root -p < schema.sql
```

### 2. Dependencies
```bash
composer install
```

### 3. Environment
```bash
cp .env.example .env
# Άνοιξε .env και συμπλήρωσε DB_PASSWORD αν χρειάζεται
```

### 4. Τοποθέτηση στον server
Βάλε τον φάκελο `FootballStats_Web/` μέσα στο:
- **XAMPP**: `htdocs/epo/`
- **MAMP**: `htdocs/epo/`

Άνοιξε: `http://localhost/epo/`

## Δομή
```
FootballStats_Web/
├── config.php          ← DB + helpers
├── index.php           ← Dashboard
├── teams.php           ← Διαχείριση ομάδων
├── players.php         ← Διαχείριση παικτών
├── championships.php   ← Δημιουργία πρωταθλήματος
├── draw.php            ← Κλήρωση (round-robin)
├── includes/
│   ├── header.php
│   └── footer.php
├── assets/css/style.css
├── uploads/            ← Εικόνες (auto-created)
├── schema.sql          ← MySQL schema
├── composer.json
└── .env.example
```

## Socket MySQL (Mac)
Αν βλέπεις σφάλμα σύνδεσης, βρες το socket:
```bash
mysql_config --socket
# ή
php -r "echo ini_get('mysql.default_socket');"
```
Ενημέρωσε DB_SOCKET στο .env.
