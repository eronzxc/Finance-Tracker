# Sentimo — Personal Finance Tracker

A clean, full-stack personal finance web application built with **PHP**, **MySQL**, and **vanilla CSS**. Sentimo lets users log daily income and expenses, visualize spending patterns, and track their net balance — all without the complexity of a heavy framework.

> Built as a self-directed upskilling project during OJT to demonstrate full-stack PHP development, UI/UX design, and secure web application fundamentals.

---

## Features

- **Secure Authentication** — Registration with `password_hash()` BCRYPT, session-based login, and logout
- **Transaction Management** — Log income and expenses with category, description, and date; delete entries with confirmation
- **Revenue Flow Chart** — 6-month bar chart (Chart.js) showing income vs. expense trends
- **Expense Breakdown** — Donut chart visualizing top spending categories
- **Real-time Filtering** — Filter transactions by type (Income / Expenses / This Month) and live search by description or category — zero page reloads
- **Net Balance Card** — Dynamically colored balance with income/spent summary chips
- **Premium Dark UI** — Glassmorphism design with animated gradient background, expandable hover sidebar, and responsive layout
- **PRG Pattern** — Post/Redirect/Get implemented on all form submissions to prevent duplicate entries on refresh

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.2 |
| Database | MySQL (via PDO with prepared statements) |
| Frontend | Vanilla CSS (Flexbox + Grid), Vanilla JS |
| Charts | Chart.js (CDN) |
| Typography | Inter — Google Fonts |
| Local Server | XAMPP (Apache + MySQL) |

> No npm. No React. No Composer. Everything runs in a single PHP file per page — intentionally lightweight for the XAMPP environment.

---

## Project Structure

```
Finance Tracker/
├── db.php # PDO database connection (credentials via config)
├── register.php # User registration with BCRYPT password hashing
├── login.php # Session-based authentication
├── logout.php # Session destruction and redirect
├── dashboard.php # Main hub — charts, transaction log, add/delete form
└── schema.sql # Database schema (tables: users, categories, transactions)
```

---

## Setup & Installation

### Prerequisites
- XAMPP (or any Apache + MySQL + PHP 8.x stack)
- PHP 8.2+
- MySQL 5.7+ / MariaDB

### Steps

**1. Clone the repository**
```bash
git clone https://github.com/eronzxc/Finance-Tracker.git
```

**2. Move to your XAMPP htdocs folder**
```
C:/xampp/htdocs/Finance Tracker/
```

**3. Import the database**

Open **phpMyAdmin**, create a new database named `personal_finance`, then import:
```
schema.sql
```

**4. Configure the database connection**

Open `db.php` and update the credentials if needed:
```php
$host = 'localhost';
$db = 'personal_finance';
$user = 'root';
$pass = '';
```

**5. Start XAMPP and open in browser**
```
http://localhost/Finance Tracker/login.php
```

---

## Database Schema

```sql
-- Users table
CREATE TABLE users (
 id INT AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(50) NOT NULL UNIQUE,
 display_name VARCHAR(100),
 password VARCHAR(255) NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Categories table
CREATE TABLE categories (
 id INT AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL,
 type ENUM('income', 'expense') NOT NULL
);

-- Transactions table
CREATE TABLE transactions (
 id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 category_id INT NOT NULL,
 amount DECIMAL(10,2) NOT NULL,
 description VARCHAR(255),
 date DATE NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
);
```

---

## Security Highlights

- Passwords hashed with `PASSWORD_BCRYPT` via `password_hash()` / `password_verify()`
- All database queries use **PDO prepared statements** — no raw SQL string interpolation
- Session authentication check on every protected page
- User-scoped queries — users can only read/delete their own transactions
- `htmlspecialchars()` on all rendered user input to prevent XSS
- PRG (Post/Redirect/Get) pattern prevents duplicate form submissions on refresh

---

## Screenshots

> *Add screenshots here after taking them at 100% zoom, 1920×1080*

| Dashboard | Empty State |
|---|---|
| `screenshot-dashboard.png` | `screenshot-empty.png` |

---

## Roadmap

- [ ] `transactions.php` — Full paginated transaction history page
- [ ] `reports.php` — Monthly and category-level reports
- [ ] `settings.php` — Profile update, password change
- [ ] Budget limits per category with over-budget alerts
- [ ] Export transactions to CSV

---

## Author

**Aaron Ludwig A. Altar** — Computer Engineering Student, currently on OJT 
[github.com/eronzxc](https://github.com/eronzxc)

---

## License

This project is open source and available under the [MIT License](LICENSE).
