-- Step 21a: a student can have several sponsors (sponsorships with pledges)

-- 1. One row per sponsor-student pair. "pledge" = the most this sponsor will give.
CREATE TABLE IF NOT EXISTS sponsorships (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  student_id   INT UNSIGNED  NOT NULL,
  sponsor_id   INT UNSIGNED  NOT NULL,
  pledge       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status       ENUM('pending','active','declined','ended') NOT NULL DEFAULT 'pending',
  message      VARCHAR(255)  NULL,
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at DATETIME      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sponsorship (student_id, sponsor_id),
  KEY idx_sponsorship_sponsor (sponsor_id, status),
  CONSTRAINT fk_sponsorship_student FOREIGN KEY (student_id) REFERENCES users(id),
  CONSTRAINT fk_sponsorship_sponsor FOREIGN KEY (sponsor_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- 2. Each sponsor's decision on a budget proposal (all sponsors must approve)
CREATE TABLE IF NOT EXISTS proposal_reviews (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  proposal_id  INT UNSIGNED  NOT NULL,
  sponsor_id   INT UNSIGNED  NOT NULL,
  decision     ENUM('approved','changes') NOT NULL,
  feedback     VARCHAR(1000) NULL,
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_review (proposal_id, sponsor_id),
  CONSTRAINT fk_review_sponsor FOREIGN KEY (sponsor_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- 3. Turn every existing wallet's sponsor into an active sponsorship.
--    Pledge = the larger of what they already sent and the approved budget.
INSERT INTO sponsorships (student_id, sponsor_id, pledge, status, created_at, responded_at)
SELECT w.student_id, w.sponsor_id,
       GREATEST(
         COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.wallet_id = w.id AND t.type = 'funding'), 0),
         COALESCE((SELECT SUM(a.allocated) FROM budget_allocations a WHERE a.wallet_id = w.id), 0)
       ),
       'active', w.created_at, w.created_at
FROM wallets w
WHERE w.sponsor_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM sponsorships s WHERE s.student_id = w.student_id AND s.sponsor_id = w.sponsor_id);

-- 4. Wallets no longer belong to a single sponsor
ALTER TABLE wallets MODIFY sponsor_id INT UNSIGNED NULL;