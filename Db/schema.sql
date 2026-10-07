-- database/schema.sql
-- Step 6 + Step 7: create the database and the users table.

CREATE DATABASE IF NOT EXISTS sponsor_wallet_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE sponsor_wallet_db;

CREATE TABLE IF NOT EXISTS users (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name   VARCHAR(100) NOT NULL,
  email       VARCHAR(150) NOT NULL,
  phone       VARCHAR(20)  NOT NULL,
  password    VARCHAR(255) NOT NULL,              -- stores the password_hash() result, never plain text
  role        ENUM('student','sponsor','admin') NOT NULL DEFAULT 'student',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_phone (phone)
) ENGINE=InnoDB;

-- Db/wallet_tables.sql
-- Step 10: wallets and transactions tables.
-- Run this in phpMyAdmin > SQL tab. Safe to run more than once.


-- One wallet per student. Each wallet is controlled by one sponsor.
CREATE TABLE IF NOT EXISTS wallets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id  INT UNSIGNED NOT NULL,
  sponsor_id  INT UNSIGNED NOT NULL,
  balance     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status      ENUM('active','frozen') NOT NULL DEFAULT 'active',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wallet_student (student_id),
  KEY idx_wallet_sponsor (sponsor_id),
  CONSTRAINT fk_wallet_student FOREIGN KEY (student_id) REFERENCES users(id),
  CONSTRAINT fk_wallet_sponsor FOREIGN KEY (sponsor_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Every movement of money is recorded here (the wallet's history).
CREATE TABLE IF NOT EXISTS transactions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  wallet_id      INT UNSIGNED NOT NULL,
  type           ENUM('funding','spending') NOT NULL,
  amount         DECIMAL(12,2) NOT NULL,
  balance_after  DECIMAL(12,2) NOT NULL,
  description    VARCHAR(255) NULL,
  created_by     INT UNSIGNED NOT NULL,           -- the user who made the transaction
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tx_wallet (wallet_id, created_at),
  CONSTRAINT fk_tx_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id),
  CONSTRAINT fk_tx_user   FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;
