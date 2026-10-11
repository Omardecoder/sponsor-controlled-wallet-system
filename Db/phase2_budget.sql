-- Db/phase2_budget.sql
-- Phase 2: student budget "envelopes". Safe to run more than once.

USE sponsor_wallet_db;

CREATE TABLE IF NOT EXISTS budget_allocations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  wallet_id   INT UNSIGNED NOT NULL,
  category    VARCHAR(30)  NOT NULL,                 -- tuition, rent, food, transport, academic
  allocated   DECIMAL(12,2) NOT NULL DEFAULT 0.00,   -- amount the student set aside
  spent       DECIMAL(12,2) NOT NULL DEFAULT 0.00,   -- filled in by Phase 3 (expenses)
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wallet_category (wallet_id, category),
  CONSTRAINT fk_budget_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id)
) ENGINE=InnoDB;
