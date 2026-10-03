-- Db/schema.sql
-- Creates the sponsor_wallet_db database and its tables.
-- Run it once in phpMyAdmin: open http://localhost/phpmyadmin -> Import -> choose this file -> Go.

CREATE DATABASE IF NOT EXISTS sponsor_wallet_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sponsor_wallet_db;

-- Everyone who can log in: students, sponsors and admins
CREATE TABLE IF NOT EXISTS users (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name   VARCHAR(100) NOT NULL,
  email       VARCHAR(150) NOT NULL UNIQUE,
  phone       VARCHAR(20)  NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,              -- password_hash() output, never plain text
  role        ENUM('student','sponsor','admin') NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- One wallet per student, funded and controlled by a sponsor
CREATE TABLE IF NOT EXISTS wallets (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id  INT UNSIGNED NOT NULL UNIQUE,
  sponsor_id  INT UNSIGNED NOT NULL,
  balance     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status      ENUM('active','frozen','closed') NOT NULL DEFAULT 'active',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_wallet_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_wallet_sponsor FOREIGN KEY (sponsor_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Every movement of money in or out of a wallet
CREATE TABLE IF NOT EXISTS transactions (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wallet_id      INT UNSIGNED NOT NULL,
  type           ENUM('funding','spending') NOT NULL,
  amount         DECIMAL(12,2) NOT NULL,
  balance_after  DECIMAL(12,2) NOT NULL,
  description    VARCHAR(255) NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tx_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE,
  INDEX idx_tx_wallet_date (wallet_id, created_at)
) ENGINE=InnoDB;
