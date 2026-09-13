
-- Schema for teams, members and absences
/* Character set: utf8mb4 so emoji (🎉) can be stored as well */
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS team (
	id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
	label       VARCHAR(8)   NOT NULL,          -- e.g. 'blue'
	level       TINYINT      NOT NULL CHECK (level BETWEEN 5 AND 13),
	edition     CHAR(7)      NOT NULL DEFAULT '2026/27',
	PRIMARY KEY (id),
	UNIQUE KEY uq_team (label, edition)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

create table member (
	id           bigint unsigned primary key auto_increment,
	team_id      int unsigned not null,
	first_name   varchar(100) not null,
	last_name    varchar(100) not null,
	birthday     date null,
	email        varchar(255) default null,
	active       boolean not null default true,
	created_at   timestamp not null default current_timestamp on update current_timestamp,
	constraint fk_member_team foreign key (team_id)
		references team (id) on delete restrict on update cascade,
	constraint chk_email check (email is null or email like '%_@_%._%')
);

CREATE TABLE absence (
  id           BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  member_id    BIGINT UNSIGNED NOT NULL REFERENCES member (id) ON DELETE CASCADE,
  starts_at    DATETIME NOT NULL,
  ends_at      DATETIME NOT NULL,
  excused      ENUM('yes', 'no', 'open') NOT NULL DEFAULT 'open',
  note         TEXT,
  hours        DECIMAL(4, 1) NOT NULL DEFAULT 0.0,
  CONSTRAINT chk_period CHECK (ends_at >= starts_at)
);

CREATE INDEX idx_absence_period ON absence (member_id, starts_at DESC);
CREATE UNIQUE INDEX idx_member_email ON member (email);

ALTER TABLE member
  ADD COLUMN emergency_contact VARCHAR(255) NULL AFTER email,
  DROP COLUMN IF EXISTS old_field;

CREATE OR REPLACE VIEW v_absent_hours AS
SELECT m.id, m.first_name, m.last_name, SUM(a.hours) AS hours_total, UPPER(m.last_name) AS upper_name
FROM member AS m
LEFT JOIN absence AS a ON a.member_id = m.id
GROUP BY m.id, m.first_name, m.last_name;

DROP TABLE IF EXISTS tmp_import;
