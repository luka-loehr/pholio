-- Master data with Unicode: café, naïve, façade 🎉 𝔄𝔭𝔫𝔯𝔞𝔦𝔪
INSERT INTO team (label, level) VALUES ('red', 5), ('blue', 5), ('green', 6);

INSERT INTO topic (code, name, weekly_hours) VALUES ('T000', 'Algebra', 1), ('T001', 'Geometry', 2), ('T002', 'Astronomy', 3), ('T003', 'Façade Design', 4), ('T004', 'Botany', 5), ('T005', 'Physics', 1), ('T006', 'Chemistry', 2), ('T007', 'Biology', 3), ('T008', 'History', 4), ('T009', 'Civics', 5), ('T010', 'Geography', 1), ('T011', 'Fine Arts', 2), ('T012', 'Music', 3), ('T013', 'Sports', 4), ('T014', 'Computing', 5), ('T015', 'Philosophy', 1), ('T016', 'Ethics', 2), ('T017', 'Economics', 3), ('T018', 'Spanish', 4), ('T019', 'Linguistics', 5), ('T020', 'Robotics', 1), ('T021', 'Film & Theatre', 2), ('T022', 'Algebra', 3), ('T023', 'Geometry', 4), ('T024', 'Astronomy', 5), ('T025', 'Façade Design', 1), ('T026', 'Botany', 2), ('T027', 'Physics', 3), ('T028', 'Chemistry', 4), ('T029', 'Biology', 5), ('T030', 'History', 1), ('T031', 'Civics', 2), ('T032', 'Geography', 3), ('T033', 'Fine Arts', 4), ('T034', 'Music', 5), ('T035', 'Sports', 1), ('T036', 'Computing', 2), ('T037', 'Philosophy', 3), ('T038', 'Ethics', 4), ('T039', 'Economics', 5), ('T040', 'Spanish', 1), ('T041', 'Linguistics', 2), ('T042', 'Robotics', 3), ('T043', 'Film & Theatre', 4), ('T044', 'Algebra', 5), ('T045', 'Geometry', 1), ('T046', 'Astronomy', 2), ('T047', 'Façade Design', 3), ('T048', 'Botany', 4), ('T049', 'Physics', 5), ('T050', 'Chemistry', 1), ('T051', 'Biology', 2), ('T052', 'History', 3), ('T053', 'Civics', 4), ('T054', 'Geography', 5), ('T055', 'Fine Arts', 1), ('T056', 'Music', 2), ('T057', 'Sports', 3), ('T058', 'Computing', 4), ('T059', 'Philosophy', 5);

INSERT INTO member (team_id, first_name, last_name, email) VALUES
  (1, 'José', 'Muñoz', 'jose.munoz@example.com'),
  (1, 'Zoë', 'Nguyễn', NULL),
  (2, 'Élodie', 'Çelik', 'elodie@example.com'),
  (2, 'Anna Lena', 'Lindqvist', ''),   -- non-breaking space in the first name
  (3, '😀 Emoji', 'Test 👩‍💻', 'emoji@example.com');   
   



SELECT last_name,
       UPPER(last_name) AS upper_name,
       CHAR_LENGTH(last_name) AS chars,
       OCTET_LENGTH(last_name) AS bytes,
       SUM(1) AS one
FROM member
WHERE last_name COLLATE utf8mb4_unicode_ci = 'MUNOZ'
   OR last_name REGEXP '^[ÀÉÎ]'
   OR first_name LIKE '%\_%' ESCAPE '\\'
GROUP BY last_name;

SELECT "Column with ""quotes""", `backtick column`, [bracketed], N'Unicode literal €'
FROM "públic"."table";

